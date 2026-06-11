<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Etechflow\OptionsPlugin\Model\SyncService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class ApplyNow extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_apply';

    public function __construct(
        Context $context,
        private readonly SyncService $syncService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('template_id');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Missing template_id.'));
            return $redirect->setPath('*/*/index');
        }
        try {
            $count = $this->syncService->resyncAll($id);
            $this->messageManager->addSuccessMessage(__('Template re-synced to %1 product(s).', $count));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Re-sync failed: %1', $e->getMessage()));
        }
        return $redirect->setPath('*/*/edit', ['template_id' => $id]);
    }
}
