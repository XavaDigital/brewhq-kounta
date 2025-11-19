<?php
/**
 * Optimized Sync Service
 * 
 * Handles product and inventory synchronization with improved performance
 * using batch processing and smart rate limiting.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Sync_Service {
    
    /**
     * API client instance
     * @var Kounta_API_Client
     */
    private $api_client;
    
    /**
     * Batch processor instance
     * @var Kounta_Batch_Processor
     */
    private $batch_processor;

    /**
     * Image sync service instance
     * @var Kounta_Image_Sync_Service
     */
    private $image_sync;

    /**
     * Description sync service instance
     * @var Kounta_Description_Sync_Service
     */
    private $description_sync;

    /**
     * Batch size for database operations
     * @var int
     */
    private $batch_size = 50;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new Kounta_API_Client();
        $this->batch_processor = new Kounta_Batch_Processor($this->api_client);
        $this->image_sync = new Kounta_Image_Sync_Service();
        $this->description_sync = new Kounta_Description_Sync_Service();
    }
    
    /**
     * Sync all inventory (optimized version)
     *
     * @return array Results with counts and errors
     */
    public function sync_inventory_optimized() {
        $start_time = microtime(true);
        $site_id = get_option('xwcpos_site_id');

        $this->log('Starting optimized inventory sync for site: ' . $site_id);

        // Get all inventory from Kounta
        $inventory = $this->api_client->get_all_inventory($site_id);

        if (is_wp_error($inventory)) {
            $error_msg = $inventory->get_error_message();
            $this->log('ERROR: Failed to get inventory - ' . $error_msg);
            return array(
                'success' => false,
                'error' => $error_msg,
                'debug' => array(
                    'site_id' => $site_id,
                    'error_code' => $inventory->get_error_code(),
                ),
            );
        }

        $this->log('Retrieved ' . count($inventory) . ' inventory items from Kounta');
        
        // Prepare batch updates
        $updates = array();
        global $wpdb;
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
        $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
        
        // Get all existing items in one query
        $existing_items = $this->get_existing_items_map();
        
        $skipped_items = 0;
        foreach ($inventory as $item) {
            // Validate item data
            if (!isset($item->id)) {
                $this->log('WARNING: Inventory item missing ID, skipping');
                $skipped_items++;
                continue;
            }

            if (!isset($item->stock)) {
                $this->log('WARNING: Inventory item ' . $item->id . ' missing stock value, defaulting to 0');
                $item->stock = 0;
            }

            if (isset($existing_items[$item->id])) {
                $xwcpos_item_id = $existing_items[$item->id];

                $updates[] = array(
                    'table' => $wpdb->xwcpos_item_shops,
                    'data' => array('qoh' => $item->stock),
                    'where' => array(
                        'xwcpos_item_id' => $xwcpos_item_id,
                        'shop_id' => $site_id,
                    ),
                );
            } else {
                $this->log('INFO: Inventory item ' . $item->id . ' not found in local database, skipping');
                $skipped_items++;
            }
        }

        if ($skipped_items > 0) {
            $this->log('WARNING: Skipped ' . $skipped_items . ' inventory items (not in local database or invalid data)');
        }

        // Process batch updates
        $this->log('Processing ' . count($updates) . ' inventory updates');
        $updated_count = $this->batch_processor->batch_database_updates($updates);

        // Check if update count matches expected
        if ($updated_count < count($updates)) {
            $failed_count = count($updates) - $updated_count;
            $this->log('WARNING: ' . $failed_count . ' inventory updates failed. Expected: ' . count($updates) . ', Actual: ' . $updated_count);
        }

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        $this->log(sprintf(
            'Inventory sync completed: %d/%d items updated in %.2f seconds (skipped: %d)',
            $updated_count,
            count($inventory),
            $duration,
            $skipped_items
        ));

        return array(
            'success' => true,
            'updated' => $updated_count,
            'total' => count($inventory),
            'skipped' => $skipped_items,
            'duration' => round($duration, 2),
        );
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     */
    private function log($message) {
        // Use WordPress uploads directory for logging
        // Avoid creating new plugin instances which can cause duplicate behavior
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/brewhq-kounta.log';

        // Format: timestamp::[Optimized Sync] message
        $log_entry = current_time('mysql') . '::[Optimized Sync] ' . $message . "\n";

        // Append to log file
        error_log($log_entry, 3, $log_file);

        // Also log to PHP error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta Optimized Sync] ' . $message);
        }
    }
    
    /**
     * Get existing items as a map (item_id => xwcpos_item_id)
     *
     * @return array Map of item IDs
     */
    private function get_existing_items_map() {
        global $wpdb;
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

        $results = $wpdb->get_results(
            "SELECT id as xwcpos_item_id, item_id FROM {$wpdb->xwcpos_items}",
            ARRAY_A
        );

        // Check for database errors
        if ($wpdb->last_error) {
            $this->log('ERROR: Database query failed in get_existing_items_map - ' . $wpdb->last_error);
            return array();
        }

        if ($results === null) {
            $this->log('WARNING: get_existing_items_map returned null');
            return array();
        }

        $map = array();
        foreach ($results as $row) {
            $map[$row['item_id']] = $row['xwcpos_item_id'];
        }

        $this->log('Loaded ' . count($map) . ' existing items into map');
        return $map;
    }
    
    /**
     * Sync products with optimized batch processing
     *
     * @param int $limit Maximum number of products to sync (0 = all)
     * @return array Results with counts and errors
     */
    public function sync_products_optimized($limit = 0) {
        $start_time = microtime(true);
        $site_id = get_option('xwcpos_site_id');
        $refresh_time = 300; // 5 minutes
        
        global $wpdb;
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
        
        // Get products that need syncing
        // Group by item_id to avoid processing duplicates
        $query = "SELECT * FROM {$wpdb->xwcpos_items}
                  WHERE xwcpos_last_sync_date > 0
                  AND wc_prod_id IS NOT NULL
                  GROUP BY item_id
                  ORDER BY xwcpos_last_sync_date ASC";

        if ($limit > 0) {
            $query .= " LIMIT " . intval($limit);
        }

        $products = $wpdb->get_results($query);

        // Log if we found any products
        $this->log(sprintf('Found %d products to sync', count($products)));

        $total_products = count($products);
        $updated_count = 0;
        $skipped_count = 0;
        $error_count = 0;

        // Initialize progress tracking
        $this->update_progress(array(
            'active' => true,
            'phase' => 'Syncing products...',
            'percent' => 0,
            'current' => 0,
            'total' => $total_products,
        ));

        // Process in batches
        $batches = array_chunk($products, $this->batch_size);
        $batch_count = count($batches);

        foreach ($batches as $batch_index => $batch) {
            $this->log(sprintf(
                'Processing batch %d of %d (%d products)',
                $batch_index + 1,
                $batch_count,
                count($batch)
            ));

            $batch_result = $this->process_product_batch($batch, $refresh_time, $site_id);
            $updated_count += $batch_result['updated'];
            $skipped_count += $batch_result['skipped'];
            $error_count += $batch_result['errors'];

            // Update progress
            $processed = ($batch_index + 1) * $this->batch_size;
            if ($processed > $total_products) {
                $processed = $total_products;
            }
            $percent = $total_products > 0 ? round(($processed / $total_products) * 100) : 0;

            $this->update_progress(array(
                'active' => true,
                'phase' => 'Syncing products...',
                'percent' => $percent,
                'current' => $processed,
                'total' => $total_products,
                'batch_info' => sprintf('Batch %d of %d', $batch_index + 1, $batch_count),
            ));

            // Add a small delay between batches to help with rate limiting
            // Only if there are more batches to process
            if ($batch_index < $batch_count - 1) {
                usleep(500000); // 0.5 second delay between batches
            }
        }

        // Clear progress on completion
        $this->update_progress(array(
            'active' => false,
            'phase' => 'Complete',
            'percent' => 100,
        ));

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        return array(
            'success' => true,
            'total' => count($products),
            'updated' => $updated_count,
            'skipped' => $skipped_count,
            'errors' => $error_count,
            'duration' => round($duration, 2),
        );
    }

    /**
     * Process a batch of products
     *
     * @param array $batch Products to process
     * @param int $refresh_time Minimum time between syncs
     * @param string $site_id Site ID
     * @return array Results
     */
    private function process_product_batch($batch, $refresh_time, $site_id) {
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $error_details = array();

        $now = time();

        foreach ($batch as $item) {
            try {
                // Validate item has required fields
                if (!isset($item->xwcpos_last_sync_date)) {
                    $this->log('WARNING: Product item ' . ($item->id ?? 'unknown') . ' missing xwcpos_last_sync_date, skipping');
                    $errors++;
                    continue;
                }

                $date = new DateTime($item->xwcpos_last_sync_date, new DateTimeZone('Pacific/Auckland'));
                $last_sync = intval($date->format('U'));
                $lapsed = $now - $last_sync;

                // Skip if synced recently
                if ($lapsed < $refresh_time) {
                    $skipped++;
                    continue;
                }

                // Sync the product
                $result = $this->sync_single_product($item, $site_id);

                if ($result) {
                    $updated++;
                } else {
                    $errors++;
                    $error_details[] = array(
                        'item_id' => $item->item_id ?? 'unknown',
                        'wc_prod_id' => $item->wc_prod_id ?? 'unknown',
                        'reason' => 'sync_single_product returned false',
                    );
                }

            } catch (Exception $e) {
                $errors++;
                $error_msg = 'Product sync exception for item ' . ($item->item_id ?? 'unknown') . ': ' . $e->getMessage();
                $this->log('ERROR: ' . $error_msg);
                error_log('[BrewHQ Kounta] ' . $error_msg . ' - Stack trace: ' . $e->getTraceAsString());

                $error_details[] = array(
                    'item_id' => $item->item_id ?? 'unknown',
                    'wc_prod_id' => $item->wc_prod_id ?? 'unknown',
                    'reason' => 'exception',
                    'message' => $e->getMessage(),
                );
            }
        }

        // Log error summary if there were errors
        if ($errors > 0) {
            $this->log('ERROR: Batch completed with ' . $errors . ' errors. Details: ' . json_encode($error_details));
        }

        return array(
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'error_details' => $error_details,
        );
    }

    /**
     * Sync a single product
     *
     * @param object $item Product item
     * @param string $site_id Site ID
     * @return bool Success status
     */
    private function sync_single_product($item, $site_id) {
        global $wpdb;

        // Validate item data
        if (!isset($item->item_id) || !isset($item->id)) {
            $this->log('ERROR: Invalid item data in sync_single_product - missing item_id or id');
            return false;
        }

        // Get Kounta product data
        $endpoint = 'companies/' . $this->api_client->get_account_id() . '/products/' . $item->item_id;
        $this->log(sprintf('Syncing product: item_id=%s, wc_prod_id=%s', $item->item_id, $item->wc_prod_id ?? 'none'));
        $k_product = $this->api_client->make_request($endpoint);

        if (is_wp_error($k_product)) {
            $this->log('ERROR: Failed to fetch product ' . $item->item_id . ' from Kounta API - ' . $k_product->get_error_message());
            return false;
        }

        if (!$k_product) {
            $this->log('ERROR: Empty response when fetching product ' . $item->item_id . ' from Kounta API');
            return false;
        }

        // Find the site data for this specific site
        $site_data = null;
        if (isset($k_product->sites) && is_array($k_product->sites)) {
            foreach ($k_product->sites as $site) {
                if ($site->id == $site_id) {
                    $site_data = $site;
                    break;
                }
            }
        }

        if (!$site_data) {
            $this->log('WARNING: Product ' . $item->item_id . ' has no data for site ' . $site_id);
        }

        // Update stock
        if ($site_data) {
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $stock_value = $site_data->stock ?? 0;

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

            // Check for database errors
            if ($result === false) {
                $this->log('ERROR: Failed to update stock for item ' . $item->id . ' in database - ' . $wpdb->last_error);
            } elseif ($result === 0) {
                // Record doesn't exist, insert it
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
                    $this->log('ERROR: Failed to insert stock record for item ' . $item->id . ' - ' . $wpdb->last_error);
                } else {
                    $this->log('SUCCESS: Created stock record for item ' . $item->id . ' with stock ' . $stock_value);
                }
            }

            // Update WooCommerce stock metadata
            if ($item->wc_prod_id) {
                // Use WooCommerce's proper stock management functions
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

                    // Save the product
                    $save_result = $product->save();

                    if (!$save_result) {
                        $this->log('ERROR: Failed to save WooCommerce stock for product ' . $item->wc_prod_id);
                    }
                } else {
                    $this->log('ERROR: Could not load WooCommerce product ' . $item->wc_prod_id);
                }
            }
        }

        // Sync price if enabled (check both global setting and per-product override)
        if ($item->wc_prod_id && $site_data && get_option('xwcpos_sync_prices', true)) {
            $disable_price_sync = get_post_meta($item->wc_prod_id, '_xwcpos_disable_price_sync', true);
            if ($disable_price_sync === 'yes') {
                $this->log('Skipping price sync for product ' . $item->wc_prod_id . ' (per-product override enabled)');
            } else {
                try {
                    $this->sync_product_price($item, $site_data, $k_product);
                } catch (Exception $e) {
                    $this->log('ERROR: Exception during price sync for product ' . $item->wc_prod_id . ': ' . $e->getMessage());
                }
            }
        }

        // Sync title/name if enabled (check both global setting and per-product override)
        if ($item->wc_prod_id && get_option('xwcpos_sync_titles', true)) {
            $disable_title_sync = get_post_meta($item->wc_prod_id, '_xwcpos_disable_title_sync', true);
            if ($disable_title_sync === 'yes') {
                $this->log('Skipping title sync for product ' . $item->wc_prod_id . ' (per-product override enabled)');
            } else {
                try {
                    $this->sync_product_title($item, $k_product);
                } catch (Exception $e) {
                    $this->log('ERROR: Exception during title sync for product ' . $item->wc_prod_id . ': ' . $e->getMessage());
                }
            }
        }

        // Sync images if enabled (check both global setting and per-product override)
        if ($item->wc_prod_id && get_option('xwcpos_sync_images', true)) {
            $disable_image_sync = get_post_meta($item->wc_prod_id, '_xwcpos_disable_image_sync', true);
            if ($disable_image_sync === 'yes') {
                $this->log('Skipping image sync for product ' . $item->wc_prod_id . ' (per-product override enabled)');
            } else {
                try {
                    $overwrite = get_option('xwcpos_overwrite_images', false);
                    $image_result = $this->image_sync->sync_product_images($item->wc_prod_id, $k_product, $overwrite);

                    if (!$image_result['success'] && !isset($image_result['skipped'])) {
                        $this->log('WARNING: Image sync failed for product ' . $item->wc_prod_id . ': ' . $image_result['message']);
                    }
                } catch (Exception $e) {
                    $this->log('ERROR: Exception during image sync for product ' . $item->wc_prod_id . ': ' . $e->getMessage());
                }
            }
        }

        // Sync descriptions if enabled (check both global setting and per-product override)
        if ($item->wc_prod_id && get_option('xwcpos_sync_descriptions', true)) {
            $disable_description_sync = get_post_meta($item->wc_prod_id, '_xwcpos_disable_description_sync', true);
            if ($disable_description_sync === 'yes') {
                $this->log('Skipping description sync for product ' . $item->wc_prod_id . ' (per-product override enabled)');
            } else {
                try {
                    $overwrite = get_option('xwcpos_overwrite_descriptions', false);
                    $desc_result = $this->description_sync->sync_product_description($item->wc_prod_id, $k_product, $overwrite);

                    if (!$desc_result['success'] && !isset($desc_result['skipped'])) {
                        $this->log('WARNING: Description sync failed for product ' . $item->wc_prod_id . ': ' . $desc_result['message']);
                    }
                } catch (Exception $e) {
                    $this->log('ERROR: Exception during description sync for product ' . $item->wc_prod_id . ': ' . $e->getMessage());
                }
            }
        }

        // Update sync timestamp
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
        $result = $wpdb->update(
            $wpdb->xwcpos_items,
            array('xwcpos_last_sync_date' => current_time('mysql')),
            array('id' => $item->id)
        );

        if ($result === false) {
            $this->log('ERROR: Failed to update sync timestamp for item ' . $item->id . ' - ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Sync product price from Kounta to WooCommerce
     *
     * @param object $item Product item from database
     * @param object $site_data Site-specific data from Kounta
     * @param object $k_product Full product data from Kounta
     * @return bool Success status
     */
    private function sync_product_price($item, $site_data, $k_product) {
        global $wpdb;

        if (!isset($site_data->unit_price)) {
            return false;
        }

        $kounta_price = floatval($site_data->unit_price);

        // Get current price from database
        $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
        $current_price_row = $wpdb->get_row($wpdb->prepare(
            "SELECT amount FROM {$wpdb->xwcpos_item_prices}
             WHERE xwcpos_item_id = %d AND site_id = %s",
            $item->id,
            $site_data->id
        ));

        $current_price = $current_price_row ? floatval($current_price_row->amount) : 0;

        // Only update if price has changed
        if ($current_price !== $kounta_price) {
            // Update price in xwcpos_item_prices table
            $wpdb->update(
                $wpdb->xwcpos_item_prices,
                array('amount' => $kounta_price),
                array(
                    'xwcpos_item_id' => $item->id,
                    'site_id' => $site_data->id,
                ),
                array('%f'),
                array('%d', '%s')
            );

            // Update WooCommerce product price
            $product = wc_get_product($item->wc_prod_id);
            if ($product) {
                $product->set_regular_price($kounta_price);
                $product->set_price($kounta_price);
                $product->save();

                $this->log("Price updated for product {$item->wc_prod_id}: {$current_price} → {$kounta_price}");
            }

            return true;
        }

        return false;
    }

    /**
     * Sync product title/name from Kounta to WooCommerce
     *
     * @param object $item Product item from database
     * @param object $k_product Full product data from Kounta
     * @return bool Success status
     */
    private function sync_product_title($item, $k_product) {
        if (!isset($k_product->name) || empty($k_product->name)) {
            return false;
        }

        $kounta_name = sanitize_text_field($k_product->name);

        // Get current WooCommerce product title
        $product = wc_get_product($item->wc_prod_id);
        if (!$product) {
            return false;
        }

        $current_name = $product->get_name();

        // Only update if name has changed
        if ($current_name !== $kounta_name) {
            // Update WooCommerce product title
            wp_update_post(array(
                'ID' => $item->wc_prod_id,
                'post_title' => $kounta_name,
            ));

            // Update name in xwcpos_items table
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->update(
                $wpdb->xwcpos_items,
                array('name' => $kounta_name),
                array('id' => $item->id),
                array('%s'),
                array('%d')
            );

            $this->log("Title updated for product {$item->wc_prod_id}: '{$current_name}' → '{$kounta_name}'");

            return true;
        }

        return false;
    }

    /**
     * Get sync progress
     *
     * @return array Progress information
     */
    public function get_sync_progress() {
        global $wpdb;
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->xwcpos_items} WHERE wc_prod_id IS NOT NULL");

        $refresh_time = 300;
        $now = time();

        $needs_sync = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->xwcpos_items}
             WHERE wc_prod_id IS NOT NULL
             AND xwcpos_last_sync_date < %s",
            date('Y-m-d H:i:s', $now - $refresh_time)
        ));

        return array(
            'total' => (int)$total,
            'needs_sync' => (int)$needs_sync,
            'synced' => (int)($total - $needs_sync),
            'percent_complete' => $total > 0 ? round((($total - $needs_sync) / $total) * 100, 2) : 0,
        );
    }

    /**
     * Update sync progress in transient for real-time UI updates
     *
     * @param array $progress Progress data
     */
    private function update_progress($progress) {
        set_transient('xwcpos_sync_progress', $progress, 600); // 10 minute expiry
    }
}
