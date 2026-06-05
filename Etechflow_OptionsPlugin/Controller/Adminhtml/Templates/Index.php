<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * Templates listing — renders the grid via the efopt_templates_listing UI Component.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Etechflow_OptionsPlugin::templates');
        $page->getConfig()->getTitle()->prepend(__('Option Templates'));
        $page->addBreadcrumb(__('eTechFlow'), __('eTechFlow'));
        $page->addBreadcrumb(__('Option Templates'), __('Option Templates'));
        return $page;
    }
}
