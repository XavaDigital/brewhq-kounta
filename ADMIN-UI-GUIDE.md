# Kounta Order Logs Admin UI - User Guide

## Overview

The Kounta Order Logs admin page provides a comprehensive interface for viewing, managing, and debugging order sync issues directly from your WordPress admin panel.

**Location:** Kounta POS Integration → Order Sync Logs

## Features

### 📊 Dashboard Overview

At the top of the page, you'll see key statistics:

- **Log File Size** - Current size of the order log file
- **Last Modified** - When the log was last updated
- **Failed Orders in Queue** - Number of orders that failed to sync (shown in red if > 0)

### 🔄 Quick Actions

Three action buttons are available:

1. **🔄 Refresh** - Reload the page to see latest logs
2. **📥 Download Log File** - Download the complete log file with timestamp
3. **🗑️ Clear Logs** - Remove all logs (requires confirmation)

### 🔍 Filters

Filter the log entries to find what you need:

- **Filter by Order ID** - Enter a specific WooCommerce order ID to see only logs for that order
- **Show last** - Choose how many log entries to display (25, 50, 100, or 200)
- **Apply Filters** - Apply your filter selections
- **Clear Filters** - Reset all filters

### ⚠️ Failed Orders Queue

If any orders have failed to sync, they'll appear in a dedicated table showing:

- **Order ID** - Clickable link to edit the order in WooCommerce
- **Error** - Error type and description
- **Failed At** - Timestamp of when the order failed
- **Retry Count** - Number of times the system has attempted to retry
- **Actions** - "📊 View Report" button to see full diagnostic

### 📋 Recent Log Entries

View formatted log entries with:

- Structured, easy-to-read format
- Syntax highlighting for better readability
- Scrollable container for long logs
- Each entry shows:
  - Timestamp
  - Log type (API_REQUEST, API_RESPONSE, ORDER_FAILURE, etc.)
  - Order ID
  - Detailed information

### 📊 Diagnostic Reports

Click "📊 View Report" on any failed order to see a comprehensive diagnostic report in a modal popup:

**Report Includes:**

- Order information (ID, total, status, customer, items)
- Kounta sync status (Kounta ID, upload time)
- Last error details (if any)
- Recent log entries for that specific order
- System information

**Actions in Modal:**

- **📥 Download Report** - Save the diagnostic report as a text file
- **Close** - Close the modal

## How to Use

### Viewing Recent Logs

1. Navigate to **Kounta POS Integration → Order Sync Logs**
2. Scroll to "Recent Log Entries" section
3. Browse through the formatted log entries
4. Use the scroll bar to view more entries

### Investigating a Failed Order

1. Look at the "Failed Orders Queue" section
2. Find the order you want to investigate
3. Click the **"📊 View Report"** button
4. Review the comprehensive diagnostic information
5. Click **"📥 Download Report"** to save for later or share with support

### Searching for a Specific Order

1. Enter the order ID in the "Filter by Order ID" field
2. Click **"Apply Filters"**
3. View all log entries related to that order
4. Click **"Clear Filters"** to return to all logs

### Downloading Logs

1. Click the **"📥 Download Log File"** button
2. The complete log file will download with a timestamp in the filename
3. Example filename: `kounta-order-logs-2025-11-18-103045.log`

### Clearing Old Logs

1. Click the **"🗑️ Clear Logs"** button
2. Confirm the action in the popup
3. All logs will be cleared (including backup files)
4. Note: This cannot be undone!

## Log Entry Format

Each log entry follows this structure:

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
{full JSON payload}

REQUEST_SIZE:
1024 bytes
```

## Log Types

You'll see these types of log entries:

- **API_REQUEST** - API request sent to Kounta
- **API_RESPONSE** - Response received from Kounta
- **ORDER_FAILURE** - Comprehensive failure diagnostic
- **LOG** - General order sync events (prepare, upload, success, etc.)

## Tips

1. **Use filters** - When investigating a specific order, always filter by order ID for cleaner results
2. **Download before clearing** - Always download logs before clearing them if you might need them later
3. **Check failed queue first** - The failed orders queue gives you a quick overview of problems
4. **Use diagnostic reports** - The diagnostic report is the fastest way to get all info about a failed order
5. **Monitor log size** - If the log file gets too large, consider clearing old logs periodically

## Troubleshooting

**Q: Page shows "No log entries found"**
A: This means no orders have been synced yet, or logs have been cleared. Try syncing an order first.

**Q: Can't see a specific order's logs**
A: Make sure you're entering the WooCommerce order ID (not the Kounta order ID) in the filter.

**Q: Download button not working**
A: Check that the log file exists and your browser allows downloads. Try refreshing the page.

**Q: Failed orders queue is empty but I know orders failed**
A: The queue only shows orders that are pending retry. Successfully synced orders (even after retries) won't appear here.

## Related Settings

Configure order sync notifications at:
**Kounta POS Integration → API Settings → Order Sync Notifications**

- Enable/disable email notifications for failed orders
- Set custom email address for notifications

## Import Categories

The Import Categories functionality has been moved to the API Settings page for better organization. You can now find it at:
**Kounta POS Integration → API Settings → Import Categories** (at the bottom of the page)

## Support

If you encounter issues with the admin UI:

1. Check browser console for JavaScript errors
2. Verify you have "manage_woocommerce" capability
3. Check that `wp-content/uploads/` directory is writable
4. Try clearing browser cache and refreshing

For order sync issues, use the diagnostic report feature to gather all relevant information before contacting support.
