<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DefaultChoice implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '',      'label' => __('None (no preselection)')],
            ['value' => 'none',  'label' => __("No thanks I'll cut it myself")],
            ['value' => 'code',  'label' => __('Cut from code')],
            ['value' => 'image', 'label' => __('Cut from image')],
        ];
    }
}
