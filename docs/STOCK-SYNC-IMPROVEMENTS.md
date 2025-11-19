# Stock Sync Improvements

## 📋 Overview

This document describes improvements made to the stock synchronization system to fix recurring errors during product sync operations.

## 🐛 Problems Identified

During product sync operations, two recurring errors were appearing in the logs:

### 1. Stock Update Affecting 0 Rows
```
2025-11-19 03:55:42::[Optimized Sync] WARNING: Stock update for item 348 affected 0 rows (may not exist in item_shops table)
```

**Root Cause:** The sync service was trying to UPDATE stock records in the `xwcpos_item_shops` table, but some products didn't have corresponding records. This happens when:
- Products are added to WooCommerce but not properly imported through the Kounta import process
- The `item_shops` table record was deleted or never created
- Database inconsistencies from previous operations

### 2. Failed WooCommerce Stock Meta Update
```
2025-11-19 03:55:42::[Optimized Sync] ERROR: Failed to update WooCommerce stock meta for product 4450
```

**Root Cause:** The code was using `update_post_meta()` to update stock, which:
- Returns `false` on failure, but also returns the meta ID on success (not a boolean)
- Doesn't properly trigger WooCommerce's stock management hooks
- Doesn't update related fields like `_stock_status`
- Can fail silently if the product doesn't exist

## ✅ Solutions Implemented

### 1. Auto-Create Missing Stock Records

**Before:**
```php
// Try to update
$result = $wpdb->update(
    $wpdb->xwcpos_item_shops,
    array('qoh' => $stock_value),
    array(
        'xwcpos_item_id' => $item->id,
        'shop_id' => $site_id,
    )
);

// Just log a warning if 0 rows affected
if ($result === 0) {
    $this->log('WARNING: Stock update for item ' . $item->id . ' affected 0 rows');
}
```

**After:**
```php
// Try to update first
$result = $wpdb->update(
    $wpdb->xwcpos_item_shops,
    array(
        'qoh' => $stock_value,
        'updated_at' => current_time('mysql')
    ),
    array(
        'xwcpos_item_id' => $item->id,
        'shop_id' => $site_id,
    )
);

// If 0 rows affected, INSERT the record
if ($result === 0) {
    $this->log('INFO: Stock record not found for item ' . $item->id . ', creating new record');
    
    $insert_result = $wpdb->insert(
        $wpdb->xwcpos_item_shops,
        array(
            'xwcpos_item_id' => $item->id,
            'shop_id' => $site_id,
            'qoh' => $stock_value,
            'item_id' => $item->item_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s')
    );
    
    if ($insert_result === false) {
        $this->log('ERROR: Failed to insert stock record - ' . $wpdb->last_error);
    } else {
        $this->log('SUCCESS: Created stock record for item ' . $item->id);
    }
}
```

**Impact:** 
- Automatically creates missing stock records instead of just logging warnings
- Ensures database consistency
- Prevents future sync failures for the same products

---

### 2. Use WooCommerce Stock Management API

**Before:**
```php
// Direct meta update (unreliable)
if ($item->wc_prod_id) {
    $meta_result = update_post_meta($item->wc_prod_id, '_stock', $stock_value);
    if ($meta_result === false) {
        $this->log('ERROR: Failed to update WooCommerce stock meta');
    }
}
```

**After:**
```php
// Use WooCommerce's proper stock management
if ($item->wc_prod_id) {
    $product = wc_get_product($item->wc_prod_id);
    
    if ($product) {
        // Set stock quantity
        $product->set_stock_quantity($stock_value);
        $product->set_manage_stock(true);
        
        // Set stock status based on quantity
        if ($stock_value > 0) {
            $product->set_stock_status('instock');
        } else {
            $product->set_stock_status('outofstock');
        }
        
        // Save the product (triggers all WooCommerce hooks)
        $save_result = $product->save();
        
        if (!$save_result) {
            $this->log('ERROR: Failed to save WooCommerce stock for product ' . $item->wc_prod_id);
        }
    } else {
        $this->log('ERROR: Could not load WooCommerce product ' . $item->wc_prod_id);
    }
}
```

**Impact:**
- Uses WooCommerce's official API instead of direct database manipulation
- Properly triggers WooCommerce hooks (for inventory notifications, etc.)
- Updates both `_stock` and `_stock_status` fields correctly
- Validates that the product exists before attempting updates
- More reliable error detection

---

## 📊 Expected Results

### Before Improvements
- ❌ Stock updates failed silently for products without `item_shops` records
- ❌ WooCommerce stock metadata updates were unreliable
- ❌ Stock status (`instock`/`outofstock`) not updated
- ❌ WooCommerce inventory hooks not triggered

### After Improvements
- ✅ Missing stock records are automatically created
- ✅ WooCommerce stock updates use official API
- ✅ Stock status properly set based on quantity
- ✅ All WooCommerce inventory hooks triggered
- ✅ Better error logging and detection

### Log Messages to Watch For

**Successful Auto-Creation:**
```
[Optimized Sync] INFO: Stock record not found for item 348, creating new record
[Optimized Sync] SUCCESS: Created stock record for item 348 with stock 5
```

**Errors:**
```
[Optimized Sync] ERROR: Failed to insert stock record for item 348 - [database error]
[Optimized Sync] ERROR: Could not load WooCommerce product 4450
[Optimized Sync] ERROR: Failed to save WooCommerce stock for product 4450
```

---

## 🔍 Database Schema

The `xwcpos_item_shops` table structure:

```sql
CREATE TABLE wp_xwcpos_item_shops (
    id int(25) NOT NULL auto_increment,
    xwcpos_item_id varchar(255) NULL,  -- FK to xwcpos_items.id
    shop_id varchar(255) NULL,          -- Kounta site/shop ID
    qoh varchar(255) NULL,              -- Quantity on hand (stock level)
    item_id varchar(255) NULL,          -- Kounta product ID
    updated_at timestamp,
    created_at timestamp,
    PRIMARY KEY (id)
);
```

**Key Relationships:**
- `xwcpos_item_id` → `wp_xwcpos_items.id` (internal ID)
- `shop_id` → Kounta site ID (from settings)
- `item_id` → Kounta product ID
- `qoh` → Stock quantity

---

## 🛠️ Troubleshooting

### Check for Missing Stock Records

```sql
-- Find products with WooCommerce mapping but no stock records
SELECT i.id, i.item_id, i.name, i.wc_prod_id
FROM wp_xwcpos_items i
LEFT JOIN wp_xwcpos_item_shops s ON i.id = s.xwcpos_item_id
WHERE i.wc_prod_id IS NOT NULL
AND s.id IS NULL;
```

### Verify Stock Sync for a Product

```sql
-- Check stock records for a specific WooCommerce product
SELECT 
    i.item_id AS kounta_id,
    i.name,
    i.wc_prod_id,
    s.shop_id,
    s.qoh AS stock_level,
    s.updated_at
FROM wp_xwcpos_items i
LEFT JOIN wp_xwcpos_item_shops s ON i.id = s.xwcpos_item_id
WHERE i.wc_prod_id = 4450;
```

### Check WooCommerce Stock Metadata

```sql
-- Verify WooCommerce stock fields
SELECT 
    p.ID,
    p.post_title,
    stock.meta_value AS stock_quantity,
    status.meta_value AS stock_status
FROM wp_posts p
LEFT JOIN wp_postmeta stock ON p.ID = stock.post_id AND stock.meta_key = '_stock'
LEFT JOIN wp_postmeta status ON p.ID = status.post_id AND status.meta_key = '_stock_status'
WHERE p.ID = 4450;
```

---

## 📝 Files Modified

1. **`includes/class-kounta-sync-service.php`**
   - Added auto-creation of missing `item_shops` records
   - Replaced `update_post_meta()` with WooCommerce stock management API
   - Improved error logging and handling
   - Added `updated_at` timestamp tracking

---

## 🔄 Migration Notes

**No migration required.** The improvements are backward compatible:
- Existing stock records continue to work normally
- Missing records are created automatically during next sync
- WooCommerce products are updated using the official API

**Recommended:** After deploying, run a full product sync to:
1. Create any missing `item_shops` records
2. Ensure all WooCommerce stock levels are correct
3. Verify stock status fields are properly set

---

**Last Updated:** 2024-11-19  
**Version:** 2.0.1

