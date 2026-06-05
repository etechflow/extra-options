<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\CollectionFactory as TemplateOptionCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value\CollectionFactory as TemplateValueCollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option as ProductOption;
use Magento\Catalog\Model\Product\OptionFactory as ProductOptionFactory;
use Magento\Catalog\Model\Product\Option\Value as ProductOptionValue;
use Magento\Catalog\Model\Product\Option\ValueFactory as ProductOptionValueFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * SyncService — the bridge between Etechflow Templates and Magento native custom options.
 *
 * When a template is linked to a product (directly or via a category), this
 * service creates real catalog_product_option rows + their type_value rows on
 * the target product. The mapping is recorded in efopt_template_product so we
 * can re-sync (update) or de-sync (delete) cleanly later.
 *
 * Why create real Magento options instead of rendering them dynamically?
 *  - Cart / checkout / quote validation work without any extra plugins
 *  - The Hyvä radio-card frontend reads from catalog_product_option as today
 *  - Admin can still inspect/override per-product options as a last resort
 *
 * Caveat: deleting a template (via the Templates list) does NOT auto-delete
 * the synced options on products — admins must run "Desync" on the template
 * first. This is intentional, to avoid accidental catalog-wide damage.
 */
class SyncService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductOptionFactory $productOptionFactory,
        private readonly ProductOptionValueFactory $productOptionValueFactory,
        private readonly TemplateOptionCollectionFactory $templateOptionCollectionFactory,
        private readonly TemplateValueCollectionFactory $templateValueCollectionFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Sync ONE template to ONE product. Creates/updates the catalog_product_option
     * rows for every option in the template. Idempotent — safe to call repeatedly.
     *
     * @param int $templateId
     * @param int $productId
     * @param string $source 'direct' (set explicitly per-product) or 'category' (resolved via category link)
     * @param int|null $sourceCategoryId  Resolving category if source==='category'
     */
    public function syncTemplateToProduct(
        int $templateId,
        int $productId,
        string $source = 'direct',
        ?int $sourceCategoryId = null
    ): void {
        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_product');

        try {
            $product = $this->productRepository->getById($productId, true);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf('[efopt sync] product %d not found', $productId));
            return;
        }

        // Load template options + their values once.
        $templateOptions = $this->templateOptionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->setOrder('sort_order', 'ASC');
        if (!$templateOptions->getSize()) {
            return;
        }

        // Existing link rows for this (template,product) → option_id → row
        $existingLinks = $conn->fetchAll(
            $conn->select()->from($linkTable)->where('template_id = ?', $templateId)->where('product_id = ?', $productId)
        );
        $linksByTemplateOption = [];
        foreach ($existingLinks as $row) {
            $linksByTemplateOption[(int)$row['template_option_id']] = $row;
        }

        $seenTemplateOptionIds = [];

        foreach ($templateOptions as $tOpt) {
            $tOptId = (int)$tOpt->getId();
            $seenTemplateOptionIds[$tOptId] = true;

            $magentoOptionId = isset($linksByTemplateOption[$tOptId])
                ? (int)$linksByTemplateOption[$tOptId]['magento_option_id']
                : 0;

            // Build or update the catalog_product_option row.
            /** @var ProductOption $productOption */
            $productOption = $this->productOptionFactory->create();
            if ($magentoOptionId) {
                $productOption->load($magentoOptionId);
                if (!$productOption->getId()) {
                    $magentoOptionId = 0; // stale, recreate below
                }
            }

            // Fallback: if no mapped magento_option_id yet, find an existing
            // catalog_product_option on this product with the SAME title and
            // reuse it. Prevents the migration-leftover bug where products had
            // an old option with the same title but no link.
            if (!$magentoOptionId) {
                $existingId = $this->findExistingOptionIdByTitle(
                    $productId,
                    (string)$tOpt->getData('title')
                );
                if ($existingId) {
                    $productOption->load($existingId);
                    if ($productOption->getId()) {
                        $magentoOptionId = (int)$productOption->getId();
                    }
                }
            }

            $isSelectable = in_array((string)$tOpt->getData('type'), ['drop_down', 'radio', 'checkbox', 'multiple'], true);

            // Build values BEFORE the option save so Magento's Select validator
            // sees them. For existing options, also wipe their cached values
            // collection so buildValueObjects rematches against fresh data.
            $newValues = $isSelectable ? $this->buildValueObjects($tOptId, $productOption) : [];

            // Use addData (NOT setData) so we don't wipe the option_id loaded
            // above. setData replaces the entire data array — losing the ID
            // makes Magento think this is a new option and creates a duplicate.
            $productOption->addData([
                'product_id'    => $productId,
                'product_sku'   => $product->getSku(),
                'title'         => (string)$tOpt->getData('title'),
                'type'          => (string)$tOpt->getData('type'),
                'is_require'    => (int)$tOpt->getData('is_required'),
                'sort_order'    => (int)$tOpt->getData('sort_order'),
                'price'         => $tOpt->getData('price') !== null ? (float)$tOpt->getData('price') : ($isSelectable ? null : 0),
                'price_type'    => ((string)$tOpt->getData('price_type') ?: 'fixed'),
                'sku'           => $tOpt->getData('sku'),
                'max_characters'=> $tOpt->getData('max_characters'),
                'image_size_x'  => $tOpt->getData('image_size_x'),
                'image_size_y'  => $tOpt->getData('image_size_y'),
                'file_extension'=> $tOpt->getData('file_extension'),
            ]);
            // Belt-and-braces: explicitly preserve the loaded ID.
            if ($magentoOptionId) {
                $productOption->setId($magentoOptionId);
                $productOption->setOptionId($magentoOptionId);
            }
            $productOption->setProduct($product);

            if ($newValues) {
                $productOption->setValues($newValues);
            }

            // Single save with values pre-attached — passes Magento's Select validator.
            $productOption->save();

            $magentoOptionId = (int)$productOption->getId();

            // Upsert link row.
            if (isset($linksByTemplateOption[$tOptId])) {
                $conn->update(
                    $linkTable,
                    [
                        'magento_option_id'   => $magentoOptionId,
                        'source'              => $source,
                        'source_category_id'  => $sourceCategoryId,
                    ],
                    [
                        'template_id = ?'         => $templateId,
                        'product_id = ?'          => $productId,
                        'template_option_id = ?'  => $tOptId,
                    ]
                );
            } else {
                $conn->insert($linkTable, [
                    'template_id'        => $templateId,
                    'product_id'         => $productId,
                    'template_option_id' => $tOptId,
                    'magento_option_id'  => $magentoOptionId,
                    'source'             => $source,
                    'source_category_id' => $sourceCategoryId,
                ]);
            }
        }

        // Remove options that were in the template before but no longer are.
        foreach ($linksByTemplateOption as $tOptId => $row) {
            if (isset($seenTemplateOptionIds[$tOptId])) { continue; }
            $magentoOptionId = (int)$row['magento_option_id'];
            if ($magentoOptionId) {
                /** @var ProductOption $oldOpt */
                $oldOpt = $this->productOptionFactory->create()->load($magentoOptionId);
                if ($oldOpt->getId()) {
                    $oldOpt->delete();
                }
            }
            $conn->delete($linkTable, ['link_id = ?' => (int)$row['link_id']]);
        }

        // CRITICAL: flip has_options / required_options on the product so
        // Magento's cart processor knows to look up the new options. Without
        // these flags, the option silently won't be added to the cart line
        // item (PDP renders fine, but add-to-cart drops the selection because
        // Magento short-circuits when has_options=0).
        $hasOptions = (int) $conn->fetchOne(
            $conn->select()->from($conn->getTableName('catalog_product_option'), new \Zend_Db_Expr('COUNT(*)'))
                ->where('product_id = ?', $productId)
        ) > 0 ? 1 : 0;
        $requiredOptions = (int) $conn->fetchOne(
            $conn->select()->from($conn->getTableName('catalog_product_option'), new \Zend_Db_Expr('COALESCE(MAX(is_require), 0)'))
                ->where('product_id = ?', $productId)
        );
        $conn->update(
            $conn->getTableName('catalog_product_entity'),
            ['has_options' => $hasOptions, 'required_options' => $requiredOptions],
            ['entity_id = ?' => $productId]
        );
    }

    /**
     * Return the option_id of an existing catalog_product_option on $productId
     * whose default-store title matches (case-insensitive) — or null if none.
     * Used to avoid creating duplicate options when an old per-product option
     * shares a title with what we're about to sync.
     */
    private function findExistingOptionIdByTitle(int $productId, string $title): ?int
    {
        $conn = $this->resourceConnection->getConnection();
        $id = $conn->fetchOne(
            $conn->select()
                ->from(['o' => $this->resourceConnection->getTableName('catalog_product_option')], 'option_id')
                ->join(
                    ['t' => $this->resourceConnection->getTableName('catalog_product_option_title')],
                    'o.option_id = t.option_id AND t.store_id = 0',
                    []
                )
                ->where('o.product_id = ?', $productId)
                ->where('LOWER(t.title) = ?', mb_strtolower(trim($title)))
                ->limit(1)
        );
        return $id ? (int) $id : null;
    }

    /**
     * Build value Models for the option WITHOUT saving — caller saves the
     * option once with values attached. Matches existing values by lowercased
     * title to preserve their IDs across re-syncs.
     *
     * IMPORTANT: $productOption->getValues() is empty after a fresh load()
     * (Magento doesn't auto-load value rows). We query the DB directly to
     * find existing option_type_value rows for this option, then match by
     * title and call load() on each so addData() doesn't lose the ID.
     *
     * @return ProductOptionValue[]
     */
    private function buildValueObjects(int $templateOptionId, ProductOption $productOption): array
    {
        $templateValues = $this->templateValueCollectionFactory->create()
            ->addFieldToFilter('template_option_id', $templateOptionId)
            ->setOrder('sort_order', 'ASC');

        // Pull existing catalog_product_option_type_value rows directly.
        // Keyed by lowercased title for matching.
        $existingValues = [];
        $existingIdsByTitle = [];
        $optionId = (int) $productOption->getId();
        if ($optionId) {
            $conn = $this->resourceConnection->getConnection();
            $rows = $conn->fetchAll(
                $conn->select()
                    ->from(['v' => $this->resourceConnection->getTableName('catalog_product_option_type_value')], 'option_type_id')
                    ->join(
                        ['t' => $this->resourceConnection->getTableName('catalog_product_option_type_title')],
                        'v.option_type_id = t.option_type_id AND t.store_id = 0',
                        ['title']
                    )
                    ->where('v.option_id = ?', $optionId)
            );
            foreach ($rows as $r) {
                $existingIdsByTitle[mb_strtolower((string)$r['title'])] = (int)$r['option_type_id'];
            }
            // Load the value model entities for each match so we can update in place.
            foreach ($existingIdsByTitle as $key => $valueId) {
                $val = $this->productOptionValueFactory->create();
                $val->load($valueId);
                if ($val->getId()) {
                    $existingValues[$key] = $val;
                }
            }
        }

        $newValues = [];
        $matchedIds = [];
        foreach ($templateValues as $tVal) {
            $key = mb_strtolower((string)$tVal->getData('title'));
            $value = $existingValues[$key] ?? $this->productOptionValueFactory->create();
            // addData merges — preserves option_type_id loaded above.
            $value->addData([
                'option_id'  => $optionId ?: null,
                'title'      => (string)$tVal->getData('title'),
                'price'      => (float)$tVal->getData('price'),
                'price_type' => ((string)$tVal->getData('price_type') ?: 'fixed'),
                'sku'        => $tVal->getData('sku'),
                'sort_order' => (int)$tVal->getData('sort_order'),
            ]);
            if (isset($existingIdsByTitle[$key])) {
                $value->setId($existingIdsByTitle[$key]);
                $value->setOptionTypeId($existingIdsByTitle[$key]);
                $matchedIds[$existingIdsByTitle[$key]] = true;
            }
            $newValues[] = $value;
            unset($existingValues[$key]);
        }
        // Delete value rows that exist on the product but are no longer in the template.
        foreach ($existingValues as $orphan) {
            try { $orphan->delete(); } catch (\Throwable $e) { /* best effort */ }
        }
        return $newValues;
    }

    /**
     * Sync a template to every product in a category, INCLUDING descendant
     * categories. Uses catalog_category_entity.path matching to find the
     * full subtree, so picking a high-level category cascades to every
     * product underneath without admin having to drill in.
     *
     * Synchronous if total products ≤50; otherwise enqueued for the cron.
     */
    public function syncTemplateToCategory(int $templateId, int $categoryId): int
    {
        $conn = $this->resourceConnection->getConnection();
        $catTable = $this->resourceConnection->getTableName('catalog_category_entity');

        // Find the category's path, then look up all descendants whose path
        // starts with this category's path/.
        $row = $conn->fetchRow(
            $conn->select()->from($catTable, ['path'])->where('entity_id = ?', $categoryId)
        );
        if (!$row) { return 0; }
        $path = (string) $row['path'];

        $categoryIds = $conn->fetchCol(
            $conn->select()
                ->from($catTable, 'entity_id')
                ->where('entity_id = ?', $categoryId)
                ->orWhere('path LIKE ?', $path . '/%')
        );
        $categoryIds = array_map('intval', $categoryIds);

        $productIds = $conn->fetchCol(
            $conn->select()
                ->from(['ccp' => $this->resourceConnection->getTableName('catalog_category_product')], 'product_id')
                ->where('ccp.category_id IN (?)', $categoryIds)
                ->distinct()
        );
        if (!$productIds) { return 0; }

        $count = count($productIds);
        if ($count <= 50) {
            foreach ($productIds as $pid) {
                $this->syncTemplateToProduct($templateId, (int)$pid, 'category', $categoryId);
            }
        } else {
            $queueTable = $this->resourceConnection->getTableName('efopt_sync_queue');
            foreach ($productIds as $pid) {
                $conn->insert($queueTable, [
                    'template_id' => $templateId,
                    'product_id'  => (int)$pid,
                    'action'      => SyncQueueItem::ACTION_SYNC,
                    'status'      => SyncQueueItem::STATUS_PENDING,
                ]);
            }
        }
        return $count;
    }

    /**
     * Remove all options that were synced from this template onto this product.
     * Does NOT touch other options that may have been added manually.
     */
    public function desyncTemplateFromProduct(int $templateId, int $productId): void
    {
        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_product');

        $links = $conn->fetchAll(
            $conn->select()->from($linkTable)
                ->where('template_id = ?', $templateId)
                ->where('product_id = ?', $productId)
        );
        foreach ($links as $row) {
            $magentoOptionId = (int)$row['magento_option_id'];
            if ($magentoOptionId) {
                $opt = $this->productOptionFactory->create()->load($magentoOptionId);
                if ($opt->getId()) { $opt->delete(); }
            }
        }
        $conn->delete($linkTable, [
            'template_id = ?' => $templateId,
            'product_id = ?'  => $productId,
        ]);
    }

    /**
     * Push template changes to ALL linked products (direct + category-resolved).
     */
    public function resyncAll(int $templateId): int
    {
        $conn = $this->resourceConnection->getConnection();

        // Direct product links
        $directIds = $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_product'), 'product_id')
                ->where('template_id = ?', $templateId)
                ->distinct()
        );
        // Category-resolved
        $categoryIds = $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_category'), 'category_id')
                ->where('template_id = ?', $templateId)
        );
        $catProductIds = [];
        if ($categoryIds) {
            $catProductIds = $conn->fetchCol(
                $conn->select()
                    ->from($this->resourceConnection->getTableName('catalog_category_product'), 'product_id')
                    ->where('category_id IN (?)', $categoryIds)
                    ->distinct()
            );
        }
        $all = array_unique(array_map('intval', array_merge($directIds, $catProductIds)));
        foreach ($all as $pid) {
            $source = in_array($pid, array_map('intval', $directIds), true) ? 'direct' : 'category';
            $this->syncTemplateToProduct($templateId, $pid, $source);
        }
        return count($all);
    }
}
