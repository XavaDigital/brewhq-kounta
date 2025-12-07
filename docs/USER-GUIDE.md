# BrewHQ Kounta POS Integration - Complete User Guide

## 📖 Table of Contents

1. [Getting Started](#getting-started)
2. [Initial Setup](#initial-setup)
3. [Importing Products](#importing-products)
4. [Managing Product Sync](#managing-product-sync)
5. [Order Synchronization](#order-synchronization)
6. [Monitoring & Troubleshooting](#monitoring--troubleshooting)
7. [Advanced Features](#advanced-features)

---

## Getting Started

### What This Plugin Does

The BrewHQ Kounta POS Integration connects your WooCommerce store with your Kounta POS system, allowing you to:

- **Sync products** from Kounta to WooCommerce (prices, images, descriptions, stock levels)
- **Upload orders** from WooCommerce to Kounta automatically
- **Keep inventory in sync** between both systems
- **Monitor sync status** through comprehensive admin dashboards

### Prerequisites

Before you begin, make sure you have:

- ✅ WordPress installed with WooCommerce active
- ✅ A Kounta POS account with API access
- ✅ Admin access to your WordPress site
- ✅ Your Kounta API credentials (Client ID and Secret)

---

## Initial Setup

### Step 1: Get Your Kounta API Credentials

1. Log into your **Kounta account**
2. Navigate to **Settings** → **API** (or **Developers**)
3. Click **"Create New Application"** or **"Generate API Credentials"**
4. Copy your **Client ID** and **Client Secret** (keep these safe!)
5. Note your **Account ID** and **Site ID** (you'll need these later)

### Step 2: Configure the Plugin

1. In WordPress, go to **Kounta POS Integration** → **API Settings**
2. Fill in the following fields:

   **API Key Section:**

   - **Client ID**: Paste your Kounta Client ID
   - **Client Secret**: Paste your Kounta Client Secret

   **Site Section:**

   - **Site ID**: Enter your Kounta site ID (the location where orders should be sent)

   **Account Section:**

   - **Account ID**: Enter your Kounta account ID

   **Shipping Product:**

   - **Shipping Product ID**: Enter the Kounta product ID to use for shipping charges

3. Click **"Save Settings"**

### Step 3: Configure Payment Methods

Scroll down to the **Payment Methods** section:

1. For each WooCommerce payment method (Stripe, PayPal, etc.), enter the corresponding **Kounta Payment Method ID**
2. This ensures orders show the correct payment method in Kounta
3. Click **"Save Settings"**

### Step 4: Configure Sync Options

In the **Product Sync Options** section, choose what data to sync:

- ☑️ **Sync product images from Kounta** - Downloads product images
  - ☑️ **Overwrite existing product images** - Replace existing images (optional)
- ☑️ **Sync product descriptions from Kounta** - Updates product descriptions
  - ☑️ **Overwrite existing descriptions** - Replace existing descriptions (optional)
- ☑️ **Sync product prices from Kounta** - Updates product prices
- ☑️ **Sync product titles/names from Kounta** - Updates product names

**Recommendation:** Enable all sync options for the initial setup. You can disable specific options later if needed.

### Step 5: Configure Order Notifications

In the **Order Sync Notifications** section:

- ☑️ **Send email notifications for failed orders** - Get alerts when orders fail to upload
- **Error notification email address**: Enter the email where you want to receive alerts (defaults to WordPress admin email)

Click **"Save Settings"** to save all your configuration.

---

## Importing Products

### Step 1: Import Categories (Optional but Recommended)

Categories help organize your products in WooCommerce.

1. Go to **Kounta POS Integration** → **API Settings**
2. Scroll to the bottom to find **"Import Categories"**
3. Click **"Import Kounta Categories"**
4. Wait for the success message (e.g., "15 Categories Imported/Updated Successfully!")

**Note:** This creates WooCommerce product categories matching your Kounta categories.

### Step 2: Load Products from Kounta

This is a **one-time process** that loads all your Kounta products into the plugin's database.

1. Go to **Kounta POS Integration** → **Import Products**
2. Click the **"Load Kounta Products"** button
3. Wait for the process to complete (this may take a few minutes depending on how many products you have)
4. You'll see a success message when complete

**What happens:**

- All products from Kounta are loaded into the plugin's internal database (`wp_xwcpos_items` table)
- Product data includes: names, prices, categories, Kounta IDs, and metadata
- **No WooCommerce products are created yet** - this is just loading the data

### Step 3: Create WooCommerce Products (First-Time Only)

**IMPORTANT:** The sync buttons at the top only UPDATE existing WooCommerce products. To create products for the first time, you must use the **bulk actions** in the product list.

#### Creating All Products at Once:

1. On the **Import Products** page, scroll down to the product list table
2. Check the box at the top of the table to **select all products**
3. From the **"Bulk Actions"** dropdown, select **"Import & Sync"**
4. Click **"Apply"**
5. Wait for the process to complete

**What happens:**

- Creates new WooCommerce products for all selected items
- Sets product names, prices, SKUs
- Downloads and assigns product images (if enabled in settings)
- Syncs descriptions and titles (based on your settings)
- Assigns products to categories
- Enables automatic sync for these products

**Success message:** You'll see "X Item(s) successfully added in WooCommerce!"

#### Creating Individual Products:

1. Find a product in the list that shows **"Not Imported"** status
2. Hover over the product row
3. Click **"Import & Sync"** or **"Import"** link
4. Wait for the success message

**Difference between "Import" and "Import & Sync":**

- **Import** - Creates the WooCommerce product but doesn't enable automatic sync
- **Import & Sync** - Creates the product AND enables automatic sync (recommended)

---

### Step 4: Update Existing Products (Ongoing Sync)

Once WooCommerce products have been created, you can update them using the sync buttons:

1. Go to **Kounta POS Integration** → **Import Products**
2. Click **"⚡ Optimized Sync (Fast)"** button (recommended)
   - OR click **"Sync All Products"** for the legacy sync method
3. Wait for the sync to complete

**What this does:**

- Updates EXISTING WooCommerce products with latest data from Kounta
- Updates prices, stock levels, images, descriptions (based on your sync settings)
- Only processes products that already have a WooCommerce product ID
- Does NOT create new products

**Progress Indicator:**

You'll see real-time updates showing:

- Number of products processed
- Current progress percentage
- Estimated time remaining
- Success/error counts

**When to use this:**

- After changing prices in Kounta
- After updating product descriptions or images in Kounta
- To refresh stock levels
- As part of regular maintenance (or let CRON do it automatically)

---

## Managing Product Sync

### Viewing Your Products

1. Go to **Kounta POS Integration** → **Import Products**
2. You'll see a table of all products with columns:
   - **Product Name** - Name from Kounta
   - **SKU** - Product SKU
   - **Price** - Current price
   - **Category** - Product category
   - **Import Status** - Whether it's imported to WooCommerce
   - **Sync Status** - Last sync date/time
   - **Stock Status** - Current stock level

### Filtering Products

Use the filter dropdowns at the top of the table:

- **Category** - Filter by product category
- **Import Status** - Show only imported or not imported products
- **Sync Status** - Filter by sync date
- **Stock Status** - Filter by in stock / out of stock
- **Price Range** - Filter by minimum and maximum price

Click **"Filter"** to apply your selections.

### Syncing Individual Products

To sync a single product:

1. Go to **Products** → **All Products** in WooCommerce
2. Edit the product you want to sync
3. Scroll to the **"Kounta POS Integration"** meta box (right sidebar)
4. Click **"Sync with Kounta"** button
5. Wait for the success message

**When to use this:**

- After making changes in Kounta that you want to pull into WooCommerce
- To update a single product's price, image, or description
- To troubleshoot sync issues with a specific product

### Automatic Sync (CRON)

The plugin automatically syncs products every hour via WordPress CRON:

- **Frequency**: Every hour
- **Products per run**: 200 products (oldest-first)
- **What it does**: Updates prices, stock levels, images, descriptions

**How it works:**

- Products are sorted by last sync date (oldest first)
- Each hourly run syncs the 200 oldest products
- Over time, all products stay up-to-date through incremental syncs
- No manual intervention needed!

**Monitoring CRON:**

- Check **Tools** → **Site Health** → **Scheduled Events** to see if CRON is running
- Look for `xwcposSyncAll_hook` in the scheduled events list
- If CRON isn't working, contact your hosting provider

### Per-Product Sync Overrides

You can disable specific sync fields for individual products:

1. Edit a product in WooCommerce
2. Scroll to **"Kounta POS Integration"** meta box
3. Check the boxes for fields you want to **exclude from sync**:
   - ☑️ Don't sync price
   - ☑️ Don't sync title
   - ☑️ Don't sync description
   - ☑️ Don't sync images
4. Click **"Update"** to save

**Use case:** If you've customized a product's description in WooCommerce and don't want it overwritten by Kounta data.

---

## Order Synchronization

### How Order Sync Works

Orders are **automatically uploaded to Kounta** when:

1. A customer completes checkout on your WooCommerce store
2. Order status changes to **"On Hold"** or **"Processing"**

**What gets uploaded:**

- Customer information (name, email)
- Order line items (products, quantities, prices)
- Shipping charges (as a separate line item)
- Payment method
- Order total

**Duplicate Prevention:**

- The plugin prevents duplicate uploads using transient locks
- If an order already has a Kounta ID, it won't be uploaded again
- Race conditions are prevented (even if multiple hooks fire simultaneously)

### Viewing Order Sync Status

**In WooCommerce Order:**

1. Go to **WooCommerce** → **Orders**
2. Click on an order to view details
3. Scroll to **"Order Notes"** section
4. Look for notes like:
   - ✅ "Order uploaded to Kounta successfully. Order#: 12345678"
   - ⚠️ "Order upload failed after all retries. Error: ..."

**In Order Sync Logs:**

1. Go to **Kounta POS Integration** → **Order Sync Logs**
2. View recent sync attempts
3. Filter by specific order ID
4. See detailed API requests and responses

### Manual Order Upload

If an order failed to upload automatically, you can retry it manually:

1. Go to **WooCommerce** → **Orders**
2. Edit the order that failed
3. Scroll to **"Kounta POS Integration"** meta box (right sidebar)
4. Click **"Sync Order with Kounta"** button
5. Wait for the success or error message

### Failed Orders Queue

Orders that fail to upload are added to a retry queue:

1. Go to **Kounta POS Integration** → **Order Sync Logs**
2. Look at the **"Failed Orders Queue"** section
3. You'll see:
   - Order ID (clickable link)
   - Error type and description
   - Failed timestamp
   - Retry count
   - **"📊 View Report"** button for detailed diagnostics

**Automatic Retry:**

- Failed orders are retried automatically with exponential backoff
- Up to 5 retry attempts
- Delays: 1s, 2s, 4s, 8s, 16s between attempts

---

## Monitoring & Troubleshooting

### Order Sync Logs Dashboard

**Location:** Kounta POS Integration → Order Sync Logs

**Features:**

- 📊 Dashboard overview (log size, last modified, failed orders count)
- 🔄 Quick actions (refresh, download, clear logs)
- 🔍 Filters (by order ID, number of entries)
- ⚠️ Failed orders queue
- 📋 Recent log entries (formatted and syntax-highlighted)

**How to use:**

1. **View recent activity:**

   - Scroll to "Recent Log Entries"
   - Browse through formatted logs
   - Each entry shows timestamp, type, order ID, and details

2. **Investigate a failed order:**

   - Find the order in "Failed Orders Queue"
   - Click **"📊 View Report"**
   - Review comprehensive diagnostic information
   - Download report for support or later reference

3. **Search for specific order:**

   - Enter order ID in filter field
   - Click "Apply Filters"
   - View all logs for that order

4. **Download logs:**
   - Click **"📥 Download Log File"**
   - Save for troubleshooting or support

### Common Issues & Solutions

#### Products Not Syncing

**Problem:** Products aren't appearing in WooCommerce after sync

**Solutions:**

1. ✅ Check API credentials in **API Settings**
2. ✅ Verify products are assigned to your site in Kounta
3. ✅ Check logs at `wp-content/uploads/brewhq-kounta.log`
4. ✅ Try syncing a single product manually to see specific error
5. ✅ Ensure products have valid data (name, price, etc.)

#### Orders Failing to Upload

**Problem:** Orders show error in order notes

**Solutions:**

1. ✅ Ensure all products in the order have Kounta product ID mapping
2. ✅ Check customer email is valid
3. ✅ Verify payment method is configured in plugin settings
4. ✅ Check shipping product ID is set correctly
5. ✅ Review order logs for specific error details
6. ✅ Try manual upload from order edit page

**Common Errors:**

- **"String value found, but a number is required"** - Fixed in latest version (product_id type error)
- **"Product not found"** - Product doesn't exist in Kounta or isn't mapped correctly
- **"Invalid customer"** - Customer email is invalid or missing

#### Stock Levels Not Updating

**Problem:** WooCommerce stock doesn't match Kounta

**Solutions:**

1. ✅ Verify Site ID is correct in settings
2. ✅ Check items exist in `xwcpos_item_shops` database table
3. ✅ Run a manual product sync to update stock
4. ✅ Ensure CRON is running (check scheduled events)
5. ✅ Review inventory sync logs

#### Duplicate Orders in Kounta

**Problem:** Same order appears twice in Kounta

**Solution:**

- ✅ This has been fixed in the latest version with transient locks
- ✅ Update to the latest plugin version
- ✅ Check order notes - should only see one "uploaded successfully" message
- ✅ If still occurring, contact support with order ID

### Diagnostic Reports

For any failed order, you can generate a comprehensive diagnostic report:

1. Go to **Order Sync Logs**
2. Find the failed order in the queue
3. Click **"📊 View Report"**
4. Review the report which includes:
   - Order information (ID, total, status, customer, items)
   - Kounta sync status (Kounta ID, upload time)
   - Last error details
   - Recent log entries for that order
   - System information (PHP version, memory, etc.)
5. Click **"📥 Download Report"** to save as text file

**When to use:**

- Before contacting support (include the report)
- Troubleshooting complex sync issues
- Documenting recurring problems

### Log Files

The plugin creates several log files:

1. **Main Plugin Log:**

   - Location: `wp-content/uploads/brewhq-kounta.log`
   - Contains: General plugin activity, product sync, errors

2. **Order Logs:**

   - Location: `wp-content/uploads/kounta-order-logs/`
   - Contains: Detailed order sync logs with API requests/responses

3. **WordPress Debug Log:**
   - Location: `wp-content/debug.log`
   - Contains: PHP errors and warnings (when WP_DEBUG enabled)

**Accessing logs:**

- Via FTP/SFTP
- Via hosting control panel file manager
- Via **Order Sync Logs** dashboard (for order logs)

---

## Advanced Features

### Bulk Product Actions

From the **Import Products** page, you can perform bulk actions:

1. Select multiple products using checkboxes
2. Choose an action from the "Bulk Actions" dropdown:
   - Sync selected products
   - Mark as imported
   - Delete from plugin database
3. Click "Apply"

### Custom Sync Schedules

By default, products sync every hour. To change this:

1. Use a plugin like **WP Crontrol** to modify the schedule
2. Find the `xwcposSyncAll_hook` event
3. Change the recurrence (hourly, twice daily, daily, etc.)

**Note:** More frequent syncs may impact server performance.

### API Rate Limiting

The plugin includes smart rate limiting to prevent API throttling:

- **Limit:** 50 requests per 60 seconds (token bucket algorithm)
- **Automatic:** No configuration needed
- **Behavior:** Requests are queued if limit is reached

### Performance Optimization

**For large catalogs (1000+ products):**

1. **Increase PHP limits** in `php.ini`:

   ```
   max_execution_time = 300
   memory_limit = 256M
   ```

2. **Adjust CRON sync limit:**

   - Currently set to 200 products per hour
   - Can be increased if server can handle it
   - Edit `brewhq-kounta.php` line 743

3. **Use Optimized Sync:**
   - Always use "⚡ Optimized Sync (Fast)" button
   - 5-8x faster than legacy sync
   - Includes batch processing and concurrent requests

### Email Notifications

Configure email alerts for failed orders:

1. Go to **API Settings**
2. Scroll to **Order Sync Notifications**
3. ☑️ Enable "Send email notifications for failed orders"
4. Enter email address (or leave blank for admin email)
5. Save settings

**Email includes:**

- Order ID and details
- Error message
- Link to order in WooCommerce
- Timestamp of failure

---

## Best Practices

### Initial Setup Checklist

- ✅ Configure all API credentials correctly
- ✅ Import categories before products
- ✅ Load products first, then sync to WooCommerce
- ✅ Test with a few products before full sync
- ✅ Enable email notifications for failed orders
- ✅ Verify CRON is running for automatic syncs

### Ongoing Maintenance

- ✅ Monitor failed orders queue weekly
- ✅ Download and archive logs monthly
- ✅ Clear old logs periodically (after backing up)
- ✅ Test order uploads after major WooCommerce updates
- ✅ Keep plugin updated to latest version

### Before Making Changes

- ✅ Backup your database before major syncs
- ✅ Test sync settings on a staging site first
- ✅ Document any custom configurations
- ✅ Keep API credentials secure (don't share)

---

## Getting Help

### Self-Service Resources

1. **Check this guide** - Most common questions are answered here
2. **Review logs** - Often reveal the exact issue
3. **Generate diagnostic report** - For failed orders
4. **Check documentation** - See `/docs` folder for technical details

### Contacting Support

When contacting support, please include:

1. **Order ID or Product ID** (if applicable)
2. **Diagnostic report** (download from Order Sync Logs)
3. **Error message** (exact text from logs or order notes)
4. **Steps to reproduce** (what you did before the error)
5. **System info** (WordPress version, PHP version, WooCommerce version)

### Useful Documentation

- **[Admin UI Guide](./ADMIN-UI-GUIDE.md)** - Detailed admin interface guide
- **[Debugging Guide](./DEBUGGING-GUIDE.md)** - Advanced troubleshooting
- **[Documentation Index](./DOCUMENTATION-INDEX.md)** - Complete documentation list

---

## Quick Reference

### Common Tasks

| Task                        | Location          | Action                                          |
| --------------------------- | ----------------- | ----------------------------------------------- |
| Configure API               | API Settings      | Enter credentials, save                         |
| Import categories           | API Settings      | Click "Import Kounta Categories"                |
| Load products from Kounta   | Import Products   | Click "Load Kounta Products"                    |
| Create WooCommerce products | Import Products   | Select products, Bulk Actions → "Import & Sync" |
| Update existing products    | Import Products   | Click "⚡ Optimized Sync (Fast)"                |
| Sync one product            | Product edit page | Click "Sync with Kounta"                        |
| View order logs             | Order Sync Logs   | Browse recent entries                           |
| Retry failed order          | Order edit page   | Click "Sync Order with Kounta"                  |
| Download logs               | Order Sync Logs   | Click "📥 Download Log File"                    |

### Plugin Menu Structure

```
Kounta POS Integration
├── API Settings (credentials, sync options, import categories)
├── Import Products (load products, sync products, view product list)
└── Order Sync Logs (view logs, failed orders, diagnostics)
```

### Sync Workflow

```
1. Configure API Settings
   ↓
2. Import Categories (optional but recommended)
   ↓
3. Load Kounta Products (into plugin database)
   ↓
4. Create WooCommerce Products (use bulk action "Import & Sync")
   ↓
5. Update Products (use "⚡ Optimized Sync" button or CRON)
   ↓
6. Orders automatically upload to Kounta
   ↓
7. Monitor via Order Sync Logs
```

---

**Last Updated:** 2025-12-07
**Plugin Version:** 2.0+
**For:** BrewHQ Kounta POS Integration

---

## Appendix: Troubleshooting Checklist

### Before Contacting Support

- [ ] Checked API credentials are correct
- [ ] Verified Kounta account is active
- [ ] Reviewed error logs
- [ ] Generated diagnostic report (for order issues)
- [ ] Tested with a single product/order
- [ ] Checked WordPress and WooCommerce are up to date
- [ ] Verified CRON is running
- [ ] Cleared WordPress cache
- [ ] Checked server error logs
- [ ] Documented exact error message

### System Requirements Check

- [ ] WordPress 5.0+
- [ ] WooCommerce 3.0+
- [ ] PHP 7.2+
- [ ] MySQL 5.6+
- [ ] PHP memory limit: 128MB+ (256MB recommended)
- [ ] PHP max execution time: 60s+ (300s recommended)
- [ ] `wp-content/uploads/` directory is writable

---

**Need more help?** Check the [Documentation Index](./DOCUMENTATION-INDEX.md) for technical documentation and feature guides.

### API Rate Limiting

The plugin includes smart rate limiting to prevent API throttling:

- **Limit:** 50 requests per 60 seconds (token bucket algorithm)
- **Automatic:** No configuration needed
- **Behavior:** Requests are queued if limit is reached

  - Retry count
  - **"📊 View Report"** button for detailed diagnostics

**Automatic Retry:**

- Failed orders are retried automatically with exponential backoff
- Up to 5 retry attempts
- Delays: 1s, 2s, 4s, 8s, 16s between attempts
