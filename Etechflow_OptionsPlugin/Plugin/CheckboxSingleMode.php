<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;

/**
 * Enforce single-selection for checkbox options the admin flagged as
 * checkbox_mode='single'.
 *
 * The storefront JS (efopt-live-price.js) already clears sibling boxes so a
 * normal customer can only tick one. This plugin is the server-side guarantee:
 * a direct/scripted POST could still submit several values for a native checkbox
 * option, so we trim the submission down to its first value before Magento builds
 * the cart item. Keeps the "single" promise honest regardless of the client.
 */
class CheckboxSingleMode
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function beforePrepareForCartAdvanced(
        AbstractType $subject,
        DataObject $buyRequest,
        Product $product,
        $processMode = AbstractType::PROCESS_MODE_FULL
    ): array {
        if (!$product->getId()) {
            return [$buyRequest, $product, $processMode];
        }
        $options = (array) $buyRequest->getOptions();
        if (!$options) {
            return [$buyRequest, $product, $processMode];
        }

        $singleIds = $this->singleModeOptionIds((int) $product->getId());
        if (!$singleIds) {
            return [$buyRequest, $product, $processMode];
        }

        $dirty = false;
        foreach ($singleIds as $oid) {
            if (isset($options[$oid]) && is_array($options[$oid]) && count($options[$oid]) > 1) {
                $options[$oid] = [reset($options[$oid])];
                $dirty = true;
            }
        }
        if ($dirty) {
            $buyRequest->setOptions($options);
        }

        return [$buyRequest, $product, $processMode];
    }

    /**
     * @return int[] magento_option_id list for single-mode checkbox options on the product
     */
    private function singleModeOptionIds(int $productId): array
    {
        $conn = $this->resourceConnection->getConnection();
        return array_map('intval', $conn->fetchCol(
            $conn->select()
                ->from(['ep' => $this->resourceConnection->getTableName('efopt_template_product')], ['magento_option_id'])
                ->join(
                    ['eo' => $this->resourceConnection->getTableName('efopt_template_option')],
                    'ep.template_option_id = eo.option_id',
                    []
                )
                ->where('ep.product_id = ?', $productId)
                ->where('ep.magento_option_id IS NOT NULL')
                ->where('eo.type = ?', 'checkbox')
                ->where('eo.checkbox_mode = ?', 'single')
        ));
    }
}
