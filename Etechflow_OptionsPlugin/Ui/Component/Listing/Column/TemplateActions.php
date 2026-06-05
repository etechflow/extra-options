<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class TemplateActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int)($item['template_id'] ?? 0);
            if (!$id) { continue; }
            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('efopt/templates/edit', ['template_id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl('efopt/templates/delete', ['template_id' => $id]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete Template'),
                        'message' => __('Delete this template? Every linked product will have its template-synced options REMOVED from the storefront. This action cannot be undone.'),
                    ],
                    'post' => true,
                ],
            ];
        }
        return $dataSource;
    }
}
