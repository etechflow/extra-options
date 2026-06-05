<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Magento\Catalog\Model\Product\Option;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\Option as SelectedOption;

/**
 * Hyvä's cart drawer renders file-type custom options through
 *   Hyva\Theme\Model\CartItem\DataProvider\CustomizableOptionValue\File::getData()
 *
 * Inside that method it does:
 *   $serializer->unserialize($selectedOption->getValue())['title']
 *
 * That call will THROW (or warn) on our `__ETMM_MULTI__:[…]` marker because
 * the marker isn't a valid PHP-serialize string. This `around` plugin
 * short-circuits the entire method when the marker is detected and returns
 * a clean data shape: `label` = "N files", `value` = list-of-links HTML.
 *
 * Falls through (calls $proceed) for any value that doesn't carry the marker,
 * so single-file uploads keep working unchanged.
 */
class HyvaMultiFileCartData
{
    public function __construct(
        private readonly Escaper $escaper,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @param object $subject The Hyva File data-provider — typed loosely so this
     *                        plugin still loads when Hyva isn't installed (then
     *                        Magento just never invokes it).
     * @param callable $proceed
     */
    public function aroundGetData(
        $subject,
        callable $proceed,
        QuoteItem $cartItem,
        Option $option,
        SelectedOption $selectedOption
    ): array {
        $rawValue = (string) $selectedOption->getValue();
        if (!str_starts_with($rawValue, MultiFileBuyRequest::MARKER)) {
            return $proceed($cartItem, $option, $selectedOption);
        }

        $json  = substr($rawValue, strlen(MultiFileBuyRequest::MARKER));
        $files = json_decode($json, true);
        if (!is_array($files)) {
            // Defensive — fall through so the legacy code path can at least try.
            return $proceed($cartItem, $option, $selectedOption);
        }

        $mediaBase = rtrim($this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]), '/');
        $names = [];
        $links = [];
        foreach ($files as $f) {
            if (!is_array($f) || empty($f['path']) || empty($f['name'])) {
                continue;
            }
            $name = $this->escaper->escapeHtml((string) $f['name']);
            $url  = $this->escaper->escapeUrl($mediaBase . '/' . ltrim((string) $f['path'], '/'));
            $names[] = (string) $f['name'];
            $links[] = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>';
        }
        $label = count($files) === 1
            ? ($names[0] ?? __('Uploaded file'))
            : __('%1 files uploaded', count($files));

        return [[
            'id'       => $selectedOption->getId(),
            'label'    => (string) $label,
            'value'    => '<span class="etmm-files">' . implode(', ', $links) . '</span>',
            'has_file' => true,
            'price'    => [
                'type'  => strtoupper((string) $option->getPriceType()),
                'units' => '',
                'value' => (float) $option->getPrice(),
            ],
        ]];
    }
}
