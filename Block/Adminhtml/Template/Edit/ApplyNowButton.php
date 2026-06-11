<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\Template\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class ApplyNowButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        $id = $this->getTemplateId();
        if (!$id) { return []; }
        return [
            'label'      => __('Re-sync All Linked Products'),
            'class'      => 'apply',
            'on_click'   => sprintf(
                "if(confirm('%s')) location.href='%s';",
                __('This will re-push the template options to every linked product (direct + category-resolved). Continue?'),
                $this->getUrl('*/*/applyNow', ['template_id' => $id])
            ),
            'sort_order' => 30,
        ];
    }
}
