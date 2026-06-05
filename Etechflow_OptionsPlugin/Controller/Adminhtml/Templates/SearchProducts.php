<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Autocomplete endpoint for the product search bar in the template's Apply-To
 * section. Returns up to 20 matches by SKU or name (case-insensitive substring).
 */
class SearchProducts extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $q = trim((string) $this->getRequest()->getParam('q', ''));
        $categoryCsv = trim((string) $this->getRequest()->getParam('category', ''));
        $categoryIds = $categoryCsv === ''
            ? []
            : array_values(array_filter(array_map('intval', explode(',', $categoryCsv))));

        // Require at least 2 chars OR a category filter so we don't dump the whole catalog.
        if (mb_strlen($q) < 2 && !$categoryIds) {
            return $result->setData(['items' => []]);
        }

        $conn = $this->resourceConnection->getConnection();
        $cpe          = $this->resourceConnection->getTableName('catalog_product_entity');
        $ccp          = $this->resourceConnection->getTableName('catalog_category_product');
        $varchar      = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $intTable     = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $nameAttrId   = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'name'"
        );
        $statusAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'status'"
        );

        $select = $conn->select()
            ->from(['p' => $cpe], ['entity_id', 'sku'])
            ->joinLeft(
                ['n' => $varchar],
                "p.entity_id = n.entity_id AND n.attribute_id = $nameAttrId AND n.store_id = 0",
                ['name' => 'n.value']
            )
            ->joinLeft(
                ['s' => $intTable],
                "p.entity_id = s.entity_id AND s.attribute_id = $statusAttrId AND s.store_id = 0",
                []
            )
            ->where('s.value IS NULL OR s.value = 1') // enabled
            ->group('p.entity_id')
            ->order('p.sku ASC')
            ->limit(50);

        if ($q !== '') {
            $like = '%' . $q . '%';
            $select->where(
                $conn->prepareSqlCondition('p.sku', ['like' => $like])
                . ' OR '
                . $conn->prepareSqlCondition('n.value', ['like' => $like])
            );
        }
        if ($categoryIds) {
            $select->join(['ccp' => $ccp], 'p.entity_id = ccp.product_id', [])
                   ->where('ccp.category_id IN (?)', $categoryIds);
        }

        $rows = $conn->fetchAll($select);

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'   => (int) $r['entity_id'],
                'sku'  => (string) ($r['sku'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }
        return $result->setData(['items' => $items]);
    }
}
