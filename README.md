# EtechFlow Extra Options for Magento 2

A power-up over Magento's native custom-options system with:

- **Admin Templates UI** — define an option set once, apply to many products or whole categories. Replaces / extends Amasty Prot.
- **Per-category apply** — link a template to a category; every product in that category inherits its options on save.
- **Bulk Price Update** — change a single option-value's price and propagate to every linked product.
- **Migration tool** — convert legacy keyword-based custom-option configs into Templates with backup.
- **Conditional required validation** — "Cut from code" requires the code field; "Cut from image" requires an upload. The non-matching satellites are silently stripped so the cart/order stays clean.
- **Multi-image upload** (up to 10 files per option) — customers can attach multiple reference photos to a file-type option; thumbnails surface in cart drawer + checkout summary + admin order view + invoice. AJAX-based, no jQuery.
- **Hyvä + Luma compatible** — Hyvä-specific data-provider patches + storefront templates work alongside default Luma rendering.

## Distribution contents

```
etechflow extra options/
├── Etechflow_OptionsPlugin/        ← the Magento 2 module
├── README.md                       ← this file
├── INSTALL.md                      ← step-by-step install
├── USAGE.md                        ← admin walkthrough + customization hooks
├── CHANGELOG.md
├── LICENSE.md
└── etechflow-extra-options-2.0.0.zip   ← drop-in ZIP for non-composer installs
```

## Compatibility

| | |
|---|---|
| Magento Open Source | 2.4.4 – 2.4.8 |
| Adobe Commerce | 2.4.4 – 2.4.8 |
| PHP | 8.1, 8.2, 8.3 |
| Themes | **Hyvä** + **Luma** + Adobe Commerce default + custom theme inheriting from either |
| Production / Developer mode | Both supported |

## At a glance

| Area | What ships |
|---|---|
| **Admin** | Top-level *eTechFlow* menu → Templates listing, Add/Edit template form (Options + Apply-To + Sync Status), Bulk Price Update, Migration Tool. Stores → Configuration → eTechFlow → Extra Options Plugin (legacy keyword config as fallback). |
| **Catalog product edit** | DataProvider modifier adds an "Etechflow Option Templates" fieldset. |
| **Catalog category edit** | DataProvider modifier adds an "Option Templates" tab. |
| **Storefront PDP** | Multi-image upload widget (AJAX, up to 10 files); conditional radio-card cut flow. |
| **Storefront cart drawer / checkout summary** | Multi-image option renders as thumbnails / link list. |
| **Admin order / invoice** | Same rendering — list of clickable file links. |
| **Cron / async** | `efopt_sync` queue drains category → product sync in the background for large categories. |

## Quickstart

```bash
unzip etechflow-extra-options-2.0.0.zip
cp -r Etechflow_OptionsPlugin <magento_root>/app/code/Etechflow/OptionsPlugin

cd <magento_root>
bin/magento module:enable Etechflow_OptionsPlugin
bin/magento setup:upgrade
bin/magento setup:di:compile           # required in production mode
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

See **INSTALL.md** for all install methods + troubleshooting, **USAGE.md** for admin walkthrough.

## Routes

| URL | Method | Purpose |
|---|---|---|
| `/etechflow/files/upload` | POST | Storefront AJAX endpoint — accepts one file per call, validates MIME + size (10 MB cap), stores at `pub/media/etechflow/uploads/<hash>/`, returns `{ok, path, name, size, url}`. Used by the multi-image picker. |
| `/efopt/templates/index` | GET (admin) | Templates listing. |
| `/efopt/templates/edit/template_id/<id>` | GET (admin) | Edit / create template. |
| `/efopt/bulkprice/index` | GET (admin) | Bulk price update form. |
| `/efopt/migration/index` | GET (admin) | Migration wizard. |

## Author

EtechFlow · etechflow0@gmail.com

## License

Proprietary — see `LICENSE.md`.
