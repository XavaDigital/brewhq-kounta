# Order Sync Logging & Debugging Improvements

## Overview

This document describes the comprehensive improvements made to order sync logging and error tracking to help diagnose and resolve order push failures to the Kounta API.

## Problem Statement

**Previous Issues:**

- Insufficient logging when orders failed to sync
- No visibility into actual API requests/responses
- Basic email alerts with just JSON dumps
- Difficult to diagnose why specific orders failed
- No structured error tracking or diagnostic reports

## Solution Implemented

### 1. **Dedicated Order Logger** (`includes/class-kounta-order-logger.php`)

A comprehensive logging system specifically for order sync operations.

**Features:**

- ✅ Separate log file (`brewhq-kounta-orders.log`) for order-specific logs
- ✅ Automatic log rotation (max 10MB, keeps last 5 backups)
- ✅ Structured, human-readable log format
- ✅ Detailed diagnostic data capture
- ✅ Order meta storage for quick access to last error

**Log Types:**

1. **Order Sync Stages** - Tracks each stage (prepare, upload, verify, success, failure)
2. **API Requests** - Full request payload, endpoint, method, size
3. **API Responses** - Full response data, HTTP codes, duration
4. **Order Failures** - Comprehensive diagnostic data including:
   - Error details with full context
   - Complete order data that was sent
   - Order details (total, status, customer info, items)
   - System information (PHP version, memory usage, etc.)
   - Retry count and attempt history

### 2. **Enhanced Email Notifications**

Beautiful, readable HTML email notifications instead of raw JSON dumps.

**Email Includes:**

- ✅ Order information table (ID, total, customer, payment method)
- ✅ Error details in highlighted box (type, description, HTTP code, duration)
- ✅ Formatted order data sent to Kounta (JSON with syntax highlighting)
- ✅ Direct link to edit order in WooCommerce
- ✅ Professional HTML formatting with color coding
- ✅ Timestamp and site information

**Configuration:**

- Enable/disable email notifications via admin settings
- Custom email address for notifications (defaults to admin email)

### 3. **Integrated Logging in Order Service**

The `Kounta_Order_Service` class now logs every step of the order sync process:

**Logged Events:**

1. **Preparation Start** - When order data preparation begins
2. **Preparation Complete** - Successful data preparation with item count and total
3. **Upload Attempts** - Each retry attempt with attempt number
4. **API Request** - Full request details before sending
5. **API Response** - Full response details with timing
6. **Duplicate Detection** - When order already exists in Kounta
7. **Success** - Successful upload with Kounta order ID and attempt count
8. **Failure** - Comprehensive failure logging with all diagnostic data
9. **Exceptions** - Full exception details with stack traces

### 4. **Diagnostic Report Generation**

Generate comprehensive diagnostic reports for any order.

**Report Includes:**

- Order information summary
- Kounta sync status
- Last error details (if any)
- Recent log entries for the order
- Full timeline of sync attempts

**Usage:**

```php
$report = Kounta_Order_Logger::generate_diagnostic_report($order_id);
echo $report; // or save to file, email, etc.
```

### 5. **Admin Settings**

New settings added to the admin panel:

**Order Sync Notifications Section:**

- ☑️ Send email notifications when order sync fails
- 📧 Error notification email address (with default to admin email)

## Files Created

1. **`includes/class-kounta-order-logger.php`** (448 lines)

   - Complete logging system for orders
   - Email notification builder
   - Diagnostic report generator
   - Log rotation and management

2. **`admin/class-kounta-order-logs-page.php`** (285 lines)

   - Admin page for viewing and managing logs
   - AJAX handlers for log operations
   - Diagnostic report viewer
   - Failed orders dashboard

3. **`admin/css/order-logs.css`** (299 lines)

   - Professional styling for admin page
   - Responsive design
   - Modal styles
   - Loading states

4. **`admin/js/order-logs.js`** (147 lines)

   - AJAX functionality for log operations
   - Modal interactions
   - Download functionality
   - Real-time updates

5. **`ORDER-LOGGING-IMPROVEMENTS.md`**
   - Complete documentation of improvements
   - Usage examples
   - Configuration guide

## Files Modified

1. **`includes/class-kounta-order-service.php`**

   - Integrated comprehensive logging at every step
   - Added timing measurements for API calls
   - Enhanced error context capture
   - Email notifications on failures

2. **`admin/class-xwcpos-admin.php`**

   - Added order notification settings section
   - Email configuration options
   - Settings save/load logic

3. **`brewhq-kounta.php`**
   - Load order logs admin page
   - Initialize admin UI

## Usage Examples

### View Order Logs

```php
// Get recent logs for a specific order
$logs = Kounta_Order_Logger::get_recent_logs(10, $order_id);
foreach ($logs as $log) {
    echo $log;
}
```

### Generate Diagnostic Report

```php
// Generate full diagnostic report
$report = Kounta_Order_Logger::generate_diagnostic_report($order_id);

// Save to file
file_put_contents('order-' . $order_id . '-diagnostic.txt', $report);

// Or email it
wp_mail('support@example.com', 'Order Diagnostic', $report);
```

### Manual Error Logging

```php
// Log a custom order sync event
Kounta_Order_Logger::log_order_sync($order_id, 'custom_stage', array(
    'message' => 'Custom event occurred',
    'data' => $some_data,
));

// Log an API request
Kounta_Order_Logger::log_api_request($order_id, $endpoint, 'POST', $request_data);

// Log an API response
Kounta_Order_Logger::log_api_response($order_id, $response, 200, 1.234);

// Log a failure
Kounta_Order_Logger::log_order_failure($order_id, $error, $order_data, $retry_count);
```

### Clear Logs

```php
// Clear all order logs (including backups)
Kounta_Order_Logger::clear_logs();
```

## Log File Location

**Main Log:** `wp-content/uploads/brewhq-kounta-orders.log`

**Backup Logs:** `wp-content/uploads/brewhq-kounta-orders.log.YYYY-MM-DD-HHMMSS.bak`

## Log Format Example

```
================================================================================
[2025-11-18 10:30:45] API_REQUEST
================================================================================
ORDER_ID:
12345

ENDPOINT:
companies/123/orders

METHOD:
POST

REQUEST_DATA:
{
    "status": "SUBMITTED",
    "sale_number": "12345",
    "order_type": "Delivery",
    ...
}

REQUEST_SIZE:
1024 bytes

================================================================================
[2025-11-18 10:30:46] API_RESPONSE
================================================================================
ORDER_ID:
12345

HTTP_CODE:
400

DURATION:
1.234s

RESPONSE:
{
    "error": "invalid_product",
    "error_description": "Product ID 789 not found"
}
```

## Benefits

1. **Complete Visibility** - See exactly what's being sent to Kounta and what's coming back
2. **Easy Debugging** - Formatted logs make it easy to spot issues
3. **Historical Tracking** - Log rotation keeps history without filling disk
4. **Proactive Alerts** - Email notifications alert you immediately to failures
5. **Diagnostic Reports** - Generate comprehensive reports for support tickets
6. **Order Meta Storage** - Last error stored on order for quick access
7. **Performance Tracking** - API call duration logged for performance monitoring

## 6. **Admin UI for Log Viewing** ✅ IMPLEMENTED

A beautiful, user-friendly admin page for viewing and managing order logs.

**Location:** Kounta POS Integration → Order Sync Logs

**Features:**

- ✅ **Dashboard Overview** - Log file size, last modified, failed order count
- ✅ **Failed Orders Queue** - Table showing all failed orders with details
- ✅ **Log Filtering** - Filter by order ID, limit number of entries
- ✅ **Recent Log Entries** - View formatted log entries with syntax highlighting
- ✅ **Diagnostic Reports** - Generate and view detailed reports for any order
- ✅ **Download Logs** - Download complete log file with timestamp
- ✅ **Clear Logs** - Clear all logs with confirmation
- ✅ **Responsive Design** - Works on desktop and mobile
- ✅ **Real-time Actions** - AJAX-powered for smooth UX

**UI Components:**

1. **Header Stats** - Quick overview of log status
2. **Action Buttons** - Refresh, Download, Clear
3. **Filters** - Order ID search, entry limit selector
4. **Failed Orders Table** - Shows order ID, error, timestamp, retry count, actions
5. **Log Entries** - Formatted display of recent logs
6. **Diagnostic Modal** - Popup with full diagnostic report

## Next Steps (Optional Enhancements)

1. ✅ ~~Admin UI for Log Viewing~~ - **COMPLETE!**
2. ✅ ~~Failed Order Dashboard~~ - **COMPLETE!**
3. **Retry from Admin** - One-click retry button for failed orders
4. ✅ ~~Log Export~~ - **COMPLETE!** (Download logs as text file)
5. **Slack/Discord Notifications** - Alternative notification channels
6. **Error Analytics** - Track error patterns and frequencies

## Configuration

### Enable Email Notifications

1. Go to **WooCommerce → Kounta Settings**
2. Scroll to **Order Sync Notifications** section
3. Check **"Send email notifications when order sync fails"**
4. Enter email address (or leave blank for admin email)
5. Click **Save Settings**

### Access Logs via Admin UI (Recommended)

1. Go to **Kounta POS Integration → Order Sync Logs**
2. View dashboard with:
   - Log file size and last modified time
   - Failed orders count
   - Recent log entries
3. Use filters to search by order ID or limit entries
4. Click **"📊 View Report"** on any failed order to see full diagnostic
5. Click **"📥 Download Log File"** to download complete logs
6. Click **"🗑️ Clear Logs"** to remove all logs (with confirmation)

### Access Logs via Command Line

Logs are stored in: `wp-content/uploads/brewhq-kounta-orders.log`

You can:

- Download via FTP/SFTP
- View via SSH: `tail -f wp-content/uploads/brewhq-kounta-orders.log`
- Access via Docker: `docker exec brewhq-kounta-wp cat /var/www/html/wp-content/uploads/brewhq-kounta-orders.log`

## Troubleshooting

**Q: Logs not being created?**
A: Check that `wp-content/uploads/` directory is writable

**Q: Not receiving email notifications?**
A: Check WordPress email configuration and spam folder

**Q: Logs growing too large?**
A: Automatic rotation happens at 10MB. Adjust `MAX_LOG_SIZE` constant if needed

**Q: Want to see logs in real-time?**
A: Use `tail -f` command or enable WP_DEBUG to also log to PHP error log
