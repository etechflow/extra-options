<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TextFieldType implements OptionSourceInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_TEXTAREA = 'textarea';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::TYPE_TEXT,     'label' => __('Single-line text input')],
            ['value' => self::TYPE_TEXTAREA, 'label' => __('Multi-line textarea')],
        ];
    }
}
