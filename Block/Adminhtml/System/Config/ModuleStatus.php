<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\System\Config;

use Etechflow\OptionsPlugin\Model\Config;
use Etechflow\OptionsPlugin\Model\LicenseValidator;
use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\Js;

/**
 * "Module Status" callout at the top of the Extra Options Plugin config section.
 * Licensing gates the ADMIN pages only, so this banner reflects whether the
 * admin Option-Templates / Bulk-Price / Migration tools are unlocked.
 */
class ModuleStatus extends Fieldset
{
    public function __construct(
        Context $context,
        Session $authSession,
        Js $jsHelper,
        private readonly Config $config,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->addClass('etechflow-module-status');

        $html  = $this->_getHeaderHtml($element);
        $html .= '<tr id="' . $element->getHtmlId() . '_status_row"><td colspan="4">';
        $html .= $this->renderStatusBanner();
        $html .= '</td></tr>';
        $html .= $this->_getFooterHtml($element);

        return $html;
    }

    private function renderStatusBanner(): string
    {
        $host          = $this->licenseValidator->getCurrentHost();
        $licenceValid  = $this->licenseValidator->isValid();
        $moduleEnabled = $this->config->isEnabled();
        $hasKey = trim($this->licenseValidator->getConfiguredKey()) !== ''
            || trim($this->licenseValidator->getConfiguredBundleKey()) !== '';

        $gateUrl = $this->getUrl('efopt/license/gate');
        $gateBtn = '<div style="margin-top:12px;"><a href="' . $this->escapeUrl($gateUrl) . '" '
            . 'style="display:inline-block;background:#1979c3;color:#fff;padding:8px 18px;border-radius:4px;'
            . 'text-decoration:none;font-weight:600;font-size:13px;">View Plans &amp; Activate License &rarr;</a></div>';

        if (!$licenceValid) {
            if (!$hasKey) {
                return $this->banner(
                    'warning',
                    '⚠️ Licence key required',
                    'No licence key has been entered for host <code>' . $this->escapeHtml($host) . '</code>. '
                    . 'A valid licence is always required — the admin Option-Templates, Bulk-Price and Migration pages '
                    . 'are locked until a valid key is saved below. The storefront and existing product options are unaffected. '
                    . 'Choose a plan and pay by card to get your key instantly, or paste an existing key in the '
                    . '<strong>License Key</strong> field.'
                    . $gateBtn
                );
            }

            return $this->banner(
                'warning',
                '⚠️ Licence key invalid for this host',
                'A licence key has been entered, but the portal rejected it for host '
                . '<code>' . $this->escapeHtml($host) . '</code>. The admin Option-Templates tools are locked '
                . '(the storefront is unaffected). Common causes: server IP removed from the portal subscription, '
                . 'wrong key, site moved domains (buy a new key), key suspended, or stray whitespace in the field.'
                . $gateBtn
            );
        }

        if (!$moduleEnabled) {
            return $this->banner(
                'neutral',
                '⚪ Licence valid, module is disabled',
                'Licence accepted for <code>' . $this->escapeHtml($host) . '</code>, but <strong>Enable Module</strong> '
                . 'in General is set to No, so the storefront option enhancements are off. Flip Enable Module to Yes to activate.'
            );
        }

        return $this->banner(
            'success',
            '✅ Extra Options Plugin is active',
            'Licence valid for <code>' . $this->escapeHtml($host) . '</code>. The admin Option-Templates tools are '
            . 'unlocked and the storefront option enhancements are live.'
        );
    }

    private function banner(string $kind, string $heading, string $body): string
    {
        $palette = match ($kind) {
            'success' => ['bg' => '#e7f5ec', 'border' => '#2e7d32', 'fg' => '#1b5e20'],
            'warning' => ['bg' => '#fff4e5', 'border' => '#ef6c00', 'fg' => '#bf360c'],
            'info'    => ['bg' => '#e3f2fd', 'border' => '#1976d2', 'fg' => '#0d47a1'],
            default   => ['bg' => '#f5f5f5', 'border' => '#9e9e9e', 'fg' => '#424242'],
        };

        return sprintf(
            '<div style="background:%s;border-left:4px solid %s;color:%s;padding:14px 18px;margin:0 0 6px;border-radius:4px;font-size:13px;line-height:1.5;">'
            . '<strong style="font-size:14px;display:block;margin-bottom:4px;">%s</strong>%s'
            . '</div>',
            $palette['bg'],
            $palette['border'],
            $palette['fg'],
            $heading,
            $body
        );
    }
}
