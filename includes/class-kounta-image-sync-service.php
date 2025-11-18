<?php
/**
 * Kounta Image Sync Service
 * 
 * Handles downloading images from Kounta API and attaching them to WooCommerce products
 * 
 * @package BrewHQ_Kounta_POS_Int
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kounta_Image_Sync_Service {
    
    /**
     * Sync product images from Kounta to WooCommerce
     * 
     * @param int $product_id WooCommerce product ID
     * @param object $kounta_product Kounta product data
     * @param bool $overwrite Whether to overwrite existing images
     * @return array Result with success status and message
     */
    public function sync_product_images($product_id, $kounta_product, $overwrite = false) {
        if (empty($product_id) || empty($kounta_product)) {
            return array(
                'success' => false,
                'message' => 'Invalid product ID or Kounta product data',
            );
        }
        
        // Get image URL from Kounta product
        $image_url = $this->get_primary_image_url($kounta_product);

        if (empty($image_url)) {
            $this->log('No image URL found in Kounta product for product ' . $product_id);
            return array(
                'success' => false,
                'message' => 'No image URL found in Kounta product',
            );
        }

        // Check if product already has this image
        if (!$overwrite && $this->product_has_image($product_id, $image_url)) {
            $this->log('Product ' . $product_id . ' already has this image, skipping (overwrite disabled)');
            return array(
                'success' => true,
                'message' => 'Product already has this image',
                'skipped' => true,
            );
        }
        
        // Download and attach image
        $attachment_id = $this->download_and_attach_image($image_url, $product_id);
        
        if (is_wp_error($attachment_id)) {
            $this->log('ERROR: Failed to download image: ' . $attachment_id->get_error_message());
            return array(
                'success' => false,
                'message' => 'Failed to download image: ' . $attachment_id->get_error_message(),
            );
        }
        
        // Set as product featured image
        set_post_thumbnail($product_id, $attachment_id);
        
        // Store image URL in product meta for future comparison
        update_post_meta($product_id, '_xwcpos_image_url', $image_url);
        update_post_meta($product_id, '_xwcpos_last_image_sync', current_time('mysql'));
        
        $this->log("Image synced successfully for product {$product_id}");
        
        return array(
            'success' => true,
            'message' => 'Image synced successfully',
            'attachment_id' => $attachment_id,
        );
    }
    
    /**
     * Get primary image URL from Kounta product
     * 
     * @param object $kounta_product Kounta product data
     * @return string|null Image URL or null if not found
     */
    private function get_primary_image_url($kounta_product) {
        // Try simple image field first
        if (!empty($kounta_product->image)) {
            return $kounta_product->image;
        }
        
        // Try Images->Image structure
        if (!empty($kounta_product->Images->Image)) {
            $images = $kounta_product->Images->Image;
            
            // If single image (object), convert to array
            if (is_object($images)) {
                $images = array($images);
            }
            
            // Get first image or image with lowest ordering
            if (is_array($images) && count($images) > 0) {
                // Sort by ordering if available
                usort($images, function($a, $b) {
                    $order_a = isset($a->ordering) ? intval($a->ordering) : 999;
                    $order_b = isset($b->ordering) ? intval($b->ordering) : 999;
                    return $order_a - $order_b;
                });
                
                $first_image = $images[0];
                
                // Build URL from baseImageURL
                if (!empty($first_image->baseImageURL)) {
                    return $first_image->baseImageURL;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Check if product already has this image
     * 
     * @param int $product_id WooCommerce product ID
     * @param string $image_url Image URL to check
     * @return bool True if product has this image
     */
    private function product_has_image($product_id, $image_url) {
        $existing_url = get_post_meta($product_id, '_xwcpos_image_url', true);
        return ($existing_url === $image_url);
    }
    
    /**
     * Download image from URL and attach to WordPress media library
     *
     * @param string $image_url Image URL
     * @param int $product_id WooCommerce product ID
     * @return int|WP_Error Attachment ID on success, WP_Error on failure
     */
    private function download_and_attach_image($image_url, $product_id) {
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid image URL');
        }

        // Include required WordPress files
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Set timeout for download
        add_filter('http_request_timeout', function() { return 30; });

        // Download image to temp file
        $temp_file = download_url($image_url);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Get file name from URL
        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // If filename is empty or invalid, generate one
        if (empty($filename) || strpos($filename, '.') === false) {
            $filename = 'kounta-product-' . $product_id . '-' . time() . '.jpg';
        }

        // Prepare file array for media_handle_sideload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
        );

        // Get product title for image alt text
        $product = wc_get_product($product_id);
        $product_title = $product ? $product->get_name() : 'Product';

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $product_id, $product_title);

        // Clean up temp file
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Set alt text
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $product_title);

        return $attachment_id;
    }

    /**
     * Log message to plugin log
     *
     * @param string $message Message to log
     */
    private function log($message) {
        if (class_exists('BrewHQ_Kounta_POS_Int')) {
            $plugin = new BrewHQ_Kounta_POS_Int();
            $plugin->plugin_log('[Image Sync] ' . $message);
        }

        // Also log to PHP error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta Image Sync] ' . $message);
        }
    }
}

