<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;

class NewAction extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_save';

    public function execute()
    {
        // Forward to Edit so create + edit share one page.
        $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
        $forward->forward('edit');
        return $forward;
    }
}
