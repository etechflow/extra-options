<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Cron;

use Etechflow\OptionsPlugin\Model\SyncQueueItem;
use Etechflow\OptionsPlugin\Model\SyncService;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Drains the efopt_sync_queue table in batches. Configured by cron job
 * `efopt_sync_queue` (see etc/crontab.xml) — runs every minute, processes
 * up to BATCH_SIZE items per tick to keep individual jobs fast.
 */
class ProcessSyncQueue
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SyncService $syncService,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('efopt_sync_queue');

        $items = $conn->fetchAll(
            $conn->select()
                ->from($table)
                ->where('status = ?', SyncQueueItem::STATUS_PENDING)
                ->order('queue_id ASC')
                ->limit(self::BATCH_SIZE)
        );

        if (!$items) { return; }

        foreach ($items as $row) {
            $queueId = (int)$row['queue_id'];
            // Mark running so a concurrent tick won't pick the same row.
            $conn->update($table, ['status' => SyncQueueItem::STATUS_RUNNING], ['queue_id = ?' => $queueId]);

            try {
                $tid = (int)$row['template_id'];
                $pid = (int)$row['product_id'];
                if ($row['action'] === SyncQueueItem::ACTION_DESYNC) {
                    $this->syncService->desyncTemplateFromProduct($tid, $pid);
                } else {
                    $this->syncService->syncTemplateToProduct($tid, $pid, 'category');
                }
                $conn->update($table, ['status' => SyncQueueItem::STATUS_DONE], ['queue_id = ?' => $queueId]);
            } catch (\Throwable $e) {
                $this->logger->error('[efopt sync queue] ' . $e->getMessage(), [
                    'queue_id' => $queueId,
                    'template_id' => $row['template_id'],
                    'product_id' => $row['product_id'],
                ]);
                $conn->update($table, [
                    'status'        => SyncQueueItem::STATUS_FAILED,
                    'error_message' => substr($e->getMessage(), 0, 1000),
                ], ['queue_id = ?' => $queueId]);
            }
        }

        // Housekeeping: trim old DONE rows to keep the table small.
        $conn->delete($table, [
            'status = ?' => SyncQueueItem::STATUS_DONE,
            'updated_at < ?' => date('Y-m-d H:i:s', strtotime('-7 days')),
        ]);
    }
}
