<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Ui\Component\Listing;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Provider for the templates listing grid. Wraps the Template collection and
 * injects three computed counts via aggregate subqueries (so the grid can
 * render # Options / # Linked Products / # Linked Categories without an extra
 * roundtrip per row).
 */
class TemplateDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
        $this->withCounts();
    }

    private function withCounts(): void
    {
        $select = $this->collection->getSelect();
        $resource = $this->collection->getResource();
        $conn = $resource->getConnection();

        $select->joinLeft(
            ['eo' => $resource->getTable('efopt_template_option')],
            'main_table.template_id = eo.template_id',
            ['options_count' => new \Zend_Db_Expr('COUNT(DISTINCT eo.option_id)')]
        );
        $select->joinLeft(
            ['ep' => $resource->getTable('efopt_template_product')],
            'main_table.template_id = ep.template_id',
            ['products_count' => new \Zend_Db_Expr('COUNT(DISTINCT ep.product_id)')]
        );
        $select->joinLeft(
            ['ec' => $resource->getTable('efopt_template_category')],
            'main_table.template_id = ec.template_id',
            ['categories_count' => new \Zend_Db_Expr('COUNT(DISTINCT ec.category_id)')]
        );
        $select->group('main_table.template_id');
    }

    public function getData(): array
    {
        $items = [];
        /** @var \Etechflow\OptionsPlugin\Model\Template $template */
        foreach ($this->getCollection() as $template) {
            $items[] = $template->getData();
        }
        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    public function addFilter(Filter $filter): void
    {
        if ($filter->getField() === 'options_count'
            || $filter->getField() === 'products_count'
            || $filter->getField() === 'categories_count') {
            // The collection's HAVING clause is required for filtering aggregates.
            $this->getCollection()
                ->getSelect()
                ->having($filter->getField() . ' ' . $filter->getConditionType() . ' ?', $filter->getValue());
            return;
        }
        parent::addFilter($filter);
    }
}
