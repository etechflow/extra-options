<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Template;

use Magento\Framework\Model\AbstractModel;

/**
 * Option within a template. Mirrors Magento's product custom-option entity:
 * title, type, is_required, price, sku, plus type-specific fields like
 * max_characters (text/area) and file_extension (file).
 */
class Option extends AbstractModel
{
    protected $_eventPrefix = 'efopt_template_option';
    protected $_eventObject = 'option';

    protected function _construct(): void
    {
        $this->_init(\Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option::class);
    }
}
