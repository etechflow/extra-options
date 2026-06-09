<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\License;

use Magento\Backend\Block\Template;

class Activated extends Template
{
    public function getLicenseKey(): string
    {
        return (string) $this->getData('license_key');
    }

    public function getPlan(): string
    {
        return (string) $this->getData('plan');
    }

    public function getError(): string
    {
        return (string) $this->getData('error');
    }

    public function hasError(): bool
    {
        return $this->getError() !== '';
    }

    public function getSettingsUrl(): string
    {
        $url = (string) $this->getData('settings_url');
        if ($url === '') {
            $url = (string) $this->getUrl('adminhtml/system_config/edit', ['section' => 'etechflow_options']);
        }
        return $url;
    }

    public function getManagementUrl(): string
    {
        $url = (string) $this->getData('management_url');
        if ($url === '') {
            $url = (string) $this->getUrl('efopt/license/gate');
        }
        return $url;
    }
}
