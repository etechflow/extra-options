<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Observer;

use Etechflow\OptionsPlugin\Model\SyncService;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * After a product is saved (catalog_product_save_after), reconcile its direct
 * template links from the posted efopt_template_ids field and call SyncService.
 */
class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SyncService $syncService,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) { return; }

        $rawIds = $product->getData('efopt_template_ids');
        if ($rawIds === null) { return; } // field wasn't submitted — skip

        $desiredIds = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : explode(',', (string)$rawIds)));
        $productId  = (int) $product->getId();

        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_product');

        $currentIds = array_map('intval', $conn->fetchCol(
            $conn->select()
                ->from($linkTable, 'template_id')
                ->where('product_id = ?', $productId)
                ->where('source = ?', 'direct')
                ->distinct()
        ));

        $toAdd    = array_diff($desiredIds, $currentIds);
        $toRemove = array_diff($currentIds, $desiredIds);

        try {
            foreach ($toAdd as $tid) {
                $this->syncService->syncTemplateToProduct((int)$tid, $productId, 'direct');
            }
            foreach ($toRemove as $tid) {
                $this->syncService->desyncTemplateFromProduct((int)$tid, $productId);
            }
            // Re-sync all currently linked templates (cheap, idempotent) so any
            // template-side option changes propagate.
            foreach ($desiredIds as $tid) {
                if (!in_array($tid, $toAdd, true)) {
                    $this->syncService->syncTemplateToProduct((int)$tid, $productId, 'direct');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[efopt product-save-after] ' . $e->getMessage(), ['product_id' => $productId]);
        }
    }
}
