<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\BulkPrice;

use Etechflow\OptionsPlugin\Model\BulkPriceService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::bulk_price';

    public function __construct(
        Context $context,
        private readonly BulkPriceService $bulkPriceService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $request = $this->getRequest();

        $templateId  = (int) $request->getParam('template_id');
        $categoryIds = (array) $request->getParam('category_ids', []);
        $productIds  = (array) $request->getParam('product_ids', []);
        $valuePrices = (array) $request->getParam('value_prices', []); // [tpl_value_id => price]
        $optionPrices = (array) $request->getParam('option_prices', []); // [tpl_option_id => price]

        if (!$templateId) {
            $this->messageManager->addErrorMessage(__('Pick a template first.'));
            return $redirect->setPath('*/*/index');
        }
        if (!$categoryIds && !$productIds) {
            $this->messageManager->addErrorMessage(__('Pick at least one category or product to apply the update to.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $affected = $this->bulkPriceService->apply(
                $templateId,
                array_map('intval', array_filter($categoryIds)),
                array_map('intval', array_filter($productIds)),
                $valuePrices,
                $optionPrices
            );
            $this->messageManager->addSuccessMessage(
                __('Bulk price update applied. %1 product option row(s) updated.', $affected)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Bulk update failed: %1', $e->getMessage()));
        }
        return $redirect->setPath('*/*/index');
    }
}
