<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\Template\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        $id = $this->getTemplateId();
        if (!$id) { return []; } // hide on "new template" page
        return [
            'label'      => __('Delete Template'),
            'class'      => 'delete',
            'on_click'   => sprintf(
                "deleteConfirm('%s', '%s')",
                __('Delete this template? Existing catalog_product_option rows created from it will NOT be auto-removed.'),
                $this->getUrl('*/*/delete', ['template_id' => $id])
            ),
            'sort_order' => 20,
        ];
    }
}
