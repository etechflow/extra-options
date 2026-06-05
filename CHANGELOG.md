# Changelog — EtechFlow Extra Options

All notable changes follow [Semantic Versioning](https://semver.org/).

## [2.0.0] — 2026-06-02

### Added
- **Multi-image upload** — customers can attach up to 10 files per *file*-type custom option.
  - New AJAX endpoint `POST /etechflow/files/upload` (`Controller/Files/Upload.php`)
  - 10 MB per-file cap, allow-list of MIME types (image/* + PDF)
  - Files stored at `pub/media/etechflow/uploads/<hash>/<sanitized-name>` (NOT under the protected `custom_options/` namespace)
  - `Plugin/MultiFileBuyRequest.php` — converts the JSON file list into a `__ETMM_MULTI__:` marker that becomes the option value on the cart item
  - `Plugin/MultiFileDisplay.php` — renders the marker as a clickable file-link list in cart drawer / order summary / admin order / invoice / PDF
  - `Plugin/HyvaMultiFileCartData.php` — Hyvä cart-data provider override that short-circuits before the legacy `unserialize()` path can crash on the marker
  - **Cart UPDATE / reorder preservation** — when `Quote::updateItem` rebuilds the buyRequest and strips the custom `etmm_multi` field, the plugin falls back to `_processing_params.currentConfig` (old buyRequest) OR to an existing `options[<id>]` marker. So the file list survives qty changes, checkout edits, and reorders.

### Added (admin)
- **Templates UI** (eTechFlow → Templates) — Amasty Prot–style Templates with per-category + per-product application.
- **Bulk Price Update** (eTechFlow → Bulk Price Update).
- **Migration Tool** (eTechFlow → Migration Tool).
- DataProvider modifiers for product-edit + category-edit pages.
- ACL resources for each new admin area.

### Added (frontend)
- `ConditionalRequired` plugin — code/image validation + non-matching satellite stripping for the cuttable-product flow.
- `SoftRequiredFile` plugin — suppresses Magento's hard-required error on file options when admin enables the flag.
- Hyvä Checkout override: `deferable-dialog-events.phtml` — suppresses the "your cart is empty" dialog flash between successful order placement and the redirect to the thank-you page.

### DB schema
- `efopt_template`, `efopt_template_option`, `efopt_template_option_value`,
  `efopt_template_category`, `efopt_template_product`, `efopt_sync_queue` — six new tables, all created via `db_schema.xml` declarative schema (Magento 2.3+ schema-XML pattern).

### Compatibility
- Magento 2.4.4 – 2.4.8 (Open Source + Adobe Commerce)
- PHP 8.1, 8.2, 8.3
- Themes: Hyvä, Luma, Adobe Commerce default, custom themes inheriting from either
- Production + Developer modes

---

## [1.0.0] — 2026-04-XX

Initial release with the keyword-classifier-based custom-options Stores → Configuration screen.
