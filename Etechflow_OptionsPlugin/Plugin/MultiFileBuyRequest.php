<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\DataObject;

/**
 * Multi-file image upload — buy-request bridge.
 *
 * The PDP renders a vanilla file picker plus a hidden JSON field named
 * `options_<id>_etmm_multi` for any custom option of type `file`. The JSON
 * contains the array of files the customer uploaded asynchronously to
 * /etechflow/files/upload (one POST per file → returned `path` collected here).
 *
 * On add-to-cart, this plugin:
 *   1. Reads the JSON hidden field out of the buy-request.
 *   2. Encodes the array into a marker string that the option's normal `value`
 *      slot can carry: `__ETMM_MULTI__:[ {name, path, size}, … ]`.
 *   3. Drops Magento's native `options_<id>_file` slot for the same option so
 *      Magento's File-type validator does not also try to handle a single file.
 *
 * The marker is later detected by {@see MultiFileDisplay} for cart / order /
 * admin order rendering.
 */
class MultiFileBuyRequest
{
    public const MARKER = '__ETMM_MULTI__:';

    public function beforePrepareForCartAdvanced(
        AbstractType $subject,
        DataObject $buyRequest,
        Product $product,
        $processMode = AbstractType::PROCESS_MODE_FULL
    ): array {
        if (!$product->getId() || !$product->hasOptions()) {
            return [$buyRequest, $product, $processMode];
        }

        $options = $product->getOptions();
        if (!$options) {
            return [$buyRequest, $product, $processMode];
        }

        $submitted = (array) $buyRequest->getOptions();
        $dirty = false;

        foreach ($options as $opt) {
            if ((string) $opt->getType() !== 'file') {
                continue;
            }

            $optId = (int) $opt->getId();
            $multiKey = 'options_' . $optId . '_etmm_multi';
            $payload  = trim((string) $buyRequest->getData($multiKey));

            // ---- Fallback paths for cart-update / reorder ----
            // Magento's Quote::updateItem rebuilds the buyRequest and STRIPS our
            // custom `options_<id>_etmm_multi` hidden field — it only forwards
            // the standard `options[]` array. To survive that path we look in
            // two more places:
            //   1. _processing_params.currentConfig — the OLD buyRequest, which
            //      Magento populates on updateItem. It still has the original
            //      etmm_multi JSON from the first add-to-cart.
            //   2. submitted options[<id>] — if it already starts with our
            //      MARKER prefix (e.g. a reorder or admin order edit), reuse it.
            if ($payload === '') {
                $processingParams = $buyRequest->getData('_processing_params');
                if ($processingParams instanceof DataObject) {
                    $currentConfig = $processingParams->getCurrentConfig();
                    if ($currentConfig instanceof DataObject) {
                        $payload = trim((string) $currentConfig->getData($multiKey));
                    }
                }
            }
            if ($payload === '') {
                $existing = (string) ($submitted[$optId] ?? '');
                if (str_starts_with($existing, self::MARKER)) {
                    // Strip the MARKER prefix so the JSON-decode path below works.
                    $payload = substr($existing, strlen(self::MARKER));
                }
            }

            if ($payload === '') {
                continue;
            }

            // The JSON came from our own AJAX uploads — sanity-check the shape.
            $files = json_decode($payload, true);
            if (!is_array($files)) {
                continue;
            }
            $clean = [];
            foreach ($files as $f) {
                if (!is_array($f) || empty($f['path']) || empty($f['name'])) {
                    continue;
                }
                $clean[] = [
                    'name' => (string) $f['name'],
                    'path' => (string) $f['path'],
                    'size' => isset($f['size']) ? (int) $f['size'] : 0,
                    'mime' => isset($f['mime']) ? (string) $f['mime'] : 'application/octet-stream',
                ];
            }
            if (!$clean) {
                continue;
            }

            $marker = self::MARKER . json_encode($clean, JSON_UNESCAPED_SLASHES);
            $submitted[$optId] = $marker;
            $dirty = true;

            // ---- CRITICAL: change the option's TYPE in-memory --------------
            // Magento's native File-type validator (`File::validateUserValue`)
            // looks at $_FILES + `options_<id>_file_action` to decide if a
            // file was supplied. With our AJAX-upload flow there is no entry
            // in either, so the File validator drops the option entirely and
            // the marker never makes it onto the cart item.
            //
            // By switching the type to `area` (multi-line text) JUST FOR THIS
            // REQUEST, Magento uses the Text type's validator instead, which
            // simply accepts any non-empty string. The DB still has type=`file`,
            // so when the cart/order is later rendered, Magento dispatches to
            // File::getFormattedOptionValue → our plugin intercepts → list of
            // links. Round-trip stable.
            $opt->setType('area');
            $opt->setMaxCharacters(0);   // 0 disables Magento's length check

            // Remove the native single-file slot so nothing else tries to
            // process an upload that isn't there.
            foreach ([
                'options_' . $optId . '_file_action',
                'options_' . $optId . '_file',
            ] as $native) {
                if ($buyRequest->hasData($native)) {
                    $buyRequest->unsetData($native);
                }
            }
        }

        if ($dirty) {
            // Persist the patched options array onto the buyRequest so the
            // subsequent type-class validators see the marker value.
            $buyRequest->setOptions($submitted);
        }

        return [$buyRequest, $product, $processMode];
    }
}
