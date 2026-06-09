<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const MODE_STANDARD    = 'standard';
    public const MODE_SMART_CARD  = 'smart_card';

    public const XML_ENABLED              = 'etechflow_options/general/enabled';
    public const XML_MODE                 = 'etechflow_options/general/mode';
    public const XML_FILE_SOFT_REQUIRED   = 'etechflow_options/file_options/soft_required';
    public const XML_FILE_LOG_RELAXED     = 'etechflow_options/file_options/log_relaxed';
    public const XML_DEFAULT_CHOICE       = 'etechflow_options/smart_card/default_choice';
    public const XML_SHOW_ALL_INPUTS      = 'etechflow_options/smart_card/show_all_inputs';
    public const XML_PRIMARY_KEYWORDS     = 'etechflow_options/smart_card/primary_keywords';
    public const XML_NONE_KEYWORDS        = 'etechflow_options/smart_card/none_keywords';
    public const XML_CODE_KEYWORDS        = 'etechflow_options/smart_card/code_keywords';
    public const XML_IMAGE_KEYWORDS       = 'etechflow_options/smart_card/image_keywords';
    public const XML_CODE_INPUT_KEYWORDS  = 'etechflow_options/smart_card/code_input_keywords';
    public const XML_IMAGE_INPUT_KEYWORDS = 'etechflow_options/smart_card/image_input_keywords';
    public const XML_EXTRA_INPUT_KEYWORDS = 'etechflow_options/smart_card/extra_input_keywords';

    public const XML_F_PRIMARY_TITLE       = 'etechflow_options/fields/primary_title_override';
    public const XML_F_PRIMARY_HELP        = 'etechflow_options/fields/primary_help_text';
    public const XML_F_NONE_LABEL          = 'etechflow_options/fields/none_label_override';
    public const XML_F_CODE_CHOICE_LABEL   = 'etechflow_options/fields/code_choice_label_override';
    public const XML_F_IMAGE_CHOICE_LABEL  = 'etechflow_options/fields/image_choice_label_override';

    public const XML_F_CODE_TITLE          = 'etechflow_options/fields/code_input_title_override';
    public const XML_F_CODE_TYPE           = 'etechflow_options/fields/code_input_type';
    public const XML_F_CODE_PLACEHOLDER    = 'etechflow_options/fields/code_input_placeholder';
    public const XML_F_CODE_HELP           = 'etechflow_options/fields/code_input_help';
    public const XML_F_CODE_MAX_LENGTH     = 'etechflow_options/fields/code_input_max_length';

    public const XML_F_IMAGE_TITLE         = 'etechflow_options/fields/image_input_title_override';
    public const XML_F_IMAGE_PLACEHOLDER   = 'etechflow_options/fields/image_input_placeholder';
    public const XML_F_IMAGE_HELP          = 'etechflow_options/fields/image_input_help';
    public const XML_F_IMAGE_ACCEPT        = 'etechflow_options/fields/image_input_accept';

    public const XML_F_EXTRA_TITLE         = 'etechflow_options/fields/extra_input_title_override';
    public const XML_F_EXTRA_TYPE          = 'etechflow_options/fields/extra_input_type';
    public const XML_F_EXTRA_PLACEHOLDER   = 'etechflow_options/fields/extra_input_placeholder';
    public const XML_F_EXTRA_HELP          = 'etechflow_options/fields/extra_input_help';
    public const XML_F_EXTRA_MAX_LENGTH    = 'etechflow_options/fields/extra_input_max_length';

    /* ===== Appearance / theme adoption ===== */
    public const XML_ADOPT_THEME_COLORS = 'etechflow_options/appearance/adopt_theme_colors';
    public const XML_CARD_LAYOUT        = 'etechflow_options/appearance/card_layout';
    public const XML_ACCENT_COLOR       = 'etechflow_options/appearance/accent_color';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getMode(): string
    {
        $mode = (string)$this->scopeConfig->getValue(self::XML_MODE, ScopeInterface::SCOPE_STORE);
        return $mode ?: self::MODE_STANDARD;
    }

    public function isSmartCardMode(): bool
    {
        return $this->isEnabled() && $this->getMode() === self::MODE_SMART_CARD;
    }

    public function isFileSoftRequired(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_FILE_SOFT_REQUIRED, ScopeInterface::SCOPE_STORE);
    }

    public function shouldLogRelaxed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_FILE_LOG_RELAXED, ScopeInterface::SCOPE_STORE);
    }

    public function getDefaultChoice(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_DEFAULT_CHOICE, ScopeInterface::SCOPE_STORE);
    }

    public function showAllInputs(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_SHOW_ALL_INPUTS, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] Lowercased trimmed keywords. */
    public function getPrimaryKeywords(): array     { return $this->kw(self::XML_PRIMARY_KEYWORDS); }
    public function getNoneKeywords(): array        { return $this->kw(self::XML_NONE_KEYWORDS); }
    public function getCodeKeywords(): array        { return $this->kw(self::XML_CODE_KEYWORDS); }
    public function getImageKeywords(): array       { return $this->kw(self::XML_IMAGE_KEYWORDS); }
    public function getCodeInputKeywords(): array   { return $this->kw(self::XML_CODE_INPUT_KEYWORDS); }
    public function getImageInputKeywords(): array  { return $this->kw(self::XML_IMAGE_INPUT_KEYWORDS); }
    public function getExtraInputKeywords(): array  { return $this->kw(self::XML_EXTRA_INPUT_KEYWORDS); }

    private function kw(string $path): array
    {
        $raw = (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        if ($raw === '') {
            return [];
        }
        $parts = array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', $raw));
        return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    /* ===== Field customization getters — all return string|int with empty fallback to source title ===== */

    public function getPrimaryTitleOverride(): string  { return $this->str(self::XML_F_PRIMARY_TITLE); }
    public function getPrimaryHelpText(): string       { return $this->str(self::XML_F_PRIMARY_HELP); }
    public function getNoneLabelOverride(): string     { return $this->str(self::XML_F_NONE_LABEL); }
    public function getCodeChoiceLabelOverride(): string  { return $this->str(self::XML_F_CODE_CHOICE_LABEL); }
    public function getImageChoiceLabelOverride(): string { return $this->str(self::XML_F_IMAGE_CHOICE_LABEL); }

    public function getCodeInputTitleOverride(): string { return $this->str(self::XML_F_CODE_TITLE); }
    public function getCodeInputType(): string          { return $this->str(self::XML_F_CODE_TYPE) ?: 'text'; }
    public function getCodeInputPlaceholder(): string   { return $this->str(self::XML_F_CODE_PLACEHOLDER); }
    public function getCodeInputHelp(): string          { return $this->str(self::XML_F_CODE_HELP); }
    public function getCodeInputMaxLength(): int        { return (int)$this->str(self::XML_F_CODE_MAX_LENGTH); }

    public function getImageInputTitleOverride(): string { return $this->str(self::XML_F_IMAGE_TITLE); }
    public function getImageInputPlaceholder(): string   { return $this->str(self::XML_F_IMAGE_PLACEHOLDER); }
    public function getImageInputHelp(): string          { return $this->str(self::XML_F_IMAGE_HELP); }
    public function getImageInputAccept(): string        { return $this->str(self::XML_F_IMAGE_ACCEPT); }

    public function getExtraInputTitleOverride(): string { return $this->str(self::XML_F_EXTRA_TITLE); }
    public function getExtraInputType(): string          { return $this->str(self::XML_F_EXTRA_TYPE) ?: 'text'; }
    public function getExtraInputPlaceholder(): string   { return $this->str(self::XML_F_EXTRA_PLACEHOLDER); }
    public function getExtraInputHelp(): string          { return $this->str(self::XML_F_EXTRA_HELP); }
    public function getExtraInputMaxLength(): int        { return (int)$this->str(self::XML_F_EXTRA_MAX_LENGTH); }

    /* ===== Appearance getters ===== */

    /** Master switch: auto-read the active theme's colors and apply them to the option UI. */
    public function isAdoptThemeColors(): bool
    {
        // Default ON when unset so enabling the module "just adopts" the theme.
        $val = $this->scopeConfig->getValue(self::XML_ADOPT_THEME_COLORS, ScopeInterface::SCOPE_STORE);
        return $val === null ? true : (bool)(int)$val;
    }

    /** Whether to render the option choices as themed cards (vs. leave the theme's native layout). */
    public function isCardLayout(): bool
    {
        $val = $this->scopeConfig->getValue(self::XML_CARD_LAYOUT, ScopeInterface::SCOPE_STORE);
        return $val === null ? true : (bool)(int)$val;
    }

    /**
     * Optional manual accent override. Empty = use the colour auto-detected from
     * the live theme. Returns a validated #hex (3/4/6/8 digit) or ''.
     */
    public function getAccentColor(): string
    {
        $raw = trim((string)$this->scopeConfig->getValue(self::XML_ACCENT_COLOR, ScopeInterface::SCOPE_STORE));
        if ($raw === '') {
            return '';
        }
        if ($raw[0] !== '#') {
            $raw = '#' . $raw;
        }
        return preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $raw) ? $raw : '';
    }

    /* Backward-compat aliases for the existing Keystation theme template */
    public function getDefaultRadioChoice(): string { return $this->getDefaultChoice(); }
    public function showImageAlongsideCode(): bool  { return $this->showAllInputs(); }

    private function str(string $path): string
    {
        return trim((string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
    }
}
