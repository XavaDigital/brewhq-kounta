<?php
/**
 * Kounta Description Sync Service
 *
 * Handles syncing product descriptions from Kounta API to WooCommerce products
 * Syncs to the long description (post_content) field only
 *
 * @package BrewHQ_Kounta_POS_Int
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kounta_Description_Sync_Service {

    /**
     * Sync product description from Kounta to WooCommerce short description field
     *
     * @param int $product_id WooCommerce product ID
     * @param object $kounta_product Kounta product data
     * @param bool $overwrite Whether to overwrite existing description
     * @return array Result with success status and message
     */
    public function sync_product_description($product_id, $kounta_product, $overwrite = false) {
        if (empty($product_id) || empty($kounta_product)) {
            return array(
                'success' => false,
                'message' => 'Invalid product ID or Kounta product data',
            );
        }

        // Get description from Kounta using standardized logic
        $description = $this->get_description($kounta_product);

        $updated = false;
        $product = wc_get_product($product_id);

        if (!$product) {
            return array(
                'success' => false,
                'message' => 'Product not found',
            );
        }

        // Update short description (post_excerpt) only
        if (!empty($description)) {
            $current_description = $product->get_short_description();
            $sanitized_description = $this->sanitize_description($description);

            if ($overwrite || empty($current_description)) {
                // Check if description actually changed before updating
                if ($current_description !== $sanitized_description) {
                    $this->log("Updating short description for product {$product_id} (overwrite: " . ($overwrite ? 'yes' : 'no') . ", current empty: " . (empty($current_description) ? 'yes' : 'no') . ")");

                    wp_update_post(array(
                        'ID' => $product_id,
                        'post_excerpt' => $sanitized_description,
                    ));

                    $updated = true;
                    $this->log("Short description updated for product {$product_id}");
                } else {
                    $this->log("Skipping short description for product {$product_id} (description unchanged)");
                }
            } else {
                $this->log("Skipping short description for product {$product_id} (overwrite disabled and description exists)");
            }
        } else {
            $this->log("No description available from Kounta for product {$product_id}");
        }

        if ($updated) {
            update_post_meta($product_id, '_xwcpos_last_description_sync', current_time('mysql'));

            return array(
                'success' => true,
                'message' => 'Description synced successfully',
            );
        }

        return array(
            'success' => true,
            'message' => 'No description updates needed',
            'skipped' => true,
        );
    }
    
    /**
     * Get description from Kounta product for short description field
     * Standardized logic: Use online_description if available, otherwise description
     * This matches the database storage logic in brewhq-kounta.php line 1103
     *
     * @param object $kounta_product Kounta product data
     * @return string|null Description or null
     */
    private function get_description($kounta_product) {
        // Priority 1: online_description (matches database storage)
        if (!empty($kounta_product->online_description) && $kounta_product->online_description !== "") {
            return $kounta_product->online_description;
        }

        // Priority 2: description (matches database storage)
        if (!empty($kounta_product->description)) {
            return $kounta_product->description;
        }

        return null;
    }
    
    /**
     * Sanitize description HTML
     *
     * @param string $description Description to sanitize
     * @return string Sanitized description
     */
    private function sanitize_description($description) {
        // Allow safe HTML tags
        $description = wp_kses_post($description);

        // Convert line breaks to <br> tags if no HTML present
        if (strip_tags($description) === $description) {
            $description = nl2br($description);
        }

        return $description;
    }

    /**
     * Log message to plugin log
     *
     * @param string $message Message to log
     */
    private function log($message) {
        // Use WordPress uploads directory for logging
        // Avoid creating new plugin instances which can cause duplicate behavior
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/brewhq-kounta.log';

        // Format: timestamp::[Description Sync] message
        $log_entry = current_time('mysql') . '::[Description Sync] ' . $message . "\n";

        // Append to log file
        error_log($log_entry, 3, $log_file);

        // Also log to PHP error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta Description Sync] ' . $message);
        }
    }
}

