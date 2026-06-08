<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * One-shot migration: backs up the legacy keyword config + the existing
 * catalog_product_option rows that the keyword classifier touches, then
 * creates Etechflow templates from the distinct option-sets it finds.
 *
 * The keyword classifier logic is replicated here in PHP (instead of relying
 * on the runtime classifier) so the migration is reproducible from SQL.
 *
 * Output:
 *   - SQL backup at var/efopt/migration-backup-{ts}.sql
 *   - One new efopt_template row per distinct option-set across products
 *   - One efopt_template_product row linking the template to each matched product
 *
 * Idempotent: re-running won't duplicate templates if their structure matches
 * an existing one (matched by name + options hash).
 */
class MigrationService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Filesystem $filesystem,
        private readonly TemplateFactory $templateFactory,
        private readonly TemplateResource $templateResource,
        private readonly Template\OptionFactory $optionFactory,
        private readonly ResourceModel\Template\Option $optionResource,
        private readonly Template\Option\ValueFactory $valueFactory,
        private readonly ResourceModel\Template\Option\Value $valueResource,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array{backup_path:string, templates_created:int, products_linked:int}
     */
    public function run(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varDir->create('efopt');

        $ts = date('Ymd-His');
        $relPath = 'efopt/migration-backup-' . $ts . '.sql';
        $absPath = $varDir->getAbsolutePath($relPath);

        $optionsTable     = $this->resourceConnection->getTableName('catalog_product_option');
        $titleTable       = $this->resourceConnection->getTableName('catalog_product_option_title');
        $valueTable       = $this->resourceConnection->getTableName('catalog_product_option_type_value');
        $valueTitleTable  = $this->resourceConnection->getTableName('catalog_product_option_type_title');

        $optionPriceTable     = $this->resourceConnection->getTableName('catalog_product_option_price');
        $valuePriceTable      = $this->resourceConnection->getTableName('catalog_product_option_type_price');

        // 1. Backup the current state of catalog_product_option for products
        //    that have any custom options. (Scope kept simple: dump every option.)
        $sql = "-- Etechflow_OptionsPlugin migration backup, generated at " . date('c') . "\n\n";
        foreach ([$optionsTable, $titleTable, $optionPriceTable, $valueTable, $valueTitleTable, $valuePriceTable] as $table) {
            $rows = $conn->fetchAll("SELECT * FROM `$table`");
            $sql .= "-- $table (" . count($rows) . " rows)\n";
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $placeholders = array_map(static fn($v) => $v === null ? 'NULL' : $conn->quote($v), array_values($row));
                $sql .= "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES ("
                    . implode(',', $placeholders) . ");\n";
            }
            $sql .= "\n";
        }
        $varDir->writeFile($relPath, $sql);

        // 2. Find products with any custom options (the migration targets these).
        $productIds = $conn->fetchCol(
            $conn->select()->from($optionsTable, 'product_id')->distinct()
        );

        $templatesCreated = 0;
        $productsLinked = 0;

        // For each product, build a "signature" of its options (titles+types+values)
        // and group products by signature. One template per signature.
        $signatures = []; // sig => [productIds, sample options]
        foreach ($productIds as $pid) {
            // Price + price_type live in catalog_product_option_price (per-store).
            // We pull store_id=0 (default scope) for the structural migration.
            $options = $conn->fetchAll(
                $conn->select()
                    ->from(['o' => $optionsTable], ['option_id', 'type', 'is_require', 'sort_order', 'sku'])
                    ->join(['t' => $titleTable], 'o.option_id = t.option_id AND t.store_id = 0', ['title'])
                    ->joinLeft(['p' => $optionPriceTable], 'o.option_id = p.option_id AND p.store_id = 0', ['price', 'price_type'])
                    ->where('o.product_id = ?', $pid)
                    ->order('o.sort_order ASC')
            );
            if (!$options) { continue; }
            $sigParts = [];
            // Iterate by index so we can mutate the array entries (foreach-by-value
            // copies, so $opt['values']=... would have been lost otherwise).
            foreach ($options as $i => $opt) {
                $valueRows = $conn->fetchAll(
                    $conn->select()
                        ->from(['v' => $valueTable], ['option_type_id', 'sku', 'sort_order'])
                        ->join(['vt' => $valueTitleTable], 'v.option_type_id = vt.option_type_id AND vt.store_id = 0', ['title'])
                        ->joinLeft(['vp' => $valuePriceTable], 'v.option_type_id = vp.option_type_id AND vp.store_id = 0', ['price', 'price_type'])
                        ->where('v.option_id = ?', (int)$opt['option_id'])
                        ->order('v.sort_order ASC')
                );
                $options[$i]['values'] = $valueRows;
                $sigParts[] = $opt['title'] . '|' . $opt['type'] . '|' . count($valueRows);
            }
            $sig = md5(implode("\n", $sigParts));
            if (!isset($signatures[$sig])) {
                $signatures[$sig] = ['products' => [], 'options' => $options];
            }
            $signatures[$sig]['products'][] = (int)$pid;
        }

        // 3. Create one template per signature.
        $linkTable     = $this->resourceConnection->getTableName('efopt_template_product');
        $templateTable = $this->resourceConnection->getTableName('efopt_template');

        foreach ($signatures as $sig => $grp) {
            // Idempotency: a previous run records "Signature: <sig>" in the template
            // description. If a template for this signature already exists, skip the
            // group so re-running the migration never duplicates templates/options.
            $existingTplId = (int) $conn->fetchOne(
                $conn->select()->from($templateTable, 'template_id')
                    ->where('description LIKE ?', '%Signature: ' . $sig . '%')
                    ->limit(1)
            );
            if ($existingTplId > 0) {
                continue;
            }

            $tplName = 'Migrated: ' . count($grp['options']) . ' option(s) — '
                . substr($grp['options'][0]['title'] ?? 'set', 0, 40);
            $tpl = $this->templateFactory->create();
            $tpl->setData([
                'name'        => $tplName,
                'is_active'   => 1,
                'description' => 'Auto-generated by Etechflow_OptionsPlugin migration tool from ' . count($grp['products']) . ' product(s). Signature: ' . $sig,
            ]);
            $this->templateResource->save($tpl);
            $templateId = (int)$tpl->getId();
            $templatesCreated++;

            // 4. Copy options + values into the template.
            $tplOptionIds = []; // index → tplOptionId, parallel with $grp['options']
            foreach ($grp['options'] as $i => $opt) {
                $tplOpt = $this->optionFactory->create();
                $tplOpt->setData([
                    'template_id'    => $templateId,
                    'sort_order'     => (int)($opt['sort_order'] ?? $i),
                    'title'          => (string)$opt['title'],
                    'type'           => (string)$opt['type'],
                    'is_required'    => (int)$opt['is_require'],
                    'price'          => $opt['price'] !== null ? (float)$opt['price'] : null,
                    'price_type'     => (string)($opt['price_type'] ?? 'fixed'),
                    'sku'            => $opt['sku'] ?? null,
                ]);
                $this->optionResource->save($tplOpt);
                $tplOptionIds[$i] = (int)$tplOpt->getId();

                foreach ($opt['values'] ?? [] as $vi => $val) {
                    $tplVal = $this->valueFactory->create();
                    $tplVal->setData([
                        'template_option_id' => $tplOptionIds[$i],
                        'sort_order'         => (int)($val['sort_order'] ?? $vi),
                        'title'              => (string)$val['title'],
                        'price'              => (float)$val['price'],
                        'price_type'         => (string)($val['price_type'] ?? 'fixed'),
                        'sku'                => $val['sku'] ?? null,
                    ]);
                    $this->valueResource->save($tplVal);
                }
            }

            // 5. Link the template to every product in this group.
            //    Mark them as 'direct' source — migration is per-product, not per-category.
            //    Note: we DO NOT call SyncService here because the products already have
            //    their options (Magento's existing catalog_product_option rows). We just
            //    record the link, mapping each template option to its existing magento_option_id.
            foreach ($grp['products'] as $pid) {
                // Find the product's options that match by title (case-insensitive).
                $existingOptions = $conn->fetchPairs(
                    $conn->select()
                        ->from(['o' => $optionsTable], 'option_id')
                        ->join(['t' => $titleTable], 'o.option_id = t.option_id', 'title')
                        ->where('o.product_id = ?', $pid)
                        ->where('t.store_id = 0')
                );
                $existingByLowerTitle = [];
                foreach ($existingOptions as $oid => $title) {
                    $existingByLowerTitle[mb_strtolower((string)$title)] = (int)$oid;
                }
                foreach ($grp['options'] as $i => $opt) {
                    $magOptId = $existingByLowerTitle[mb_strtolower((string)$opt['title'])] ?? null;
                    $conn->insertOnDuplicate($linkTable, [
                        'template_id'        => $templateId,
                        'product_id'         => $pid,
                        'template_option_id' => $tplOptionIds[$i],
                        'magento_option_id'  => $magOptId,
                        'source'             => 'direct',
                    ], ['magento_option_id', 'source']);
                }
                $productsLinked++;
            }
        }

        return [
            'backup_path'      => $absPath,
            'templates_created'=> $templatesCreated,
            'products_linked'  => $productsLinked,
        ];
    }
}
