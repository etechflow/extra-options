/**
 * Etechflow_OptionsPlugin — defaults, single-mode checkbox, and LIVE price.
 *
 *  - applyDefaults(): pre-select the admin-chosen default value.
 *  - enforceCheckboxModes(): for "tick only one" checkbox groups, clear siblings.
 *  - Live price: recompute the displayed product price as base + the prices of the
 *    currently-selected custom options, read straight from the rendered
 *    data-price-amount attributes. This works on any theme (incl. Luma) without
 *    depending on the theme's own price widget — which wasn't updating here.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    function cssEsc(s) {
        return (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/([^\w-])/g, '\\$1');
    }

    // ── option price reading ──────────────────────────────────────────────
    function optionPrice(input) {
        var label = (input.id && document.querySelector('label[for="' + cssEsc(input.id) + '"]'))
            || input.closest('.field.choice') || input.closest('.choice') || input.parentElement;
        if (!label) { return 0; }
        var pw = label.querySelector('[data-price-amount]');
        if (pw) {
            var v = parseFloat(pw.getAttribute('data-price-amount'));
            if (!isNaN(v)) { return v; }
        }
        // Fallback: parse "+ $10.00" out of the label text.
        var m = (label.textContent || '').match(/\+\s*[^\d.,\-]*([\d.,]+)/);
        return m ? parseFloat(m[1].replace(/,/g, '')) : 0;
    }

    function collectDeltas() {
        var total = 0;
        document.querySelectorAll('input[name^="options"]:checked').forEach(function (i) {
            if (i.type === 'radio' || i.type === 'checkbox') { total += optionPrice(i); }
        });
        document.querySelectorAll('select[name^="options"]').forEach(function (sel) {
            if (sel.selectedIndex < 0) { return; }
            var o = sel.options[sel.selectedIndex];
            if (!o || !o.value) { return; }
            var v = parseFloat(o.getAttribute('data-price-amount'));
            if (!isNaN(v)) { total += v; return; }
            var m = (o.textContent || '').match(/\+\s*[^\d.,\-]*([\d.,]+)/);
            if (m) { total += parseFloat(m[1].replace(/,/g, '')); }
        });
        return total;
    }

    // ── price display element ─────────────────────────────────────────────
    function priceWrapper() {
        return document.querySelector('.product-info-main .price-box .price-wrapper[data-price-amount]')
            || document.querySelector('.ks-price-now-amt')
            || document.querySelector('.product-info-price [data-price-amount]')
            || document.querySelector('[data-role="priceBox"] [data-price-amount]');
    }

    var basePrice = null, curPrefix = '', curSuffix = '';
    function captureBase() {
        var w = priceWrapper();
        if (!w) { return false; }
        var amt = parseFloat(w.getAttribute('data-price-amount'));
        var pe = w.querySelector('.price') || w;
        var txt = (pe.textContent || '').trim();
        if (isNaN(amt)) { amt = parseFloat(txt.replace(/[^0-9.]/g, '')) || 0; }
        basePrice = amt;
        var pm = txt.match(/^([^\d.,\-]+)/);
        var sm = txt.match(/([^\d.,\s]+)\s*$/);
        curPrefix = pm ? pm[1] : '';
        curSuffix = (!pm && sm) ? sm[1] : '';
        return true;
    }
    function renderPrice() {
        var w = priceWrapper();
        if (!w || basePrice == null) { return; }
        var total = basePrice + collectDeltas();
        var pe = w.querySelector('.price') || w;
        pe.textContent = curPrefix + total.toFixed(2) + curSuffix;
    }

    // ── defaults + single-mode (run on every theme) ───────────────────────
    function applyDefaults() {
        var d = window.efoptTemplateDefaults;
        if (!d || typeof d !== 'object') { return; }
        Object.keys(d).forEach(function (optId) {
            var valId = d[optId];
            if (!valId) { return; }
            var sel = document.querySelector('select[name="options[' + optId + ']"]');
            if (sel) {
                var o = sel.querySelector('option[value="' + valId + '"]');
                if (o) { sel.value = valId; sel.dispatchEvent(new Event('change', { bubbles: true })); return; }
            }
            var r = document.querySelector('input[type="radio"][name="options[' + optId + ']"][value="' + valId + '"]');
            if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); return; }
            var c = document.querySelector('input[type="checkbox"][name="options[' + optId + '][]"][value="' + valId + '"]');
            if (c) { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); return; }
        });
    }
    function enforceCheckboxModes() {
        var modes = window.efoptCheckboxModes;
        if (!modes || typeof modes !== 'object') { return; }
        Object.keys(modes).forEach(function (optId) {
            if (modes[optId] !== 'single') { return; }
            var boxes = Array.prototype.slice.call(
                document.querySelectorAll('input[type="checkbox"][name="options[' + optId + '][]"]')
            );
            if (boxes.length < 2) { return; }
            boxes.forEach(function (box) {
                box.addEventListener('change', function () {
                    if (box.checked) {
                        boxes.forEach(function (o) { if (o !== box) { o.checked = false; } });
                        renderPrice();
                    }
                });
            });
        });
    }

    function init() {
        enforceCheckboxModes();
        applyDefaults();
        if (!captureBase()) { return; }   // not a standard PDP with a price box

        var form = document.querySelector('#product_addtocart_form')
            || document.querySelector('form[id^="product_addtocart_form"]')
            || document.body;
        form.addEventListener('change', renderPrice, true);
        form.addEventListener('input', function (e) {
            if (e.target && e.target.matches && e.target.matches('input[name^="options"], textarea[name^="options"]')) {
                renderPrice();
            }
        }, true);
        renderPrice();
    }

    ready(init);
})();
