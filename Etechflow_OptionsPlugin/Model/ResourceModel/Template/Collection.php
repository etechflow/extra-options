<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\Template;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'template_id';

    protected function _construct(): void
    {
        $this->_init(
            \Etechflow\OptionsPlugin\Model\Template::class,
            \Etechflow\OptionsPlugin\Model\ResourceModel\Template::class
        );
    }
}
