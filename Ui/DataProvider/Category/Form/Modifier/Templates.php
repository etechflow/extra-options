<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Ui\DataProvider\Category\Form\Modifier;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Adds an "eTechFlow Option Templates" fieldset to the category-edit form.
 * Multi-select to attach templates → SyncService fans out to every product
 * currently in the category when the category is saved (CategoryAfterSave plugin).
 */
class Templates implements ModifierInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {}

    public function modifyData(array $data): array
    {
        $categoryId = (int) $this->request->getParam('id');
        if (!$categoryId || !isset($data[$categoryId])) { return $data; }

        $conn = $this->resourceConnection->getConnection();
        $linkedIds = $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_category'), 'template_id')
                ->where('category_id = ?', $categoryId)
        );
        $data[$categoryId]['efopt_template_ids'] = array_map('intval', $linkedIds);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $templates = $this->templateCollectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->setOrder('name', 'ASC');
        $options = [];
        foreach ($templates as $tpl) {
            $options[] = ['value' => (int)$tpl->getId(), 'label' => (string)$tpl->getData('name')];
        }

        $meta['efopt_templates'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'         => __('eTechFlow Option Templates'),
                        'componentType' => 'fieldset',
                        'collapsible'   => true,
                        'opened'        => false,
                        'sortOrder'     => 200,
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
                                'notice'        => __('Every product in this category will receive these template options when the category is saved. Removing a template here desyncs it from all products that inherited it from this category (but keeps options that were directly applied on a product).'),
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
