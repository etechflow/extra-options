<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Block\Adminhtml\Template\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\RequestInterface;

abstract class GenericButton
{
    public function __construct(
        protected readonly Context $context,
        protected readonly RequestInterface $request
    ) {}

    public function getTemplateId(): ?int
    {
        $id = (int) $this->request->getParam('template_id');
        return $id ?: null;
    }

    protected function getUrl(string $path, array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($path, $params);
    }
}
