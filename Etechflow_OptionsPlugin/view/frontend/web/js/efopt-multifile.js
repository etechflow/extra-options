/**
 * Etechflow_OptionsPlugin — multi-image / multi-file uploader (storefront).
 *
 * Enhances every custom-option FILE input (which is what "Image Upload" and
 * "File Upload" sub-fields sync to) into a multi-upload widget:
 *   • pick several files at once
 *   • each uploads immediately to POST /etechflow/files/upload (one per file)
 *   • a thumbnail/preview appears for each, with a × button to remove it
 *   • the collected list is kept as JSON in a hidden field
 *     options_<id>_etmm_multi, which the MultiFileBuyRequest plugin turns into
 *     the cart option value on Add to Cart.
 *
 * Theme-agnostic: it matches by the option file-input name Magento emits, and
 * uses the upload URL + form key already on the page.
 */
(function () {
    'use strict';

    var UPLOAD_URL = window.efoptUploadUrl;
    if (!UPLOAD_URL) { return; }
    var MAX = 10;

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    function formKey() {
        var i = document.querySelector('input[name="form_key"]');
        if (i && i.value) { return i.value; }
        var m = document.cookie.match(/(?:^|;\s*)form_key=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
        });
    }
    function optIdFromName(name) {
        var m = name.match(/options_(\d+)_file/) || name.match(/options\[(\d+)\]/);
        return m ? m[1] : null;
    }

    function enhance(nativeInput) {
        var optId = optIdFromName(nativeInput.name || '');
        if (!optId || nativeInput.dataset.efmf) { return; }
        nativeInput.dataset.efmf = '1';

        var accept = nativeInput.getAttribute('accept') || 'image/*,application/pdf';

        // Hide + disable the native single-file input so it neither shows nor
        // submits — our hidden JSON field carries the uploads instead.
        nativeInput.style.display = 'none';
        nativeInput.disabled = true;

        var files = []; // {name, path, size, mime, url}

        var wrap = document.createElement('div');
        wrap.className = 'efmf';
        wrap.innerHTML =
            '<input type="hidden" name="options_' + optId + '_etmm_multi" value="">'
            + '<div class="efmf-grid"></div>'
            + '<label class="efmf-add"><input type="file" multiple accept="' + esc(accept) + '" hidden>'
            + '<span>+ Add image(s)</span></label>'
            + '<div class="efmf-status"></div>';
        nativeInput.parentNode.insertBefore(wrap, nativeInput.nextSibling);

        var hidden = wrap.querySelector('input[type="hidden"]');
        var grid   = wrap.querySelector('.efmf-grid');
        var picker = wrap.querySelector('.efmf-add input[type="file"]');
        var status = wrap.querySelector('.efmf-status');

        function syncHidden() { hidden.value = files.length ? JSON.stringify(files) : ''; }
        function render() {
            grid.innerHTML = '';
            files.forEach(function (f, idx) {
                var isImg = (f.mime || '').indexOf('image/') === 0;
                var cell = document.createElement('div');
                cell.className = 'efmf-item';
                cell.innerHTML = (isImg
                    ? '<img src="' + esc(f.url) + '" alt="' + esc(f.name) + '">'
                    : '<span class="efmf-file">' + esc(f.name) + '</span>')
                    + '<button type="button" class="efmf-x" title="Remove" data-idx="' + idx + '">&times;</button>';
                grid.appendChild(cell);
            });
            syncHidden();
        }

        grid.addEventListener('click', function (e) {
            var b = e.target.closest('.efmf-x');
            if (!b) { return; }
            files.splice(parseInt(b.dataset.idx, 10), 1);
            render();
        });

        function uploadQueue(list, i) {
            if (i >= list.length) { status.textContent = ''; return; }
            if (files.length >= MAX) { status.textContent = 'Maximum ' + MAX + ' files reached.'; return; }
            status.textContent = 'Uploading ' + (i + 1) + ' of ' + list.length + '…';
            var fd = new FormData();
            fd.append('file', list[i]);
            fd.append('option_id', optId);
            fd.append('existing_count', files.length);
            fd.append('form_key', formKey());
            fetch(UPLOAD_URL, {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        files.push({ name: d.name, path: d.path, size: d.size, mime: d.mime, url: d.url });
                        render();
                    } else {
                        status.textContent = (d && d.error) ? d.error : 'Upload failed.';
                    }
                    uploadQueue(list, i + 1);
                })
                .catch(function () { status.textContent = 'Upload error.'; uploadQueue(list, i + 1); });
        }

        picker.addEventListener('change', function () {
            var list = Array.prototype.slice.call(picker.files || []);
            picker.value = '';
            uploadQueue(list, 0);
        });

        // If this sub-field is conditional and its parent value gets deselected,
        // the wrapping .field is hidden — don't submit the now-irrelevant uploads.
        var container = nativeInput.closest('.field') || wrap.parentElement;
        if (container && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                var isHidden = container.style.display === 'none'
                    || getComputedStyle(container).display === 'none';
                if (isHidden) { hidden.value = ''; } else { syncHidden(); }
            }).observe(container, { attributes: true, attributeFilter: ['style', 'class'] });
        }
    }

    function init() {
        document.querySelectorAll('input[type="file"][name^="options"]').forEach(enhance);
    }
    ready(init);
})();
