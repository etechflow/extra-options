/**
 * Etechflow_OptionsPlugin — conditional sub-fields on the storefront.
 *
 * A sub-field (configured in a template under a value) must appear ONLY when the
 * customer selects that value, and — if the admin marked it Required — must then
 * be filled before Add to Cart. Magento renders every custom option unconditionally,
 * so this script does the show/hide and the conditional-required toggle.
 *
 * Input: window.efoptConditionalFields, injected by product/defaults.phtml as
 *   [{ sub, parentOption, parentValue, required, type }, ...]
 * where `sub` / `parentOption` are Magento option_ids and `parentValue` is the
 * Magento option_type_id that triggers the sub-field. Theme-agnostic: it matches
 * by the `options[...]` input names Magento emits on every theme.
 */
(function () {
    'use strict';

    var fields = window.efoptConditionalFields;
    if (!Array.isArray(fields) || !fields.length) { return; }

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // Any DOM element that belongs to option <optId>. Covers selectable/text
    // names (options[ID]) AND file-option names (options_ID_file) plus the
    // multi-file widget's hidden field (options_ID_etmm_multi).
    function ctrlEl(optId) {
        return document.querySelector(
            '[name="options[' + optId + ']"], [name="options[' + optId + '][]"], '
            + '[name^="options[' + optId + ']["], '
            + '[name="options_' + optId + '_file"], [name="options_' + optId + '_etmm_multi"]'
        );
    }
    function containerOf(el) {
        return el.closest('.field') || el.closest('.product-options-wrapper > *') || el.parentElement;
    }

    // option_type_ids currently selected for a parent option (select/radio/checkbox).
    function selectedValues(parentOptionId) {
        var vals = [];
        document.querySelectorAll(
            'select[name="options[' + parentOptionId + ']"], select[name="options[' + parentOptionId + '][]"]'
        ).forEach(function (sel) {
            Array.prototype.forEach.call(sel.options, function (o) {
                if (o.selected && o.value) { vals.push(String(o.value)); }
            });
        });
        document.querySelectorAll(
            'input[type="radio"][name="options[' + parentOptionId + ']"]:checked, '
            + 'input[type="checkbox"][name="options[' + parentOptionId + '][]"]:checked'
        ).forEach(function (i) { vals.push(String(i.value)); });
        return vals;
    }

    var registry = [];

    function setShown(entry, shown) {
        var c = entry.container;
        if (!c) { return; }
        if (shown) {
            c.style.display = entry.origDisplay || '';
            if (entry.cf.required) {
                entry.inputs.forEach(function (i) {
                    i.required = true;
                    // Magento's frontend validator keys on this class.
                    if (i.classList) { i.classList.add('required-entry'); }
                });
                c.classList.add('required');
            }
        } else {
            if (c.style.display && c.style.display !== 'none') { entry.origDisplay = c.style.display; }
            c.style.display = 'none';
            entry.inputs.forEach(function (i) {
                i.required = false;
                if (i.classList) { i.classList.remove('required-entry'); }
                if (i.type === 'file') { try { i.value = ''; } catch (e) {} }
                else if (i.type !== 'radio' && i.type !== 'checkbox') { i.value = ''; }
            });
            c.classList.remove('required');
        }
    }

    function evaluate() {
        registry.forEach(function (entry) {
            var sel = selectedValues(entry.cf.parentOption);
            setShown(entry, sel.indexOf(String(entry.cf.parentValue)) >= 0);
        });
    }

    function init() {
        fields.forEach(function (cf) {
            var el = ctrlEl(cf.sub);
            if (!el) { return; }
            var container = containerOf(el);
            var inputs = [];
            if (container) {
                container.querySelectorAll('input, textarea, select').forEach(function (i) { inputs.push(i); });
            }
            if (!inputs.length) { inputs = [el]; }
            // Friendly field types: 'number' → numeric text input; 'image' → file
            // input restricted to images. (Magento stores these as field/file.)
            if (cf.type === 'number') {
                inputs.forEach(function (i) {
                    if (i.tagName === 'INPUT' && i.type === 'text') { i.type = 'number'; i.setAttribute('inputmode', 'decimal'); }
                });
            } else if (cf.type === 'image') {
                inputs.forEach(function (i) {
                    if (i.tagName === 'INPUT' && i.type === 'file') { i.setAttribute('accept', 'image/*'); }
                });
            }
            registry.push({ cf: cf, container: container, inputs: inputs, origDisplay: '' });
        });
        if (!registry.length) { return; }

        var form = document.querySelector('#product_addtocart_form')
            || document.querySelector('form[id^="product_addtocart_form"]')
            || document.body;
        form.addEventListener('change', evaluate, true);

        evaluate(); // initial state — also reveals fields for any pre-selected default
    }

    ready(init);
})();
