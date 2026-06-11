<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\DataObject\IdentityInterface;

/**
 * Template entity. A template is a named, reusable set of custom options
 * that can be linked to many products and many categories.
 */
class Template extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'efopt_template';
    public const ENTITY    = 'efopt_template';

    protected $_eventPrefix = 'efopt_template';
    protected $_eventObject = 'template';

    protected function _construct(): void
    {
        $this->_init(\Etechflow\OptionsPlugin\Model\ResourceModel\Template::class);
    }

    /** @return string[] */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }
}
