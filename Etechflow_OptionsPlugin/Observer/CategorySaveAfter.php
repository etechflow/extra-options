<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Observer;

use Etechflow\OptionsPlugin\Model\SyncService;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CategorySaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SyncService $syncService,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Category $category */
        $category = $observer->getEvent()->getCategory();
        if (!$category || !$category->getId()) { return; }

        $rawIds = $category->getData('efopt_template_ids');
        if ($rawIds === null) { return; }

        $desiredIds = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : explode(',', (string)$rawIds)));
        $categoryId = (int) $category->getId();

        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_category');

        $currentIds = array_map('intval', $conn->fetchCol(
            $conn->select()->from($linkTable, 'template_id')->where('category_id = ?', $categoryId)
        ));

        $toAdd    = array_diff($desiredIds, $currentIds);
        $toRemove = array_diff($currentIds, $desiredIds);

        try {
            // Persist link rows first.
            foreach ($toAdd as $tid) {
                $conn->insert($linkTable, ['template_id' => $tid, 'category_id' => $categoryId]);
            }
            if ($toRemove) {
                $conn->delete($linkTable, [
                    'category_id = ?'  => $categoryId,
                    'template_id IN (?)' => $toRemove,
                ]);
            }

            // Then sync. For removals, desync from every product in the category
            // (but only the ones linked via THIS category — direct links survive).
            $productLinkTable = $this->resourceConnection->getTableName('efopt_template_product');
            foreach ($toRemove as $tid) {
                $productIds = $conn->fetchCol(
                    $conn->select()->from($productLinkTable, 'product_id')
                        ->where('template_id = ?', $tid)
                        ->where('source_category_id = ?', $categoryId)
                );
                foreach ($productIds as $pid) {
                    $this->syncService->desyncTemplateFromProduct((int)$tid, (int)$pid);
                }
            }
            foreach ($toAdd as $tid) {
                $this->syncService->syncTemplateToCategory((int)$tid, $categoryId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[efopt category-save-after] ' . $e->getMessage(), ['category_id' => $categoryId]);
        }
    }
}
