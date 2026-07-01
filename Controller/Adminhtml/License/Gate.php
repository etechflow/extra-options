<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\License;

use Etechflow\OptionsPlugin\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin License-required gate. Shows plan cards + "Enter License Key".
 * Redirects to the module configuration when the license is already valid.
 */
class Gate extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::config';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $this->messageManager->addSuccessMessage(
                (string) __('Extra Options Plugin is licensed. Configure the module below.')
            );
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('adminhtml/system_config/edit', ['section' => 'etechflow_options']);
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Extra Options Plugin — License Required'));
        $portalBase = rtrim(str_replace('/license/validate', '', $this->licenseValidator->getPortalUrl()), '/');
        $domain     = $this->licenseValidator->getCurrentHost();
        $plansUrl   = $portalBase . '/license/plans?module=options-plugin&domain=' . urlencode($domain);
        $block = $page->getLayout()->getBlock('etechflow.eo.license.gate');
        if ($block) {
            $block->setData('plans_url', $plansUrl);
        }
        return $page;
    }
}
