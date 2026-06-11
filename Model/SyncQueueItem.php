<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Magento\Framework\Model\AbstractModel;

class SyncQueueItem extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public const ACTION_SYNC   = 'sync';
    public const ACTION_DESYNC = 'desync';

    protected $_eventPrefix = 'efopt_sync_queue';
    protected $_eventObject = 'item';

    protected function _construct(): void
    {
        $this->_init(\Etechflow\OptionsPlugin\Model\ResourceModel\SyncQueueItem::class);
    }
}
