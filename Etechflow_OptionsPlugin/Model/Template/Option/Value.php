<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Template\Option;

use Magento\Framework\Model\AbstractModel;

/**
 * A single value choice inside a selectable option (drop_down / radio /
 * checkbox / multiple). Holds title, price, price_type, sku.
 */
class Value extends AbstractModel
{
    protected $_eventPrefix = 'efopt_template_option_value';
    protected $_eventObject = 'value';

    protected function _construct(): void
    {
        $this->_init(\Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value::class);
    }
}
