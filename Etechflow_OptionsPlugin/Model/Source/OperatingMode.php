<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Source;

use Etechflow\OptionsPlugin\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class OperatingMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::MODE_STANDARD,   'label' => __('Standard (backend only — safe in any theme)')],
            ['value' => Config::MODE_SMART_CARD, 'label' => __('Smart Radio-Card (grouped UI on product page)')],
        ];
    }
}
