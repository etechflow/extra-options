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

        // Atomically claim a batch: SELECT ... FOR UPDATE the pending rows and flip
        // them to RUNNING inside one transaction, so a concurrent cron tick blocks
        // and then sees them as RUNNING — never double-processing the same rows.
        $conn->beginTransaction();
        try {
            $items = $conn->fetchAll(
                $conn->select()
                    ->from($table)
                    ->where('status = ?', SyncQueueItem::STATUS_PENDING)
                    ->order('queue_id ASC')
                    ->limit(self::BATCH_SIZE)
                    ->forUpdate(true)
            );
            if (!$items) {
                $conn->commit();
                return;
            }
            $queueIds = array_map(static fn($r) => (int)$r['queue_id'], $items);
            $conn->update($table, ['status' => SyncQueueItem::STATUS_RUNNING], ['queue_id IN (?)' => $queueIds]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('[efopt sync queue] batch claim failed: ' . $e->getMessage());
            return;
        }

        // Process the claimed batch outside the lock.
        foreach ($items as $row) {
            $queueId = (int)$row['queue_id'];
            try {
                $tid    = (int)$row['template_id'];
                $pid    = (int)$row['product_id'];
                // Preserve the link source recorded at enqueue time (direct | category).
                $source = isset($row['source']) && $row['source'] !== '' ? (string)$row['source'] : 'category';
                if ($row['action'] === SyncQueueItem::ACTION_DESYNC) {
                    $this->syncService->desyncTemplateFromProduct($tid, $pid);
                } else {
                    $this->syncService->syncTemplateToProduct($tid, $pid, $source);
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
