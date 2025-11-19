# BrewHQ Kounta POS Integration for WooCommerce

A high-performance WordPress plugin that integrates WooCommerce with Kounta POS system, providing seamless product sync, order management, and inventory synchronization.

## 🚀 Features

### Core Functionality

- **Product Sync** - Bi-directional sync between Kounta and WooCommerce
- **Order Upload** - Automatic order creation in Kounta POS
- **Inventory Sync** - Real-time stock level synchronization
- **Image Sync** - Automatic product image downloads from Kounta
- **Description Sync** - Product description synchronization

### Performance

- **5-8x Faster** - Batch processing with concurrent API requests
- **Smart Rate Limiting** - Token bucket algorithm prevents API throttling
- **Optimized Database** - Batch operations for improved performance
- **No Artificial Delays** - Intelligent request management

### Reliability

- **Automatic Retry** - Exponential backoff for failed operations (5 attempts)
- **Error Classification** - Smart handling of retryable vs non-retryable errors
- **Failed Order Queue** - Track and retry failed orders
- **Comprehensive Logging** - Detailed error tracking and debugging

### Admin Features

- **Order Logs Dashboard** - View and manage order sync logs
- **Failed Order Management** - Retry failed orders from admin panel
- **Diagnostic Reports** - Generate detailed reports for troubleshooting
- **Configurable Settings** - Fine-tune sync behavior and notifications

## 📋 Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- MySQL 5.6 or higher
- Kounta POS account with API access

## 🔧 Installation

### For Production Deployment

1. **Build the plugin package:**

   ```bash
   npm run build
   ```

   This creates a clean deployment package in `dist/brewhq-kounta/`

2. **Upload to WordPress:**

   - Via FTP: Upload `dist/brewhq-kounta/` to `/wp-content/plugins/`
   - Via Admin: Run `npm run build:zip` and upload the ZIP file

3. **Activate the plugin** through the 'Plugins' menu in WordPress

4. **Configure settings:**
   - Go to **Kounta POS Integration** → **Settings**
   - Enter your Kounta API credentials
   - Configure sync settings as needed

See **[BUILD.md](./BUILD.md)** for detailed deployment instructions.

### For Development

See **[Development Setup](./docs/README-DEV.md)** for local development setup.

## ⚙️ Configuration

### API Credentials

1. Log into your Kounta account
2. Navigate to Settings → API
3. Generate API credentials (Client ID and Secret)
4. Enter credentials in plugin settings

### Sync Settings

- **Site ID** - Your Kounta site identifier
- **Account ID** - Your Kounta account identifier
- **Sync Prices** - Enable/disable price synchronization
- **Sync Titles** - Enable/disable product name synchronization
- **Sync Images** - Enable/disable image synchronization
- **Sync Descriptions** - Enable/disable description synchronization

## 📖 Documentation

### User Guides

- **[Admin UI Guide](./docs/ADMIN-UI-GUIDE.md)** - Using the admin interface
- **[Debugging Guide](./docs/DEBUGGING-GUIDE.md)** - Troubleshooting common issues

### Feature Documentation

- **[Per-Product Sync Overrides](./docs/PER-PRODUCT-SYNC-OVERRIDES.md)** - Disable sync for specific fields on individual products
- **[Progress Indicator](./docs/PROGRESS-INDICATOR.md)** - Real-time sync progress tracking
- **[Image & Description Sync](./docs/IMAGE-DESCRIPTION-SYNC-PLAN.md)** - Image and description sync features
- **[Order Logging](./docs/ORDER-LOGGING-IMPROVEMENTS.md)** - Order sync logging system

### Technical Documentation

- **[Error Handling](./docs/ERROR-HANDLING-README.md)** - Error handling and logging overview
- **[Performance Improvements](./docs/PERFORMANCE-IMPROVEMENTS.md)** - Performance optimization details
- **[Reliability Improvements](./docs/RELIABILITY-IMPROVEMENTS.md)** - Retry logic and reliability features
- **[Rate Limiting](./docs/RATE-LIMITING-IMPROVEMENTS.md)** - Smart rate limiting implementation
- **[Stock Sync](./docs/STOCK-SYNC-IMPROVEMENTS.md)** - Stock synchronization improvements

### Bug Fixes & Improvements

- **[Duplicate Order Upload Fix](./docs/DUPLICATE-ORDER-UPLOAD-FIX.md)** - Fixed duplicate order uploads
- **[Duplicate Request Fix](./docs/DUPLICATE-REQUEST-FIX.md)** - Fixed duplicate API requests
- **[Sync Improvements Summary](./docs/SYNC-IMPROVEMENTS-SUMMARY.md)** - Recent sync improvements
- **[UI Improvements Summary](./docs/UI-IMPROVEMENTS-SUMMARY.md)** - Admin UI enhancements

### Development

- **[Development Setup](./docs/README-DEV.md)** - Setting up local development environment
- **[Roadmap](./docs/ROADMAP.md)** - Future development plans and enhancements
- **[Documentation Index](./docs/DOCUMENTATION-INDEX.md)** - Complete documentation index

## 🔍 Quick Start

### Sync Products from Kounta

1. Go to **Kounta POS Integration** → **Import Products**
2. Click **⚡ Optimized Sync (Fast)** button
3. Wait for sync to complete
4. Products will appear in WooCommerce

### Upload Orders to Kounta

Orders are automatically uploaded when:

- Customer completes checkout
- Order status changes to "On Hold"

### View Order Logs

1. Go to **Kounta POS Integration** → **Order Sync Logs**
2. View recent sync attempts
3. Check failed orders
4. Generate diagnostic reports

## 🐛 Troubleshooting

### Common Issues

**Products not syncing?**

- Check API credentials in settings
- Verify products are assigned to your site in Kounta
- Check logs at `wp-content/uploads/brewhq-kounta.log`

**Orders failing to upload?**

- Ensure products have Kounta product ID mapping
- Check customer email is valid
- Review order logs for specific errors

**Stock levels not updating?**

- Verify site ID is correct
- Check items exist in `xwcpos_item_shops` table
- Review inventory sync logs

See [Debugging Guide](./docs/DEBUGGING-GUIDE.md) for detailed troubleshooting steps.

## 📊 Logging

### Log Locations

- **Plugin Log**: `wp-content/uploads/brewhq-kounta.log`
- **Order Logs**: `wp-content/uploads/kounta-order-logs/`
- **WordPress Debug**: `wp-content/debug.log` (when WP_DEBUG enabled)

### Enable Debug Mode

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## 🏗️ Architecture

### Core Classes

- `Kounta_API_Client` - API communication with rate limiting
- `Kounta_Sync_Service` - Product and inventory synchronization
- `Kounta_Order_Service` - Order upload with retry logic
- `Kounta_Batch_Processor` - Batch processing for performance
- `Kounta_Order_Logger` - Comprehensive order logging
- `Kounta_Rate_Limiter` - Token bucket rate limiting
- `Kounta_Retry_Strategy` - Exponential backoff retry logic

### Database Tables

- `wp_xwcpos_items` - Product mappings
- `wp_xwcpos_item_shops` - Site-specific inventory
- `wp_xwcpos_item_prices` - Product pricing
- `wp_xwcpos_item_categories` - Category mappings

## 🤝 Contributing

This is a private plugin for BrewHQ. For issues or feature requests, contact the development team.

## 📄 License

This plugin is proprietary software. See [LICENSE](./LICENSE) for details.

## 🔗 Support

For support, please contact:

- Email: support@brewhq.com
- Documentation: See files in this repository

## 📝 Changelog

### Version 2.0 (Current)

- ✅ Comprehensive error handling and logging
- ✅ Performance improvements (5-8x faster)
- ✅ Automatic retry with exponential backoff
- ✅ Failed order queue and management
- ✅ Order logs admin dashboard
- ✅ Image and description sync
- ✅ Smart rate limiting

### Version 1.0

- Initial release
- Basic product sync
- Order upload functionality
- Inventory synchronization

---

**Maintained by:** BrewHQ Development Team  
**Last Updated:** 2024-01-15  
**Version:** 2.0
