# BrewHQ Kounta Integration - Debugging Guide

## Quick Reference for Common Issues

### Product Sync Issues

#### Symptom: Products not syncing
**Check:**
1. Look for `ERROR: Failed to fetch product` in logs
2. Check for `WARNING: Product has no data for site` messages
3. Verify API credentials are valid

**Log Location:** `wp-content/uploads/brewhq-kounta.log`

**Search Pattern:**
```
grep "ERROR.*product" wp-content/uploads/brewhq-kounta.log
grep "sync_single_product" wp-content/uploads/brewhq-kounta.log
```

**Common Causes:**
- Product not assigned to the configured site in Kounta
- Invalid Kounta product ID in database
- API authentication issues
- Network connectivity problems

---

#### Symptom: Stock levels not updating
**Check:**
1. Look for `WARNING: Stock update.*affected 0 rows`
2. Check for `ERROR: Failed to update stock for item`
3. Verify item exists in `xwcpos_item_shops` table

**Database Query:**
```sql
SELECT i.item_id, i.name, s.qoh, s.shop_id 
FROM wp_xwcpos_items i
LEFT JOIN wp_xwcpos_item_shops s ON i.id = s.xwcpos_item_id
WHERE i.wc_prod_id = [PRODUCT_ID];
```

**Common Causes:**
- Item not in `xwcpos_item_shops` table
- Wrong site_id in database
- Database connection issues

---

### Order Upload Issues

#### Symptom: Orders failing to upload
**Check:**
1. Look for `Order upload failed after all retries` in order notes
2. Check `wp-content/uploads/kounta-order-logs/` for detailed logs
3. Review `xwcpos_failed_orders` option in database

**Log Location:** `wp-content/uploads/kounta-order-logs/order-[ORDER_ID]-[DATE].log`

**Search Pattern:**
```
grep "Order upload" wp-content/uploads/brewhq-kounta.log
grep "ERROR.*Order" wp-content/uploads/brewhq-kounta.log
```

**Common Causes:**
- Products in order don't have Kounta product ID mapping
- Customer creation failed
- Invalid payment method mapping
- Network timeout
- Kounta API service issues

---

#### Symptom: Products missing from uploaded order
**Check:**
1. Look for `has no Kounta product ID mapping` in logs
2. Check for `Skipped items` in order preparation logs
3. Verify product has `_xwcpos_item_id` meta

**Database Query:**
```sql
SELECT p.ID, p.post_title, pm.meta_value as kounta_id
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_xwcpos_item_id'
WHERE p.post_type = 'product'
AND pm.meta_value IS NULL;
```

**Common Causes:**
- Product not imported from Kounta
- Product mapping deleted
- Variation product not properly linked

---

#### Symptom: Customer creation failing
**Check:**
1. Look for `Failed to create customer` in logs
2. Check for `has no billing email` warnings
3. Verify customer email is valid

**Log Pattern:**
```
grep "customer" wp-content/uploads/brewhq-kounta.log | grep -i error
```

**Common Causes:**
- Missing or invalid email address
- Duplicate customer in Kounta
- API permission issues

---

### Stock Sync Issues

#### Symptom: Inventory sync fails completely
**Check:**
1. Look for `ERROR: Failed to get inventory` in logs
2. Check for `Database query failed in get_existing_items_map`
3. Verify site_id is configured

**Configuration Check:**
```php
// In WordPress admin or via WP-CLI
get_option('xwcpos_site_id');
get_option('xwcpos_account_id');
```

**Common Causes:**
- Invalid site_id
- API authentication failure
- Database table corruption
- Network issues

---

#### Symptom: Some items not updating
**Check:**
1. Look for `Skipped X inventory items` in logs
2. Check for `not found in local database` messages
3. Verify items exist in both Kounta and local database

**Database Query:**
```sql
-- Find items in Kounta but not in local DB
SELECT item_id FROM wp_xwcpos_items 
WHERE item_id NOT IN (
    SELECT DISTINCT item_id FROM wp_xwcpos_item_shops
);
```

**Common Causes:**
- Items not imported yet
- Items deleted from local database
- Site assignment mismatch

---

## Log Analysis Commands

### Find all errors in last 24 hours
```bash
find wp-content/uploads -name "*.log" -mtime -1 -exec grep -H "ERROR" {} \;
```

### Count errors by type
```bash
grep "ERROR" wp-content/uploads/brewhq-kounta.log | cut -d: -f3 | sort | uniq -c | sort -rn
```

### Find failed orders
```bash
grep "Order upload failed" wp-content/uploads/brewhq-kounta.log | grep -o "Order #[0-9]*"
```

### Check API response times
```bash
grep "Duration:" wp-content/uploads/brewhq-kounta.log | awk '{print $NF}' | sort -n
```

---

## Database Diagnostic Queries

### Check sync status
```sql
SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN xwcpos_last_sync_date > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as synced_last_hour,
    SUM(CASE WHEN wc_prod_id IS NULL THEN 1 ELSE 0 END) as not_imported
FROM wp_xwcpos_items;
```

### Find products with sync issues
```sql
SELECT i.item_id, i.name, i.xwcpos_last_sync_date, i.wc_prod_id
FROM wp_xwcpos_items i
WHERE i.xwcpos_last_sync_date < DATE_SUB(NOW(), INTERVAL 1 DAY)
AND i.wc_prod_id IS NOT NULL
ORDER BY i.xwcpos_last_sync_date ASC
LIMIT 20;
```

### Check failed orders queue
```sql
SELECT option_value 
FROM wp_options 
WHERE option_name = 'xwcpos_failed_orders';
```

---

## Enable Debug Mode

Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

This will enable comprehensive logging to `wp-content/debug.log`.

---

## Getting Help

When reporting issues, include:
1. Relevant log excerpts (last 50 lines around error)
2. Order ID or Product ID affected
3. Database query results for affected items
4. Plugin version and WordPress version
5. Recent configuration changes

