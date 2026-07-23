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

        $stored_url = get_post_meta($product_id, '_xwcpos_image_url', true);
        $stored_attachment_id = intval(get_post_meta($product_id, '_xwcpos_image_attachment_id', true));
        $current_thumbnail_id = intval(get_post_thumbnail_id($product_id));
        $url_unchanged = ($stored_url === $image_url);

        // Kounta's image hasn't changed and we still have the attachment from the
        // previous download — never re-download, regardless of the overwrite setting.
        // Overwrite mode only re-attaches our existing copy if the thumbnail was changed.
        if ($url_unchanged && $stored_attachment_id && $this->attachment_exists($stored_attachment_id)) {
            if ($current_thumbnail_id === $stored_attachment_id) {
                return array(
                    'success' => true,
                    'message' => 'Product already has this image',
                    'skipped' => true,
                );
            }

            if ($overwrite || !$current_thumbnail_id) {
                set_post_thumbnail($product_id, $stored_attachment_id);
                update_post_meta($product_id, '_xwcpos_last_image_sync', current_time('mysql'));
                $this->log('Re-attached existing image ' . $stored_attachment_id . ' to product ' . $product_id . ' (no download needed)');
                return array(
                    'success' => true,
                    'message' => 'Existing image re-attached (no download needed)',
                    'attachment_id' => $stored_attachment_id,
                );
            }

            // Thumbnail was manually changed and overwrite is off — leave it alone
            return array(
                'success' => true,
                'message' => 'Product image was manually changed, skipping (overwrite disabled)',
                'skipped' => true,
            );
        }

        // URL matches meta written before attachment tracking existed — adopt the
        // current thumbnail instead of downloading a duplicate.
        if ($url_unchanged && !$stored_attachment_id && $current_thumbnail_id) {
            update_post_meta($product_id, '_xwcpos_image_attachment_id', $current_thumbnail_id);
            return array(
                'success' => true,
                'message' => 'Product already has this image',
                'skipped' => true,
            );
        }

        // Product has an image but no sync record (e.g. imported before image
        // tracking) — with overwrite off, record the URL so we don't download on
        // every cycle, and keep the existing image.
        if (!$overwrite && !$url_unchanged && empty($stored_url) && $current_thumbnail_id) {
            update_post_meta($product_id, '_xwcpos_image_url', $image_url);
            update_post_meta($product_id, '_xwcpos_image_attachment_id', $current_thumbnail_id);
            $this->log('Product ' . $product_id . ' already has an image from import, adopting it (overwrite disabled)');
            return array(
                'success' => true,
                'message' => 'Existing image adopted without download',
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

        // Remove the attachment this download replaced, if it's now unused
        $old_attachment_id = $stored_attachment_id ? $stored_attachment_id : $current_thumbnail_id;
        if ($old_attachment_id && $old_attachment_id !== $attachment_id) {
            $this->maybe_delete_replaced_attachment($old_attachment_id, $product_id);
        }

        // Store image URL and attachment ID in product meta for future comparison
        update_post_meta($product_id, '_xwcpos_image_url', $image_url);
        update_post_meta($product_id, '_xwcpos_image_attachment_id', $attachment_id);
        update_post_meta($product_id, '_xwcpos_last_image_sync', current_time('mysql'));

        $this->log("Image synced successfully for product {$product_id}");

        return array(
            'success' => true,
            'message' => 'Image synced successfully',
            'attachment_id' => $attachment_id,
        );
    }

    /**
     * Check an attachment still exists in the media library
     *
     * @param int $attachment_id Attachment ID
     * @return bool
     */
    private function attachment_exists($attachment_id) {
        $post = get_post($attachment_id);
        return ($post && $post->post_type === 'attachment');
    }

    /**
     * Delete an attachment that has been replaced by a newer download,
     * but only if nothing else on the site still uses it.
     *
     * @param int $attachment_id Attachment ID to remove
     * @param int $product_id Product the image belonged to
     */
    private function maybe_delete_replaced_attachment($attachment_id, $product_id) {
        global $wpdb;

        if (!$this->attachment_exists($attachment_id)) {
            return;
        }

        // Still used as a featured image anywhere (the product itself now points
        // at the new attachment, so any hit means it's used elsewhere)
        $thumbnail_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
            (string) $attachment_id
        ));
        if (intval($thumbnail_use) > 0) {
            $this->log('Keeping replaced attachment ' . $attachment_id . ' (still used as a featured image)');
            return;
        }

        // Still referenced in a product image gallery
        $gallery_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND (meta_value = %s
                  OR meta_value LIKE %s
                  OR meta_value LIKE %s
                  OR meta_value LIKE %s)",
            (string) $attachment_id,
            $wpdb->esc_like($attachment_id) . ',%',
            '%,' . $wpdb->esc_like($attachment_id) . ',%',
            '%,' . $wpdb->esc_like($attachment_id)
        ));
        if (intval($gallery_use) > 0) {
            $this->log('Keeping replaced attachment ' . $attachment_id . ' (still used in a product gallery)');
            return;
        }

        // Referenced in post content (inserted into a page/post/description)
        $content_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type NOT IN ('attachment', 'revision')
             AND post_status NOT IN ('trash', 'auto-draft')
             AND post_content LIKE %s",
            '%wp-image-' . $wpdb->esc_like($attachment_id) . '%'
        ));
        if (intval($content_use) > 0) {
            $this->log('Keeping replaced attachment ' . $attachment_id . ' (still referenced in post content)');
            return;
        }

        $deleted = wp_delete_attachment($attachment_id, true);
        if ($deleted) {
            $this->log('Deleted replaced attachment ' . $attachment_id . ' for product ' . $product_id);
        } else {
            $this->log('WARNING: Failed to delete replaced attachment ' . $attachment_id . ' for product ' . $product_id);
        }
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

        // Download image to temp file (30s timeout)
        $temp_file = download_url($image_url, 30);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Kounta originals can be several MB; WooCommerce never displays them
        // larger than ~1024px, so downscale before the file enters the library
        $this->maybe_downscale_image($temp_file);

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
     * Downscale an image file in place so its SHORTEST side is at most the
     * configured limit — the image keeps at least that many pixels in both
     * dimensions (e.g. 4000x3000 becomes 1365x1024 with the default 1024).
     *
     * The limit comes from the xwcpos_max_image_dimension option (default
     * 1024) and can be overridden with the xwcpos_image_max_dimension filter.
     * Setting it to 0 disables downscaling. Images whose shortest side is
     * already at or below the limit are left untouched.
     *
     * @param string $file_path Path to the downloaded image
     */
    private function maybe_downscale_image($file_path) {
        $max = intval(apply_filters('xwcpos_image_max_dimension', get_option('xwcpos_max_image_dimension', 1024)));
        if ($max <= 0) {
            return;
        }

        $size = @getimagesize($file_path);
        if (!$size || empty($size[0]) || empty($size[1])) {
            return;
        }
        if (min($size[0], $size[1]) <= $max) {
            return;
        }

        $scale = $max / min($size[0], $size[1]);
        $new_w = intval(round($size[0] * $scale));
        $new_h = intval(round($size[1] * $scale));

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            $this->log('WARNING: Could not load image editor for downscale: ' . $editor->get_error_message());
            return;
        }

        $editor->set_quality(82);
        $resized = $editor->resize($new_w, $new_h, false);
        if (is_wp_error($resized)) {
            $this->log('WARNING: Downscale resize failed: ' . $resized->get_error_message());
            return;
        }

        $saved = $editor->save($file_path);
        if (is_wp_error($saved)) {
            $this->log('WARNING: Downscale save failed: ' . $saved->get_error_message());
            return;
        }

        $this->log(sprintf('Downscaled image from %dx%d to %dx%d (shortest side %dpx) before import', $size[0], $size[1], $new_w, $new_h, $max));
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

        // Format: timestamp::[Image Sync] message
        $log_entry = current_time('mysql') . '::[Image Sync] ' . $message . "\n";

        // Append to log file
        error_log($log_entry, 3, $log_file);

        // Also log to PHP error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta Image Sync] ' . $message);
        }
    }
}

