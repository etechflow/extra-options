<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\License;

use Etechflow\OptionsPlugin\Model\LicenseValidator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Gate extends Template
{
    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        if ($this->formKey !== null) {
            return $this->formKey->getFormKey();
        }
        return \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Data\Form\FormKey::class)
            ->getFormKey();
    }

    public function getConfigUrl(): string
    {
        return (string) $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'etechflow_options', '_fragment' => 'etechflow_options_license-head']
        );
    }

    public function getCheckoutUrl(): string
    {
        return (string) $this->getUrl('efopt/license/checkout');
    }

    public function getCurrentDomain(): string
    {
        return $this->licenseValidator->getCurrentHost();
    }

    public function isStripeConfigured(): bool
    {
        $sk = trim((string) $this->_scopeConfig->getValue('etechflow_options/payment/stripe_secret_key'));
        return $sk !== '';
    }

    public function isPortalConfigured(): bool
    {
        $u = trim((string) $this->_scopeConfig->getValue('etechflow_options/license/portal_url'))
           ?: trim((string) $this->_scopeConfig->getValue('etechflow_options/license/portal_api_url'));
        return $u !== '';
    }
}
