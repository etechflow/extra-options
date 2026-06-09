<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;

/**
 * Bulk price update. Given a template + a target scope (categories + products)
 * + new prices for option values and/or options, this service:
 *   1. Updates the template's own value/option prices (single source of truth)
 *   2. Walks every product in the chosen scope that's linked to this template
 *   3. Updates the corresponding catalog_product_option_type_value (or
 *      catalog_product_option for text-type options) rows directly via SQL
 *      for speed — bypassing Magento's entity layer is safe here because
 *      we only touch a single column and we own the mapping.
 *
 * Returns the number of catalog rows actually updated.
 */
class BulkPriceService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly EventManager $eventManager
    ) {}

    /**
     * @param int[] $categoryIds
     * @param int[] $productIds
     * @param array<int,string|float> $valuePrices  template value_id → new price
     * @param array<int,string|float> $optionPrices template option_id → new price (for text/file types)
     */
    public function apply(
        int $templateId,
        array $categoryIds,
        array $productIds,
        array $valuePrices,
        array $optionPrices
    ): int {
        $conn = $this->resourceConnection->getConnection();
        $conn->beginTransaction();

        try {
            // 1) Update template-level prices first.
            $tplValueTable = $this->resourceConnection->getTableName('efopt_template_option_value');
            foreach ($valuePrices as $valueId => $price) {
                if ((int)$valueId <= 0 || !is_numeric($price)) { continue; }
                $conn->update(
                    $tplValueTable,
                    ['price' => (float)$price],
                    ['value_id = ?' => (int)$valueId]
                );
            }
            $tplOptionTable = $this->resourceConnection->getTableName('efopt_template_option');
            foreach ($optionPrices as $optionId => $price) {
                if ((int)$optionId <= 0 || !is_numeric($price)) { continue; }
                $conn->update(
                    $tplOptionTable,
                    ['price' => $price === '' ? null : (float)$price],
                    ['option_id = ?' => (int)$optionId]
                );
            }

            // 2) Resolve the target product list.
            $targetProductIds = $productIds;
            if ($categoryIds) {
                $ids = $conn->fetchCol(
                    $conn->select()
                        ->from($this->resourceConnection->getTableName('catalog_category_product'), 'product_id')
                        ->where('category_id IN (?)', $categoryIds)
                        ->distinct()
                );
                $targetProductIds = array_unique(array_merge($targetProductIds, array_map('intval', $ids)));
            }
            if (!$targetProductIds) {
                $conn->commit();
                return 0;
            }

            // 3) Update the catalog_product_option / catalog_product_option_type_value
            //    rows that were synced from THIS template, restricted to these products.
            $linkTable      = $this->resourceConnection->getTableName('efopt_template_product');
            $cpOptionTable  = $this->resourceConnection->getTableName('catalog_product_option_price');
            $cpoTypeValTable= $this->resourceConnection->getTableName('catalog_product_option_type_price');

            $rowsAffected = 0;

            // Build the join: which Magento product options came from each template option?
            $links = $conn->fetchAll(
                $conn->select()->from($linkTable, ['template_option_id', 'magento_option_id', 'product_id'])
                    ->where('template_id = ?', $templateId)
                    ->where('product_id IN (?)', $targetProductIds)
                    ->where('magento_option_id IS NOT NULL')
            );

            // Group magento_option_ids by template_option_id; track affected products.
            $magOptIdsByTplOpt = [];
            $magOptIds = [];
            $affectedProductIds = [];
            foreach ($links as $row) {
                $magOptIdsByTplOpt[(int)$row['template_option_id']][] = (int)$row['magento_option_id'];
                $magOptIds[] = (int)$row['magento_option_id'];
                $affectedProductIds[(int)$row['product_id']] = true;
            }

            // Update option-level prices (text/file/area types). Upsert so a price
            // row is CREATED when the option had none before (a plain UPDATE would
            // match 0 rows and silently leave the product price unchanged).
            foreach ($optionPrices as $tplOptionId => $newPrice) {
                $tid = (int)$tplOptionId;
                if (!isset($magOptIdsByTplOpt[$tid]) || !is_numeric($newPrice)) { continue; }
                $upsert = [];
                foreach ($magOptIdsByTplOpt[$tid] as $oid) {
                    $upsert[] = ['option_id' => (int)$oid, 'store_id' => 0, 'price' => (float)$newPrice, 'price_type' => 'fixed'];
                }
                $rowsAffected += (int)$conn->insertOnDuplicate($cpOptionTable, $upsert, ['price']);
            }

            // Update value-level prices (select types) — match by title since
            // catalog_product_option_type_value doesn't store our value_id.
            if ($valuePrices && $magOptIds) {
                $tplValueTable = $this->resourceConnection->getTableName('efopt_template_option_value');
                $valuesById = $conn->fetchAssoc(
                    $conn->select()->from($tplValueTable, ['value_id', 'template_option_id', 'title'])
                        ->where('value_id IN (?)', array_keys($valuePrices))
                );
                foreach ($valuePrices as $valueId => $price) {
                    if (!is_numeric($price) || !isset($valuesById[$valueId])) { continue; }
                    $tplOptId = (int)$valuesById[$valueId]['template_option_id'];
                    if (!isset($magOptIdsByTplOpt[$tplOptId])) { continue; }
                    $title = (string)$valuesById[$valueId]['title'];

                    // Find the catalog_product_option_type_value rows whose option
                    // belongs to our magento_option_ids AND whose title matches.
                    $cpovTable = $this->resourceConnection->getTableName('catalog_product_option_type_value');
                    $cpovTitleTable = $this->resourceConnection->getTableName('catalog_product_option_type_title');
                    $valueIds = $conn->fetchCol(
                        $conn->select()
                            ->from(['v' => $cpovTable], 'v.option_type_id')
                            ->join(['t' => $cpovTitleTable], 'v.option_type_id = t.option_type_id', [])
                            ->where('v.option_id IN (?)', $magOptIdsByTplOpt[$tplOptId])
                            ->where('t.title = ?', $title)
                    );
                    if (!$valueIds) { continue; }
                    $upsert = [];
                    foreach ($valueIds as $otid) {
                        $upsert[] = ['option_type_id' => (int)$otid, 'store_id' => 0, 'price' => (float)$price, 'price_type' => 'fixed'];
                    }
                    // Upsert (create-or-update) so values that were £0 — and so had
                    // no price row — still receive the new price on the product.
                    $rowsAffected += (int)$conn->insertOnDuplicate($cpoTypeValTable, $upsert, ['price']);
                }
            }

            $conn->commit();

            // Bust the per-product cache so the storefront/full-page cache reflects
            // the new prices without a manual flush (raw SQL bypasses the save flow
            // that would normally invalidate it).
            $this->bustCache(array_keys($affectedProductIds));

            return $rowsAffected;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /** @param int[] $productIds */
    private function bustCache(array $productIds): void
    {
        foreach ($productIds as $pid) {
            try {
                $this->eventManager->dispatch('clean_cache_by_tags', [
                    'object' => new DataObject(['identities' => [Product::CACHE_TAG . '_' . (int)$pid]]),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[efopt bulk price] cache clean failed for product ' . $pid . ': ' . $e->getMessage());
            }
        }
    }
}
