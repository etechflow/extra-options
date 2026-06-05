<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\BulkPrice;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\CollectionFactory as OptionCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value\CollectionFactory as ValueCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Backing block for the Bulk Price Update page. Exposes:
 *  - All active templates (for the picker)
 *  - For the picked template: its options + values (so admin can edit prices)
 *  - All categories (flat tree for picker)
 *  - All linked products of the picked template
 */
class Form extends Template
{
    protected $_template = 'Etechflow_OptionsPlugin::bulkprice/form.phtml';

    public function __construct(
        Context $context,
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly ValueCollectionFactory $valueCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly CategoryRepositoryInterface $categoryRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array{template_id:int, name:string}[] */
    public function getActiveTemplates(): array
    {
        $result = [];
        $collection = $this->templateCollectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->setOrder('name', 'ASC');
        foreach ($collection as $tpl) {
            $result[] = ['template_id' => (int)$tpl->getId(), 'name' => (string)$tpl->getData('name')];
        }
        return $result;
    }

    public function getSelectedTemplateId(): int
    {
        return (int) $this->getRequest()->getParam('template_id');
    }

    /** @return array<int,array{option_id:int,title:string,type:string,price:?float,values:array}> */
    public function getTemplateBreakdown(int $templateId): array
    {
        if (!$templateId) { return []; }
        $options = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->setOrder('sort_order', 'ASC');
        $rows = [];
        foreach ($options as $opt) {
            $rows[(int)$opt->getId()] = [
                'option_id' => (int)$opt->getId(),
                'title'     => (string)$opt->getData('title'),
                'type'      => (string)$opt->getData('type'),
                'price'     => $opt->getData('price') !== null ? (float)$opt->getData('price') : null,
                'values'    => [],
            ];
        }
        if ($rows) {
            $values = $this->valueCollectionFactory->create()
                ->addFieldToFilter('template_option_id', ['in' => array_keys($rows)])
                ->setOrder('sort_order', 'ASC');
            foreach ($values as $val) {
                $oid = (int)$val->getData('template_option_id');
                if (!isset($rows[$oid])) { continue; }
                $rows[$oid]['values'][] = [
                    'value_id' => (int)$val->getId(),
                    'title'    => (string)$val->getData('title'),
                    'price'    => (float)$val->getData('price'),
                ];
            }
        }
        return array_values($rows);
    }

    public function getFormAction(): string
    {
        return $this->getUrl('efopt/bulkprice/save');
    }

    /** @return array<int,string> category_id → path-formatted display name */
    public function getCategoryOptions(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(
                    ['cc' => $this->resourceConnection->getTableName('catalog_category_entity')],
                    ['entity_id', 'level', 'path']
                )
                ->joinLeft(
                    ['cv' => $table],
                    'cc.entity_id = cv.entity_id AND cv.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 3 AND attribute_code = "name")',
                    ['name' => 'cv.value']
                )
                ->where('cc.level > 1') // skip root + admin store root
                ->order('path ASC')
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['entity_id']] = $r;
        }
        $result = [];
        foreach ($byId as $id => $r) {
            $pathIds = explode('/', (string)$r['path']);
            $names = [];
            foreach ($pathIds as $pid) {
                $pid = (int)$pid;
                if ($pid > 1 && isset($byId[$pid])) {
                    $names[] = (string)($byId[$pid]['name'] ?? "#$pid");
                }
            }
            $result[$id] = implode(' → ', $names);
        }
        return $result;
    }
}
