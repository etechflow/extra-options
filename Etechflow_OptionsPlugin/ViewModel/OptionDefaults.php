<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\ViewModel;

use Etechflow\OptionsPlugin\Model\Config;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * ViewModel exposed to the frontend product page. Computes the default
 * radio/select selection for the current product by joining:
 *
 *   efopt_template_product  (template ⇄ product map)
 *     → efopt_template_option  (which has default_value_id)
 *     → efopt_template_option_value  (lookup the title of the default value)
 *
 * Then matches that title to the product's actual catalog_product_option_type_value
 * row, so the frontend JS knows: "on product 5030, pre-check value 12733 of option 15321".
 */
class OptionDefaults implements ArgumentInterface
{
    public function __construct(
        private readonly CatalogHelper $catalogHelper,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config
    ) {}

    /**
     * Appearance settings handed to the frontend JS so it knows whether to adopt
     * theme colours, whether to draw cards, and any manual accent override.
     *
     * @return array{adopt:bool,cards:bool,accent:string}
     */
    public function getAppearance(): array
    {
        return [
            'adopt'  => $this->config->isAdoptThemeColors(),
            'cards'  => $this->config->isCardLayout(),
            'accent' => $this->config->getAccentColor(),
        ];
    }

    /**
     * @return array<int,int> magento_option_id → magento_option_type_id to default-select
     */
    public function getDefaults(): array
    {
        $product = $this->catalogHelper->getProduct();
        if (!$product || !$product->getId()) { return []; }

        $conn = $this->resourceConnection->getConnection();
        $linkTable      = $this->resourceConnection->getTableName('efopt_template_product');
        $tplOptTable    = $this->resourceConnection->getTableName('efopt_template_option');
        $tplValTable    = $this->resourceConnection->getTableName('efopt_template_option_value');
        $cpovTable      = $this->resourceConnection->getTableName('catalog_product_option_type_value');
        $cpovTitleTable = $this->resourceConnection->getTableName('catalog_product_option_type_title');

        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['ep' => $linkTable], ['magento_option_id'])
                ->join(
                    ['eo' => $tplOptTable],
                    'ep.template_option_id = eo.option_id',
                    ['default_value_id']
                )
                ->join(
                    ['ev' => $tplValTable],
                    'eo.default_value_id = ev.value_id',
                    ['default_value_title' => 'title']
                )
                ->where('ep.product_id = ?', (int)$product->getId())
                ->where('ep.magento_option_id IS NOT NULL')
                ->where('eo.default_value_id IS NOT NULL')
        );

        if (!$rows) { return []; }

        // Resolve the matching catalog_product_option_type_value.option_type_id
        // by title (matching the same way SyncService does).
        $defaults = [];
        foreach ($rows as $r) {
            $magOptionId = (int) $r['magento_option_id'];
            $title       = (string) $r['default_value_title'];
            $optionTypeId = (int) $conn->fetchOne(
                $conn->select()
                    ->from(['v' => $cpovTable], ['option_type_id'])
                    ->join(
                        ['t' => $cpovTitleTable],
                        'v.option_type_id = t.option_type_id AND t.store_id = 0',
                        []
                    )
                    ->where('v.option_id = ?', $magOptionId)
                    ->where('LOWER(t.title) = ?', mb_strtolower(trim($title)))
                    ->limit(1)
            );
            if ($optionTypeId) {
                $defaults[$magOptionId] = $optionTypeId;
            }
        }
        return $defaults;
    }

    /**
     * Map of magento_option_id → 'single' for every synced checkbox option on the
     * current product whose template is set to single-selection mode. The frontend
     * JS uses this to enforce "tick only one" on those checkbox groups while keeping
     * the checkbox appearance.
     *
     * @return array<int,string>
     */
    public function getCheckboxModes(): array
    {
        $product = $this->catalogHelper->getProduct();
        if (!$product || !$product->getId()) { return []; }

        $conn = $this->resourceConnection->getConnection();
        $rows = $conn->fetchCol(
            $conn->select()
                ->from(['ep' => $this->resourceConnection->getTableName('efopt_template_product')], ['magento_option_id'])
                ->join(
                    ['eo' => $this->resourceConnection->getTableName('efopt_template_option')],
                    'ep.template_option_id = eo.option_id',
                    []
                )
                ->where('ep.product_id = ?', (int)$product->getId())
                ->where('ep.magento_option_id IS NOT NULL')
                ->where('eo.type = ?', 'checkbox')
                ->where('eo.checkbox_mode = ?', 'single')
        );

        $modes = [];
        foreach ($rows as $optionId) {
            $modes[(int)$optionId] = 'single';
        }
        return $modes;
    }

    /**
     * Conditional sub-fields for the current product. Each entry tells the
     * storefront JS: "show option <sub> only when option <parentOption> has value
     * <parentValue> selected; make it required-when-shown if <required>".
     *
     * Resolves the template linkage (efopt_template_option.parent_value_id →
     * value → its option) down to the real Magento option_id / option_type_id on
     * THIS product, so the JS can match by input name + value.
     *
     * @return array<int,array{sub:int,parentOption:int,parentValue:int,required:bool,type:string}>
     */
    public function getConditionalFields(): array
    {
        $product = $this->catalogHelper->getProduct();
        if (!$product || !$product->getId()) { return []; }

        $conn = $this->resourceConnection->getConnection();
        $pid  = (int)$product->getId();

        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['ep_sub' => $this->resourceConnection->getTableName('efopt_template_product')],
                    ['sub' => 'ep_sub.magento_option_id'])
                ->join(
                    ['eo_sub' => $this->resourceConnection->getTableName('efopt_template_option')],
                    'ep_sub.template_option_id = eo_sub.option_id',
                    ['required' => 'eo_sub.is_required', 'type' => 'eo_sub.type', 'parent_value_id' => 'eo_sub.parent_value_id']
                )
                ->join(
                    ['ev' => $this->resourceConnection->getTableName('efopt_template_option_value')],
                    'ev.value_id = eo_sub.parent_value_id',
                    ['value_title' => 'ev.title']
                )
                ->join(
                    ['ep_par' => $this->resourceConnection->getTableName('efopt_template_product')],
                    'ep_par.template_option_id = ev.template_option_id AND ep_par.product_id = ep_sub.product_id',
                    ['parentOption' => 'ep_par.magento_option_id']
                )
                ->where('ep_sub.product_id = ?', $pid)
                ->where('eo_sub.parent_value_id IS NOT NULL')
                ->where('ep_sub.magento_option_id IS NOT NULL')
                ->where('ep_par.magento_option_id IS NOT NULL')
        );
        if (!$rows) { return []; }

        $cpovTable      = $this->resourceConnection->getTableName('catalog_product_option_type_value');
        $cpovTitleTable = $this->resourceConnection->getTableName('catalog_product_option_type_title');

        $out = [];
        foreach ($rows as $r) {
            $parentOptionId = (int)$r['parentOption'];
            // Resolve the parent value's Magento option_type_id by title.
            $optionTypeId = (int)$conn->fetchOne(
                $conn->select()
                    ->from(['v' => $cpovTable], ['option_type_id'])
                    ->join(
                        ['t' => $cpovTitleTable],
                        'v.option_type_id = t.option_type_id AND t.store_id = 0',
                        []
                    )
                    ->where('v.option_id = ?', $parentOptionId)
                    ->where('LOWER(t.title) = ?', mb_strtolower(trim((string)$r['value_title'])))
                    ->limit(1)
            );
            if (!$optionTypeId) { continue; }
            $out[] = [
                'sub'          => (int)$r['sub'],
                'parentOption' => $parentOptionId,
                'parentValue'  => $optionTypeId,
                'required'     => (bool)(int)$r['required'],
                'type'         => (string)$r['type'],
            ];
        }
        return $out;
    }
}
