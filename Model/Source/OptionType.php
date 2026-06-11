<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Mirrors Magento's native catalog_product_option type whitelist.
 */
class OptionType implements OptionSourceInterface
{
    /** @return array<int, array{value:string,label:\Magento\Framework\Phrase}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'drop_down', 'label' => __('Drop-down')],
            ['value' => 'radio',     'label' => __('Radio Buttons')],
            ['value' => 'checkbox',  'label' => __('Checkbox')],
            ['value' => 'multiple',  'label' => __('Multiple Select')],
            ['value' => 'field',     'label' => __('Text Field')],
            ['value' => 'area',      'label' => __('Text Area')],
            ['value' => 'file',      'label' => __('File Upload')],
            ['value' => 'date',      'label' => __('Date')],
            ['value' => 'date_time', 'label' => __('Date & Time')],
            ['value' => 'time',      'label' => __('Time')],
        ];
    }
}
