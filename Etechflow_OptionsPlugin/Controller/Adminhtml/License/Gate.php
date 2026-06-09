<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\License;

use Etechflow\OptionsPlugin\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin License Gate page.
 * If isValid(), redirect to the module config page; else render the gate.
 */
class Gate extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::config';

    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $this->messageManager->addSuccessMessage(
                (string) __('Extra Options Plugin is licensed. Configure the module below.')
            );
            return $this->resultRedirectFactory->create()->setPath(
                'adminhtml/system_config/edit',
                ['section' => 'etechflow_options']
            );
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend((string) __('Extra Options Plugin — License Required'));
        return $resultPage;
    }
}
