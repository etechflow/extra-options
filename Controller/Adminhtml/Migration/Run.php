<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Migration;

use Etechflow\OptionsPlugin\Model\MigrationService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Run extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::migration';

    public function __construct(
        Context $context,
        private readonly MigrationService $migrationService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $result = $this->migrationService->run();
            $this->messageManager->addSuccessMessage(
                __(
                    'Migration complete. Backup saved to %1. Created %2 template(s), linked %3 product(s).',
                    $result['backup_path'],
                    $result['templates_created'],
                    $result['products_linked']
                )
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Migration failed: %1', $e->getMessage()));
        }
        return $redirect->setPath('*/*/index');
    }
}
