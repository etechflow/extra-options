<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\BulkPrice;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::bulk_price';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Etechflow_OptionsPlugin::bulk_price');
        $page->getConfig()->getTitle()->prepend(__('Bulk Price Update'));
        $page->addBreadcrumb(__('eTechFlow'), __('eTechFlow'));
        $page->addBreadcrumb(__('Bulk Price Update'), __('Bulk Price Update'));
        return $page;
    }
}
