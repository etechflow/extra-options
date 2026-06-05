# Usage guide — EtechFlow Extra Options

This guide covers everyday admin use, customer-facing behavior, and developer extension points.

---

## 1. Top-level admin menu

After install, you have a new top-level admin nav: **eTechFlow**.

- **Templates** — list, create, edit, delete option templates.
- **Bulk Price Update** — change values across many products at once.
- **Migration Tool** — convert legacy keyword-config to Templates with backup.

Stores → Configuration → eTechFlow → Extra Options Plugin keeps the original keyword classifier as a fallback for any product not linked to a Template.

---

## 2. Working with Templates

### Create a Template

1. eTechFlow → Templates → **Add New Template**
2. **General** card: Name, Description, Active=Yes
3. **Options** section: click **Add Option** for each option to ship to products
   - Title (e.g. "Would you like us to cut this?")
   - Type (drop_down, radio, checkbox, field, area, file, etc.)
   - Sort order, required flag
   - For selectable types — click each option row to expand and add **Values** (title, price, price_type, SKU, sort_order)
   - For *file*-type options the customer will be able to upload up to 10 images on the storefront (see §5 below)
4. **Apply To** card — cascading picker:
   - **Top-level categories** (column 1) — pick one or more
   - **Sub-categories** (column 2) — pick one or more under the chosen top-level
   - **Sub-sub-categories** (column 3) — drill further if needed
   - **Specific Products** — type to search by name or SKU; products inside picked categories are added automatically
   - The picker enforces "deepest selection wins" — if you pick *Car Keys* AND *Range Rover* (a child of Car Keys), only Range Rover is saved.
5. **Save Template** — on save, the SyncService fires:
   - For ≤ 50 products: synchronous sync (creates real `catalog_product_option` rows on every linked product)
   - For > 50 products: queued, drained by the `efopt_sync` cron in the background
   - Errors per product are logged but don't abort the whole batch

### Edit a Template

- Same form as create, plus:
  - **Sync Status** column shows last-synced timestamp per linked product
  - **Re-sync All Linked Products** button to force-replay
  - Removing a value or option from the template will REMOVE it from every linked product on next save

### Delete a Template

- Confirmation dialog. On confirm, every linked product has its template-synced options REMOVED from the storefront.

### Bulk Price Update

- eTechFlow → Bulk Price Update
- Pick a Template → its option-value table renders
- Edit any value's price → Apply
- All products linked to that template get the new price reflected in `catalog_product_option_type_value`

### Migration Tool

- eTechFlow → Migration Tool
- Phase 1: **Backup** — dumps current keyword-classified options to `var/efopt/migration-backup-<timestamp>.sql`
- Phase 2: **Preview** — shows which products will be grouped into which Templates
- Phase 3: **Run** — creates Templates, links products, sets feature flag
- Phase 4: **Decommission** (optional, after verification) — deletes the legacy keyword config rows

---

## 3. Catalog product edit

On any product's admin edit page, the **Etechflow Option Templates** fieldset shows:

- List of currently-linked Templates (chip per template)
- **Add from template** dropdown to link a new one
- **Re-sync from template** button to replay the most recent template state onto this single product

---

## 4. Catalog category edit

On any category admin edit page, the **Option Templates** tab shows:

- Multi-select of Templates currently linked to this category
- **Apply now** button — triggers a sync for every product in the category (synchronous if ≤ 50, queued otherwise)

---

## 5. Multi-image upload (storefront)

Customers can attach **up to 10 images** to any *file*-type option from the product detail page.

### How it works

1. Customer clicks the upload area on the PDP and selects one or more files (Ctrl/Cmd-click for multiple).
2. JS uploads each file individually to `/etechflow/files/upload` (with form_key auth).
3. The endpoint validates MIME (JPEG, PNG, GIF, WebP, HEIC, HEIF, AVIF, BMP, TIFF, PDF) and size (10 MB cap per file).
4. Each accepted file is stored at `pub/media/etechflow/uploads/<random-hash>/<sanitized-name>` (NOT under the standard `custom_options/` namespace, which Magento's nginx config blocks).
5. The hidden form field `options_<id>_etmm_multi` accumulates the JSON array of saved files.
6. On Add to Cart, the `MultiFileBuyRequest` plugin reads the JSON and stores it on the cart item as `__ETMM_MULTI__:<json>` (a marker value).

### Display

The same marker is rendered as a list of clickable file links in:

- Cart drawer / mini-cart (Hyvä) — image thumbnails (48×48) for image MIMEs, text links for PDFs
- Checkout summary / price-summary cart items
- Customer "My Account" order detail
- Admin → Sales → Orders → View
- Admin → Sales → Invoice
- PDF invoice email attachments

### Cart UPDATE preservation

When a customer changes qty in the cart or summary, Magento's `Quote::updateItem` rebuilds the buyRequest and would normally strip the custom `etmm_multi` field. The plugin handles this:

- **Path A:** New buyRequest has `etmm_multi` JSON → use it.
- **Path B:** New buyRequest is empty but `_processing_params.currentConfig` (the OLD buyRequest) has it → preserve.
- **Path C:** `options[<id>]` already contains the marker (reorder / admin order edit) → reuse.

So the file list survives qty changes, checkout edits, and reorders.

### Storefront markup hooks

If you want to override the look of the upload widget, replicate this pattern in your theme's PDP options template (Hyvä syntax shown):

```html
<input type="file"
       multiple
       accept="image/*,.pdf"
       :disabled="etmmUploading || etmmFiles.length >= 10"
       @change="etmmUploadFiles($event)">
<input type="hidden"
       :name="'options_' + etmmOptionId + '_etmm_multi'"
       :value="JSON.stringify(etmmFiles)">

<ul x-show="etmmFiles.length">
    <template x-for="(f, i) in etmmFiles" :key="f.path">
        <li>
            <a :href="f.url" target="_blank" x-text="f.name"></a>
            <button @click="etmmRemoveFile(i)">×</button>
        </li>
    </template>
</ul>
```

With Alpine state:
```js
{
    etmmFiles: [],
    etmmUploading: false,
    etmmOptionId: <option_id>,
    etmmEndpoint: '/etechflow/files/upload',
    etmmMax: 10,
    async etmmUploadFiles($event) {
        // POST each file individually, accumulate into etmmFiles
    },
    etmmRemoveFile(i) {
        this.etmmFiles.splice(i, 1);
    }
}
```

For Luma, same flow in vanilla JS — the AJAX endpoint and JSON shape are theme-agnostic.

---

## 6. Conditional required validation

For products with a primary dropdown option titled `"Would you like... cut..."` and two satellite options (text field for code + file upload for image), the `ConditionalRequired` plugin enforces:

- **"Cut from code"** chosen → text field must be non-empty (throws error if blank)
- **"Cut from image"** chosen → at least one image must be uploaded
- **"No thanks, I'll cut it myself"** (or anything else) → strips BOTH satellite values so the cart/order doesn't show empty rows for code/image

Plugin matches by case-insensitive title keywords:
- Primary: contains `"would you like"` AND `"cut"`
- Code: contains `"key code"` OR `"enter code"` AND type is `field` or `area`
- Image: contains `"upload image"` OR `"image of"` AND type is `file`

To adjust the matching, edit `Plugin/ConditionalRequired.php` `CHOICE_KEYWORDS_CODE` / `CHOICE_KEYWORDS_IMAGE` constants.

---

## 7. Developer extension points

### Override a plugin
Override any `Etechflow\OptionsPlugin\Plugin\*` class via `di.xml` `preference`.

### Subscribe to sync events
The `Etechflow\OptionsPlugin\Model\SyncService` emits no events in v2.0.0. If you need hooks, wrap the public methods (`syncTemplateToProduct`, `syncTemplateToCategory`, `desyncTemplateFromProduct`) with your own plugin.

### Direct DB integration
The DB schema is in `etc/db_schema.xml`. Tables:
- `efopt_template` — template header
- `efopt_template_option` — options inside a template (supports parent_value_id for sub-options)
- `efopt_template_option_value` — value rows
- `efopt_template_category` — links to categories
- `efopt_template_product` — links to products + Magento option ID mapping
- `efopt_sync_queue` — async sync queue

### Marker prefix
If you need to add another option-value transformer that won't collide:
```php
\Etechflow\OptionsPlugin\Plugin\MultiFileBuyRequest::MARKER === '__ETMM_MULTI__:'
```

Anything other than this prefix is left untouched by the display plugin — your custom values pass through unchanged.

---

## 8. Where to get help

- Issues: `etechflow0@gmail.com`
- Code reference: every file in `Etechflow_OptionsPlugin/` has docblocks explaining its role.
- Logs: `var/log/system.log` for sync warnings, `var/log/exception.log` for hard errors.
