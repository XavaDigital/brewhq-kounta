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
        
        foreach ($inventory as $item) {
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
            }
        }
        
        // Process batch updates
        $this->log('Processing ' . count($updates) . ' inventory updates');
        $updated_count = $this->batch_processor->batch_database_updates($updates);

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        $this->log(sprintf(
            'Inventory sync completed: %d/%d items updated in %.2f seconds',
            $updated_count,
            count($inventory),
            $duration
        ));

        return array(
            'success' => true,
            'updated' => $updated_count,
            'total' => count($inventory),
            'duration' => round($duration, 2),
        );
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     */
    private function log($message) {
        if (class_exists('BrewHQ_Kounta_POS_Int')) {
            $plugin = new BrewHQ_Kounta_POS_Int();
            $plugin->plugin_log('[Optimized Sync] ' . $message);
        }

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
        
        $map = array();
        foreach ($results as $row) {
            $map[$row['item_id']] = $row['xwcpos_item_id'];
        }
        
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
        $query = "SELECT * FROM {$wpdb->xwcpos_items} 
                  WHERE xwcpos_last_sync_date > 0 
                  AND wc_prod_id IS NOT NULL
                  ORDER BY xwcpos_last_sync_date ASC";
        
        if ($limit > 0) {
            $query .= " LIMIT " . intval($limit);
        }
        
        $products = $wpdb->get_results($query);
        
        $updated_count = 0;
        $skipped_count = 0;
        $error_count = 0;
        
        // Process in batches
        $batches = array_chunk($products, $this->batch_size);
        
        foreach ($batches as $batch) {
            $batch_result = $this->process_product_batch($batch, $refresh_time, $site_id);
            $updated_count += $batch_result['updated'];
            $skipped_count += $batch_result['skipped'];
            $error_count += $batch_result['errors'];
        }
        
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

        $now = time();

        foreach ($batch as $item) {
            try {
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
                }

            } catch (Exception $e) {
                $errors++;
                error_log('Product sync error: ' . $e->getMessage());
            }
        }

        return array(
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
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

        // Get Kounta product data
        $endpoint = 'companies/' . $this->api_client->get_account_id() . '/products/' . $item->item_id;
        $k_product = $this->api_client->make_request($endpoint);

        if (is_wp_error($k_product)) {
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

        // Update stock
        if ($site_data) {
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $wpdb->update(
                $wpdb->xwcpos_item_shops,
                array('qoh' => $site_data->stock ?? 0),
                array(
                    'xwcpos_item_id' => $item->id,
                    'shop_id' => $site_id,
                )
            );

            // Update WooCommerce stock
            if ($item->wc_prod_id) {
                update_post_meta($item->wc_prod_id, '_stock', $site_data->stock ?? 0);
            }
        }

        // Sync price if enabled
        if ($item->wc_prod_id && $site_data && get_option('xwcpos_sync_prices', true)) {
            $this->sync_product_price($item, $site_data, $k_product);
        }

        // Sync title/name if enabled
        if ($item->wc_prod_id && get_option('xwcpos_sync_titles', true)) {
            $this->sync_product_title($item, $k_product);
        }

        // Sync images if enabled
        if ($item->wc_prod_id && get_option('xwcpos_sync_images', true)) {
            $overwrite = get_option('xwcpos_overwrite_images', false);
            $image_result = $this->image_sync->sync_product_images($item->wc_prod_id, $k_product, $overwrite);

            if (!$image_result['success'] && !isset($image_result['skipped'])) {
                $this->log('Image sync failed for product ' . $item->wc_prod_id . ': ' . $image_result['message']);
            }
        }

        // Sync descriptions if enabled
        if ($item->wc_prod_id && get_option('xwcpos_sync_descriptions', true)) {
            $overwrite = get_option('xwcpos_overwrite_descriptions', false);
            $desc_result = $this->description_sync->sync_product_description($item->wc_prod_id, $k_product, $overwrite);

            if (!$desc_result['success'] && !isset($desc_result['skipped'])) {
                $this->log('Description sync failed for product ' . $item->wc_prod_id . ': ' . $desc_result['message']);
            }
        }

        // Update sync timestamp
        $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
        $wpdb->update(
            $wpdb->xwcpos_items,
            array('xwcpos_last_sync_date' => current_time('mysql')),
            array('id' => $item->id)
        );

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
}
