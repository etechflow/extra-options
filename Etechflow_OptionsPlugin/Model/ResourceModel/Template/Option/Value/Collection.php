<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'value_id';

    protected function _construct(): void
    {
        $this->_init(
            \Etechflow\OptionsPlugin\Model\Template\Option\Value::class,
            \Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value::class
        );
    }
}
