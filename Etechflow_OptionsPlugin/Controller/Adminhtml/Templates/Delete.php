<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Etechflow\OptionsPlugin\Model\SyncService;
use Etechflow\OptionsPlugin\Model\TemplateRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Delete a template AND cascade-desync every linked product so the storefront
 * stops showing the options that were created from this template.
 *
 * Order matters: desync products FIRST (deletes catalog_product_option rows on
 * each product), then delete the template (FK CASCADE handles efopt_template_*
 * cleanup). If we delete the template first, efopt_template_product is gone and
 * we can't enumerate which products to desync — orphan options would remain.
 */
class Delete extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_delete';

    public function __construct(
        Context $context,
        private readonly TemplateRepository $templateRepository,
        private readonly SyncService $syncService,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('template_id');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $desynced = $this->desyncAllLinkedProducts($id);
            $this->templateRepository->deleteById($id);
            $this->messageManager->addSuccessMessage(
                __('Template deleted. Removed options from %1 product(s).', $desynced)
            );
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Template not found.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Delete failed: %1', $e->getMessage()));
        }
        return $redirect->setPath('*/*/index');
    }

    /**
     * Find every distinct product_id linked to this template (direct OR via
     * category) and tell SyncService to remove the synced options.
     */
    private function desyncAllLinkedProducts(int $templateId): int
    {
        $conn = $this->resourceConnection->getConnection();
        $productIds = $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_product'), 'product_id')
                ->where('template_id = ?', $templateId)
                ->distinct()
        );
        $count = 0;
        foreach ($productIds as $pid) {
            try {
                $this->syncService->desyncTemplateFromProduct($templateId, (int)$pid);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[efopt template-delete] failed to desync product %d from template %d: %s',
                    (int)$pid, $templateId, $e->getMessage()
                ));
            }
        }
        return $count;
    }
}
