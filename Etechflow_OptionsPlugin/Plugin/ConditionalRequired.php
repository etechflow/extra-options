<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Etechflow\OptionsPlugin\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Conditional validation + stripping for cuttable products.
 *
 * On add-to-cart:
 *   - "Cut from code"  → CODE field must be non-empty (validate); strip image option
 *   - "Cut from image" → image file must be uploaded (validate); strip code option
 *   - "No thanks, I'll cut it myself" (or any other choice) → strip BOTH satellite
 *     fields so they don't appear as empty rows on the cart/checkout/order display.
 *
 * Hooks prepareForCartAdvanced() so it runs before Magento's per-option native
 * validation. Detects the cutting pattern by matching option titles against
 * substring keywords (case-insensitive) — same keywords the frontend uses.
 */
class ConditionalRequired
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function beforePrepareForCartAdvanced(
        AbstractType $subject,
        DataObject $buyRequest,
        Product $product,
        $processMode = AbstractType::PROCESS_MODE_FULL
    ): array {
        if ($processMode !== AbstractType::PROCESS_MODE_FULL) {
            return [$buyRequest, $product, $processMode];
        }
        if (!$product->getId() || !$product->hasOptions()) {
            return [$buyRequest, $product, $processMode];
        }

        $options = $product->getOptions();
        if (!$options) {
            return [$buyRequest, $product, $processMode];
        }

        // Keyword lists come from admin config (Stores → Configuration → eTechFlow →
        // Extra Options Plugin → Frontend Radio-Card), with sane English fallbacks.
        // Sourcing them here keeps this validation in step with the storefront
        // grouping and lets non-English stores localise the matching.
        $primaryKw     = $this->config->getPrimaryKeywords()    ?: ['would you like', 'cut this', 'cut it'];
        $codeInputKw   = $this->config->getCodeInputKeywords()  ?: ['key code', 'enter code'];
        $imageInputKw  = $this->config->getImageInputKeywords() ?: ['upload image', 'image of'];
        $codeChoiceKw  = $this->config->getCodeKeywords()       ?: ['from the code', 'cut from code'];
        $imageChoiceKw = $this->config->getImageKeywords()      ?: ['from the image', 'cut from image'];

        // Find the cutting dropdown + the satellite code field + the satellite image file.
        $primary = null;
        $codeField = null;
        $imageFile = null;
        foreach ($options as $opt) {
            $title = strtolower((string)$opt->getTitle());
            $type  = (string)$opt->getType();
            if (!$primary && $type === 'drop_down' && $this->matchesAny($title, $primaryKw)) {
                $primary = $opt;
            } elseif (!$codeField
                && in_array($type, ['field', 'area'], true)
                && $this->matchesAny($title, $codeInputKw)
            ) {
                $codeField = $opt;
            } elseif (!$imageFile && $type === 'file' && $this->matchesAny($title, $imageInputKw)) {
                $imageFile = $opt;
            }
        }
        if (!$primary) {
            return [$buyRequest, $product, $processMode];
        }

        $submittedOptions = (array)$buyRequest->getOptions();
        $primaryValue = (string)($submittedOptions[$primary->getId()] ?? '');
        if ($primaryValue === '') {
            return [$buyRequest, $product, $processMode];
        }

        // Look up the chosen value's title.
        $chosenTitle = '';
        foreach ($primary->getValues() ?: [] as $val) {
            if ((string)$val->getOptionTypeId() === $primaryValue) {
                $chosenTitle = strtolower((string)$val->getTitle());
                break;
            }
        }
        if ($chosenTitle === '') {
            return [$buyRequest, $product, $processMode];
        }

        $isCodeChoice  = $this->matchesAny($chosenTitle, $codeChoiceKw);
        $isImageChoice = $this->matchesAny($chosenTitle, $imageChoiceKw);

        // ---- Validate the matching satellite ----
        if ($isCodeChoice && $codeField) {
            $codeValue = trim((string)($submittedOptions[$codeField->getId()] ?? ''));
            if ($codeValue === '') {
                throw new LocalizedException(__(
                    'Please enter the key code — you selected "%1".',
                    $chosenTitle
                ));
            }
        }

        if ($isImageChoice && $imageFile) {
            $fileKey = 'options_' . $imageFile->getId() . '_file';
            $hasFile = isset($_FILES[$fileKey]['tmp_name'])
                && $_FILES[$fileKey]['tmp_name'] !== ''
                && is_uploaded_file($_FILES[$fileKey]['tmp_name']);
            $hasReuse = !empty($buyRequest->getData("options_{$imageFile->getId()}_file_action"))
                && $buyRequest->getData("options_{$imageFile->getId()}_file_action") === 'save_old';
            if (!$hasFile && !$hasReuse) {
                throw new LocalizedException(__(
                    'Please upload an image — you selected "%1".',
                    $chosenTitle
                ));
            }
        }

        // ---- Strip non-matching satellite values so the cart/checkout/order
        //      don't show an empty CODE row when "no thanks" (or "from image")
        //      was chosen — and vice versa. ----
        $dirty = false;
        if ($codeField && !$isCodeChoice) {
            $cid = (int)$codeField->getId();
            if (array_key_exists($cid, $submittedOptions)) {
                unset($submittedOptions[$cid]);
                $dirty = true;
            }
        }
        if ($imageFile && !$isImageChoice) {
            $iid = (int)$imageFile->getId();
            if (array_key_exists($iid, $submittedOptions)) {
                unset($submittedOptions[$iid]);
                $dirty = true;
            }
            // Drop file-upload housekeeping keys so the file isn't re-used.
            $fileAction = "options_{$iid}_file_action";
            if ($buyRequest->hasData($fileAction)) {
                $buyRequest->unsetData($fileAction);
            }
        }
        if ($dirty) {
            $buyRequest->setOptions($submittedOptions);
        }

        return [$buyRequest, $product, $processMode];
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) {
                return true;
            }
        }
        return false;
    }
}
