<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Value extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('efopt_template_option_value', 'value_id');
    }
}
