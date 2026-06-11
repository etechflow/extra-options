<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'option_id';

    protected function _construct(): void
    {
        $this->_init(
            \Etechflow\OptionsPlugin\Model\Template\Option::class,
            \Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option::class
        );
    }
}
