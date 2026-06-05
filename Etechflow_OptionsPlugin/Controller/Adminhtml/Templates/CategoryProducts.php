<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Returns up to 200 products belonging to a given category (direct membership
 * only — admin can pick the category itself to cascade into sub-categories).
 * Used by the cascade picker when a leaf category is selected.
 */
class CategoryProducts extends Action
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
        $categoryId = (int) $this->getRequest()->getParam('category_id');
        if (!$categoryId) {
            return $result->setData(['items' => []]);
        }

        $conn = $this->resourceConnection->getConnection();
        $cpe        = $this->resourceConnection->getTableName('catalog_product_entity');
        $ccp        = $this->resourceConnection->getTableName('catalog_category_product');
        $varchar    = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $intTable   = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $nameAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'name'"
        );
        $statusAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'status'"
        );

        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['ccp' => $ccp], [])
                ->join(['p' => $cpe], 'ccp.product_id = p.entity_id', ['entity_id', 'sku'])
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
                ->where('ccp.category_id = ?', $categoryId)
                ->where('s.value IS NULL OR s.value = 1')
                ->order('p.sku ASC')
                ->limit(200)
        );

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
