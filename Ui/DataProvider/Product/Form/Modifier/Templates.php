<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Ui\DataProvider\Product\Form\Modifier;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * Adds an "eTechFlow Option Templates" fieldset to the product-edit form.
 *
 * Admin sees a multi-select listing all active templates, with the currently
 * linked ones preselected. On save (handled by ProductSaveObserver), the
 * SyncService creates/removes the corresponding catalog_product_option rows.
 *
 * Read-only display: also shows category-resolved templates so admin knows
 * which options the product inherits via category links (those can't be
 * unchecked here — go to the template or category edit page instead).
 */
class Templates extends AbstractModifier
{
    private const GROUP_CONTENT = 'product-details';
    private const FIELDSET_NAME = 'efopt_templates';

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly ArrayManager $arrayManager
    ) {}

    public function modifyData(array $data): array
    {
        $product = $this->locator->getProduct();
        $productId = (int)$product->getId();
        if (!$productId) { return $data; }

        $conn = $this->resourceConnection->getConnection();
        $linkedIds = $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_product'), 'template_id')
                ->where('product_id = ?', $productId)
                ->where('source = ?', 'direct')
                ->distinct()
        );
        $data[$productId]['product']['efopt_template_ids'] = array_map('intval', $linkedIds);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $templates = $this->templateCollectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->setOrder('name', 'ASC');
        $options = [['value' => '', 'label' => __('— No template —')]];
        foreach ($templates as $tpl) {
            $options[] = ['value' => (int)$tpl->getId(), 'label' => (string)$tpl->getData('name')];
        }

        $meta[self::FIELDSET_NAME] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'        => __('eTechFlow Option Templates'),
                        'componentType' => 'fieldset',
                        'collapsible'  => true,
                        'opened'       => false,
                        'sortOrder'    => 100,
                        // Product-form fields live under the `product` data scope. The
                        // initial value is written to $data[$productId]['product'][...]
                        // and ProductSaveAfter reads $product->getData('efopt_template_ids'),
                        // so the fieldset must scope to `product` — otherwise the field
                        // renders empty AND its value posts outside the product data,
                        // so the observer never sees it (link never saves).
                        'dataScope'    => 'product',
                    ],
                ],
            ],
            'children' => [
                'efopt_template_ids' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'field',
                                'formElement'   => 'multiselect',
                                'dataType'      => 'text',
                                'label'         => __('Linked Templates'),
                                'notice'        => __('When you save the product, the options from the selected templates are pushed to this product (re-syncing if already linked).'),
                                'dataScope'     => 'efopt_template_ids',
                                'options'       => $options,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        return $meta;
    }
}
