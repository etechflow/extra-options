<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\Template\Edit;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\CollectionFactory as OptionCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value\CollectionFactory as ValueCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;

/**
 * Backing block for the Template edit/new page. Renders everything in plain
 * PHTML — reliable across Magento versions, no UI Component data-binding
 * quirks. The General fields, Options table, Apply-To section, and a list of
 * currently-linked products all live in the same template, posted as one
 * form to the Save controller.
 */
class Form extends Template
{
    protected $_template = 'Etechflow_OptionsPlugin::template/edit.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly ValueCollectionFactory $valueCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTemplate_(): ?\Etechflow\OptionsPlugin\Model\Template
    {
        return $this->registry->registry('efopt_current_template');
    }

    public function getTemplateId(): int
    {
        $t = $this->getTemplate_();
        return $t ? (int)$t->getId() : 0;
    }

    /**
     * Build a hierarchical options tree:
     * - Top-level options (parent_value_id IS NULL) appear at the root
     * - For each select-type option's value, attach `sub_options` — any option
     *   whose parent_value_id matches that value
     * - Sub-options themselves carry their values + further sub_options recursively
     *
     * @return array<int,array<string,mixed>>
     */
    public function getOptionsTree(): array
    {
        $id = $this->getTemplateId();
        if (!$id) { return []; }
        $options = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $id)
            ->setOrder('sort_order', 'ASC');

        // Index all options by ID + by parent_value_id bucket
        $byId = [];
        $byParentValueId = [];
        foreach ($options as $opt) {
            $row = $opt->getData();
            $row['values'] = [];
            $byId[(int)$opt->getId()] = $row;
            $pvid = (int)($opt->getData('parent_value_id') ?? 0);
            if ($pvid) { $byParentValueId[$pvid][] = (int)$opt->getId(); }
        }

        // Load values for all options
        if ($byId) {
            $values = $this->valueCollectionFactory->create()
                ->addFieldToFilter('template_option_id', ['in' => array_keys($byId)])
                ->setOrder('sort_order', 'ASC');
            foreach ($values as $val) {
                $oid = (int)$val->getData('template_option_id');
                if (isset($byId[$oid])) {
                    $byId[$oid]['values'][] = $val->getData();
                }
            }
        }

        // Walk values and attach sub_options per value
        foreach ($byId as $oid => &$row) {
            foreach ($row['values'] as &$v) {
                $vid = (int)$v['value_id'];
                $v['sub_options'] = [];
                if (isset($byParentValueId[$vid])) {
                    foreach ($byParentValueId[$vid] as $subOid) {
                        $v['sub_options'][] = $byId[$subOid];
                    }
                }
            }
            unset($v);
        }
        unset($row);

        // Return only TOP-LEVEL options (those with no parent_value_id)
        $top = [];
        foreach ($byId as $oid => $row) {
            if (empty($row['parent_value_id'])) { $top[] = $row; }
        }
        return $top;
    }

    /** @return array<int,int> linked category IDs */
    public function getLinkedCategoryIds(): array
    {
        $id = $this->getTemplateId();
        if (!$id) { return []; }
        $conn = $this->resourceConnection->getConnection();
        return array_map('intval', $conn->fetchCol(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_category'), 'category_id')
                ->where('template_id = ?', $id)
        ));
    }

    /** @return array<int,int> category_id => level (used to split chips by top-level vs sub) */
    public function getCategoryLevels(array $categoryIds): array
    {
        if (!$categoryIds) { return []; }
        $conn = $this->resourceConnection->getConnection();
        return array_map('intval', $conn->fetchPairs(
            $conn->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity'), ['entity_id', 'level'])
                ->where('entity_id IN (?)', $categoryIds)
        ));
    }

    /** @return array<int,array{product_id:int,name:string,sku:string,source:string}> linked products */
    public function getLinkedProducts(int $limit = 50, int $offset = 0): array
    {
        $id = $this->getTemplateId();
        if (!$id) { return []; }
        $conn = $this->resourceConnection->getConnection();
        return $conn->fetchAll(
            $conn->select()
                ->from(
                    ['ep' => $this->resourceConnection->getTableName('efopt_template_product')],
                    ['product_id', 'source', 'synced_at']
                )
                ->joinLeft(
                    ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'ep.product_id = cpe.entity_id',
                    ['sku']
                )
                ->joinLeft(
                    ['cpe_v' => $this->resourceConnection->getTableName('catalog_product_entity_varchar')],
                    'ep.product_id = cpe_v.entity_id AND cpe_v.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = "name" AND entity_type_id = 4) AND cpe_v.store_id = 0',
                    ['name' => 'value']
                )
                ->where('ep.template_id = ?', $id)
                ->group('ep.product_id')
                ->order('ep.product_id ASC')
                ->limit($limit, $offset)
        );
    }

    /** Page-size used by the Currently-Linked-Products table. */
    public const PRODUCTS_PER_PAGE = 50;

    public function getCurrentPage(): int
    {
        $p = (int) $this->getRequest()->getParam('linked_page', 1);
        return max(1, $p);
    }

    public function getTotalPages(): int
    {
        $total = $this->getLinkedProductCount();
        if ($total === 0) { return 1; }
        return (int) ceil($total / self::PRODUCTS_PER_PAGE);
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('efopt/templates/edit', [
            'template_id' => $this->getTemplateId(),
            'linked_page' => $page,
        ]);
    }

    public function getLinkedProductCount(): int
    {
        $id = $this->getTemplateId();
        if (!$id) { return 0; }
        $conn = $this->resourceConnection->getConnection();
        return (int)$conn->fetchOne(
            $conn->select()
                ->from($this->resourceConnection->getTableName('efopt_template_product'), new \Zend_Db_Expr('COUNT(DISTINCT product_id)'))
                ->where('template_id = ?', $id)
        );
    }

    /** @return array<int,string> id → path-formatted display name */
    public function getCategoryOptions(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['cc' => $this->resourceConnection->getTableName('catalog_category_entity')], ['entity_id', 'level', 'path'])
                ->joinLeft(['cv' => $table],
                    'cc.entity_id = cv.entity_id AND cv.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 3 AND attribute_code = "name")',
                    ['name' => 'cv.value'])
                ->where('cc.level > 1')
                ->order('path ASC')
        );
        $byId = [];
        foreach ($rows as $r) { $byId[(int)$r['entity_id']] = $r; }
        $result = [];
        foreach ($byId as $id => $r) {
            $names = [];
            foreach (explode('/', (string)$r['path']) as $pid) {
                $pid = (int)$pid;
                if ($pid > 1 && isset($byId[$pid])) { $names[] = (string)($byId[$pid]['name'] ?? "#$pid"); }
            }
            $result[$id] = implode(' → ', $names);
        }
        return $result;
    }

    /**
     * Build a JS-friendly category tree for the cascading picker.
     * Shape: [ {id, parent_id, name, level, has_children, product_count}, ... ]
     * Flat array (admin JS walks it by parent_id); only includes active+visible cats above the root.
     */
    public function getCategoryTreeJson(): string
    {
        $conn = $this->resourceConnection->getConnection();
        $catTable    = $this->resourceConnection->getTableName('catalog_category_entity');
        $varcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $intTable    = $this->resourceConnection->getTableName('catalog_category_entity_int');
        $ccpTable    = $this->resourceConnection->getTableName('catalog_category_product');

        // is_active attribute id (entity_type_id=3 for category)
        $isActiveAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 3 AND attribute_code = 'is_active'"
        );
        $nameAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 3 AND attribute_code = 'name'"
        );

        $select = $conn->select()
            ->from(['cc' => $catTable], ['entity_id', 'parent_id', 'level', 'path', 'children_count'])
            ->joinLeft(['n' => $varcharTable],
                "cc.entity_id = n.entity_id AND n.attribute_id = $nameAttrId AND n.store_id = 0",
                ['name' => 'n.value'])
            ->joinLeft(['a' => $intTable],
                "cc.entity_id = a.entity_id AND a.attribute_id = $isActiveAttrId AND a.store_id = 0",
                ['is_active' => 'a.value'])
            ->joinLeft(['ccp' => $ccpTable], 'cc.entity_id = ccp.category_id',
                ['product_count' => new \Zend_Db_Expr('COUNT(DISTINCT ccp.product_id)')])
            ->where('cc.level >= 2')          // skip the root admin category
            ->group('cc.entity_id')
            ->order('cc.level ASC')
            ->order('n.value ASC');

        $rows = $conn->fetchAll($select);
        $tree = [];
        foreach ($rows as $r) {
            // Filter out inactive categories — the admin shouldn't be able
            // to attach templates to disabled categories (would create dead links).
            if ($r['is_active'] !== null && (int)$r['is_active'] !== 1) { continue; }
            $tree[] = [
                'id'             => (int) $r['entity_id'],
                'parent_id'      => (int) $r['parent_id'],
                'name'           => (string) ($r['name'] ?? "#{$r['entity_id']}"),
                'level'          => (int) $r['level'],
                'children_count' => (int) $r['children_count'],
                'product_count'  => (int) $r['product_count'],
            ];
        }
        return json_encode($tree, JSON_UNESCAPED_UNICODE);
    }

    public function getSaveUrl(): string  { return $this->getUrl('efopt/templates/save'); }
    public function getBackUrl(): string  { return $this->getUrl('efopt/templates/index'); }
    public function getDeleteUrl(): string
    {
        return $this->getUrl('efopt/templates/delete', ['template_id' => $this->getTemplateId()]);
    }
    public function getResyncUrl(): string
    {
        return $this->getUrl('efopt/templates/applyNow', ['template_id' => $this->getTemplateId()]);
    }
}
