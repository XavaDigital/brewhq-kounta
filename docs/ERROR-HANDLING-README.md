# Error Handling & Logging - BrewHQ Kounta Integration

## 📋 Overview

This document provides a comprehensive reference for the error handling and logging improvements implemented across the BrewHQ Kounta integration plugin. These improvements ensure that bugs and failures are properly logged with sufficient context for debugging.

## 🎯 Critical Areas Covered

The following three critical areas have been thoroughly reviewed and enhanced:

1. **Product Sync** - Syncing products from Kounta to WooCommerce
2. **Order Upload** - Uploading WooCommerce orders to Kounta POS
3. **Stock Sync** - Syncing inventory levels between systems

## 📁 Files Modified

### Core Sync Services
- `includes/class-kounta-sync-service.php` - Product and inventory sync with comprehensive error handling
- `includes/class-kounta-order-service.php` - Order upload with detailed logging
- `includes/class-kounta-api-client.php` - API client with request/response logging
- `includes/class-kounta-batch-processor.php` - Batch operations with error tracking

## 🔍 What Was Improved

### 1. Product Sync (`class-kounta-sync-service.php`)

#### Error Handling Added
✅ Database error checking for all `$wpdb` operations  
✅ Validation of inventory item data (ID, stock values)  
✅ API response validation (null/empty checks)  
✅ Exception handling with try-catch blocks  
✅ Database update verification (check affected rows)  

#### Logging Added
✅ Detailed batch processing results with error details  
✅ Skipped items tracking with reasons  
✅ API failure logging with specific error messages  
✅ Database operation failures with SQL errors  
✅ Sync progress summaries (updated, skipped, failed counts)  

**Example Log Output:**
```
[2024-01-15 10:23:45] ERROR: Failed to fetch product 12345 from Kounta API - Connection timeout
[2024-01-15 10:23:46] WARNING: Product 67890 has no data for site ABC123
[2024-01-15 10:23:47] ERROR: Failed to update stock for item 456 in database - Duplicate entry
[2024-01-15 10:23:50] INFO: Batch completed - Updated: 95, Skipped: 3, Errors: 2
```

### 2. Order Upload (`class-kounta-order-service.php`)

#### Error Handling Added
✅ Customer creation validation and error logging  
✅ Order item validation (product mappings, quantities)  
✅ Missing product mapping detection  
✅ Shipping configuration validation  
✅ Empty order item detection  

#### Logging Added
✅ Customer search and creation results  
✅ Skipped order items with product details  
✅ Shipping handling and configuration warnings  
✅ Order preparation details (item count, total)  
✅ Comprehensive failure context  

**Example Log Output:**
```
[2024-01-15 11:30:12] INFO: Found existing customer: CUST-789 for email: customer@example.com
[2024-01-15 11:30:13] WARNING: Product 123 (Premium Coffee) has no Kounta product ID mapping
[2024-01-15 11:30:14] WARNING: Order 5678 has shipping ($15.00) but no shipping product ID configured
[2024-01-15 11:30:15] ERROR: Order 5678 has 3 items without Kounta mapping
```

### 3. Stock Sync (`class-kounta-sync-service.php`)

#### Error Handling Added
✅ Inventory item validation (missing IDs, stock values)  
✅ Database update verification  
✅ Update count mismatch detection  
✅ Skipped item tracking  

#### Logging Added
✅ Items not found in local database  
✅ Invalid data warnings (missing fields)  
✅ Update result summaries  
✅ Skipped item counts  

**Example Log Output:**
```
[2024-01-15 12:15:20] WARNING: Inventory item missing ID, skipping
[2024-01-15 12:15:21] WARNING: Inventory item 789 missing stock value, defaulting to 0
[2024-01-15 12:15:25] INFO: Inventory sync complete - Updated: 450, Skipped: 12
[2024-01-15 12:15:25] WARNING: 3 inventory updates failed. Expected: 453, Actual: 450
```

### 4. API Client (`class-kounta-api-client.php`)

#### Error Handling Added
✅ Request timing/duration tracking  
✅ JSON decode error detection  
✅ HTTP status code inclusion in errors  
✅ Token refresh logging  
✅ Credential validation  

#### Logging Added
✅ All API requests (method, endpoint, duration)  
✅ Rate limiting events and retry delays  
✅ Authentication and token refresh attempts  
✅ Server error context (5xx errors)  
✅ Response status codes  

**Example Log Output:**
```
[2024-01-15 13:45:10] INFO: API Request: GET companies/ABC123/products/789
[2024-01-15 13:45:11] INFO: API Response: HTTP 200 (Duration: 0.85s)
[2024-01-15 13:45:15] WARNING: Rate limit hit (HTTP 429), retrying after: 5s
[2024-01-15 13:45:20] WARNING: Authentication failed (HTTP 401), attempting token refresh
[2024-01-15 13:45:21] INFO: Token refreshed successfully, retrying request
```

### 5. Batch Processor (`class-kounta-batch-processor.php`)

#### Error Handling Added
✅ Empty data validation (where/data clauses)  
✅ Database error detection after each operation  
✅ Zero-row update tracking  
✅ Batch operation summaries  

#### Logging Added
✅ Individual database operation failures  
✅ Batch summaries (total, successful, failed)  
✅ Context data in error logs (table, where, data)  
✅ SQL error messages  

**Example Log Output:**
```
[2024-01-15 14:20:30] ERROR: Database update failed - Table: wp_xwcpos_items, Error: Unknown column 'invalid_field'
[2024-01-15 14:20:35] WARNING: Batch update summary - Total: 100, Successful: 97, Errors: 3, Zero rows: 5
```

## 📊 Log Locations

All logs are written to multiple locations for comprehensive debugging:

| Log Type | Location | Purpose |
|----------|----------|---------|
| **Plugin Log** | `wp-content/uploads/brewhq-kounta.log` | General plugin operations |
| **Order Logs** | `wp-content/uploads/kounta-order-logs/` | Order-specific operations |
| **WordPress Debug** | `wp-content/debug.log` | When WP_DEBUG enabled |
| **PHP Error Log** | Server error log | Critical PHP errors |

## 🚨 Error Categories

### Critical Errors (Immediate Attention Required)
- Database connection failures
- API authentication failures
- Missing required configuration
- Order upload failures after all retries

### Warnings (Should Be Monitored)
- Products without Kounta mappings
- Items skipped during sync
- Database updates affecting 0 rows
- Rate limiting events

### Info (Normal Operations)
- Successful syncs
- Customer creation
- Token refresh
- Batch processing progress

## 📖 Related Documentation

- **[DEBUGGING-GUIDE.md](./DEBUGGING-GUIDE.md)** - Quick reference for troubleshooting common issues
- **[ERROR-HANDLING-IMPROVEMENTS.md](./ERROR-HANDLING-IMPROVEMENTS.md)** - Detailed technical implementation
- **[ORDER-LOGGING-IMPROVEMENTS.md](./ORDER-LOGGING-IMPROVEMENTS.md)** - Order-specific logging features
- **[RELIABILITY-IMPROVEMENTS.md](./RELIABILITY-IMPROVEMENTS.md)** - Retry logic and reliability features
- **[PERFORMANCE-IMPROVEMENTS.md](./PERFORMANCE-IMPROVEMENTS.md)** - Performance optimization details

## 🔧 Enabling Debug Mode

To enable comprehensive logging, add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## 📈 Monitoring Recommendations

| Frequency | Action |
|-----------|--------|
| **Daily** | Check for critical errors in order uploads |
| **Weekly** | Review warning logs for missing product mappings |
| **Monthly** | Analyze sync performance and error trends |
| **After Changes** | Monitor logs closely after configuration changes |

## 🛠️ Quick Debugging Commands

See [DEBUGGING-GUIDE.md](./DEBUGGING-GUIDE.md) for detailed commands and queries.

## ✅ Testing Checklist

After deployment, verify:
- [ ] Product sync logs show detailed error messages
- [ ] Order upload failures include full context
- [ ] Stock sync tracks skipped items
- [ ] API errors include HTTP status codes
- [ ] Database errors include SQL error messages
- [ ] Log files are being created in correct locations

## 📞 Support

When reporting issues, include:
1. Relevant log excerpts (last 50 lines around error)
2. Order ID or Product ID affected
3. Database query results for affected items
4. Plugin version and WordPress version
5. Recent configuration changes

---

**Last Updated:** 2024-01-15  
**Version:** 2.0  
**Author:** BrewHQ Development Team

