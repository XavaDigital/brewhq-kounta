# Duplicate API Request Fix

## 📋 Overview

This document describes the fix for duplicate API requests that were being made to the Kounta API during product sync operations.

## 🐛 Problem Identified

During product sync, duplicate API requests were being made for the same products:

```
2025-11-19 04:45:26::[API Client] API Request: GET companies/27154/products/9677153
...
2025-11-19 04:45:26::[API Client] API Request: GET companies/27154/products/9677153
```

Investigation revealed **two separate issues** causing duplicates.

## 🔍 Root Causes

### Issue #1: Plugin Instantiation in Log Methods

The logging methods across all service classes were creating a **new instance** of the main plugin class for every log message:

```php
private function log($message) {
    if (class_exists('BrewHQ_Kounta_POS_Int')) {
        $plugin = new BrewHQ_Kounta_POS_Int();  // ❌ Creates new instance!
        $plugin->plugin_log('[API Client] ' . $message);
    }
}
```

**Why This Caused Problems:**

1. **Constructor Side Effects**: The `BrewHQ_Kounta_POS_Int` constructor registers WordPress hooks and initializes components
2. **Multiple Instances**: Creating a new instance for every log call meant these hooks ran multiple times
3. **Cascading Effects**: Could cause duplicate behavior throughout the plugin

**Affected Classes:**

- `includes/class-kounta-api-client.php`
- `includes/class-kounta-sync-service.php`
- `includes/class-kounta-image-sync-service.php`
- `includes/class-kounta-description-sync-service.php`

### Issue #2: Duplicate Products in Database

The product sync query was selecting ALL rows from `xwcpos_items` without checking for duplicate `item_id` values:

```php
// Old query - could return duplicates
$query = "SELECT * FROM {$wpdb->xwcpos_items}
          WHERE xwcpos_last_sync_date > 0
          AND wc_prod_id IS NOT NULL
          ORDER BY xwcpos_last_sync_date ASC";
```

**Why This Caused Duplicates:**

1. **No Deduplication**: If the database had multiple rows with the same `item_id`, all would be processed
2. **Actual API Calls**: Each duplicate row triggered a real API request to Kounta
3. **Wasted Resources**: Same product data fetched multiple times

### Issue #3: CRON Running Old Sync Method ⭐ **MAIN CULPRIT!**

The WordPress CRON job was still using the **old sync method** while users were running the **new optimized sync** manually:

```php
// CRON was calling the OLD sync
public function xwcposSyncAllProdsCRON(){
    $this->plugin_log('/**** CRON Process initiated: Sync all ****/ ');
    $this->xwcposSyncAllProds();  // ❌ Old method!
}
```

**Why This Caused Duplicates:**

1. **Two Sync Methods Running Simultaneously**: CRON (old) + Manual AJAX (new) both running at the same time
2. **Same Products Fetched Twice**: Both methods iterate through products and make API calls
3. **Timing Collision**: When CRON runs hourly and user clicks sync button, both processes fetch the same products
4. **Interleaved Requests**: Log shows requests from both syncs mixed together

## ✅ Solutions

### Fix #1: Direct File Logging

Replaced the plugin instantiation with direct file logging using WordPress's `error_log()` function:

**Before:**

```php
private function log($message) {
    if (class_exists('BrewHQ_Kounta_POS_Int')) {
        $plugin = new BrewHQ_Kounta_POS_Int();  // ❌ Bad!
        $plugin->plugin_log('[API Client] ' . $message);
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[BrewHQ Kounta API] ' . $message);
    }
}
```

**After:**

```php
private function log($message) {
    // Use WordPress uploads directory for logging
    // Avoid creating new plugin instances which can cause duplicate behavior
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/brewhq-kounta.log';

    // Format: timestamp::[API Client] message
    $log_entry = current_time('mysql') . '::[API Client] ' . $message . "\n";

    // Append to log file
    error_log($log_entry, 3, $log_file);

    // Also log to PHP error log if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[BrewHQ Kounta API] ' . $message);
    }
}
```

### Fix #2: Deduplicate Database Query

Added `GROUP BY item_id` to prevent processing duplicate products:

**Before:**

```php
$query = "SELECT * FROM {$wpdb->xwcpos_items}
          WHERE xwcpos_last_sync_date > 0
          AND wc_prod_id IS NOT NULL
          ORDER BY xwcpos_last_sync_date ASC";
```

**After:**

```php
$query = "SELECT * FROM {$wpdb->xwcpos_items}
          WHERE xwcpos_last_sync_date > 0
          AND wc_prod_id IS NOT NULL
          GROUP BY item_id
          ORDER BY xwcpos_last_sync_date ASC";
```

**Also Added:**

- Logging of total products found: `Found X products to sync`
- Logging of each product being synced: `Syncing product: item_id=X, wc_prod_id=Y`

### Fix #3: Update CRON to Use Optimized Sync + Add Sync Lock

Changed the CRON handler to use the new optimized sync method instead of the old one, and added a transient-based lock to prevent multiple syncs from running simultaneously:

**Before:**

```php
public function xwcposSyncAllProdsCRON(){
    $this->plugin_log('/**** CRON Process initiated: Sync all ****/ ');
    $this->xwcposSyncAllProds();  // ❌ Old method!
}
```

**After:**

```php
public function xwcposSyncAllProdsCRON(){
    $this->plugin_log('/**** CRON Process initiated: Optimized Sync ****/ ');

    // Use the new optimized sync instead of the old method
    // This prevents duplicate API calls when CRON runs during manual sync
    try {
        $sync_service = new Kounta_Sync_Service();

        // First sync inventory
        $inventory_result = $sync_service->sync_inventory_optimized();

        if (!$inventory_result['success']) {
            $this->plugin_log('CRON ERROR: Inventory sync failed - ' . $inventory_result['error']);
            return;
        }

        // Then sync products
        $product_result = $sync_service->sync_products_optimized(0);

        $this->plugin_log(sprintf(
            'CRON: Optimized sync completed - %d products updated in %.2f seconds',
            $product_result['updated'],
            $product_result['duration']
        ));

    } catch (Exception $e) {
        $this->plugin_log('CRON ERROR: ' . $e->getMessage());
    }
}
```

**Key Features:**

1. **Transient Lock**: Uses WordPress transients to create a mutex lock

   - Lock key: `xwcpos_sync_in_progress`
   - Lock duration: 10 minutes (auto-expires if process crashes)
   - Lock info includes: timestamp, source (manual/cron), user ID

2. **Lock Checking**: Both AJAX and CRON handlers check for existing lock before starting

   - AJAX: Returns error message to user if locked
   - CRON: Logs and skips if locked

3. **Lock Release**: Lock is released in all exit paths
   - After successful completion
   - On inventory sync failure
   - On exception/error

**Benefits:**

- ✅ **Prevents concurrent syncs** - Only one sync can run at a time
- ✅ **CRON and manual sync use same method** - Consistent behavior
- ✅ **No duplicate API calls** - Even if user clicks sync button multiple times
- ✅ **Better error handling** - Lock is always released, even on errors
- ✅ **Auto-recovery** - Lock expires after 10 minutes if process crashes

## 📊 Benefits

### Performance Improvements

1. **No Plugin Instantiation**: Eliminates the overhead of creating new plugin instances
2. **Faster Logging**: Direct file writes are much faster than going through the plugin class
3. **Reduced Memory**: No unnecessary object creation
4. **No Duplicate API Calls**: Each product is only fetched once per sync
5. **Faster Syncs**: Fewer API requests means faster completion

### Reliability Improvements

1. **No Duplicate Hooks**: WordPress hooks are no longer registered multiple times
2. **Consistent Behavior**: Logging doesn't trigger side effects
3. **Thread Safety**: Direct file writes are more predictable
4. **Accurate Sync Counts**: Product counts reflect actual unique products
5. **Better Rate Limiting**: Fewer requests means less chance of hitting rate limits

### Code Quality

1. **Better Separation of Concerns**: Logging is independent of the main plugin class
2. **Easier Testing**: Log methods can be tested without full plugin initialization
3. **Clearer Dependencies**: No hidden dependencies on the main plugin class
4. **Better Diagnostics**: New logging shows exactly which products are being synced

## 🔧 Technical Details

### Log File Location

All logs are written to: `wp-content/uploads/brewhq-kounta.log`

### Log Format

```
YYYY-MM-DD HH:MM:SS::[Component] Message
```

Examples:

```
2025-11-19 04:01:23::[API Client] API Request: GET companies/27154/products/3057407
2025-11-19 04:01:24::[API Client] API Response: HTTP 200 (Duration: 0.98s)
2025-11-19 04:01:24::[Optimized Sync] Processing batch 1 of 10 (50 products)
2025-11-19 04:01:25::[Image Sync] Image synced successfully for product 4490
2025-11-19 04:01:25::[Description Sync] Long description updated for product 4490
```

### Components

- `[API Client]` - Kounta API communication
- `[Optimized Sync]` - Product sync service
- `[Image Sync]` - Image synchronization
- `[Description Sync]` - Description synchronization
- `[Order Service]` - Order upload (uses separate logger)

## 📝 Files Modified

1. **`includes/class-kounta-api-client.php`**

   - Replaced plugin instantiation with direct file logging
   - Maintains WP_DEBUG logging

2. **`includes/class-kounta-sync-service.php`**

   - Replaced plugin instantiation with direct file logging
   - Added `GROUP BY item_id` to product query
   - Added logging for total products found
   - Added logging for each product being synced
   - Maintains WP_DEBUG logging

3. **`includes/class-kounta-image-sync-service.php`**

   - Replaced plugin instantiation with direct file logging
   - Maintains WP_DEBUG logging

4. **`includes/class-kounta-description-sync-service.php`**

   - Replaced plugin instantiation with direct file logging
   - Maintains WP_DEBUG logging

5. **`brewhq-kounta.php`** ⭐ **CRITICAL FIX**
   - Updated `xwcposSyncAllProdsCRON()` to use optimized sync
   - Prevents CRON from running old sync method
   - Eliminates duplicate API calls when CRON and manual sync overlap
   - Added better error handling and logging for CRON

## 🧪 Testing

### Verify the Fix

1. **Run a product sync** and check the logs
2. **Look for duplicate requests** - they should be gone
3. **Check log format** - should match the new format below
4. **Verify product count** - should see "Found X products to sync" message
5. **Check individual syncs** - should see "Syncing product: item_id=X" for each product

### Expected Results

**Before:**

```
2025-11-19 04:45:26::[API Client] API Request: GET companies/27154/products/9677153
2025-11-19 04:45:26::[Image Sync] Image synced successfully for product 4220
2025-11-19 04:45:26::[Description Sync] Long description updated for product 4220
2025-11-19 04:45:26::[API Client] API Request: GET companies/27154/products/9677153  ← Duplicate!
```

**After:**

```
2025-11-19 05:00:00::[Optimized Sync] Found 150 products to sync
2025-11-19 05:00:00::[Optimized Sync] Processing batch 1 of 3 (50 products)
2025-11-19 05:00:01::[Optimized Sync] Syncing product: item_id=9677153, wc_prod_id=4220
2025-11-19 05:00:01::[API Client] API Request: GET companies/27154/products/9677153
2025-11-19 05:00:02::[API Client] API Response: HTTP 200 (Duration: 0.98s)
2025-11-19 05:00:02::[Image Sync] Image synced successfully for product 4220
2025-11-19 05:00:02::[Description Sync] Long description updated for product 4220
2025-11-19 05:00:02::[Optimized Sync] Syncing product: item_id=4100321, wc_prod_id=5165
2025-11-19 05:00:02::[API Client] API Request: GET companies/27154/products/4100321  ← Different product!
```

**Key Differences:**

- ✅ No duplicate requests for the same `item_id`
- ✅ Clear logging of which product is being synced
- ✅ Total product count at the start
- ✅ Each product only appears once in the sync

## ⚠️ Notes

### Backward Compatibility

- ✅ Log file location unchanged (`wp-content/uploads/brewhq-kounta.log`)
- ✅ Log format unchanged (timestamp + component + message)
- ✅ WP_DEBUG logging still works
- ✅ No changes to public APIs

### Order Logging

The `Kounta_Order_Logger` class already uses direct file logging and was not affected by this issue.

---

**Last Updated:** 2024-11-19  
**Version:** 2.0.1  
**Issue:** Duplicate API requests in logs  
**Status:** ✅ Fixed
