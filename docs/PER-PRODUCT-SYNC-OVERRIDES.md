# Per-Product Sync Overrides

## Overview

Added the ability to disable Kounta sync for specific fields on individual products. This allows admins to customize product information in WooCommerce without it being overwritten by Kounta data during sync.

## Features

### Per-Product Override Checkboxes

Each WooCommerce product now has a "Kounta Sync Overrides" meta box in the sidebar with checkboxes to disable sync for:

- **Image Sync** - Prevent Kounta from updating product images
- **Description Sync** - Prevent Kounta from updating product description
- **Title Sync** - Prevent Kounta from updating product title/name
- **Price Sync** - Prevent Kounta from updating product price

### How It Works

1. **Global Settings** - Admin can enable/disable sync globally in plugin settings
2. **Per-Product Overrides** - Admin can disable sync for specific fields on individual products
3. **Priority** - Per-product overrides take precedence over global settings

**Example:**
- Global setting: "Sync Images" = **Enabled**
- Product #123: "Disable Image Sync" = **Checked**
- Result: All products get image sync EXCEPT product #123

## Use Cases

### Custom Product Images
Upload custom lifestyle photos or professional product shots in WooCommerce without them being replaced by Kounta's images.

### Custom Product Descriptions
Write SEO-optimized, detailed product descriptions in WooCommerce without them being overwritten by Kounta's basic descriptions.

### Custom Product Names
Use different product names for ecommerce (e.g., "Organic Fair Trade Coffee Beans 1kg") vs POS (e.g., "Coffee 1kg").

### Custom Pricing
Set special promotional prices or different pricing tiers in WooCommerce without Kounta overwriting them.

## User Interface

### Location
The "Kounta Sync Overrides" meta box appears in the **right sidebar** on the product edit page, below the "Publish" box.

### Design
- Clean WordPress admin styling
- Clear checkbox labels with descriptions
- Helpful note about stock levels always syncing
- Tooltips explaining each option

### Example
```
┌─────────────────────────────────────────┐
│ Kounta Sync Overrides                   │
├─────────────────────────────────────────┤
│ Check any option below to prevent       │
│ Kounta from updating that field on      │
│ this product, even if global sync       │
│ settings are enabled.                   │
│                                          │
│ ☐ Disable Image Sync                    │
│   Prevent Kounta from updating images   │
│                                          │
│ ☑ Disable Description Sync              │
│   Prevent Kounta from updating desc     │
│                                          │
│ ☐ Disable Title Sync                    │
│   Prevent Kounta from updating title    │
│                                          │
│ ☐ Disable Price Sync                    │
│   Prevent Kounta from updating price    │
│                                          │
│ Note: Stock levels will always sync     │
│ from Kounta regardless of these         │
│ settings.                                │
└─────────────────────────────────────────┘
```

## Technical Implementation

### Meta Fields

Four new post meta fields are stored for each product:

- `_xwcpos_disable_image_sync` - Value: 'yes' or empty
- `_xwcpos_disable_description_sync` - Value: 'yes' or empty
- `_xwcpos_disable_title_sync` - Value: 'yes' or empty
- `_xwcpos_disable_price_sync` - Value: 'yes' or empty

### Sync Logic

Before syncing each field, the system checks:

1. **Global setting enabled?** - e.g., `get_option('xwcpos_sync_images', true)`
2. **Per-product override disabled?** - e.g., `get_post_meta($product_id, '_xwcpos_disable_image_sync', true) === 'yes'`
3. **If override is enabled** - Skip sync and log message
4. **If override is not enabled** - Proceed with sync

### Code Example

```php
// Sync images if enabled (check both global setting and per-product override)
if ($item->wc_prod_id && get_option('xwcpos_sync_images', true)) {
    $disable_image_sync = get_post_meta($item->wc_prod_id, '_xwcpos_disable_image_sync', true);
    if ($disable_image_sync === 'yes') {
        $this->log('Skipping image sync for product ' . $item->wc_prod_id . ' (per-product override enabled)');
    } else {
        // Proceed with image sync
        $this->sync_product_images(...);
    }
}
```

## Logging

When a field is skipped due to per-product override, a log entry is created:

```
2025-11-19 05:30:15::[Optimized Sync] Skipping image sync for product 4138 (per-product override enabled)
2025-11-19 05:30:15::[Optimized Sync] Skipping description sync for product 4138 (per-product override enabled)
```

This helps admins understand why certain products aren't being updated during sync.

## Important Notes

### Stock Levels Always Sync
Stock levels (inventory) will **always** sync from Kounta regardless of these override settings. This ensures accurate stock management.

### Global Settings Still Apply
If a global sync setting is disabled (e.g., "Sync Images" = disabled), the per-product override has no effect since sync is already disabled globally.

### Overrides Are Permanent
Once you check an override box, it stays checked until you manually uncheck it. The setting persists across all future syncs.

## Files Modified

1. **brewhq-kounta.php**
   - Added `xwcpos_add_sync_override_meta_box()` - Registers meta box
   - Added `xwcpos_render_sync_override_meta_box()` - Renders UI
   - Added `xwcpos_save_sync_override_meta()` - Saves checkbox values
   - Added hooks: `add_meta_boxes` and `woocommerce_process_product_meta`

2. **includes/class-kounta-sync-service.php**
   - Updated `sync_product_optimized()` to check per-product overrides
   - Added override checks for image, description, title, and price sync
   - Added logging for skipped fields

## Benefits

✅ **Flexibility** - Customize individual products without affecting global sync
✅ **Control** - Admins decide which products get Kounta data and which don't
✅ **SEO** - Write custom descriptions for better search engine optimization
✅ **Branding** - Use custom images and names for better brand presentation
✅ **Pricing** - Set special prices without Kounta interference
✅ **Transparency** - Clear logging shows which products are being skipped

