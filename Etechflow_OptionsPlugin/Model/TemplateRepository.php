<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template as TemplateResource;
use Etechflow\OptionsPlugin\Model\TemplateFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Lightweight repository facade for the Template entity. Not exposed via REST
 * yet (no \Api\TemplateRepositoryInterface)  — admin controllers depend on this
 * concrete class to keep things simple. A REST-friendly interface can be added
 * later without disturbing callers.
 */
class TemplateRepository
{
    public function __construct(
        private readonly TemplateFactory $templateFactory,
        private readonly TemplateResource $templateResource
    ) {}

    public function create(): Template
    {
        return $this->templateFactory->create();
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): Template
    {
        $tpl = $this->templateFactory->create();
        $this->templateResource->load($tpl, $id);
        if (!$tpl->getId()) {
            throw new NoSuchEntityException(
                __('Template with id "%1" does not exist.', $id)
            );
        }
        return $tpl;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Template $template): Template
    {
        try {
            $this->templateResource->save($template);
            return $template;
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save template: %1', $e->getMessage()), $e);
        }
    }

    public function delete(Template $template): void
    {
        $this->templateResource->delete($template);
    }

    public function deleteById(int $id): void
    {
        $this->delete($this->getById($id));
    }
}
