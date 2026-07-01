<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\License;

use Magento\Backend\Block\Template;

/**
 * Renders the admin License-required gate page (plan cards + enter-key).
 * The controller passes the portal plans_url in via setData('plans_url').
 */
class Gate extends Template
{
}
