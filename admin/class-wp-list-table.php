<?php
if (!defined('WPINC')) {
    die;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once XWCPOS_PLUGIN_DIR . 'brewhq-kounta.php';

if (!class_exists('BrewHQ_Kounta_Import_Table')) {

    class BrewHQ_Kounta_Import_Table extends WP_List_Table
    {

        public $main_class_obj;

        public function __construct()
        {

            parent::__construct([
                'singular' => __('Import Product', 'xwcpos'), //singular name of the listed records
                'plural' => __('Import Products', 'xwcpos'), //plural name of the listed records
                'ajax' => false, //should this table support ajax?

            ]);

        }

        public function plugin_log( $entry, $mode = 'a', $file = 'brewhq-kounta' ) {
          // Get WordPress uploads directory.
          $upload_dir = wp_upload_dir();
          $upload_dir = $upload_dir['basedir'];

          // If the entry is array, json_encode.
          if ( is_array( $entry ) ) {
            $entry = json_encode( $entry );
          }

          // Write the log file.
          $file  = $upload_dir . '/' . $file . '.log';
          $file  = fopen( $file, $mode );
          $bytes = fwrite( $file, current_time( 'mysql' ) . "::" . $entry . "\n" );
          fclose( $file );

          return $bytes;
        }

        /**
         * Fetch a single product from Kounta API by product ID
         */
        public function fetch_kounta_product_by_id($product_id)
        {
            if (empty($product_id)) {
                $this->plugin_log('[FETCH ERROR] Empty product_id provided');
                return null;
            }

            // Get main plugin instance to access API methods
            if (!$this->main_class_obj) {
                // Access the global plugin instance
                global $xwcpos_plugin_instance;
                if ($xwcpos_plugin_instance) {
                    $this->main_class_obj = $xwcpos_plugin_instance;
                } else {
                    $this->plugin_log('[FETCH ERROR] Plugin instance not available');
                    return null;
                }
            }

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

            if (empty($xwcpos_account_id)) {
                $this->plugin_log('[FETCH ERROR] Kounta account ID not configured');
                return null;
            }

            // Fetch single product with all relations
            $relations = array(
                "Sites",
                "ItemECommerce",
                "Tags",
                "Images",
            );

            $search_data = array(
                'load_relations' => json_encode($relations),
            );

            try {
                $this->plugin_log("[FETCH] Fetching product {$product_id} from Kounta API");

                $response = $this->main_class_obj->xwcpos_make_api_call(
                    'companies/' . $xwcpos_account_id . '/products/' . $product_id,
                    'Read',
                    $search_data
                );

                if (is_wp_error($response)) {
                    $this->plugin_log('[FETCH ERROR] WP_Error: ' . $response->get_error_message());
                    return null;
                }

                if ($response) {
                    $this->plugin_log("[FETCH] Successfully fetched product {$product_id}");
                    return $response;
                } else {
                    $this->plugin_log("[FETCH ERROR] Empty response from API for product {$product_id}");
                    return null;
                }
            } catch (Exception $e) {
                $this->plugin_log('[FETCH ERROR] Exception: ' . $e->getMessage());
                $this->plugin_log('[FETCH ERROR] Stack trace: ' . $e->getTraceAsString());
                return null;
            }
        }

        public function get_row_actions()
        {
            $actions = array(
                'import_sync',
                'enable_sync',
                'disable_sync',
                'import',
                'update',
                'delete',
            );
            return $actions;

        }

        public function xwcpos_get_sing_item($item_id)
        {
            // var_dump('single sync');

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            $result = $wpdb->get_row($wpdb->prepare(
              "SELECT *,
                item.id AS product_id,
                item.item_id AS product_ls_id,
                item.name AS product_name,
                item.description AS product_description,
                item_price.amount AS product_price,
                item_shop.qoh AS product_inventory,
                item.xwcpos_import_date AS product_last_import,
                item.xwcpos_last_sync_date AS product_last_sync,
                item.xwcpos_is_synced AS product_is_synced,
                item.wc_prod_id AS wc_prod_id,
                item.sku AS product_sku,
                item.image AS product_image
              FROM $wpdb->xwcpos_items as item
              LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id
              LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
              WHERE item.id = %d", $item_id
            ));

            // echo '<pre> Output';
            // var_dump($result);
            // echo '</pre>';

            return $result;
            
        }

        public function xwcpos_get_sing_item_for_update($item_id)
        {

          global $wpdb;
          $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
          $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
          $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
          $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
          $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

          $result = $wpdb->get_row($wpdb->prepare(
            "SELECT *,
              item.id                       AS product_id,
              item.item_id                  AS product_ls_id,
              item.name                     AS product_name,
              item_price.amount             AS product_price,
              category.name                 AS product_category,
              item_shop.qoh                 AS product_inventory,
              item.xwcpos_import_date       AS product_last_import,
              item.xwcpos_last_sync_date    AS product_last_sync,
              item.xwcpos_is_synced 	      AS product_is_synced,
              item.wc_prod_id               AS wc_prod_id,
              item.sku                      AS product_sku,
              item.image                    AS product_image
            FROM $wpdb->xwcpos_items as item
            LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.categories LIKE CONCAT('%', CONCAT(category.cat_id ,'%' ))
            LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id
            LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
            WHERE item.id = %d", $item_id
          ));
          var_dump($result);

          return $result;

        }

        public function xwcpos_get_sing_item_attr($item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            $result = $wpdb->get_row($wpdb->prepare(
            "SELECT *,
                item.id                       AS product_id,
                item.item_id                  AS product_ls_id,
                item_price.amount             AS product_price,
                category.name                 AS product_category,
                item_shop.qoh                 AS product_inventory,
                item.xwcpos_import_date       AS product_last_import,
                item.xwcpos_last_sync_date    AS product_last_sync,
                item.xwcpos_is_synced 	      AS product_is_synced,
                item.wc_prod_id               AS wc_prod_id,
                item.sku                      AS product_sku
                item.image                    AS product_image
              FROM $wpdb->xwcpos_items as item
              LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.category_id = category.category_id
              LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id AND item_shop.shop_id = 0
              LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
              WHERE item.item_id = %d", $item_id

            ));

            var_dump($result);

            return $result;

        }

        public function xwcpos_get_mat_item($item_id)
        {

          var_dump('marker 1');

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT *,
					item.id                AS product_id,
					item.item_id           AS product_ls_id,
					item_price.amount      AS product_price,
					category.name          AS product_category,
					item_shop.qoh          AS product_inventory,
					item.xwcpos_import_date      AS product_last_import,
					item.xwcpos_last_sync_date   AS product_last_sync,
					item.xwcpos_is_synced 	   AS product_is_synced,
					item.wc_prod_id        AS wc_prod_id,
					item.sku        AS product_sku,
			  FROM $wpdb->xwcpos_items as item
				LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.category_id = category.category_id
				LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id AND item_shop.shop_id = 0
				--LEFT JOIN $wpdb->xwcpos_item_images as item_image ON item.id = item_image.xwcpos_item_id AND item_image.ordering = 0
				LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
				WHERE item.item_id > 0 AND item.item_matrix_id = %d", $item_id

            ));

            return $result;

        }

        public function xwcpos_get_mat_items($item_id)
        {

          var_dump('marker 2');

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            $result = $wpdb->get_results($wpdb->prepare(
                "SELECT *,
						item.id                AS product_id,
					item.item_id           AS product_ls_id,
					item_price.amount      AS product_price,
					category.name          AS product_category,
					item_shop.qoh          AS product_inventory,
					item.xwcpos_import_date      AS product_last_import,
					item.xwcpos_last_sync_date   AS product_last_sync,
					item.xwcpos_is_synced 	   AS product_is_synced,
					item.wc_prod_id        AS wc_prod_id,
					item.sku        AS product_sku,
					--CONCAT(item_image.base_image_url, 'w_40,c_fill/', item_image.public_id, '.', SUBSTRING_INDEX(item_image.filename, '.', -1)) AS product_image
				FROM $wpdb->xwcpos_items as item
				LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.category_id = category.category_id
				LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id AND item_shop.shop_id = 0
				--LEFT JOIN $wpdb->xwcpos_item_images as item_image ON item.id = item_image.xwcpos_item_id AND item_image.ordering = 0
				LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
				--LEFT JOIN $wpdb->xwcpos_item_attributes as item_attribute ON item.id = item_attribute.item_attribute_set_id
				WHERE item.item_id > 0 AND item.item_matrix_id = %d", $item_id

            ));

            return $result;

        }

        public function xwcpos_get_items($per_page = 20, $page_number = 1)
        {

          //var_dump('marker 3');

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            // $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            // $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            $request = $_REQUEST;
            $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'item.item_id';
            $order = isset($_REQUEST['order'])? $_REQUEST['order'] : 'ASC';
            $offset = ($page_number - 1) * $per_page;

            // Build WHERE clause with filters
            $where_clauses = array("item.name IS NOT NULL AND item.name != ''");

            // Search filter
            if (!empty($_REQUEST['s'])) {
                $search = $wpdb->esc_like($_REQUEST['s']);
                $where_clauses[] = $wpdb->prepare(
                    "(item.name LIKE %s OR item.sku LIKE %s OR item.description LIKE %s OR item.item_id LIKE %s)",
                    '%' . $search . '%',
                    '%' . $search . '%',
                    '%' . $search . '%',
                    '%' . $search . '%'
                );
            }

            // Category filter
            if (!empty($_REQUEST['filter_category']) && $_REQUEST['filter_category'] !== 'all') {
                $category_id = sanitize_text_field($_REQUEST['filter_category']);
                $where_clauses[] = $wpdb->prepare(
                    "item.categories LIKE %s",
                    '%' . $wpdb->esc_like($category_id) . '%'
                );
            }

            // Import status filter
            if (!empty($_REQUEST['filter_import_status']) && $_REQUEST['filter_import_status'] !== 'all') {
                $import_status = sanitize_text_field($_REQUEST['filter_import_status']);
                if ($import_status === 'imported') {
                    $where_clauses[] = "item.wc_prod_id IS NOT NULL AND item.wc_prod_id > 0";
                } elseif ($import_status === 'not_imported') {
                    $where_clauses[] = "(item.wc_prod_id IS NULL OR item.wc_prod_id = 0)";
                }
            }

            // Sync status filter
            if (!empty($_REQUEST['filter_sync_status']) && $_REQUEST['filter_sync_status'] !== 'all') {
                $sync_status = sanitize_text_field($_REQUEST['filter_sync_status']);
                if ($sync_status === 'synced') {
                    $where_clauses[] = "item.xwcpos_is_synced = 1";
                } elseif ($sync_status === 'not_synced') {
                    $where_clauses[] = "(item.xwcpos_is_synced = 0 OR item.xwcpos_is_synced IS NULL)";
                }
            }

            // Stock status filter
            if (!empty($_REQUEST['filter_stock_status']) && $_REQUEST['filter_stock_status'] !== 'all') {
                $stock_status = sanitize_text_field($_REQUEST['filter_stock_status']);
                if ($stock_status === 'in_stock') {
                    $where_clauses[] = "item_shop.qoh > 0";
                } elseif ($stock_status === 'out_of_stock') {
                    $where_clauses[] = "(item_shop.qoh = 0 OR item_shop.qoh IS NULL)";
                } elseif ($stock_status === 'low_stock') {
                    $where_clauses[] = "item_shop.qoh > 0 AND item_shop.qoh <= 5";
                }
            }

            // Price range filter
            if (!empty($_REQUEST['filter_price_min'])) {
                $price_min = floatval($_REQUEST['filter_price_min']);
                $where_clauses[] = $wpdb->prepare("item_price.amount >= %f", $price_min);
            }
            if (!empty($_REQUEST['filter_price_max'])) {
                $price_max = floatval($_REQUEST['filter_price_max']);
                $where_clauses[] = $wpdb->prepare("item_price.amount <= %f", $price_max);
            }

            $where_sql = implode(' AND ', $where_clauses);

            $results = $wpdb->get_results(
              "SELECT
                	item.id                       AS product_id,
                  item.item_id                  AS product_ls_id,
                  item.name                     AS product_name,
                  item_price.amount             AS product_price,
                  category.name                 AS product_category,
                  item_shop.qoh                 AS product_inventory,
                  item.xwcpos_import_date       AS product_last_import,
                  item.xwcpos_last_sync_date    AS product_last_sync,
                  item.xwcpos_is_synced 	      AS product_is_synced,
                  item.wc_prod_id               AS wc_prod_id,
                  item.sku                      AS product_sku,
                  item.image                    AS product_image
              FROM $wpdb->xwcpos_items as item
              LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.categories LIKE CONCAT('%', CONCAT(category.cat_id ,'%' ))
              LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id
              LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
              WHERE $where_sql
              GROUP BY item.id
              ORDER BY $orderby
              $order
              LIMIT $per_page
              OFFSET $offset");

            return $results;
        }

        public function record_count()
        {
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            // $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

            // Build WHERE clause with filters (same as xwcpos_get_items)
            $where_clauses = array("item.name IS NOT NULL AND item.name != ''");

            // Search filter
            if (!empty($_REQUEST['s'])) {
                $search = $wpdb->esc_like($_REQUEST['s']);
                $where_clauses[] = $wpdb->prepare(
                    "(item.name LIKE %s OR item.sku LIKE %s OR item.description LIKE %s OR item.item_id LIKE %s)",
                    '%' . $search . '%',
                    '%' . $search . '%',
                    '%' . $search . '%',
                    '%' . $search . '%'
                );
            }

            // Category filter
            if (!empty($_REQUEST['filter_category']) && $_REQUEST['filter_category'] !== 'all') {
                $category_id = sanitize_text_field($_REQUEST['filter_category']);
                $where_clauses[] = $wpdb->prepare(
                    "item.categories LIKE %s",
                    '%' . $wpdb->esc_like($category_id) . '%'
                );
            }

            // Import status filter
            if (!empty($_REQUEST['filter_import_status']) && $_REQUEST['filter_import_status'] !== 'all') {
                $import_status = sanitize_text_field($_REQUEST['filter_import_status']);
                if ($import_status === 'imported') {
                    $where_clauses[] = "item.wc_prod_id IS NOT NULL AND item.wc_prod_id > 0";
                } elseif ($import_status === 'not_imported') {
                    $where_clauses[] = "(item.wc_prod_id IS NULL OR item.wc_prod_id = 0)";
                }
            }

            // Sync status filter
            if (!empty($_REQUEST['filter_sync_status']) && $_REQUEST['filter_sync_status'] !== 'all') {
                $sync_status = sanitize_text_field($_REQUEST['filter_sync_status']);
                if ($sync_status === 'synced') {
                    $where_clauses[] = "item.xwcpos_is_synced = 1";
                } elseif ($sync_status === 'not_synced') {
                    $where_clauses[] = "(item.xwcpos_is_synced = 0 OR item.xwcpos_is_synced IS NULL)";
                }
            }

            // Stock status filter
            if (!empty($_REQUEST['filter_stock_status']) && $_REQUEST['filter_stock_status'] !== 'all') {
                $stock_status = sanitize_text_field($_REQUEST['filter_stock_status']);
                if ($stock_status === 'in_stock') {
                    $where_clauses[] = "item_shop.qoh > 0";
                } elseif ($stock_status === 'out_of_stock') {
                    $where_clauses[] = "(item_shop.qoh = 0 OR item_shop.qoh IS NULL)";
                } elseif ($stock_status === 'low_stock') {
                    $where_clauses[] = "item_shop.qoh > 0 AND item_shop.qoh <= 5";
                }
            }

            // Price range filter
            if (!empty($_REQUEST['filter_price_min'])) {
                $price_min = floatval($_REQUEST['filter_price_min']);
                $where_clauses[] = $wpdb->prepare("item_price.amount >= %f", $price_min);
            }
            if (!empty($_REQUEST['filter_price_max'])) {
                $price_max = floatval($_REQUEST['filter_price_max']);
                $where_clauses[] = $wpdb->prepare("item_price.amount <= %f", $price_max);
            }

            $where_sql = implode(' AND ', $where_clauses);

            $sql = "SELECT COUNT(DISTINCT item.id)
                    FROM $wpdb->xwcpos_items as item
                    LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.categories LIKE CONCAT('%', CONCAT(category.cat_id ,'%' ))
                    LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id
                    LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
                    WHERE $where_sql";

            $result = $wpdb->get_var($sql);

            return $result;
        }

        public function no_items()
        {
            // Check if filters are active
            $has_filters = !empty($_REQUEST['filter_category']) ||
                          !empty($_REQUEST['filter_import_status']) ||
                          !empty($_REQUEST['filter_sync_status']) ||
                          !empty($_REQUEST['filter_stock_status']) ||
                          !empty($_REQUEST['filter_price_min']) ||
                          !empty($_REQUEST['filter_price_max']) ||
                          !empty($_REQUEST['s']);

            if ($has_filters) {
                echo '<p>' . esc_html__('No products found matching your filters. Try adjusting your search criteria.', 'xwcpos') . '</p>';
            } else {
                echo '<p>' . esc_html__('No Products available.', 'xwcpos') . '</p>';
            }
        }

        /**
         * Display filter summary above the table
         */
        public function display_filter_summary()
        {
            $active_filters = array();

            if (!empty($_REQUEST['s'])) {
                $active_filters[] = sprintf(
                    '<strong>%s:</strong> "%s"',
                    esc_html__('Search', 'xwcpos'),
                    esc_html($_REQUEST['s'])
                );
            }

            if (!empty($_REQUEST['filter_category']) && $_REQUEST['filter_category'] !== 'all') {
                global $wpdb;
                $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
                $category = $wpdb->get_var($wpdb->prepare(
                    "SELECT name FROM $wpdb->xwcpos_item_categories WHERE cat_id = %s",
                    $_REQUEST['filter_category']
                ));
                if ($category) {
                    $active_filters[] = sprintf(
                        '<strong>%s:</strong> %s',
                        esc_html__('Category', 'xwcpos'),
                        esc_html($category)
                    );
                }
            }

            if (!empty($_REQUEST['filter_import_status']) && $_REQUEST['filter_import_status'] !== 'all') {
                $status_labels = array(
                    'imported' => __('Imported', 'xwcpos'),
                    'not_imported' => __('Not Imported', 'xwcpos'),
                );
                $active_filters[] = sprintf(
                    '<strong>%s:</strong> %s',
                    esc_html__('Import Status', 'xwcpos'),
                    esc_html($status_labels[$_REQUEST['filter_import_status']])
                );
            }

            if (!empty($_REQUEST['filter_sync_status']) && $_REQUEST['filter_sync_status'] !== 'all') {
                $status_labels = array(
                    'synced' => __('Synced', 'xwcpos'),
                    'not_synced' => __('Not Synced', 'xwcpos'),
                );
                $active_filters[] = sprintf(
                    '<strong>%s:</strong> %s',
                    esc_html__('Sync Status', 'xwcpos'),
                    esc_html($status_labels[$_REQUEST['filter_sync_status']])
                );
            }

            if (!empty($_REQUEST['filter_stock_status']) && $_REQUEST['filter_stock_status'] !== 'all') {
                $status_labels = array(
                    'in_stock' => __('In Stock', 'xwcpos'),
                    'low_stock' => __('Low Stock (≤5)', 'xwcpos'),
                    'out_of_stock' => __('Out of Stock', 'xwcpos'),
                );
                $active_filters[] = sprintf(
                    '<strong>%s:</strong> %s',
                    esc_html__('Stock', 'xwcpos'),
                    esc_html($status_labels[$_REQUEST['filter_stock_status']])
                );
            }

            if (!empty($_REQUEST['filter_price_min']) || !empty($_REQUEST['filter_price_max'])) {
                $price_min = !empty($_REQUEST['filter_price_min']) ? floatval($_REQUEST['filter_price_min']) : 0;
                $price_max = !empty($_REQUEST['filter_price_max']) ? floatval($_REQUEST['filter_price_max']) : '∞';
                $active_filters[] = sprintf(
                    '<strong>%s:</strong> $%s - $%s',
                    esc_html__('Price Range', 'xwcpos'),
                    $price_min,
                    $price_max
                );
            }

            if (!empty($active_filters)) {
                echo '<div class="xwcpos-filter-summary" style="background: #f0f9f4; border-left: 4px solid #00a32a; padding: 12px 15px; margin: 10px 0; border-radius: 4px;">';
                echo '<strong style="color: #00a32a;">🔍 ' . esc_html__('Active Filters:', 'xwcpos') . '</strong> ';
                echo implode(' <span style="color: #ccc;">|</span> ', $active_filters);
                echo '</div>';
            }
        }

        public function get_columns()
        {

            $columns = [
                'cb' => '<input type="checkbox" />',
                'product_image' => esc_html__('Image', 'xwcpos'),
                'product_name' => esc_html__('Name', 'xwcpos'),
                'product_price' => esc_html__('Price', 'xwcpos'),
                'product_sku' => esc_html__('SKU', 'xwcpos'),
                'product_kounta_id' => esc_html__('Kounta Product #', 'xwcpos'),
                'product_inventory' => esc_html__('Inventory', 'xwcpos'),
                'product_category' => esc_html__('Category', 'xwcpos'),
                'product_last_import' => esc_html__('Import Date', 'xwcpos'),
                'product_last_sync' => esc_html__('Last Sync', 'xwcpos'),
                'product_auto_sync' => esc_html__('Sync Status', 'xwcpos'),

            ];

            return $columns;
        }

        public function column_default($item, $column_name)
        {

            switch ($column_name) {
                case 'product_name':
                    return $item->product_name;
                case 'product_sku':
                    return $item->product_sku;
                case 'product_kounta_id':
                    return $item->product_ls_id;
                case 'product_price':
                    return $item->product_price;
                case 'product_inventory':
                    return $item->product_inventory;
                case 'product_category':
                    return $item->product_category;
                case 'product_last_import':
                    return $item->product_last_import;
                case 'product_last_sync':
                    return $item->product_last_sync;
                case 'product_auto_sync':
                    return $item->product_auto_sync;
                default:

            }
        }

        public function column_cb($item)
        {
            return sprintf(
                '<input type="checkbox" name="bulk-delete[]" value="%s" />', $item->product_id
            );
        }

        public function column_product_image($item)
        {
            $image = !empty($item->product_id) ? $item->product_image : wc_placeholder_img_src();
            return '<img src="' . esc_url($image) . '" height="50" />';
        }

        public function column_product_inventory($item)
        {

            if ($item->wc_prod_id != '' && $item->wc_prod_id != null) {

                $wc_product = wc_get_product($item->wc_prod_id);
                if($wc_product){
                  return $wc_product->get_stock_quantity();
                }
                else return $item->product_inventory;
            } else {
                return $item->product_inventory;
            }

        }

        public function column_product_name($item)
        {

            $current_page = $this->get_pagenum();
            $product_id = intval($item->product_id);

            $query_args = array(
                'page' => 'xwcpos-integration-products',
                'product_id' => $product_id,
                'paged' => $current_page,
            );

            $query_args['action'] = '';

            $base_url = add_query_arg($query_args, admin_url('admin.php'));

            $actions = array(
                'import_and_sync' => sprintf('<a href="%s=%s" title="Import product to WooCommerce and enable sync">' . esc_html__("Import & Sync", "xwcpos") . '</a>', $base_url, 'import_sync'),
                'import' => sprintf('<a href="%s=%s" title="Import product to WooCommerce only">' . esc_html__("Import", "xwcpos") . '</a>', $base_url, 'import'),
                'enable_sync' => sprintf('<a href="%s=%s" title="Include this product in sync operations" style="color:#46b450;">' . esc_html__("Enable Sync", "xwcpos") . '</a>', $base_url, 'enable_sync'),
                'disable_sync' => sprintf('<a href="%s=%s" title="Exclude this product from sync operations" style="color:#dc3232;">' . esc_html__("Disable Sync", "xwcpos") . '</a>', $base_url, 'disable_sync'),
                'update' => sprintf('<a href="%s=%s" title="Update WooCommerce from internal database">' . esc_html__("Update", "xwcpos") . '</a>', $base_url, 'update'),
                'delete' => sprintf('<a href="%s=%s" title="Delete product from internal database">' . esc_html__("Delete", "xwcpos") . '</a>', $base_url, 'delete'),
            );

            if ($item->wc_prod_id) {
                if (false !== wc_get_product($item->wc_prod_id)) {
                    $edit = array(
                        'Edit' => sprintf('<a href="%s" title="Edit product in WooCommerce">' . esc_html__("Edit", "xwcpos") . '</a>', admin_url('post.php?post=' . $item->wc_prod_id . '&action=edit')),
                    );
                    unset($actions['import_and_sync']);
                    unset($actions['import']);
                    $actions = $edit + $actions;
                }
            }

            // Show only the appropriate sync action based on current status
            if ($item->product_is_synced == 1) {
                // Product is currently enabled for sync, show disable option
                unset($actions['enable_sync']);
            } else {
                // Product is currently disabled for sync, show enable option
                unset($actions['disable_sync']);
            }

            return sprintf('%1$s %2$s', '<b>' . $this->xwcpos_namehtml($item) . '</b>', $this->row_actions($actions));

        }

        public function xwcpos_namehtml($item)
        {
            if ($item->wc_prod_id > 0 && false !== wc_get_product($item->wc_prod_id)) {
                $href = add_query_arg(
                    array(
                        'post' => $item->wc_prod_id,
                        'action' => 'edit',
                    ),
                    admin_url('post.php')
                );
                return sprintf('<a href="%s">%s</a>', esc_attr($href), esc_attr(get_the_title($item->wc_prod_id)));
            } else if (!empty($item->product_name)) {
                return $item->product_name;
            } else {
                return '--';
            }
        }

        public function column_product_price($item)
        {
            if ($item->wc_prod_id > 0) {
                $wc_product = wc_get_product($item->wc_prod_id);
                if (false !== $wc_product) {
                    return $wc_product->get_price_html();
                } else {
                    return '–';
                }
            } else {
                return wc_price($item->product_price);
            }
        }

        public function column_product_sku($item)
        {

            $xwcpos_sku = '–';
            if (!empty($item->product_sku)) {
                $xwcpos_sku = esc_attr($item->product_sku);
            }

            // if ($item->product_matrix_item_id > 0) {
            //     return '–';
            // } else {
                if ($item->wc_prod_id > 0) {
                    $wc_product = wc_get_product($item->wc_prod_id);
                    if (false !== $wc_product) {
                        return $wc_product->get_sku();
                    } else {
                        return $xwcpos_sku;
                    }
                } else {
                    return $xwcpos_sku;
                }
            //}
        }

        public function column_product_kounta_id($item)
        {
            if (!empty($item->product_ls_id)) {
                return '<span style="font-family: monospace; color: #2271b1; font-weight: 600;">' .
                       esc_html($item->product_ls_id) .
                       '</span>';
            }
            return '–';
        }

        public function column_product_category($item)
        {

            $cat_html = '–'; // default value

            if ($item->wc_prod_id > 0) {
                $wc_prod_id = (int) $item->wc_prod_id;
                $wc_prod = wc_get_product($wc_prod_id);

                if (false !== $wc_prod) {
                    $cat_html = wc_get_product_category_list($item->wc_prod_id);
                    if (empty($cat_html)) {
                        $cat_html = '–';
                    }
                }
            } else {
                $cat_html = $item->product_category;
            }

            return $cat_html;
        }

        public function column_product_last_sync($item)
        {
            $is_synced = get_post_meta($item->wc_prod_id, '_xwcpos_sync', true);


            if ($is_synced) {
                if (is_null($item->product_last_sync)) {
                    return esc_html__('Not Synced', 'xwcpos');
                } else {
                    return date('d-m-Y H:i', strtotime($item->product_last_sync));
                }
            }

            return '';
        }

        public function column_product_last_import($item)
        {
          if(isset($item->product_last_import)){
            return date('d-m-Y H:i', strtotime($item->product_last_import));
          } else return null;
          
        }

        public function column_product_auto_sync($item)
        {
            $auto_synced = $item->product_is_synced;

            if ($auto_synced == 1) {
                return '<span class="xwcpos-sync-badge xwcpos-sync-enabled" title="This product is included in sync operations">
                    <span class="dashicons dashicons-yes-alt"></span> Enabled
                </span>';
            } else {
                return '<span class="xwcpos-sync-badge xwcpos-sync-disabled" title="This product is excluded from sync operations">
                    <span class="dashicons dashicons-dismiss"></span> Disabled
                </span>';
            }
        }

        public function get_sortable_columns()
        {
            $sortable_columns = array(
                'product_name' => array('product_name', true),
                'product_sku' => array('product_sku', true),
                'product_kounta_id' => array('product_ls_id', true),
                'product_inventory' => array('product_inventory', true),
                'product_last_import' => array('product_last_import', true),
                'product_last_sync' => array('product_last_sync', true),
                'product_auto_sync' => array('product_auto_sync', true),
            );

            return $sortable_columns;
        }

        /**
         * Get all categories for filter dropdown
         */
        protected function get_categories_for_filter()
        {
            global $wpdb;
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

            $categories = $wpdb->get_results(
                "SELECT DISTINCT cat_id, name
                 FROM $wpdb->xwcpos_item_categories
                 WHERE name IS NOT NULL AND name != ''
                 ORDER BY name ASC"
            );

            return $categories;
        }

        /**
         * Display extra table navigation (filters)
         */
        protected function extra_tablenav($which)
        {
            if ($which !== 'top') {
                return;
            }

            $categories = $this->get_categories_for_filter();
            $current_category = isset($_REQUEST['filter_category']) ? $_REQUEST['filter_category'] : 'all';
            $current_import_status = isset($_REQUEST['filter_import_status']) ? $_REQUEST['filter_import_status'] : 'all';
            $current_sync_status = isset($_REQUEST['filter_sync_status']) ? $_REQUEST['filter_sync_status'] : 'all';
            $current_stock_status = isset($_REQUEST['filter_stock_status']) ? $_REQUEST['filter_stock_status'] : 'all';
            $current_price_min = isset($_REQUEST['filter_price_min']) ? $_REQUEST['filter_price_min'] : '';
            $current_price_max = isset($_REQUEST['filter_price_max']) ? $_REQUEST['filter_price_max'] : '';
            ?>
            <div class="alignleft actions">
                <style>
                    .xwcpos-filters-wrapper {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 8px;
                        align-items: center;
                        margin-bottom: 10px;
                    }
                    .xwcpos-filter-group {
                        display: flex;
                        align-items: center;
                        gap: 5px;
                    }
                    .xwcpos-filter-group label {
                        font-weight: 600;
                        font-size: 12px;
                        color: #555;
                    }
                    .xwcpos-filter-group select,
                    .xwcpos-filter-group input[type="number"] {
                        min-width: 140px;
                    }
                    .xwcpos-price-range {
                        display: flex;
                        align-items: center;
                        gap: 5px;
                    }
                    .xwcpos-price-range input {
                        width: 80px;
                    }
                </style>

                <div class="xwcpos-filters-wrapper">
                    <!-- Category Filter -->
                    <div class="xwcpos-filter-group">
                        <label for="filter-category"><?php esc_html_e('Category:', 'xwcpos'); ?></label>
                        <select name="filter_category" id="filter-category">
                            <option value="all" <?php selected($current_category, 'all'); ?>><?php esc_html_e('All Categories', 'xwcpos'); ?></option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->cat_id); ?>" <?php selected($current_category, $category->cat_id); ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Import Status Filter -->
                    <div class="xwcpos-filter-group">
                        <label for="filter-import-status"><?php esc_html_e('Import Status:', 'xwcpos'); ?></label>
                        <select name="filter_import_status" id="filter-import-status">
                            <option value="all" <?php selected($current_import_status, 'all'); ?>><?php esc_html_e('All Products', 'xwcpos'); ?></option>
                            <option value="imported" <?php selected($current_import_status, 'imported'); ?>><?php esc_html_e('Imported', 'xwcpos'); ?></option>
                            <option value="not_imported" <?php selected($current_import_status, 'not_imported'); ?>><?php esc_html_e('Not Imported', 'xwcpos'); ?></option>
                        </select>
                    </div>

                    <!-- Sync Status Filter -->
                    <div class="xwcpos-filter-group">
                        <label for="filter-sync-status"><?php esc_html_e('Sync Status:', 'xwcpos'); ?></label>
                        <select name="filter_sync_status" id="filter-sync-status">
                            <option value="all" <?php selected($current_sync_status, 'all'); ?>><?php esc_html_e('All', 'xwcpos'); ?></option>
                            <option value="synced" <?php selected($current_sync_status, 'synced'); ?>><?php esc_html_e('Synced', 'xwcpos'); ?></option>
                            <option value="not_synced" <?php selected($current_sync_status, 'not_synced'); ?>><?php esc_html_e('Not Synced', 'xwcpos'); ?></option>
                        </select>
                    </div>

                    <!-- Stock Status Filter -->
                    <div class="xwcpos-filter-group">
                        <label for="filter-stock-status"><?php esc_html_e('Stock:', 'xwcpos'); ?></label>
                        <select name="filter_stock_status" id="filter-stock-status">
                            <option value="all" <?php selected($current_stock_status, 'all'); ?>><?php esc_html_e('All Stock', 'xwcpos'); ?></option>
                            <option value="in_stock" <?php selected($current_stock_status, 'in_stock'); ?>><?php esc_html_e('In Stock', 'xwcpos'); ?></option>
                            <option value="low_stock" <?php selected($current_stock_status, 'low_stock'); ?>><?php esc_html_e('Low Stock (≤5)', 'xwcpos'); ?></option>
                            <option value="out_of_stock" <?php selected($current_stock_status, 'out_of_stock'); ?>><?php esc_html_e('Out of Stock', 'xwcpos'); ?></option>
                        </select>
                    </div>

                    <!-- Price Range Filter -->
                    <div class="xwcpos-filter-group">
                        <label><?php esc_html_e('Price:', 'xwcpos'); ?></label>
                        <div class="xwcpos-price-range">
                            <input type="number" name="filter_price_min" placeholder="Min" step="0.01" min="0" value="<?php echo esc_attr($current_price_min); ?>" />
                            <span>-</span>
                            <input type="number" name="filter_price_max" placeholder="Max" step="0.01" min="0" value="<?php echo esc_attr($current_price_max); ?>" />
                        </div>
                    </div>

                    <!-- Filter Button -->
                    <input type="submit" name="filter_action" id="post-query-submit" class="button" value="<?php esc_attr_e('Filter', 'xwcpos'); ?>" />

                    <!-- Clear Filters Button -->
                    <?php if (!empty($_REQUEST['filter_category']) || !empty($_REQUEST['filter_import_status']) ||
                              !empty($_REQUEST['filter_sync_status']) || !empty($_REQUEST['filter_stock_status']) ||
                              !empty($_REQUEST['filter_price_min']) || !empty($_REQUEST['filter_price_max']) ||
                              !empty($_REQUEST['s'])): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=xwcpos-integration-products')); ?>" class="button">
                            <?php esc_html_e('Clear Filters', 'xwcpos'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        public function get_bulk_actions()
        {
            $actions = [
                'import_and_sync' => 'Import & Sync',
                'import' => 'Import',
                'enable_sync' => 'Enable Sync',
                'disable_sync' => 'Disable Sync',
                'update' => 'Update',
                'delete' => 'Delete',
            ];

            return $actions;
        }

        public function my_sort_custom_column_query( $query )
        {
            $orderby = $query->get( 'orderby' );
        
            if ( 'MY_CUSTOM_COLUMN' == $orderby ) {
        
                $meta_query = array(
                    'relation' => 'OR',
                    array(
                        'key' => 'MY_META_KEY',
                        'compare' => 'NOT EXISTS', // see note above
                    ),
                    array(
                        'key' => 'MY_META_KEY',
                    ),
                );
        
                $query->set( 'meta_query', $meta_query );
                $query->set( 'orderby', 'meta_value' );
            }
        }
        

        public function prepare_items()
        {

            $this->_column_headers = $this->get_column_info();

            $this->xwcpos_process_bulk_action();
            $this->xwcpos_process_row_actions();

            $per_page = $this->get_items_per_page('xwcpos_per_page', 20);
            $current_page = $this->get_pagenum();
            $total_items = self::record_count();

            $this->set_pagination_args([
                'total_items' => $total_items, //WE have to calculate the total number of items
                'per_page' => $per_page, //WE have to determine how many items to show on a page
            ]);
            $this->items = self::xwcpos_get_items($per_page, $current_page);

        }

        public function xwcpos_process_row_actions()
        {
            if (isset($_GET['action']) && (isset($_GET['product_id']) || isset($_GET['matrix_id']))) {

                $row_action = $_GET['action'];

                if (!in_array($row_action, $this->get_row_actions())) {
                    return;
                }

                $this->xwcpos_implement_row_action($row_action);

            }
        }

        public function xwcpos_implement_row_action($action)
        {

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            $item_id = 0;
            if (isset($_GET['product_id'])) {
                $item_id = intval($_GET['product_id']);
            }

            if ($item_id == 0) {

                $xwcpos_message = '<div id="message" class="error">
				<p><strong>Import failed. Could not find a proper Kounta product ID</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

                return;
            }
            switch ($action) {

                case 'import_sync':
                    return $this->xwcpos_process_single_import($item_id, 'single', true);
                case 'enable_sync':
                    return $this->xwcpos_process_single_sync($item_id, true, 'single');
                case 'disable_sync':
                    return $this->xwcpos_process_single_sync($item_id, false, 'single');
                case 'import':
                    return $this->xwcpos_process_single_import($item_id, 'single', false);
                case 'update':
                    return $this->xwcpos_process_single_update($item_id, 'single');
                case 'delete':
                    return $this->xwcpos_process_single_delete($item_id, 'single');
            }

        }

        public function xwcpos_process_single_import($item_id, $importType, $sync = false)
        {

            $imflag = '';
            $result = false;

            $item = $this->xwcpos_get_sing_item($item_id);
            

            if ($item && ($item->wc_prod_id == '' || $item->wc_prod_id == 0 || $item->wc_prod_id == null)) {
                $result = $this->xwcpos_import_item($item, $sync, $imflag, $importType);
            } else {
              $wc_product = wc_get_product( $item->wc_prod_id );
              if($wc_product){
                $wc_prod_status = $wc_product->get_status();
                if(!isset($wc_product)){
                  $result = $this->xwcpos_import_item($item, $sync, $imflag, $importType);
                }
                else if ($wc_prod_status == 'trash'){
                  //TODO this could restore the item from trash
                  $result = $this->xwcpos_import_item($item, $sync, $imflag, $importType);
                }
              }
            }

            return $result;
        }

        public function xwcpos_import_item($item, $sync, $imflag, $importType)
        {
            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            $item = apply_filters('xwcpos_import_product', $item);
            $post_id = '';

            //echo 'about to save WC product<br/>';

            // echo 'LS productID: '.$item->product_ls_id .'<br/>';
            // echo '<pre>';
            // var_dump($item);
            // echo '</pre>';

            if (isset($item->product_ls_id) && $item->product_ls_id > 0) {
                $post_id = $this->xwcpos_savewc_single_product($item, $sync, $imflag);
            } else if (isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
                $this->xwcpos_savewc_matrix_product($item, $sync, $imflag);
            }

            if ($importType == 'single' && isset($post_id)) {
                $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					        <p>Item successfully added in WooCommerce and Synced!</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
            }

            return $post_id;

        }

        public function xwcpos_savewc_single_product($item, $sync, $imflag)
        {
          $wc_prod = wc_get_product($item->wc_prod_id);

          if($wc_prod){
            $wc_prod_status = $wc_prod->get_status();
            if ($wc_prod !== false && $wc_prod_status != 'trash') {
                return $item->wc_prod_id;
            }
          }

          if($this->xwcpos_get_valid_sku($item, $item->sku, false)){

            $product = new WC_product;
            $product->set_name($item->product_name);
            if($item->sku != null){
              $product->set_sku($item->sku);
            }
            // Use short description instead of long description (standardized)
            $product->set_short_description(isset($item->product_description) ? $item->product_description : '');
            $product->set_price($item->amount);
            $product->set_status('draft');
            $product->save();

            $post_id = $product->get_id();
            $item->wc_prod_id = $post_id;
            if (!is_wp_error($post_id)) {

              //Add categories/tags
              $this->xwcpos_add_product_taxonomy($item, $post_id);
              $fields = apply_filters('xwcpos_import_post_meta_single_product', $this->map_kounta_fields($item), $post_id);

              if (!empty($fields)) {
                  foreach ($fields as $field_key => $value) {
                      update_post_meta($post_id, $field_key, $value);
                  }
              }

              $item->wc_prod_id = $post_id;
              $item->last_import = current_time('mysql');

              if ($sync) {
                  update_post_meta($post_id, '_xwcpos_sync', $sync);
              }

              //Add product images
              if (!$imflag) {
                $product_image = $this->add_kounta_product_image($item);
                $product_image = apply_filters('xwcpos_import_product_images_single_product', $product_image, $post_id);
                if ($product_image != false) {
                  if (!empty($product_image)) {
                    $result = set_post_thumbnail($post_id, $product_image);
                  }
                }
              }

              $this->xwcpos_match_import_data_table($item, $post_id, $sync);

            }

            return $post_id;
          } else {
            //There is a SKU conflict
            $product_id = wc_get_product_id_by_sku($item->sku);
            $product = wc_get_product($product_id);
            if($product && $product->get_status() != 'trash'){
              $result = $this->update_item_wc_prod_id($item, $product_id);
              $update = update_post_meta($product_id, '_xwcpos_item_id', $item->product_id);
              $update = update_post_meta($product_id, '_xwcpos_sync', 1);
              $this->xwcpos_match_import_data_table($item, $product_id, 1);
            } else {
              //disassociate the kounta product with any WC products
              $result = $this->update_item_disassociate($item);
            }
          }

        }

        public function update_item_wc_prod_id($item, $wc_prod_id)
        {
            if(isset($item->product_id)){
              global $wpdb;
              $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

              $data['wc_prod_id'] = $wc_prod_id;
              $where['id'] = $item->product_id;
              $result = $wpdb->update($wpdb->xwcpos_items, $data, $where);
              if($result != 1){
                return false;
              }
              return true;
            } else {
              echo 'new_item appears to be empty';
            }
            return false;
        }

        public function update_item_disassociate($item)
        {
            if(isset($item->product_id)){
              global $wpdb;
              $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
              $data = array(
                'wc_prod_id' => null,
                'xwcpos_import_date' => null,
                'xwcpos_last_sync_date' => null,
                'xwcpos_is_synced' => null,
              );
              $where['id'] = $item->product_id;
              $result = $wpdb->update($wpdb->xwcpos_items, $data, $where);
              if($result != 1){
                return false;
              }
              return true;
            } else {
              echo 'new_item appears to be empty';
            }
            return false;
        }

        public function xwcpos_savewc_matrix_product($item, $sync, $imflag)
        {

            if (false !== wc_get_product($item->wc_prod_id)) {
                return $item->wc_prod_id;
            }

            $item = apply_filters('xwcpos_import_ls_result_matrix_product', $item);

            // Standardized description logic: use online_description if available, otherwise description
            $description = '';
            if (!empty($item->online_description) && $item->online_description !== "") {
                $description = $item->online_description;
            } else if (!empty($item->product_description)) {
                $description = $item->product_description;
            }

            $matrix_product_data = array(
                'post_author' => get_current_user_id(),
                'post_title' => isset($item->product_name) ? $item->product_name : '',
                'post_content' => '', // Don't populate long description
                'post_excerpt' => $description, // Use standardized description for short description
                'post_status' => 'publish',
                'post_type' => 'product',
            );

            $matrix_product_data = apply_filters('xwcpos_import_post_fields_matrix_product', $matrix_product_data);
            $post_id = wp_insert_post($matrix_product_data);

            if (!is_wp_error($post_id)) {

                wp_set_object_terms($post_id, 'variable', 'product_type');

                $this->xwcpos_add_product_taxonomy($item, $post_id);

                $fields = apply_filters('xwcpos_import_post_meta_matrix_product', $this->map_kounta_fields($item), $post_id);

                if (!empty($fields)) {
                    foreach ($fields as $field_key => $value) {
                        update_post_meta($post_id, $field_key, $value);
                    }
                }

                $variations = $this->xwcpos_ls_get_matrix_prods($item->product_matrix_item_id);
                //create attribute
                $attributes = $this->xwcpos_get_attributes($variations);

                if (!empty($attributes)) {

                    if (isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
                        $iitems = $this->xwcpos_get_mat_items($item->product_matrix_item_id);
                        foreach ($iitems as $iitem) {

                            $itemattr = unserialize($iitem->item_attributes);

                            $this->set_item_attributes($itemattr->itemAttributeSetID, $post_id, apply_filters('xwcpos_import_attributes_matrix_item', $attributes, $post_id));
                        }
                    }

                }

                //create variations
                $this->xwcpos_create_variations(apply_filters('xwcpos_import_variations_matrix_item', $variations, $post_id), $sync, $post_id);

                //Variable product stock status
                $this->set_matrix_stock_status($post_id);

                $item->wc_prod_id = $post_id;
                if ($sync) {
                    update_post_meta($post_id, '_xwcpos_sync', $sync);
                }

                //Product Images
                // if (!$imflag) {
                //     $product_images = $this->add_kounta_product_images($item);
                //     $product_images = apply_filters('xwcpos_import_product_images_single_product', $product_images, $post_id);
                //     if (count($product_images) > 0) {
                //         if (!empty($product_images)) {
                //             set_post_thumbnail($post_id, $product_images[0]);
                //             unset($product_images[0]);
                //             update_post_meta($post_id, '_product_image_gallery', implode(',', $product_images));
                //         } else {
                //             delete_post_thumbnail($post_id);
                //             update_post_meta($post_id, '_product_image_gallery', '');
                //         }
                //     }
                // }

                $this->xwcpos_match_import_data_table($item, $post_id, $sync);

            }

            return $post_id;
        }

        public function set_matrix_stock_status($post_id)
        {

            update_post_meta($post_id, '_stock_status', 'instock');

        }

        public function set_item_attributes($attributeset_id, $post_id, $attribute_values)
        {

            global $wpdb;
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            $attribute_set = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_attributes . " WHERE item_attribute_set_id = %d", $attributeset_id));

            if ($attribute_set->id > 0) {

                $attribute_data = array();
                $position = 0;

                for ($i = 1; $i <= 3; $i++) {
                    $name = $attribute_set->{'attribute_name_' . $i};
                    if (!empty($name)) {
                        $slug = sanitize_title($name);

                        $attribute_data[$slug] = array(
                            'name' => $name,
                            'value' => implode('|', $attribute_values[$attributeset_id][$slug]),
                            'position' => $position++,
                            'is_visible' => 1,
                            'is_variation' => 1,
                            'is_taxonomy' => 0,
                        );
                    }
                }

                update_post_meta($post_id, '_product_attributes', $attribute_data);
            }
        }

        public function xwcpos_create_variations($variations, $sync, $post_main_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

            foreach ($variations as $variation) {

                $variation = apply_filters('xwcpos_create_ls_data_variation', $variation);

                $variation_data = array(
                    'post_author' => get_current_user_id(),
                    'post_title' => isset($variation->description) ? $variation->description : '',
                    'post_content' => isset($variation->item_e_commerce->long_description) ? $variation->item_e_commerce->long_description : '',
                    'post_excerpt' => isset($variation->item_e_commerce->short_description) ? $variation->item_e_commerce->short_description : '',
                    'post_status' => 'publish',
                    'post_type' => 'product_variation',
                    'post_parent' => $post_main_id,
                );
                $variation_data = apply_filters('xwcpos_create_post_fields_variation', $variation_data);
                $post_id = wp_insert_post($variation_data);

                if (!is_wp_error($post_id)) {

                    $this->xwcpos_add_product_taxonomy($variation, $post_id);
                    $fields = apply_filters('xwcpos_create_post_meta_variation', $this->map_kounta_fields($variation), $post_id);
                    if (!empty($fields)) {
                        foreach ($fields as $field_key => $value) {
                            update_post_meta($post_id, $field_key, $value);
                        }
                    }
                    update_post_meta($post_id, '_variation_description', $variation->description);

                    $itemattr = unserialize($variation->item_attributes);

                    $attribute_set = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_attributes . " WHERE item_attribute_set_id = %d", $itemattr->itemAttributeSetID));

                    for ($i = 1; $i <= 3; $i++) {

                        if (!empty(sanitize_title($attribute_set->{'attribute_name_' . $i}))) {

                            $attribute_slug = sanitize_title($attribute_set->{'attribute_name_' . $i});
                        } else {
                            $attribute_slug = '';
                        }

                        if (!empty($itemattr->{'attribute' . $i})) {

                            $attrbute_value = $itemattr->{'attribute' . $i};
                        } else {
                            $attrbute_value = '';
                        }

                        update_post_meta($post_id, 'attribute_' . $attribute_slug, $attrbute_value);

                    }

                    if ($sync) {
                        update_post_meta($post_id, '_xwcpos_sync', true);
                    }

                    $variation->wc_prod_id = $post_id;
                    // $product_images = apply_filters('xwcpos_create_product_images_variation', $this->add_kounta_product_images($variation), $post_id);

                    // if (count($product_images) > 0) {
                    //     set_post_thumbnail($post_id, $product_images[0]);
                    // }

                    $this->xwcpos_match_import_data_table($variation, $post_id, $sync);
                }

            }
        }

        public function xwcpos_ls_get_matrix_prods($matrix_id, $matrix_product = null)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $matrix_products = array();
            if (isset($matrix_product->wc_prod_id)) {
                $variations = get_children(
                    array(
                        'post_parent' => $matrix_product->wc_prod_id,
                        'post_type' => 'product_variation',
                    )
                );

                if (!empty($variations)) {
                    foreach ($variations as $post_id => $variation) {
                        $matrix_products[] = get_post_meta($post_id, '_xwcpos_ls_obj', true);
                    }
                }
            } elseif ($matrix_id > 0) {

                $matrix_ids = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_matrix_id = %d AND item_id > 0", $matrix_id));

                foreach ($matrix_ids as $id) {
                    $matrix_products[] = $this->xwcpos_get_sing_item_attr($id->item_id);
                }
            }

            return $matrix_products;
        }

        public function xwcpos_get_attributes($variations)
        {

            global $wpdb;
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            $choosen_attrs = array();

            foreach ($variations as $variation) {

                if (!is_null($variation->item_attributes)) {

                    $itemattr = unserialize($variation->item_attributes);

                    $attributeset_id = $itemattr->itemAttributeSetID;

                    $attribute_set = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_attributes . " WHERE item_attribute_set_id = %d", $attributeset_id));

                    $attribute_slug_1 = sanitize_title($attribute_set->attribute_name_1);
                    $attribute_slug_2 = sanitize_title($attribute_set->attribute_name_2);
                    $attribute_slug_3 = sanitize_title($attribute_set->attribute_name_3);

                    $item_attrs = array(
                        $itemattr->attribute1,
                        $itemattr->attribute2,
                        $itemattr->attribute3,
                    );

                    if (!isset($choosen_attrs[$attributeset_id])) {
                        if (!empty($attribute_slug_1)) {
                            $choosen_attrs[$attributeset_id][$attribute_slug_1] = array($item_attrs[0]);
                        }
                        if (!empty($attribute_slug_2)) {
                            $choosen_attrs[$attributeset_id][$attribute_slug_2] = array($item_attrs[1]);
                        }
                        if (!empty($attribute_slug_3)) {
                            $choosen_attrs[$attributeset_id][$attribute_slug_3] = array($item_attrs[2]);
                        }
                    } else {
                        if (isset($choosen_attrs[$attributeset_id][$attribute_slug_1])) {
                            array_push($choosen_attrs[$attributeset_id][$attribute_slug_1], $item_attrs[0]);
                        }
                        if (isset($choosen_attrs[$attributeset_id][$attribute_slug_2])) {
                            array_push($choosen_attrs[$attributeset_id][$attribute_slug_2], $item_attrs[1]);
                        }
                        if (isset($choosen_attrs[$attributeset_id][$attribute_slug_3])) {
                            array_push($choosen_attrs[$attributeset_id][$attribute_slug_3], $item_attrs[2]);
                        }
                    }
                }
            }

            foreach ($choosen_attrs as $id => $attribute_set) {
                foreach ($attribute_set as $attr_slug => $attr_val) {
                    $choosen_attrs[$id][$attr_slug] = array_filter(array_unique($choosen_attrs[$id][$attr_slug]));
                }
            }

            return $choosen_attrs;

        }

        public function xwcpos_add_product_taxonomy($item, $post_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

            if (!empty($item->categories)) {
              $categories = unserialize($item->categories);
              if(isset($categories)){
                
                foreach($categories as $cat_id){
                  $kounta_cat = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_categories . " WHERE cat_id = %d", $cat_id));

                  if (!is_null($kounta_cat) && $kounta_cat->wc_cat_id > 0) {
                      $wc_cat_id = intval($kounta_cat->wc_cat_id);
                      wp_set_object_terms($post_id, array($wc_cat_id), 'product_cat', true);
                  }
                }
                $cat_result = wp_remove_object_terms($post_id, 15, 'product_cat');
              }
            }

            //If tags
            if (!empty($item->tags)) {
                wp_set_object_terms($post_id, unserialize($item->tags), 'product_tag', true);
            }
        }

        public function map_kounta_fields($item, $matrix = false, $update = false)
        {
            if($item->wc_prod_id){
              $product = wc_get_product( $item->wc_prod_id );
              if($product){
                $wc_price = $product->get_price();
                $wc_saleprice = $product->get_sale_price();
                $k_price = $this->xwcpos_get_kounta_price($item);

                if($wc_saleprice != '' && ($k_price < $wc_price)){
                  $kounta_fields = array(
                    '_sale_price' => number_format((float) $k_price, 2, '.', ''),
                  );
                } else if ($wc_saleprice != '' && ($k_price >= $wc_price)){
                  $kounta_fields = array(
                    '_sale_price' => '',
                    '_price' => number_format((float) $k_price, 2, '.', ''),
                    '_regular_price' => number_format((float) $k_price, 2, '.', ''),
                  );
                } else {
                  $kounta_fields = array(
                    '_price' => number_format((float) $k_price, 2, '.', ''),
                    '_regular_price' => number_format((float) $k_price, 2, '.', ''),
                  );
                }
              }

            }

            $kounta_fields['_stock'] = isset($item->product_inventory) ? $item->product_inventory : 0;

            $sku = $this->xwcpos_get_kounta_sku($item);
            $valid_sku = $this->xwcpos_get_valid_sku($item, $sku, $update);
            if ($valid_sku) {
                $kounta_fields['_sku'] = $sku;
            } else {
                $kounta_fields['_sku'] = '';
            }

            if (!$update) {
                $kounta_fields['_visibility'] = 'visible';
                $kounta_fields['_manage_stock'] = $matrix ? 'no' : 'yes';
            }

            if ($kounta_fields['_stock'] > 0) {
                $kounta_fields['_stock_status'] = 'instock';
            } else if ($kounta_fields['_stock'] == 0) {
                $kounta_fields['_stock_status'] = 'outofstock';
            }

            foreach ($kounta_fields as $field_key => $field_val) {
                if (is_null($field_val)) {
                    unset($kounta_fields[$field_key]);
                }
            }

            return $kounta_fields;

        }

        public function xwcpos_get_kounta_price($item, $default_use_type_id = 1)
        {

            global $wpdb;

            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';

            $item_prices = $wpdb->get_results($wpdb->prepare(
              "SELECT * FROM " . $wpdb->xwcpos_item_prices . " WHERE xwcpos_item_id = %d", $item->product_id
            ));
            // var_dump($item_prices);

            if (empty($item_prices)) {
              // echo 'prices empty';
                return 0;
            }

            foreach ($item_prices as $price){
              return $price->amount;
            }

            return 0;
        }

        public function xwcpos_get_kounta_sku($item)
        {
            if (isset($item->product_sku) && !empty($item->product_sku)) {
                return $item->product_sku;
            }

            return '';
        }

        public function xwcpos_get_valid_sku($item, $sku, $update = false)
        {

            // if (isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
            //     return true;
            // }

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if($sku != null){
              $post_id = wc_get_product_id_by_sku($sku);
              //&& $update==false
              if ($post_id > 0 && $post_id != $item->wc_prod_id) {
                  // $xwcpos_message = '<div id="message" class="error"><p>Could not set SKU on product ' . $item->name . '. SKU ' . $sku . ' already exists.</p></div>';
                  // echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                  // return false;
              } else {
                  return true;
              }
            } else {
              return true;
            }
            
        }

        public function add_kounta_product_image($item)
        {          

            $attach_id = false;

            if (isset($item->image)) {

              //get image and store in upload folder

              
              
              // $filename = $item->item_id.'_'.$item->sku;
              // $uploaddir = wp_upload_dir();

              // $contents= file_get_contents($item->image);
              $img = media_sideload_image($item->image);
              $img = explode("'",$img)[1];
              $attach_id = attachment_url_to_postid($img);
              // $filetype = 'jpg';

              // $uploadfile = $uploaddir['path'] . '/' . $filename .'.'.$filetype;

              
              // $savefile = fopen($uploadfile, 'w');
              // fwrite($savefile, $contents);
              // fclose($savefile);

              // //insert into media library
              // $wp_filetype = wp_check_filetype(basename($filename), null );

              // $attachment = array(
              //     'post_mime_type' => $wp_filetype['type'],
              //     'post_title' => $filename,
              //     'post_content' => '',
              //     'post_status' => 'inherit'
              // );

              // $attach_id = wp_insert_attachment( $attachment, $uploadfile );

              // $imagenew = get_post( $attach_id );
              // $fullsizepath = get_attached_file( $imagenew->ID );
              // $attach_data = wp_generate_attachment_metadata( $attach_id, $fullsizepath );
              // wp_update_attachment_metadata( $attach_id, $attach_data );
            }

            return $attach_id;
        }

        public function xwcpos_get_wc_attach_id($product_image, $post_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

            if (isset($product_image->filename) && isset($product_image->base_image_url) && isset($product_image->public_id)) {

                $upload_dir = wp_upload_dir();
                $image_extension = pathinfo($product_image->filename, PATHINFO_EXTENSION);
                $image_url = $product_image->base_image_url . 'q_auto:eco/' . $product_image->public_id . '.' . $image_extension;
                $save_image_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . uniqid() . '.' . $image_extension;

                file_put_contents($save_image_path, @file_get_contents($image_url));
                if (file_exists($save_image_path)) {
                    $attachment_id = $this->wc_ext_create_attachment($save_image_path, $post_id, $product_image->description);
                    $wpdb->update(
                        $wpdb->xwcpos_item_images,
                        array('wp_attachment_id' => $attachment_id),
                        array('image_id' => $product_image->image_id),
                        array('%d'),
                        array('%d')
                    );

                    return $attachment_id;
                }
            }

            return 0;

        }

        public function wc_ext_create_attachment($filename, $parent_id, $content = '', $attachment_id = null, $include_files = true)
        {

            $user_id = empty($user_id) ? get_current_user_id() : $user_id;

            $wp_filetype = wp_check_filetype(basename($filename), null);
            $wp_upload_dir = wp_upload_dir();
            $post = get_post($parent_id);
            $attachment = array(
                'guid' => $wp_upload_dir['url'] . '/' . basename($filename),
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => get_the_title($parent_id),
                'post_content' => empty($post->post_content) ? basename($filename) : $post->post_content,
                'post_status' => 'inherit',
                'post_author' => $user_id,
            );
            $attachment_id = wp_insert_attachment($attachment, $filename, $parent_id);

            if ($include_files) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $filename);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
            return $attachment_id;
        }

        public function xwcpos_match_import_data_table($item, $post_id, $sync = false)
        {
          
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

            $args = array(
                'xwcpos_import_date' => current_time('mysql'),
                'wc_prod_id' => $post_id,
            );

            if ($sync) {
                $args['xwcpos_is_synced'] = true;
                $args['xwcpos_last_sync_date'] = current_time('mysql');
            }

            $wpdb->update(
                $wpdb->xwcpos_items,
                $args,
                array('id' => $item->product_id),
                array('%s', '%d'),
                array('%d', '%s')
            );

            // if (isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
            //     update_post_meta($post_id, '_xwcpos_matrix_id', $item->product_matrix_item_id);
            // } else

            if (isset($item->product_ls_id) && $item->product_ls_id > 0) {
                update_post_meta($post_id, '_xwcpos_item_id', $item->product_ls_id);
            }
        }

        public function xwcpos_process_single_sync($item_id, $synced, $importType)
        {
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            
            //BOOKMARK
            $item = $this->xwcpos_get_sing_item($item_id);

            //If product has been added to WC
            if (isset($item->wc_prod_id) && $item->wc_prod_id > 0) {

                update_post_meta($item->wc_prod_id, '_xwcpos_sync', $synced);

                $args = array(
                    'xwcpos_last_sync_date' => current_time('mysql'),
                    'xwcpos_is_synced' => $synced,
                );

                $wpdb->update(
                    $wpdb->xwcpos_items,
                    $args,
                    array('id' => $item->product_id),
                    array('%s', '%d')
                );

                if ($importType == 'single') {
                    $status_text = $synced ? 'enabled' : 'disabled';
                    $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					          <p>Sync successfully ' . $status_text . ' for this product!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                }
            } else {

            }
        }

        public function xwcpos_process_single_update($item_id, $importType)
        {
            try {
                $item = $this->xwcpos_get_sing_item($item_id);

                if (!$item) {
                    throw new Exception("Product not found in database (ID: {$item_id})");
                }

                // Fetch fresh data from Kounta API for complete product information
                $kounta_product = $this->fetch_kounta_product_by_id($item->product_ls_id);

                if (!$kounta_product) {
                    $this->plugin_log("[UPDATE ERROR] Failed to fetch product from Kounta API (item_id: {$item->product_ls_id})");
                    // Continue with update using database data only
                }

                $result = $this->update_xwcpos_product($item, $kounta_product, $importType);
                return $result;
            } catch (Exception $e) {
                $this->plugin_log("[UPDATE ERROR] " . $e->getMessage());
                $this->plugin_log("[UPDATE ERROR] Stack trace: " . $e->getTraceAsString());

                $allowed_tags = array(
                    'div' => array('class' => array(), 'id' => array()),
                    'p' => array(),
                    'strong' => array(),
                );

                $error_message = '<div id="message" class="error notice is-dismissible">
                    <p><strong>Update Failed:</strong> ' . esc_html($e->getMessage()) . '</p>
                    <p>Check the log file for more details: wp-content/uploads/brewhq-kounta.log</p>
                </div>';
                echo wp_kses($error_message, $allowed_tags);

                return false;
            }

        }

        public function update_xwcpos_product($item, $kounta_product, $importType)
        {

            if ($item->wc_prod_id > 0) {
                $this->xwcpos_update_woocommerce_product($item, $kounta_product, $importType);
            }

        }

        public function xwcpos_update_woocommerce_product($item, $kounta_product, $importType)
        {

            $variations_update = true;

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            //if ($importType == 'single') {

                if ($item->product_ls_id > 0) {
                    //single item
                    $xwcpos_message = '<div id="message" class="updated notice is-dismissible">
					            <p>Item updated successfully!!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                    return $this->update_woocommerce_single_item($item, $kounta_product);
                } else if ($item->product_ls_id > 0 && $item->product_matrix_item_id > 0) {
                    //single variation
                    $xwcpos_message = '<div id="message" class="updated notice is-dismissible">
					            <p>Item updated successfully!!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                    return $this->update_woocommerce_single_item($item, $kounta_product);
                } else if ((is_null($item->product_ls_id) || $item->product_ls_id == 0) && $item->product_matrix_item_id > 0) {
                    //matrix item
                    $xwcpos_message = '<div id="message" class="updated notice is-dismissible">
					            <p>Item updated successfully!!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                    return $this->update_woocommerce_matrix_item($item, $kounta_product, $variations_update);
                } else {
                    $xwcpos_message = '<div id="message" class="error notice is-dismissible">
					            <p>Could not process update, invalid product!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                    return false;
                }
            //}
        }

        public function update_woocommerce_single_item($item, $kounta_product = null)
        {

            $post_id = isset($item->wc_prod_id) ? $item->wc_prod_id : 0;
            if ($post_id > 0) {

                // Update title if sync is enabled and we have Kounta data
                if ($kounta_product && get_option('xwcpos_sync_titles', true)) {
                    $kounta_name = sanitize_text_field($kounta_product->name);
                    $current_title = get_the_title($post_id);

                    if ($current_title !== $kounta_name) {
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_title' => $kounta_name,
                        ));
                        $this->plugin_log("[UPDATE] Title updated for product {$post_id}: '{$current_title}' → '{$kounta_name}'");
                    }
                }

                // Update price if sync is enabled and we have Kounta data
                if ($kounta_product && get_option('xwcpos_sync_prices', true)) {
                    // Get site data for price
                    $site_data = null;
                    if (!empty($kounta_product->sites) && is_array($kounta_product->sites)) {
                        $site_data = $kounta_product->sites[0];
                    }

                    if ($site_data && isset($site_data->unit_price)) {
                        $kounta_price = floatval($site_data->unit_price);
                        $product = wc_get_product($post_id);

                        if ($product) {
                            $current_price = floatval($product->get_regular_price());

                            if ($current_price !== $kounta_price) {
                                $product->set_regular_price($kounta_price);
                                $product->set_price($kounta_price);
                                $product->save();
                                $this->plugin_log("[UPDATE] Price updated for product {$post_id}: {$current_price} → {$kounta_price}");
                            }
                        }
                    }
                }

                //Update categories/tags
                $this->xwcpos_add_product_taxonomy($item, $post_id);

                // Update stock and other meta fields
                $fields = apply_filters('xwcpos_update_post_meta_single_product', $this->map_kounta_fields($item, false, true), $post_id,);
                if (!empty($fields)) {
                    foreach ($fields as $field_key => $value) {
                        update_post_meta($post_id, $field_key, $value);
                    }
                }

                // Update images if sync is enabled and we have Kounta data
                $sync_images_enabled = get_option('xwcpos_sync_images', true);
                $this->plugin_log("[UPDATE] Image sync setting: " . ($sync_images_enabled ? 'enabled' : 'disabled'));
                $this->plugin_log("[UPDATE] Kounta product available: " . ($kounta_product ? 'yes' : 'no'));

                if ($kounta_product && $sync_images_enabled) {
                    $this->plugin_log("[UPDATE] Attempting to sync images for product {$post_id}");
                    require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-image-sync-service.php';
                    $image_sync = new Kounta_Image_Sync_Service();
                    // Respect the overwrite setting - FIXED: use correct option name
                    $overwrite = get_option('xwcpos_overwrite_images', false);
                    $this->plugin_log("[UPDATE] Image overwrite setting: " . ($overwrite ? 'enabled' : 'disabled'));
                    $result = $image_sync->sync_product_images($post_id, $kounta_product, $overwrite);
                    if ($result && isset($result['success'])) {
                        $this->plugin_log("[UPDATE] Image sync result: " . ($result['success'] ? 'success' : 'failed') . " - " . $result['message']);
                    }
                } else {
                    $this->plugin_log("[UPDATE] Skipping image sync - kounta_product: " . ($kounta_product ? 'yes' : 'no') . ", setting: " . ($sync_images_enabled ? 'enabled' : 'disabled'));
                }

                // Update descriptions if sync is enabled and we have Kounta data
                $sync_descriptions_enabled = get_option('xwcpos_sync_descriptions', true);
                $this->plugin_log("[UPDATE] Description sync setting: " . ($sync_descriptions_enabled ? 'enabled' : 'disabled'));

                if ($kounta_product && $sync_descriptions_enabled) {
                    $this->plugin_log("[UPDATE] Attempting to sync descriptions for product {$post_id}");
                    require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-description-sync-service.php';
                    $description_sync = new Kounta_Description_Sync_Service();
                    // Respect the overwrite setting - FIXED: use correct option name
                    $overwrite = get_option('xwcpos_overwrite_descriptions', false);
                    $this->plugin_log("[UPDATE] Description overwrite setting: " . ($overwrite ? 'enabled' : 'disabled'));
                    $result = $description_sync->sync_product_description($post_id, $kounta_product, $overwrite);
                    if ($result && isset($result['success'])) {
                        $this->plugin_log("[UPDATE] Description sync result: " . ($result['success'] ? 'success' : 'failed') . " - " . $result['message']);
                    }
                } else {
                    $this->plugin_log("[UPDATE] Skipping description sync - kounta_product: " . ($kounta_product ? 'yes' : 'no') . ", setting: " . ($sync_descriptions_enabled ? 'enabled' : 'disabled'));
                }

                if ($item->product_ls_id > 0 ) {

                    if (!empty($item->item_e_commerce)) {
                        $variation_description = apply_filters('xwcpos_update_variation_description', $item->item_e_commerce->short_description);
                        update_post_meta($post_id, '_variation_description', $variation_description);
                    }
                }

            }

            return $post_id;

        }

        public function update_woocommerce_matrix_item($item, $kounta_product = null, $variations_update = true)
        {

            $post_id = isset($item->wc_prod_id) ? $item->wc_prod_id : 0;
            if ($post_id > 0) {

                // Update title if sync is enabled and we have Kounta data
                if ($kounta_product && get_option('xwcpos_sync_titles', true)) {
                    $kounta_name = sanitize_text_field($kounta_product->name);
                    $current_title = get_the_title($post_id);

                    if ($current_title !== $kounta_name) {
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_title' => $kounta_name,
                        ));
                        $this->plugin_log("[UPDATE] Title updated for matrix product {$post_id}: '{$current_title}' → '{$kounta_name}'");
                    }
                }

                // Update descriptions if sync is enabled and we have Kounta data
                if ($kounta_product && get_option('xwcpos_sync_descriptions', true)) {
                    require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-description-sync-service.php';
                    $description_sync = new Kounta_Description_Sync_Service();
                    // Respect the overwrite setting - FIXED: use correct option name
                    $overwrite = get_option('xwcpos_overwrite_descriptions', false);
                    $description_sync->sync_product_description($post_id, $kounta_product, $overwrite);
                }

                // Update images if sync is enabled and we have Kounta data
                if ($kounta_product && get_option('xwcpos_sync_images', true)) {
                    require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-image-sync-service.php';
                    $image_sync = new Kounta_Image_Sync_Service();
                    // Respect the overwrite setting - FIXED: use correct option name
                    $overwrite = get_option('xwcpos_overwrite_images', false);
                    $image_sync->sync_product_images($post_id, $kounta_product, $overwrite);
                }

                $post_id = wp_update_post(
                    apply_filters('xwcpos_update_post_fields_matrix_product', array('ID' => $post_id))
                );

                if (!is_wp_error($post_id)) {
                    $this->xwcpos_add_product_taxonomy($item, $post_id);

                    $fields = apply_filters('xwcpos_import_post_meta_matrix_product', $this->map_kounta_fields($item), $post_id);
                    $update_fields = apply_filters('xwcpos_update_post_meta_matrix_item', $fields, $post_id);
                    foreach ($update_fields as $field_key => $value) {
                        update_post_meta($post_id, $field_key, $value);
                    }
                }

                //Product Images

                // $product_images = $this->add_kounta_product_images($item);
                // $product_images = apply_filters('xwcpos_import_product_images_single_product', $product_images, $post_id);
                // if (count($product_images) > 0) {
                //     if (!empty($product_images)) {
                //         set_post_thumbnail($post_id, $product_images[0]);
                //         unset($product_images[0]);
                //         update_post_meta($post_id, '_product_image_gallery', implode(',', $product_images));
                //     } else {
                //         delete_post_thumbnail($post_id);
                //         update_post_meta($post_id, '_product_image_gallery', '');
                //     }
                // }

            }

            if ($variations_update) {

                $variations = $this->xwcpos_ls_get_matrix_prods($item->product_matrix_item_id);

                if (!empty($variations)) {

                    $new_variations = array();
                    foreach ($variations as $variation) {
                        if ($variation->wc_prod_id > 0) {
                            $this->update_woocommerce_single_item($variation);
                        } elseif (is_null($variation->wc_prod_id)) {
                            $new_variations[] = $variation;
                        }
                    }

                    if (!empty($new_variations)) {

                        $itemattr = unserialize($variations[0]->item_attributes);
                        $this->set_item_attributes($itemattr->itemAttributeSetID, $post_id, $this->xwcpos_get_attributes($variations));

                        $sync = get_post_meta($post_id, '_xwcpos_sync', true);
                        $this->xwcpos_create_variations($new_variations, $sync, $post_id);

                    }
                }
            }

            $this->set_matrix_stock_status($post_id);

            return $post_id;

        }

        public function update_product_via_api($item)
        {
            $main_class_obj = new BrewHQ_Kounta_POS_Int();

            $xwcpos_single_relation = array(
                "sites",
                "categories",
            );

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

            $search_data = array();
            $search_str = '';

            if (is_array($item)) {
                $search_data = array(
                    'load_relations' => json_encode($xwcpos_single_relation),
                    'item_id' => 'IN,' . json_encode($item),
                );
                $search_str = '/products';
            } else if (isset($item->product_ls_id) && $item->product_ls_id > 0) {
                $search_data = array(
                    'load_relations' => json_encode($xwcpos_single_relation),
                );
                $search_str = '/products/' . $item->product_ls_id;

            }
            // elseif (!isset($item->product_ls_id) && isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
            //     $search_data = array(
            //         'load_relations' => json_encode($xwcpos_matrix_relation),
            //     );
            //     $search_str = '/ItemMatrix/' . $item->product_matrix_item_id;
            // }

            $ret_data = array('path' => $search_str, 'params' => $search_data);
            $endpoint = 'companies/' . $xwcpos_account_id . $ret_data['path'];

            $result = $main_class_obj->xwcpos_make_api_call($endpoint, 'Read', $ret_data['params']);
         

            if ($result == '401') {?>
				<div class="error"><p><?php echo esc_html__("401 Invalid access token. Please check API connection with Light Speed POS.", "xwcpos"); ?></p></div>

        <?php } else {

                  if ($result->id > 0) {

                      $itemData = $result->id;

                  } else {?>

            <div class="error"><p><?php echo esc_html__("invalid Kounta Product.", "xwcpos"); ?></p></div>

          <?php }

                if (isset($result->ItemMatrix->itemMatrixID) && $result->ItemMatrix->itemMatrixID > 0) {

                    $endpoint = 'Account/' . $xwcpos_account_id . '/Item/';
                    $search_params = array(
                        'load_relations' => json_encode($xwcpos_single_relation),
                        'itemMatrixID' => $item->product_matrix_item_id,
                    );

                    $new_variations = $main_class_obj->xwcpos_make_api_call($endpoint, 'Read', $search_params);

                    if ($new_variations == '401') {?>

					<div class="error"><p><?php echo esc_html__("401 Invalid access token. Please check API connection with Light Speed POS.", "xwcpos"); ?></p></div>

				<?php } else {

                        if (!empty($new_variations->Item)) {
                            if (is_array($new_variations->Item)) {
                                $new_variations = $new_variations->Item;
                            } else if (is_object($new_variations->Item)) {
                                $new_variations = array($new_variations->Item);
                            }

                            $this->xwcpos_update_variations($new_variations);
                        }

                    }

                } else {
                  // echo 'updating product database: '.$result->id;
                  // echo '<pre>';
                  // var_dump($result);
                  // echo '</pre>';
                  
                  $main_class_obj->update_item_data($result, $item);
                }
                //echo 'updating product database: '.$item->product_id;
                if(isset($item)){
                  return $item->product_id;
                } else {
                  return '';
                }
                

            }

        }

        public function update_product_via_api_2($item)
        {
          //echo 'updating via API';

            $main_class_obj = new BrewHQ_Kounta_POS_Int();

            $xwcpos_single_relation = array(
                "sites",
                "categories",
            );

            // $xwcpos_matrix_relation = array(
            //     "ItemECommerce",
            //     "Tags",
            //     "Images",
            // );

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $search_data = array();
            $search_str = '';

            if (is_array($item)) {
                $search_data = array(
                    'load_relations' => json_encode($xwcpos_single_relation),
                    'itemID' => 'IN,' . json_encode($item),
                );
                $search_str = '/products';

            } else if (isset($item->product_ls_id) && $item->product_ls_id > 0) {
                $search_data = array(
                    'load_relations' => json_encode($xwcpos_single_relation),
                );
                $search_str = '/products/' . $item->product_ls_id;

            }
            // elseif (!isset($item->product_ls_id) && isset($item->product_matrix_item_id) && $item->product_matrix_item_id > 0) {
            //     $search_data = array(
            //         'load_relations' => json_encode($xwcpos_matrix_relation),
            //     );
            //     $search_str = '/ItemMatrix/' . $item->product_matrix_item_id;
            // }

            $ret_data = array('path' => $search_str, 'params' => $search_data);
            $endpoint = 'companies/' . $xwcpos_account_id . $ret_data['path'];

            $result = $main_class_obj->xwcpos_make_api_call($endpoint, 'Read', $ret_data['params']);
         

            if ($result == '401') {?>
				<div class="error"><p><?php echo esc_html__("401 Invalid access token. Please check API connection with Light Speed POS.", "xwcpos"); ?></p></div>

			<?php } else {

                if ($result->id > 0) {

                    $itemData = $result->id;

                } else {?>

					<div class="error"><p><?php echo esc_html__("invalid Kounta Product.", "xwcpos"); ?></p></div>

				<?php }

                if (isset($result->ItemMatrix->itemMatrixID) && $result->ItemMatrix->itemMatrixID > 0) {

                    $endpoint = 'Account/' . $xwcpos_account_id . '/Item/';
                    $search_params = array(
                        'load_relations' => json_encode($xwcpos_single_relation),
                        'itemMatrixID' => $item->product_matrix_item_id,
                    );

                    $new_variations = $main_class_obj->xwcpos_make_api_call($endpoint, 'Read', $search_params);

                    if ($new_variations == '401') {?>

					<div class="error"><p><?php echo esc_html__("401 Invalid access token. Please check API connection with Light Speed POS.", "xwcpos"); ?></p></div>

				<?php } else {

                        if (!empty($new_variations->Item)) {
                            if (is_array($new_variations->Item)) {
                                $new_variations = $new_variations->Item;
                            } else if (is_object($new_variations->Item)) {
                                $new_variations = array($new_variations->Item);
                            }

                            $this->xwcpos_update_variations($new_variations);
                        }

                    }

                } else {
                  // echo 'updating product database: '.$result->id;
                  // echo '<pre>';
                  // var_dump($result);
                  // echo '</pre>';
                  
                    $this->update_item_data($result, $item);
                }
                //echo 'updating product database: '.$item->product_id;
                return $item->product_id;

            }

        }

        public function xwcpos_update_variations($new_variations)
        {

            $main_class_obj = new BrewHQ_Kounta_POS_Int();
            foreach ($new_variations as $new_var) {

                $item_database_id = $main_class_obj->xwcpos_item_id_check($new_var->itemID, $new_var->itemMatrixID);
                if ($item_database_id > 0) {

                    $old_item = $this->xwcpos_get_sing_item_for_update($item_database_id);
                    $main_class_obj->update_item_data($new_var, $old_item);
                } else {

                    $main_class_obj->xwcpos_insert_item($new_var);
                }
            }
        }

        public function xwcpos_process_single_delete($item_id, $importType)
        {

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if ($item_id > 0) {

                $this->xwcpos_delete_item($item_id);

                if ($importType == 'single') {

                    $xwcpos_message = '<div id="message" class="updated notice is-dismissible">
					<p>Product deleted successfully!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                }
            } else {

                if ($importType == 'single') {
                    $xwcpos_message = '<div id="message" class="error notice is-dismissible">
					<p>Could not delete this product!</p></div>';
                    echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);
                }
            }

        }

        // public function get_variation_for_del($item_id)
        // {

        //     global $wpdb;
        //     $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

        //     $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_items WHERE id = %d", $item_id));

        //     //$result2 = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_items WHERE item_matrix_id!=0 AND item_matrix_id = %d", $result->item_matrix_id));

        //     //return $result2;
        // }

        public function xwcpos_delete_item($item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            //$wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            //$wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            //$wpdb->xwcpos_item_ecomm = $wpdb->prefix . 'xwcpos_item_ecomm';

//$variations = $this->get_variation_for_del($item_id);

            // foreach ($variations as $variation) {

            //     $wpdb->query("DELETE FROM $wpdb->xwcpos_items WHERE id = " . $variation->id);
            //     $wpdb->query("DELETE FROM $wpdb->xwcpos_item_shops WHERE xwcpos_item_id = " . $variation->id);
            //     $wpdb->query("DELETE FROM $wpdb->xwcpos_item_prices WHERE xwcpos_item_id = " . $variation->id);
            //     //$wpdb->query("DELETE FROM $wpdb->xwcpos_item_images WHERE xwcpos_item_id = " . $variation->id);
            //     //$wpdb->query("DELETE FROM $wpdb->xwcpos_item_ecomm WHERE xwcpos_item_id = " . $variation->id);

            // }

            $wpdb->query("DELETE FROM $wpdb->xwcpos_items WHERE id = " . $item_id);
            $wpdb->query("DELETE FROM $wpdb->xwcpos_item_shops WHERE xwcpos_item_id = " . $item_id);
            $wpdb->query("DELETE FROM $wpdb->xwcpos_item_prices WHERE xwcpos_item_id = " . $item_id);
            //$wpdb->query("DELETE FROM $wpdb->xwcpos_item_images WHERE xwcpos_item_id = " . $item_id);
            //$wpdb->query("DELETE FROM $wpdb->xwcpos_item_ecomm WHERE xwcpos_item_id = " . $item_id);

        }

        public function xwcpos_process_bulk_action()
        {
            //$main_class_obj = new BrewHQ_Kounta_POS_Int();
            // $main_class_obj->sync_inventory();

            if ($this->current_action() == 'import_and_sync') {
                $this->xwcpos_process_bulk_import(true);
            }

            if ($this->current_action() == 'import') {
                $this->xwcpos_process_bulk_import(false);
            }

            if ($this->current_action() == 'enable_sync') {
                $this->xwcpos_process_bulk_sync(true);
            }

            if ($this->current_action() == 'disable_sync') {
                $this->xwcpos_process_bulk_sync(false);
            }

            if ($this->current_action() == 'update') {
                $this->xwcpos_process_bulk_update();
            }

            if ($this->current_action() == 'delete') {
                $this->xwcpos_process_bulk_delete();
            }
        }

        public function xwcpos_process_bulk_import($sync)
        {

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if (isset($_REQUEST['bulk-delete']) && is_array($_REQUEST['bulk-delete'])) {

                //$count = count($_REQUEST['bulk-delete']);
                $count = 0;

                foreach ($_REQUEST['bulk-delete'] as $key => $product_id) {

                    $result = $this->xwcpos_process_single_import($product_id, 'bulk', $sync);
                  if($result){
                    $count = $count+=1;
                  }
                }

                $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					<p>' . $count . ' Item(s) successfully added in WooCommerce!</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

            }

        }

        public function xwcpos_process_bulk_sync($sync)
        {

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if (isset($_REQUEST['bulk-delete']) && is_array($_REQUEST['bulk-delete'])) {

                $count = count($_REQUEST['bulk-delete']);

                foreach ($_REQUEST['bulk-delete'] as $key => $product_id) {

                    $result = $this->xwcpos_process_single_sync($product_id, $sync, 'bulk');

                }

                $status_text = $sync ? 'enabled' : 'disabled';
                $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					<p>Sync successfully ' . $status_text . ' for ' . $count . ' product(s)!</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

            }

        }

        public function xwcpos_process_bulk_update()
        {
            

          $this->plugin_log('/**** Process initiated: Bulk Update ****/ ');
          $this->plugin_log('Process initiated: Bulk Update');

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if (isset($_REQUEST['bulk-delete']) && is_array($_REQUEST['bulk-delete'])) {

              $main_class_obj = new BrewHQ_Kounta_POS_Int();
              $main_class_obj->sync_inventory();

                $count = count($_REQUEST['bulk-delete']);

                foreach ($_REQUEST['bulk-delete'] as $key => $product_id) {
                  $result = $this->xwcpos_process_single_update($product_id, 'bulk');
                  $this->plugin_log('Product updated. ID:'.$product_id);
                  usleep(250000);
                }

                $this->plugin_log('Process completed: Bulk update. Count:'.$count);

                $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					      <p>' . $count . ' Item(s) successfully updated!</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

            }

        }

        public function xwcpos_process_bulk_delete()
        {

            $allowed_tags = array(
                'a' => array(
                    'class' => array(),
                    'href' => array(),
                    'rel' => array(),
                    'title' => array(),
                ),
                'b' => array(),
                'div' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),

                'p' => array(
                    'class' => array(),
                ),
                'span' => array(
                    'class' => array(),
                    'title' => array(),
                    'style' => array(),
                ),
                'strike' => array(),
                'strong' => array(),
                'ul' => array(
                    'class' => array(),
                ),
            );

            if (isset($_REQUEST['bulk-delete']) && is_array($_REQUEST['bulk-delete'])) {

                $count = count($_REQUEST['bulk-delete']);

                foreach ($_REQUEST['bulk-delete'] as $key => $product_id) {

                    $result = $this->xwcpos_process_single_delete($product_id, 'bulk');

                }

                $xwcpos_message = '<div id="message" class="updated notice notice-success is-dismissible">
					<p>' . $count . ' Item(s) deleted successfully!</p></div>';
                echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

            }

        }

    }

    new BrewHQ_Kounta_Import_Table();

}