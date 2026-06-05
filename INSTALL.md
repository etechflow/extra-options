# Installation guide — EtechFlow Extra Options

Three install methods. Pick the one that matches how your Magento store is managed.

---

## Method 1 — Manual ZIP install (fastest)

Use this if your store is not managed by Composer.

### 1. Extract

```bash
unzip etechflow-extra-options-2.0.0.zip -d /tmp/etmm
```

### 2. Copy into Magento

```bash
cd <magento_root>
mkdir -p app/code/Etechflow
cp -r /tmp/etmm/Etechflow_OptionsPlugin app/code/Etechflow/OptionsPlugin
chown -R <web-user>:<web-user> app/code/Etechflow
```

> The final path must be exactly `app/code/Etechflow/OptionsPlugin/` — Magento's autoloader requires the folder layout to mirror the namespace.

### 3. Enable + install

```bash
cd <magento_root>
bin/magento module:enable Etechflow_OptionsPlugin
bin/magento setup:upgrade
```

`setup:upgrade` will create the five `efopt_*` tables (templates, options, values, category links, product links + sync queue).

### 4. Production-mode finalisation

```bash
bin/magento setup:di:compile           # generates the interceptors — REQUIRED
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Behind Varnish / CDN? Purge them too:
- Varnish: `varnishadm 'ban req.url ~ .'`
- Cloudflare: purge everything

### 5. Verify

- Admin → eTechFlow → Templates → "Add New Template" loads without error.
- Storefront PDP of any cuttable product renders the multi-image upload widget.
- `curl -X POST <store>/etechflow/files/upload -F file=@something.jpg -F option_id=1 -F existing_count=0 -F form_key=<key>` → JSON response.

---

## Method 2 — Composer local repository

```bash
mkdir -p packages
cp -r /path/to/Etechflow_OptionsPlugin packages/etechflow-options-plugin
cd <magento_root>
composer config repositories.etechflow-options-plugin path packages/etechflow-options-plugin
composer require etechflow/module-options-plugin:^2.0
bin/magento module:enable Etechflow_OptionsPlugin
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

---

## Method 3 — Direct Composer package (when published)

```bash
composer require etechflow/module-options-plugin:^2.0
bin/magento module:enable Etechflow_OptionsPlugin
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

---

## Storefront integration

### Multi-image upload UI in your PDP

The module ships the backend pipeline + Hyvä cart-data plugin + storefront-display plugin. For the upload UI itself to render on a product detail page, your theme's product-options template needs to either:

**Option A:** Use a generic file-input that picks up the `<input multiple>` semantics. (Default Magento file input.)

**Option B:** Render the multi-file picker explicitly:

```html
<!-- inside your options.phtml for type='file' options -->
<input type="file"
       multiple
       accept="image/*"
       data-etmm-multi-input="<option_id>"
       @change="etmmUploadFiles($event)">
<input type="hidden"
       :name="'options_<option_id>_etmm_multi'"
       :value="JSON.stringify(etmmFiles)">
```

Plus the supporting Alpine.js methods (`etmmUploadFiles`, `etmmRemoveFile`, state `etmmFiles=[]`, etc.) — see the bundled reference implementation in
`Etechflow_OptionsPlugin/view/frontend/templates/` (if your theme is Hyvä-based).

For Luma, the same pattern works with vanilla JS / jQuery driving fetch() calls to `/etechflow/files/upload`.

---

## Troubleshooting

### "Could not enable Etechflow_OptionsPlugin"

- `app/etc/config.php` may be locked. Check file permissions — must be writable by your web user during install.

### Templates → Add New Template form is blank

- `setup:di:compile` not run. Run it.
- Confirm `bin/magento module:status` shows `Etechflow_OptionsPlugin` enabled.

### Storefront PDP — upload box doesn't appear

- The theme's `options.phtml` for type='file' must include the Alpine bindings shown above.
- Confirm `/etechflow/files/upload` route is registered: `curl -X POST <store>/etechflow/files/upload` should return JSON `{"ok":false,"error":"Missing option_id."}` (NOT 404).

### Uploaded files return 404

- Files are stored at `pub/media/etechflow/uploads/<hash>/<file>`.
- Magento's nginx config blocks `/media/custom_options/` — make sure you're using `/media/etechflow/uploads/` (not custom_options).

### Cart drawer crashes on a multi-file item

- Hyvä's File data-provider unserializes the option value directly. Confirm `Etechflow\OptionsPlugin\Plugin\HyvaMultiFileCartData` is wired in `etc/di.xml` and `setup:di:compile` has been run.

### Order placement loses the file option

- Magento's `Quote::updateItem` strips custom hidden fields. The plugin handles this via `_processing_params.currentConfig` fallback — confirm `MultiFileBuyRequest::beforePrepareForCartAdvanced` is in the active interceptor for `AbstractType` after `setup:di:compile`.

### "Cut from image" / "Cut from code" validation throws even with valid input

- The `ConditionalRequired` plugin matches option titles by keyword. Confirm your option titles include "would you like" + "cut" for the primary, "key code" / "enter code" for the code field, and "upload image" / "image of" for the image file.

---

## Uninstall

```bash
bin/magento module:disable Etechflow_OptionsPlugin
bin/magento setup:upgrade
rm -rf app/code/Etechflow/OptionsPlugin
bin/magento cache:flush
```

The `efopt_*` tables remain in the DB after uninstall (preserves your templates). To drop them too:

```sql
DROP TABLE IF EXISTS efopt_template_option_value;
DROP TABLE IF EXISTS efopt_template_option;
DROP TABLE IF EXISTS efopt_template_product;
DROP TABLE IF EXISTS efopt_template_category;
DROP TABLE IF EXISTS efopt_sync_queue;
DROP TABLE IF EXISTS efopt_template;
```
