/**
 * Etechflow_OptionsPlugin — frontend live-price + default selection.
 *
 * Runs on PDPs. Two responsibilities:
 *   1. Read window.efoptTemplateDefaults (a {magento_option_id: magento_value_id}
 *      map injected by the theme block) and pre-check the corresponding inputs
 *      on page load.
 *   2. Listen for changes on ANY customizable-option input (select, radio,
 *      checkbox, file, text) and recompute the displayed product price as
 *      base + sum(selected_option_prices). Updates `.ks-price-now-amt`.
 *
 * Why this lives in the extension (not the theme): so toggling the module
 * on/off cleanly enables/disables the live-price behavior without theme edits.
 *
 * Does NOT replace cart/checkout pricing logic — Magento computes the real
 * total on add-to-cart. This is purely visual feedback on the PDP.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function findPriceTarget() {
        // Hyvä theme uses .ks-price-now-amt; fall back to [data-price-amount] if needed.
        return document.querySelector('.ks-price-now-amt')
            || document.querySelector('[data-role="priceBox"] [data-price-amount]');
    }

    function getBasePrice() {
        // Look for a data-base-price attribute on the product info container,
        // OR snapshot the initial displayed price as the base.
        var holder = document.querySelector('[data-product-base-price]');
        if (holder) {
            var v = parseFloat(holder.getAttribute('data-product-base-price'));
            if (!isNaN(v)) return v;
        }
        var target = findPriceTarget();
        if (target) {
            var txt = (target.textContent || target.innerText || '').replace(/[^0-9.,]/g, '').replace(/,/g, '');
            var n = parseFloat(txt);
            if (!isNaN(n)) return n;
        }
        return 0;
    }

    function fmt(amount, currencyPrefix) {
        return (currencyPrefix || '') + Number(amount).toFixed(2);
    }

    function detectCurrencyPrefix(target) {
        if (!target) return '';
        var txt = (target.textContent || '').trim();
        var m = txt.match(/^([^\d.,\s]+)/);
        return m ? m[1] : '';
    }

    function collectOptionDeltas() {
        // Walk all <select name="options[ID]">, radios/checkboxes name="options[ID]"
        // or "options[ID][]", text/file inputs likewise. Sum the price_amount data
        // attribute (Magento sets this on each <option> + radio/checkbox) of the
        // currently-selected ones.
        var total = 0;
        // Selects
        document.querySelectorAll('select[name^="options["]').forEach(function (sel) {
            if (!sel.value) return;
            var opt = sel.options[sel.selectedIndex];
            if (!opt) return;
            var price = parseFloat(opt.getAttribute('data-price-amount'))
                || parseFloat(opt.getAttribute('data-price'))
                || parseFloat((opt.textContent.match(/\+\s*[^\d]*([0-9.]+)/) || [])[1])
                || 0;
            total += price;
        });
        // Radios
        document.querySelectorAll('input[type="radio"][name^="options["]').forEach(function (radio) {
            if (!radio.checked) return;
            var price = parseFloat(radio.getAttribute('data-price-amount'))
                || parseFloat(radio.getAttribute('data-price'))
                || 0;
            // Fall back to a sibling label with [data-price-amount]
            if (!price) {
                var lbl = radio.closest('label') || (radio.parentNode && radio.parentNode.querySelector('[data-price-amount]'));
                if (lbl) {
                    var attr = lbl.getAttribute && lbl.getAttribute('data-price-amount');
                    if (attr) { price = parseFloat(attr) || 0; }
                }
            }
            total += price;
        });
        // Checkboxes
        document.querySelectorAll('input[type="checkbox"][name^="options["]').forEach(function (cb) {
            if (!cb.checked) return;
            var price = parseFloat(cb.getAttribute('data-price-amount'))
                || parseFloat(cb.getAttribute('data-price'))
                || 0;
            total += price;
        });
        // Text/area inputs with a data-price-amount on the row container indicate
        // a flat add-on when non-empty.
        document.querySelectorAll('input[type="text"][name^="options["], textarea[name^="options["]').forEach(function (inp) {
            if (!inp.value || !inp.value.trim()) return;
            var holder = inp.closest('[data-price-amount]');
            if (holder) {
                var price = parseFloat(holder.getAttribute('data-price-amount'));
                if (!isNaN(price)) total += price;
            }
        });
        return total;
    }

    function updatePrice() {
        var target = findPriceTarget();
        if (!target) return;
        if (!window._efoptBase) {
            window._efoptBase = getBasePrice();
            window._efoptCurrencyPrefix = detectCurrencyPrefix(target);
        }
        var delta = collectOptionDeltas();
        var newTotal = window._efoptBase + delta;
        // Preserve any prefix span if present, else just replace text.
        target.textContent = fmt(newTotal, window._efoptCurrencyPrefix);
    }

    function applyDefaults() {
        var defaults = window.efoptTemplateDefaults;
        if (!defaults || typeof defaults !== 'object') return;
        // {magento_option_id: magento_value_id}
        Object.keys(defaults).forEach(function (optId) {
            var valId = defaults[optId];
            if (!valId) return;
            // Select <option> with matching value
            var sel = document.querySelector('select[name="options[' + optId + ']"]');
            if (sel) {
                var opt = sel.querySelector('option[value="' + valId + '"]');
                if (opt) { sel.value = valId; sel.dispatchEvent(new Event('change', {bubbles: true})); return; }
            }
            // Radio with matching value (name="options[ID]", single-select)
            var radio = document.querySelector('input[type="radio"][name="options[' + optId + ']"][value="' + valId + '"]');
            if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', {bubbles: true})); return; }
            // Checkbox with matching value (name="options[ID][]", multi-select)
            var cb = document.querySelector('input[type="checkbox"][name="options[' + optId + '][]"][value="' + valId + '"]');
            if (cb) { cb.checked = true; cb.dispatchEvent(new Event('change', {bubbles: true})); return; }
        });
    }

    function enforceCheckboxModes() {
        // window.efoptCheckboxModes = {magento_option_id: 'single'} — for these
        // option groups the admin chose "tick only one". Magento renders them as
        // native (multi) checkboxes; we make them behave like radios while keeping
        // the square checkbox look: checking one box clears the rest in its group.
        var modes = window.efoptCheckboxModes;
        if (!modes || typeof modes !== 'object') return;
        Object.keys(modes).forEach(function (optId) {
            if (modes[optId] !== 'single') return;
            var sel = 'input[type="checkbox"][name="options[' + optId + '][]"]';
            var boxes = Array.prototype.slice.call(document.querySelectorAll(sel));
            if (boxes.length < 2) return;
            boxes.forEach(function (box) {
                box.addEventListener('change', function () {
                    if (box.checked) {
                        boxes.forEach(function (other) {
                            if (other !== box) { other.checked = false; }
                        });
                        updatePrice();
                    }
                });
            });
        });
    }

    function init() {
        var target = findPriceTarget();
        if (!target) return;          // not a PDP
        window._efoptBase = getBasePrice();
        window._efoptCurrencyPrefix = detectCurrencyPrefix(target);

        // Listen on the product form for any change.
        var form = document.querySelector('form[id^="product_addtocart_form"]')
            || document.querySelector('#product_addtocart_form')
            || document.body;
        form.addEventListener('change', updatePrice, true);
        form.addEventListener('input', function (e) {
            if (e.target && (e.target.matches('input[type="text"][name^="options["]') || e.target.matches('textarea[name^="options["]'))) {
                updatePrice();
            }
        }, true);

        enforceCheckboxModes();
        applyDefaults();
        // One initial recompute in case defaults were applied.
        updatePrice();
    }

    ready(init);
})();
