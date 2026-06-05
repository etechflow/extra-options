<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Etechflow\OptionsPlugin\Model\TemplateRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_save';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly TemplateRepository $templateRepository,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('template_id');
        $template = $this->templateRepository->create();
        if ($id) {
            try {
                $template = $this->templateRepository->getById($id);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('This template no longer exists.'));
                return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                    ->setPath('*/*/index');
            }
        }
        // The UI Component form's data provider reads this registry entry.
        $this->registry->register('efopt_current_template', $template, true);

        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Etechflow_OptionsPlugin::templates');
        $page->getConfig()->getTitle()->prepend(
            $id ? __('Edit Template: %1', $template->getData('name')) : __('New Template')
        );
        $page->addBreadcrumb(__('eTechFlow'), __('eTechFlow'));
        $page->addBreadcrumb(__('Option Templates'), __('Option Templates'));
        $page->addBreadcrumb(
            $id ? __('Edit') : __('New'),
            $id ? __('Edit') : __('New')
        );
        return $page;
    }
}
