<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Magento\Catalog\Model\Product\Option\Type\DefaultType;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;

/**
 * Renders the multi-file marker produced by {@see MultiFileBuyRequest}.
 *
 * Hooks the option value formatter so the cart, mini-cart, order summary,
 * invoice, and admin order view all surface the uploaded files as a clean
 * list of links pointing back at pub/media/custom_options/etechflow/<…>.
 *
 * Falls through (no change) on values that don't carry the marker prefix.
 */
class MultiFileDisplay
{
    public function __construct(
        private readonly Escaper $escaper,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * After Magento computes the formatted display string, replace it for
     * multi-file values with a rendered list of file links.
     *
     * @return string
     */
    public function afterGetFormattedOptionValue(
        DefaultType $subject,
        $formatted,
        $optionValue = null
    ) {
        // $optionValue arg is filled when called as 2-arg form; otherwise read
        // the value off the option itself (legacy entry points).
        $raw = $optionValue ?? $subject->getUserValue();
        if (!is_string($raw)) {
            return $formatted;
        }
        $files = $this->parseMarker($raw);
        if ($files === null) {
            return $formatted;
        }
        return $this->renderList($files);
    }

    /**
     * Used by storefront cart drawer + checkout summary on Magento's
     * "print value" path (plain text — no HTML allowed). Render a comma-
     * separated list of filenames as a fallback.
     *
     * @return string
     */
    public function afterGetPrintValue(
        DefaultType $subject,
        $printValue,
        $optionValue = null
    ) {
        $raw = $optionValue ?? $subject->getUserValue();
        if (!is_string($raw)) {
            return $printValue;
        }
        $files = $this->parseMarker($raw);
        if ($files === null) {
            return $printValue;
        }
        return implode(', ', array_map(static fn(array $f): string => (string) $f['name'], $files));
    }

    /**
     * Admin "Customized view" + customer-account order detail use this entry
     * point instead of getFormattedOptionValue. Same logic — return the list.
     *
     * @return string|array
     */
    public function afterGetCustomizedView(
        DefaultType $subject,
        $view,
        $optionInfo = null
    ) {
        $candidate = is_array($optionInfo) ? ($optionInfo['value'] ?? null) : $optionInfo;
        if (!is_string($candidate)) {
            // Some callers pass the option object itself; fall back to user value.
            $candidate = is_string($subject->getUserValue()) ? $subject->getUserValue() : null;
        }
        if (!is_string($candidate)) {
            return $view;
        }
        $files = $this->parseMarker($candidate);
        if ($files === null) {
            return $view;
        }
        return $this->renderList($files);
    }

    /**
     * Admin "Edit order" populates inputs via this method. Return a plain
     * comma-separated list of names — it goes into an input field, not HTML.
     *
     * @return string
     */
    public function afterGetEditableOptionValue(
        DefaultType $subject,
        $editable,
        $optionValue = null
    ) {
        $raw = $optionValue ?? $subject->getUserValue();
        if (!is_string($raw)) {
            return $editable;
        }
        $files = $this->parseMarker($raw);
        if ($files === null) {
            return $editable;
        }
        return implode(', ', array_map(static fn(array $f): string => (string) $f['name'], $files));
    }

    /**
     * @param string $raw
     * @return array<int,array{name:string,path:string,size:int,mime:string}>|null
     */
    private function parseMarker(string $raw): ?array
    {
        if (!str_starts_with($raw, MultiFileBuyRequest::MARKER)) {
            return null;
        }
        $json = substr($raw, strlen(MultiFileBuyRequest::MARKER));
        $files = json_decode($json, true);
        if (!is_array($files)) {
            return null;
        }
        return $files;
    }

    /** @param array<int,array{name:string,path:string,size:int,mime:string}> $files */
    private function renderList(array $files): string
    {
        $mediaBase = rtrim($this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]), '/');
        $items = [];
        foreach ($files as $f) {
            $name = $this->escaper->escapeHtml((string) $f['name']);
            $url  = $this->escaper->escapeUrl($mediaBase . '/' . ltrim((string) $f['path'], '/'));
            $items[] = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>';
        }
        return '<span class="etmm-files">' . implode(', ', $items) . '</span>';
    }
}
