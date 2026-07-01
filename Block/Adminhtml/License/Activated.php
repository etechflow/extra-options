<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\License;

use Magento\Backend\Block\Template;

/**
 * Renders the post-payment "Subscription Activated" page. The controller passes
 * license_key / plan / error / settings_url / management_url via setData().
 */
class Activated extends Template
{
}
