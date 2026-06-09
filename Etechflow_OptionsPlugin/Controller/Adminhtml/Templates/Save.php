<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Adminhtml\Templates;

use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option as OptionResource;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Collection as OptionCollection;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\CollectionFactory as OptionCollectionFactory;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value as ValueResource;
use Etechflow\OptionsPlugin\Model\ResourceModel\Template\Option\Value\CollectionFactory as ValueCollectionFactory;
use Etechflow\OptionsPlugin\Model\SyncService;
use Etechflow\OptionsPlugin\Model\Template\Option\ValueFactory;
use Etechflow\OptionsPlugin\Model\Template\OptionFactory;
use Etechflow\OptionsPlugin\Model\TemplateRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Save controller handles both header fields (name, is_active, description) AND
 * the nested options + values rows submitted by the dynamic-rows UI on the form.
 *
 * Payload shape (when submitted via UI Component form):
 *   data[template_id]
 *   data[name]
 *   data[is_active]
 *   data[description]
 *   data[options][N][option_id]     ← nullable for new rows
 *   data[options][N][title]
 *   data[options][N][type]
 *   data[options][N][is_required]
 *   data[options][N][sort_order]
 *   data[options][N][price]
 *   data[options][N][values][M][value_id]
 *   data[options][N][values][M][title]
 *   data[options][N][values][M][price]
 *   ...
 */
class Save extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_OptionsPlugin::templates_save';

    public function __construct(
        Context $context,
        private readonly TemplateRepository $templateRepository,
        private readonly OptionFactory $optionFactory,
        private readonly OptionResource $optionResource,
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly ValueFactory $valueFactory,
        private readonly ValueResource $valueResource,
        private readonly ValueCollectionFactory $valueCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly ResourceConnection $resourceConnection,
        private readonly SyncService $syncService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = $this->getRequest()->getPostValue('data', []);
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!is_array($data) || !isset($data['name']) || trim((string)$data['name']) === '') {
            $this->messageManager->addErrorMessage(__('Template name is required.'));
            $this->dataPersistor->set('efopt_template', $data);
            return $redirect->setPath('*/*/edit', ['template_id' => $data['template_id'] ?? null]);
        }

        try {
            // Load or create the template entity.
            $id = (int)($data['template_id'] ?? 0);
            $template = $id
                ? $this->templateRepository->getById($id)
                : $this->templateRepository->create();

            $template->setData('name', trim((string)$data['name']));
            $template->setData('is_active', (int)($data['is_active'] ?? 1));
            $template->setData('description', (string)($data['description'] ?? ''));
            $this->templateRepository->save($template);

            // Options, their values, and the conditional sub-fields nested under
            // each value are all reconciled in one pass (see syncOptionRows →
            // syncValueRows → syncSubFields).
            $this->syncOptionRows((int)$template->getId(), $data['options'] ?? []);

            // The picker posts CSV strings in `category_ids_csv` / `product_ids_csv`.
            // We also support the legacy array form `category_ids[]` / `product_ids[]`
            // for backward compatibility with anything that may still POST that shape.
            $catIds = $this->normalizeIds($data['category_ids_csv'] ?? $data['category_ids'] ?? []);
            $prodIds = $this->normalizeIds($data['product_ids_csv']  ?? $data['product_ids']  ?? []);

            // "Deepest selection wins" — when both a parent category AND one of its
            // descendants are submitted, drop the parent. This matches the warning
            // module's UX: picking BMW after picking Car Keys means the template
            // applies ONLY to BMW, not the whole Car Keys branch.
            $catIds = $this->narrowToDeepest($catIds);

            $this->syncCategoryLinks((int)$template->getId(), $catIds);
            $preserve = !empty($data['preserve_unrendered_products']);
            $this->syncProductLinks(
                (int)$template->getId(),
                $prodIds,
                array_map('intval', (array) ($data['remove_product_ids'] ?? [])),
                $preserve
            );

            // Re-push the (possibly changed) template options to every already-linked
            // product. Without this, changing an option type (e.g. radio → checkbox)
            // only updates efopt_template_option — the catalog_product_option rows on
            // existing products keep the old type and the frontend keeps rendering radio.
            // syncCategoryLinks / syncProductLinks only fire for newly-added links, so
            // existing products were never updated on a plain save.
            if ($id > 0) {
                $this->syncService->resyncAll((int)$template->getId());
            }

            $this->messageManager->addSuccessMessage(__('Template saved.'));
            $this->dataPersistor->clear('efopt_template');

            if ($this->getRequest()->getParam('back') === 'edit') {
                return $redirect->setPath('*/*/edit', ['template_id' => $template->getId()]);
            }
            return $redirect->setPath('*/*/index');
        } catch (CouldNotSaveException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Save failed: %1', $e->getMessage()));
        }

        $this->dataPersistor->set('efopt_template', $data);
        return $redirect->setPath('*/*/edit', ['template_id' => $data['template_id'] ?? null]);
    }

    /**
     * Reconcile submitted options+values against existing rows:
     * - Existing IDs present in payload → update
     * - Missing existing IDs → delete (cascades values)
     * - New rows (no ID) → insert
     */
    private function syncOptionRows(int $templateId, array $optionsPayload): void
    {
        /** @var OptionCollection $existingOptions */
        $existingOptions = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId);
        $existingById = [];
        foreach ($existingOptions as $opt) {
            // Sub-fields (parent_value_id set) are reconciled per-value in
            // syncValueRows → syncSubFields; exclude them here so the top-level
            // delete sweep never removes them.
            if ($opt->getData('parent_value_id')) { continue; }
            $existingById[(int)$opt->getId()] = $opt;
        }

        $seenIds = [];
        $sort = 0;
        foreach ($optionsPayload as $row) {
            if (!is_array($row)) { continue; }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') { continue; } // skip empty rows

            $optionId = isset($row['option_id']) ? (int)$row['option_id'] : 0;
            $option = $optionId && isset($existingById[$optionId])
                ? $existingById[$optionId]
                : $this->optionFactory->create();

            // Space top-level options 1000 apart so each option's conditional
            // sub-fields can slot in right after it (parentSort + 1, +2 …).
            $optSort = ($sort++) * 1000;
            $option->setData('template_id', $templateId);
            $option->setData('sort_order', $optSort);
            $option->setData('title', $title);
            $option->setData('type', (string)($row['type'] ?? 'drop_down'));
            // Checkbox sub-mode: 'single' makes the storefront enforce exactly one
            // tick (radio-like) while keeping the checkbox look. Only meaningful for
            // type=checkbox; stored as 'multi' otherwise so the column is always sane.
            $cbMode = (string)($row['checkbox_mode'] ?? 'multi');
            $option->setData('checkbox_mode', $cbMode === 'single' ? 'single' : 'multi');
            $option->setData('is_required', (int)($row['is_required'] ?? 0));
            $option->setData('price', isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null);
            $option->setData('price_type', (string)($row['price_type'] ?? 'fixed'));
            $option->setData('sku', $row['sku'] ?? null);
            $option->setData('max_characters', isset($row['max_characters']) && $row['max_characters'] !== ''
                ? (int)$row['max_characters'] : null);
            $option->setData('file_extension', $row['file_extension'] ?? null);
            // Reset default_value_id; we'll set it AFTER saving values once we know their IDs.
            $option->setData('default_value_id', null);
            $this->optionResource->save($option);

            $seenIds[(int)$option->getId()] = true;
            // Save values (may assign new IDs to brand-new rows) + their sub-fields.
            $valueIdsByIdx = $this->syncValueRows($templateId, (int)$option->getId(), $row['values'] ?? [], $optSort);

            // Now resolve which value got marked as default via the index posted
            // by the form's radio group.
            $defaultIdx = isset($row['default_value_idx']) && $row['default_value_idx'] !== ''
                ? (int)$row['default_value_idx'] : null;
            if ($defaultIdx !== null && isset($valueIdsByIdx[$defaultIdx])) {
                $option->setData('default_value_id', (int)$valueIdsByIdx[$defaultIdx]);
                $this->optionResource->save($option);
            }
        }

        // Delete options that were removed in the form — and the conditional
        // sub-fields hanging off their values (parent_value_id has no DB cascade).
        foreach ($existingById as $id => $opt) {
            if (!isset($seenIds[$id])) {
                $this->deleteSubFieldsForOption($templateId, (int)$id);
                $this->purgeTemplateOption($opt);
            }
        }
    }

    /**
     * Delete a template option AND first remove every product-side option it was
     * synced to. Order matters: the efopt_template_product FK cascades when the
     * template option row is deleted, so we must clean the products (via the
     * links' magento_option_id) BEFORE deleting the row — otherwise the storefront
     * options are orphaned and stay visible.
     *
     * @param \Etechflow\OptionsPlugin\Model\Template\Option $opt
     */
    private function purgeTemplateOption($opt): void
    {
        $this->syncService->removeTemplateOptionEverywhere((int)$opt->getId());
        $this->optionResource->delete($opt);
    }

    /**
     * Persist the value rows for an option and return a map of submitted-index
     * → resolved value_id. The form posts a separate `default_value_idx` radio
     * pointing at one of these indices; the caller uses this map to translate
     * that index into the real value_id stored on the option.
     *
     * @return array<int,int>
     */
    private function syncValueRows(int $templateId, int $optionId, array $valuesPayload, int $parentSort = 0): array
    {
        $existing = $this->valueCollectionFactory->create()
            ->addFieldToFilter('template_option_id', $optionId);
        $existingById = [];
        foreach ($existing as $val) {
            $existingById[(int)$val->getId()] = $val;
        }

        $idsByIdx = [];
        $seenIds = [];
        $sort = 0;
        // Use submitted index keys so the default_value_idx radio can map back.
        foreach ($valuesPayload as $idx => $row) {
            if (!is_array($row)) { continue; }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') { continue; }

            $valueId = isset($row['value_id']) ? (int)$row['value_id'] : 0;
            $value = $valueId && isset($existingById[$valueId])
                ? $existingById[$valueId]
                : $this->valueFactory->create();

            $value->setData('template_option_id', $optionId);
            $value->setData('sort_order', $sort++);
            $value->setData('title', $title);
            $value->setData('price', (float)($row['price'] ?? 0));
            $value->setData('price_type', (string)($row['price_type'] ?? 'fixed'));
            $value->setData('sku', $row['sku'] ?? null);
            $this->valueResource->save($value);

            $idsByIdx[(int)$idx] = (int)$value->getId();
            $seenIds[(int)$value->getId()] = true;

            // Reconcile the conditional sub-fields attached to THIS value.
            $this->syncSubFields($templateId, (int)$value->getId(), $row['sub_fields'] ?? [], $parentSort);
        }

        foreach ($existingById as $id => $val) {
            if (!isset($seenIds[$id])) {
                // Drop the value AND any sub-fields keyed to it (no FK cascade).
                $this->deleteSubFieldsForValue($templateId, (int)$id);
                $this->valueResource->delete($val);
            }
        }
        return $idsByIdx;
    }

    /**
     * Create / update / delete the conditional sub-fields attached to ONE value.
     * Each sub-field is an efopt_template_option row with parent_value_id set to
     * the value's id and a type of field|area|file. On the storefront it is shown
     * only when this value is selected (see the frontend conditional JS), and is
     * enforced as required-when-shown if the admin ticked Required.
     *
     * @param array<int,mixed> $subFieldsPayload
     */
    private function syncSubFields(int $templateId, int $valueId, array $subFieldsPayload, int $parentSort = 0): void
    {
        $existing = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->addFieldToFilter('parent_value_id', $valueId);
        $existingById = [];
        foreach ($existing as $opt) {
            $existingById[(int)$opt->getId()] = $opt;
        }

        $seen = [];
        $sort = 0;
        foreach ($subFieldsPayload as $row) {
            if (!is_array($row)) { continue; }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') { continue; }

            // Sub-fields are input types only (no drop-down/radio/checkbox). The
            // friendly types 'number' and 'image' are stored as-is on the template
            // and translated to real Magento types (field / file) at sync time.
            $type = (string)($row['type'] ?? 'field');
            if (!in_array($type, ['field', 'number', 'area', 'file', 'image', 'date', 'time', 'date_time'], true)) {
                $type = 'field';
            }
            $isSelectable = false;

            $optionId = isset($row['option_id']) ? (int)$row['option_id'] : 0;
            $opt = $optionId && isset($existingById[$optionId])
                ? $existingById[$optionId]
                : $this->optionFactory->create();

            $opt->setData('template_id', $templateId);
            $opt->setData('parent_value_id', $valueId);
            // Slot the sub-field right AFTER its parent option (parentSort + 1, +2 …)
            // so the conditional field appears directly below its own dropdown,
            // not at the very bottom of the option list.
            $opt->setData('sort_order', $parentSort + 1 + $sort++);
            $opt->setData('title', $title);
            $opt->setData('type', $type);
            $opt->setData('is_required', (int)($row['is_required'] ?? 0));
            $opt->setData('price', isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null);
            $opt->setData('price_type', 'fixed');
            $this->optionResource->save($opt);
            $seen[(int)$opt->getId()] = true;

            // A selectable sub-field (e.g. its own drop-down) carries choices;
            // persist them, and clear any leftover choices when it isn't selectable.
            $this->saveSubFieldValues(
                (int)$opt->getId(),
                $isSelectable ? ($row['sub_values'] ?? []) : []
            );
        }

        foreach ($existingById as $id => $opt) {
            if (!isset($seen[$id])) {
                $this->purgeTemplateOption($opt);
            }
        }
    }

    /**
     * Persist the choices of a selectable sub-field (efopt_template_option_value
     * rows keyed to the sub-field's own option_id). Mirrors syncValueRows but
     * never recurses into further sub-fields — sub-field choices are leaves.
     *
     * @param array<int,mixed> $valuesPayload
     */
    private function saveSubFieldValues(int $subOptionId, array $valuesPayload): void
    {
        $existing = $this->valueCollectionFactory->create()
            ->addFieldToFilter('template_option_id', $subOptionId);
        $existingById = [];
        foreach ($existing as $val) {
            $existingById[(int)$val->getId()] = $val;
        }

        $seen = [];
        $sort = 0;
        foreach ($valuesPayload as $row) {
            if (!is_array($row)) { continue; }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') { continue; }

            $valueId = isset($row['value_id']) ? (int)$row['value_id'] : 0;
            $value = $valueId && isset($existingById[$valueId])
                ? $existingById[$valueId]
                : $this->valueFactory->create();

            $value->setData('template_option_id', $subOptionId);
            $value->setData('sort_order', $sort++);
            $value->setData('title', $title);
            $value->setData('price', (float)($row['price'] ?? 0));
            $value->setData('price_type', 'fixed');
            $this->valueResource->save($value);
            $seen[(int)$value->getId()] = true;
        }

        foreach ($existingById as $id => $val) {
            if (!isset($seen[$id])) {
                $this->valueResource->delete($val);
            }
        }
    }

    /** Delete all sub-fields attached to a single value. */
    private function deleteSubFieldsForValue(int $templateId, int $valueId): void
    {
        $subs = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->addFieldToFilter('parent_value_id', $valueId);
        foreach ($subs as $opt) {
            $this->purgeTemplateOption($opt);
        }
    }

    /** Delete all sub-fields attached to any value of a (being-deleted) option. */
    private function deleteSubFieldsForOption(int $templateId, int $optionId): void
    {
        $valueIds = array_map('intval', $this->valueCollectionFactory->create()
            ->addFieldToFilter('template_option_id', $optionId)
            ->getAllIds());
        if (!$valueIds) { return; }
        $subs = $this->optionCollectionFactory->create()
            ->addFieldToFilter('template_id', $templateId)
            ->addFieldToFilter('parent_value_id', ['in' => $valueIds]);
        foreach ($subs as $opt) {
            $this->purgeTemplateOption($opt);
        }
    }

    /**
     * Update sub-option rows posted from the admin form. Each entry is keyed by
     * the option_id of an existing efopt_template_option row that has its
     * parent_value_id set. We just update the editable scalar fields — no
     * insert/delete logic here (sub-options can only be created via the
     * separate "Add sub-field" flow, future work).
     */
    private function syncSubOptions(int $templateId, array $subPayload): void
    {
        if (!$subPayload) { return; }
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('efopt_template_option');
        foreach ($subPayload as $optionId => $row) {
            $optionId = (int) $optionId;
            if (!$optionId || !is_array($row)) { continue; }
            $conn->update($table, [
                'title'           => trim((string)($row['title'] ?? '')),
                'type'            => (string)($row['type'] ?? 'field'),
                'is_required'     => (int)($row['is_required'] ?? 0),
                'price'           => isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null,
                'parent_value_id' => isset($row['parent_value_id']) && $row['parent_value_id'] !== ''
                    ? (int)$row['parent_value_id'] : null,
            ], [
                'option_id = ?'   => $optionId,
                'template_id = ?' => $templateId, // safety: don't update foreign rows
            ]);
        }
    }

    /**
     * Accept either an array of int IDs or a CSV string. Used by the picker
     * (which posts CSV via hidden inputs).
     */
    private function normalizeIds($input): array
    {
        if (is_string($input)) {
            $input = $input === '' ? [] : array_map('trim', explode(',', $input));
        }
        if (!is_array($input)) { return []; }
        return array_values(array_filter(array_map('intval', $input)));
    }

    /**
     * Drop any category whose descendant is also in the selection — copied
     * from Keystation/ProductWarning/Save::narrowToDeepest. Lets the admin
     * pick "BMW" after "Car Keys" and have ONLY BMW applied on save.
     */
    private function narrowToDeepest(array $catIds): array
    {
        if (count($catIds) < 2) { return $catIds; }
        $conn = $this->resourceConnection->getConnection();
        $rows = $conn->fetchPairs(
            $conn->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity'), ['entity_id', 'path'])
                ->where('entity_id IN (?)', $catIds)
        );
        $kept = [];
        foreach ($catIds as $id) {
            if (!isset($rows[$id])) { continue; }
            $myPath = (string) $rows[$id];
            $isAncestor = false;
            foreach ($catIds as $otherId) {
                if ($otherId === $id || !isset($rows[$otherId])) { continue; }
                if (str_starts_with((string) $rows[$otherId], $myPath . '/')) {
                    $isAncestor = true;
                    break;
                }
            }
            if (!$isAncestor) { $kept[] = (int) $id; }
        }
        return $kept;
    }

    /**
     * Reconcile efopt_template_category links. For added categories, fan-out
     * sync to all products in that category via SyncService. For removed,
     * desync the products that were linked only via this category.
     */
    private function syncCategoryLinks(int $templateId, array $categoryIds, array $removeIds = []): void
    {
        $conn = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('efopt_template_category');
        $productLinkTable = $this->resourceConnection->getTableName('efopt_template_product');

        $desired = array_unique(array_map('intval', array_filter($categoryIds)));
        $current = array_map('intval', $conn->fetchCol(
            $conn->select()->from($linkTable, 'category_id')->where('template_id = ?', $templateId)
        ));
        $toAdd = array_diff($desired, $current);
        // Explicit removals (× on a chip) plus any current that are gone from desired AND were in desired
        // before — for category links we keep the simpler "diff" semantics: any current id not in desired
        // is removed. Plus explicit removeIds for chip-based UX consistency.
        $toRemove = array_unique(array_merge(array_diff($current, $desired), $removeIds));

        foreach ($toAdd as $cid) {
            $conn->insert($linkTable, ['template_id' => $templateId, 'category_id' => $cid]);
            // Wrap sync so a per-product failure doesn't silently lose the link.
            // Errors get logged via Magento's messageManager (notice) AND error log,
            // so admins can diagnose if products fail to receive options.
            try {
                $synced = $this->syncService->syncTemplateToCategory($templateId, $cid);
                if ($synced === 0) {
                    $this->messageManager->addNoticeMessage(
                        __('Category %1 has no products to sync (template options will apply when products are added).', $cid)
                    );
                }
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage(
                    __('Template saved, but sync to category %1 failed: %2 — products may not show the options yet. Use "Re-sync All Linked Products" to retry.', $cid, $e->getMessage())
                );
                error_log('[efopt sync] category ' . $cid . ' template ' . $templateId . ' failed: ' . $e->getMessage());
            }
        }
        if ($toRemove) {
            // Desync products that were linked ONLY via these removed categories.
            $productIds = $conn->fetchCol(
                $conn->select()->from($productLinkTable, 'product_id')
                    ->where('template_id = ?', $templateId)
                    ->where('source = ?', 'category')
                    ->where('source_category_id IN (?)', $toRemove)
                    ->distinct()
            );
            foreach ($productIds as $pid) {
                $this->syncService->desyncTemplateFromProduct($templateId, (int)$pid);
            }
            $conn->delete($linkTable, ['template_id = ?' => $templateId, 'category_id IN (?)' => $toRemove]);
        }
    }

    /**
     * Add the submitted product IDs as direct links, then desync the explicit
     * removeIds. When $preserveUnrendered is true (form had >100 products, only
     * the first 100 rendered as chips), we DO NOT touch any direct link whose
     * id is neither in $productIds nor $removeIds — those products keep their
     * link untouched.
     */
    private function syncProductLinks(
        int $templateId,
        array $productIds,
        array $removeIds = [],
        bool $preserveUnrendered = true
    ): void {
        $ids = array_unique(array_map('intval', array_filter($productIds)));
        foreach ($ids as $pid) {
            $this->syncService->syncTemplateToProduct($templateId, $pid, 'direct');
        }
        foreach (array_unique(array_map('intval', array_filter($removeIds))) as $rid) {
            $this->syncService->desyncTemplateFromProduct($templateId, $rid);
        }
        // (Future: if $preserveUnrendered === false, we could remove every direct
        // link not in $productIds. Current UX always preserves to avoid mass
        // accidental unlinking from large templates.)
    }
}
