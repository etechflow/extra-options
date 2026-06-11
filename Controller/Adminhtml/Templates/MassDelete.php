<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory;
use Etechflow\OptionsPlugin\Model\SyncService;
use Etechflow\OptionsPlugin\Model\TemplateRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

class MassDelete extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly TemplateRepository $templateRepository,
        private readonly SyncService $syncService,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;
        $totalDesynced = 0;
        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_product');

        foreach ($collection as $template) {
            $tid = (int) $template->getId();
            // Desync linked products BEFORE deleting the template — FK CASCADE
            // wipes efopt_template_product on template delete, so we have to
            // enumerate first.
            $pids = $conn->fetchCol(
                $conn->select()->from($linkTable, 'product_id')
                    ->where('template_id = ?', $tid)->distinct()
            );
            foreach ($pids as $pid) {
                try {
                    $this->syncService->desyncTemplateFromProduct($tid, (int)$pid);
                    $totalDesynced++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[efopt mass-delete desync] ' . $e->getMessage());
                }
            }
            try {
                $this->templateRepository->delete($template);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('[efopt mass-delete template] ' . $e->getMessage());
            }
        }

        $this->messageManager->addSuccessMessage(
            __('%1 template(s) deleted. Removed options from %2 product link(s).', $deleted, $totalDesynced)
        );
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('*/*/index');
    }
}
