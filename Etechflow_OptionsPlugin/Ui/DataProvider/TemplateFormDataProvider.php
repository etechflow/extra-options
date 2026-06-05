<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Ui\DataProvider;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\CollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\CollectionFactory as OptionCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value\CollectionFactory as ValueCollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Hydrates the template edit form. Returns a single-row data array keyed by
 * template_id whose body contains scalars + a nested `options` array (one row
 * per option, each with nested `values`). Empty for the "new template" page.
 */
class TemplateFormDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly ValueCollectionFactory $valueCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        // Re-populate the form from previously-failed submit (DataPersistor).
        $previous = $this->dataPersistor->get('efopt_template');
        if (is_array($previous) && $previous) {
            $this->dataPersistor->clear('efopt_template');
            $id = (int)($previous['template_id'] ?? 0);
            return [$id ?: '_new' => $previous];
        }

        $items = [];
        foreach ($this->collection as $template) {
            $row = $template->getData();
            $row['options'] = $this->loadOptionsTree((int)$template->getId());
            $items[(int)$template->getId()] = $row;
        }
        return $items;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadOptionsTree(int $templateId): array
    {
        $options = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->setOrder('sort_order', 'ASC');
        $optionIds = [];
        $rows = [];
        foreach ($options as $opt) {
            $optionIds[] = (int)$opt->getId();
            $rows[(int)$opt->getId()] = $opt->getData();
            $rows[(int)$opt->getId()]['values'] = [];
        }
        if ($optionIds) {
            $values = $this->valueCollectionFactory->create()
                ->addFieldToFilter('template_option_id', ['in' => $optionIds])
                ->setOrder('sort_order', 'ASC');
            foreach ($values as $val) {
                $oid = (int)$val->getData('template_option_id');
                if (isset($rows[$oid])) {
                    $rows[$oid]['values'][] = $val->getData();
                }
            }
        }
        return array_values($rows);
    }
}
