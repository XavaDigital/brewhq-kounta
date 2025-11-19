# Error Handling and Logging Improvements

## Overview
This document outlines the comprehensive error handling and logging improvements made to the BrewHQ Kounta integration plugin, focusing on the three critical areas: **Product Sync**, **Order Upload**, and **Stock Sync**.

## Summary of Changes

### 1. Product Sync Improvements (`includes/class-kounta-sync-service.php`)

#### Enhanced Error Handling
- **Database Query Validation**: Added error checking for all database queries with `$wpdb->last_error`
- **Item Data Validation**: Validate inventory items have required fields (ID, stock) before processing
- **API Response Validation**: Check for null/empty responses from Kounta API
- **Exception Handling**: Wrapped all sync operations in try-catch blocks with detailed logging

#### Enhanced Logging
- **Batch Processing**: Log detailed error information for each failed item in batch
- **Skipped Items**: Track and log items skipped due to missing data or not in local database
- **Database Operations**: Log when database updates fail or affect 0 rows
- **API Failures**: Log specific error messages when product fetch fails
- **Sync Progress**: Log counts of updated, skipped, and failed items

#### Key Improvements
```php
// Before: Silent failure
$result = $this->sync_single_product($item, $site_id);

// After: Detailed error tracking
try {
    $result = $this->sync_single_product($item, $site_id);
    if (!$result) {
        $error_details[] = array(
            'item_id' => $item->item_id,
            'reason' => 'sync_single_product returned false',
        );
    }
} catch (Exception $e) {
    $this->log('ERROR: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
}
```

### 2. Order Upload Improvements (`includes/class-kounta-order-service.php`)

#### Enhanced Error Handling
- **Customer Creation**: Detailed error logging for customer search and creation failures
- **Order Item Validation**: Validate product mappings and quantities before upload
- **Missing Product Mappings**: Track and log all items without Kounta product IDs
- **Shipping Handling**: Log warnings when shipping is present but no shipping product configured

#### Enhanced Logging
- **Customer Operations**: Log customer search, creation, and lookup results
- **Order Preparation**: Log skipped items and reasons for exclusion
- **Item Details**: Log product IDs, names, and mapping status for debugging
- **Shipping**: Log when shipping is added or when configuration is missing

#### Key Improvements
```php
// Before: Silent null return
if (!$kounta_product_id) {
    return null;
}

// After: Detailed logging
if (!$kounta_product_id) {
    error_log('[BrewHQ Kounta Order] Product ' . $product_id . 
              ' (' . $item->get_name() . ') has no Kounta product ID mapping');
    return null;
}
```

### 3. Stock Sync Improvements

#### Enhanced Error Handling
- **Inventory Item Validation**: Check for missing IDs and stock values
- **Database Update Verification**: Verify update success and log failures
- **Mismatch Detection**: Alert when updated count doesn't match expected count

#### Enhanced Logging
- **Skipped Items**: Count and log items not in local database
- **Invalid Data**: Log items with missing required fields
- **Update Results**: Log detailed summary of successful vs failed updates

### 4. API Client Improvements (`includes/class-kounta-api-client.php`)

#### Enhanced Error Handling
- **Request Timing**: Track and log request duration for performance monitoring
- **JSON Decode Errors**: Detect and log JSON parsing failures
- **HTTP Status Codes**: Include HTTP codes in error responses for better debugging
- **Token Refresh**: Detailed logging of authentication refresh attempts

#### Enhanced Logging
- **All Requests**: Log method, endpoint, and duration for every API call
- **Rate Limiting**: Log when rate limits are hit and retry delays
- **Authentication**: Log token refresh attempts and results
- **Server Errors**: Additional context for 5xx errors

#### Key Improvements
```php
// Before: Basic error return
if ($status_code >= 400) {
    return new WP_Error('api_error', 'API Error ' . $status_code);
}

// After: Comprehensive error logging
if ($status_code >= 400) {
    $this->log('ERROR: API Error ' . $status_code . ': ' . $error_msg);
    if ($status_code >= 500) {
        $this->log('ERROR: Server error - Endpoint: ' . $endpoint);
    }
    return new WP_Error('api_error', $full_error, array('http_code' => $status_code));
}
```

### 5. Batch Processor Improvements (`includes/class-kounta-batch-processor.php`)

#### Enhanced Error Handling
- **Empty Data Validation**: Check for empty where/data clauses before updates
- **Database Error Detection**: Check `$wpdb->last_error` after each operation
- **Zero Rows Tracking**: Track updates that affect 0 rows (potential issues)

#### Enhanced Logging
- **Batch Summaries**: Log total, successful, and failed operations
- **Individual Failures**: Log details of each failed database operation
- **Context Data**: Include table name, where clause, and data in error logs

## Logging Locations

All logs are written to multiple locations for comprehensive debugging:

1. **WordPress Debug Log**: When `WP_DEBUG` is enabled
2. **Plugin Log File**: `wp-content/uploads/brewhq-kounta.log`
3. **Order-Specific Logs**: `wp-content/uploads/kounta-order-logs/` (for order operations)
4. **PHP Error Log**: Standard PHP error_log() for critical errors

## Error Categories

### Critical Errors (Require Immediate Attention)
- Database connection failures
- API authentication failures
- Missing required configuration (account ID, credentials)
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

## Monitoring Recommendations

1. **Daily**: Check for critical errors in order uploads
2. **Weekly**: Review warning logs for missing product mappings
3. **Monthly**: Analyze sync performance and error trends
4. **After Changes**: Monitor logs closely after any configuration changes

## Next Steps

1. Set up log monitoring/alerting for critical errors
2. Create dashboard to visualize error rates
3. Implement automated tests for error scenarios
4. Document common error patterns and solutions

