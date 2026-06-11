<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Observer;

use Etechflow\OptionsPlugin\Model\LicenseValidator;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Admin-only licence gate. Fires on every admin controller under the `efopt`
 * route (controller_action_predispatch_efopt). When the module is not licensed,
 * any admin page EXCEPT the licence controllers themselves is redirected to the
 * licence gate.
 *
 * This deliberately gates the admin side ONLY — the storefront option rendering
 * and the checkout plugins are never touched, so an unlicensed state can never
 * break add-to-cart on a live store.
 */
class AdminLicenseGate implements ObserverInterface
{
    public function __construct(
        private readonly LicenseValidator $licenseValidator,
        private readonly BackendUrl $backendUrl
    ) {
    }

    public function execute(Observer $observer): void
    {
        $action  = $observer->getControllerAction();
        if ($action === null) {
            return;
        }
        $request = $action->getRequest();

        // Never gate the licence controllers themselves (would loop the redirect).
        if ($request->getControllerName() === 'license') {
            return;
        }

        if ($this->licenseValidator->isValid()) {
            return;
        }

        $action->getResponse()->setRedirect($this->backendUrl->getUrl('efopt/license/gate'));
        $action->getActionFlag()->set('', ActionInterface::FLAG_NO_DISPATCH, true);
    }
}
