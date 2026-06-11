<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\ResourceModel\Template;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Option extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('efopt_template_option', 'option_id');
    }
}
