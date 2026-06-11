<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Migration;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::migration';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Etechflow_OptionsPlugin::migration');
        $page->getConfig()->getTitle()->prepend(__('Migration Tool'));
        $page->addBreadcrumb(__('eTechFlow'), __('eTechFlow'));
        $page->addBreadcrumb(__('Migration Tool'), __('Migration Tool'));
        return $page;
    }
}
