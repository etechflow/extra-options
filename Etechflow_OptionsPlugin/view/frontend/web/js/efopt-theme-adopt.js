/**
 * Etechflow_OptionsPlugin — theme-adaptive option styling.
 *
 * Goal: when the module is enabled, the product-option UI automatically adopts
 * the colours of WHATEVER theme is running — Luma, Adobe Commerce, Hyvä, or a
 * custom theme — with nothing hard-coded.
 *
 * How it stays theme-agnostic:
 *   1. COLOURS are read at runtime from the live, already-rendered page
 *      (computed styles of the primary button / links / text). Every theme
 *      paints those, so we never depend on a theme's class names for colour.
 *   2. ELEMENTS are found by the `options[...]` input names that Magento emits
 *      identically on every theme — never by theme-specific markup. We tag the
 *      choices with our own classes and style those.
 *
 * Config comes from window.efoptAppearance = {adopt, cards, accent} (injected by
 * product/defaults.phtml). If adopt=false we do nothing.
 */
(function () {
    'use strict';

    var cfg = window.efoptAppearance || { adopt: true, cards: true, accent: '' };
    if (!cfg.adopt) { return; }

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // ── colour helpers ────────────────────────────────────────────────────
    function isPaint(c) {
        return !!c && c !== 'transparent'
            && c !== 'rgba(0, 0, 0, 0)' && c.indexOf('rgba(0,0,0,0') !== 0;
    }
    function parts(c) {
        var m = (c || '').match(/rgba?\(([^)]+)\)/);
        if (!m) { return null; }
        var p = m[1].split(',').map(function (s) { return parseFloat(s.trim()); });
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function rgba(p, a) { return 'rgba(' + p.r + ',' + p.g + ',' + p.b + ',' + a + ')'; }

    // First selector that yields a painted background wins. Ordered from the most
    // reliable "primary action" across themes down to generic CTAs.
    function detectAccent() {
        var sels = [
            '#product-addtocart-button', 'button.action.tocart', '.action.tocart',
            'button.action.primary', '.action.primary', 'a.action.primary',
            '[data-role="add-to-cart"]', 'button[type="submit"].action',
            '.btn-primary', '.button--primary', '.btn.btn-primary'
        ];
        for (var i = 0; i < sels.length; i++) {
            var el = document.querySelector(sels[i]);
            if (el) {
                var bg = getComputedStyle(el).backgroundColor;
                if (isPaint(bg)) { return bg; }
            }
        }
        // Fall back to a content link colour (themes always style links).
        var link = document.querySelector('.product-info-main a, .column.main a, main a, a');
        if (link) {
            var c = getComputedStyle(link).color;
            if (isPaint(c)) { return c; }
        }
        return '';
    }
    function detectText() {
        var el = document.querySelector('.product-info-main, .column.main, main, body');
        var c = el ? getComputedStyle(el).color : '';
        return isPaint(c) ? c : '';
    }
    function detectSurface() {
        var el = document.querySelector('.product-info-main')
            || document.querySelector('.column.main') || document.body;
        while (el) {
            var bg = getComputedStyle(el).backgroundColor;
            if (isPaint(bg)) { return bg; }
            el = el.parentElement;
        }
        return '';
    }

    function applyColours() {
        var root = document.documentElement;
        var accent = cfg.accent || detectAccent();
        var text = detectText();
        var surface = detectSurface();

        if (accent) {
            root.style.setProperty('--efopt-accent', accent);
            var ap = parts(accent);
            if (ap) {
                root.style.setProperty('--efopt-accent-soft', rgba(ap, 0.10));
                root.style.setProperty('--efopt-accent-border', rgba(ap, 0.45));
            }
        }
        if (text) {
            root.style.setProperty('--efopt-text', text);
            var tp = parts(text);
            if (tp) {
                root.style.setProperty('--efopt-border', rgba(tp, 0.16));
                root.style.setProperty('--efopt-muted', rgba(tp, 0.6));
            }
        }
        if (surface) { root.style.setProperty('--efopt-surface', surface); }
    }

    // ── element tagging (theme-agnostic, keyed off option input names) ────
    function choiceContainer(input) {
        return input.closest('.field.choice')
            || input.closest('.choice')
            || input.closest('li')
            || input.closest('label')
            || input.parentElement;
    }

    function tagChoices() {
        var inputs = document.querySelectorAll(
            'input[name^="options["][type="radio"], input[name^="options["][type="checkbox"]'
        );
        if (!inputs.length) { return false; }
        inputs.forEach(function (input) {
            var card = choiceContainer(input);
            if (card && !card.classList.contains('efopt-choice')) {
                card.classList.add('efopt-choice');
                if (cfg.cards) { card.classList.add('efopt-card'); }
                // Tag the wrapping group too, so we can scope spacing.
                var grp = input.closest('.options-list, fieldset, .product-options-wrapper')
                    || card.parentElement;
                if (grp) { grp.classList.add('efopt-themed'); }
            }
        });
        refreshSelected();
        return true;
    }

    function refreshSelected() {
        document.querySelectorAll('.efopt-choice').forEach(function (card) {
            var input = card.querySelector('input[name^="options["]');
            if (input) {
                card.classList.toggle('efopt-choice--selected', !!input.checked);
            }
        });
    }

    function init() {
        if (!tagChoices()) { return; }   // no custom options on this page
        applyColours();
        // Keep the selected-card highlight in sync with any option change.
        var form = document.querySelector('#product_addtocart_form')
            || document.querySelector('form[id^="product_addtocart_form"]')
            || document.body;
        form.addEventListener('change', refreshSelected, true);
        // Configurable/bundle re-renders can inject options later — re-tag once
        // shortly after load to catch them, cheaply and without an observer.
        requestAnimationFrame(function () { tagChoices(); applyColours(); });
    }

    ready(init);
})();
