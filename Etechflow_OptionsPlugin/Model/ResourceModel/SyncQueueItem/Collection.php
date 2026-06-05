<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\SyncQueueItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'queue_id';

    protected function _construct(): void
    {
        $this->_init(
            \Etechflow\OptionsPlugin\Model\SyncQueueItem::class,
            \Etechflow\OptionsPlugin\Model\ResourceModel\SyncQueueItem::class
        );
    }
}
