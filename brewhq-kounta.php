<?php

/*
 * Plugin Name:       BrewHQ Kounta
 * Plugin URI:        http://xavadigital.com
 * Description:       Integration between Kounta POS & WooCommerce for BrewHQ.
 * Version:           1.0.0
 * Author:            David Baird
 * Developed By:      Xava Digital
 * Author URI:        http://www.xavadigital.com/
 * Support URI:          http://xavadigital.com/
 * Text Domain:       xwcpos
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Check if WooCommerce is active
 * if wooCommerce is not active WooCommerce & Kounta POS Integration module will not work.
 **/
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function xwcpos_admin_notice()
    {

        // Deactivate the plugin
        deactivate_plugins(__FILE__);

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

        $xwcpos_message = '<div id="message" class="error">
				<p><strong>BrewHQ WooCommerce & Kounta POS Integration is inactive.</strong> The <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce plugin</a> must be active for this plugin to work. Please install &amp; activate WooCommerce »</p></div>';

        echo wp_kses(__($xwcpos_message, 'xwcpos'), $allowed_tags);

    }
    add_action('admin_notices', 'xwcpos_admin_notice');
}

if (!class_exists('BrewHQ_Kounta_POS_Int')) {

    class BrewHQ_Kounta_POS_Int
    {

        public function __construct()
        {

            $this->xwcpos_globconstants();

            // Load new optimized classes
            require_once XWCPOS_PLUGIN_DIR . 'includes/autoloader.php';

            add_action('init', array($this, 'xwcpos_init'));
            register_activation_hook(__FILE__, array($this, 'xwcpos_mod_tables'));
            require_once XWCPOS_PLUGIN_DIR . 'admin/class-wp-mosapicall.php';
            require_once XWCPOS_PLUGIN_DIR . 'admin/class-wp-moscurl.php';
            require_once XWCPOS_PLUGIN_DIR . 'admin/class-xwcpos-admin.php';

            // Load order logs admin page
            if (is_admin()) {
                require_once XWCPOS_PLUGIN_DIR . 'admin/class-kounta-order-logs-page.php';
            }

            add_action('wp_ajax_xwcposImpCats', array($this, 'xwcposImpCats'));
            add_action('wp_ajax_xwcposImpProds', array($this, 'xwcposImpProds'));
            add_action('wp_ajax_xwcposSyncAllProds', array($this, 'xwcposSyncAllProds'));
            //add_action('wp_ajax_xwcposSyncProds', array($this, 'xwcposSyncProds'));
            add_action('wp_ajax_xwcposCheckCart', array($this, 'xwcposCheckCart'));
            add_action('wp_ajax_xwcposSyncOrder', array($this, 'xwcposSyncOrder'));

            // New optimized sync handlers
            add_action('wp_ajax_xwcposSyncAllProdsOptimized', array($this, 'xwcposSyncAllProdsOptimized'));
            add_action('wp_ajax_xwcposSyncInventoryOptimized', array($this, 'xwcposSyncInventoryOptimized'));
            add_action('wp_ajax_xwcposGetSyncProgress', array($this, 'xwcposGetSyncProgress'));

            // Order retry handlers
            add_action('wp_ajax_xwcposGetFailedOrders', array($this, 'xwcposGetFailedOrders'));
            add_action('wp_ajax_xwcposRetryFailedOrders', array($this, 'xwcposRetryFailedOrders'));
            add_action('wp_ajax_xwcposClearFailedOrder', array($this, 'xwcposClearFailedOrder'));

            // Debug log handler
            add_action('wp_ajax_xwcposGetDebugLog', array($this, 'xwcposGetDebugLog'));

            // Cleanup handler
            add_action('wp_ajax_xwcposCleanupEmptyProducts', array($this, 'xwcposCleanupEmptyProducts'));

            //Schedule product sync function with WP CRON
            add_action('xwcposSyncAll_hook', array($this, 'xwcposSyncAllProdsCRON'));

            register_activation_hook( __FILE__, array($this, 'schedule_CRON') );

            //add_action('http_api_curl', array($this, 'xwcpos_curl_img_upload'), 10, 3);

            /**
             * TODO
             *
             * Display kounta order synced on orders admin table
             * Send order again button on Order actions. Update if already synced, add if not. https://www.skyverge.com/blog/add-woocommerce-custom-order-actions/
             * Enforce the show_online attribute from Kounta
             * Update customer when order sent if exist
             * Product min/max/increment
             * If top_seller, add tag
             * If 'special_item' add special price?
             * Select site from list
             *
             * Implement webhooks for:
             * Order status change
             * Low stock levels
             * Customer data update
             *
             */

            // Upload orders to Kounta when status changes to on-hold or processing
            // Note: Only using status change hooks to prevent duplicate uploads
            add_action('woocommerce_order_status_on-hold', array($this, 'xwcpos_add_order_to_kounta'), 9999);
            add_action('woocommerce_order_status_processing', array($this, 'xwcpos_add_order_to_kounta'), 9999);
            //add_action('woocommerce_order_status_completed', array($this, 'xwcpos_add_order_to_kounta'), 9999);

            add_action( 'init', array($this, 'script_enqueuer') );

            // Add product sync override meta box
            add_action('add_meta_boxes', array($this, 'xwcpos_add_sync_override_meta_box'));
            add_action('woocommerce_process_product_meta', array($this, 'xwcpos_save_sync_override_meta'));

            /* Check cart items for current stock levels */
            // add_action('woocommerce_before_checkout_process', array($this, 'xwcpos_update_inventory_checkout'));
            //add_action('woocommerce_check_cart_items', array($this, 'xwcpos_update_inventory_checkout'));

            add_action( 'woocommerce_before_cart', array($this, 'xwcpos_add_jscript_checkout_and_cart'), 9999 );
        }

        public function schedule_CRON() {
            if ( ! wp_next_scheduled( 'xwcposSyncAll_hook' ) ) {
                wp_schedule_event( time(), 'hourly', 'xwcposSyncAll_hook' );
            }
        }



        public function script_enqueuer() {

            wp_register_script( "xwcpos_ajax", plugin_dir_url(__FILE__).'/assets/js/xwcpos_ajax.js', array('jquery') );
            wp_localize_script( 'xwcpos_ajax', 'myAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' )));
            wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'xwcpos_ajax' );
        }

        public function xwcpos_add_jscript_checkout_and_cart() {
            global $wp;
            if ( is_checkout() || is_cart() ) {
                echo
                '<script>
              jQuery(document).ready(function(){
                  //alert("done");
                  jQuery.ajax({
                    type : "post",
                    dataType : "json",
                    url : myAjax.ajaxurl,
                    data : {action: "xwcposCheckCart"},
                    success: function(response) {
                      
                        if(response == "success") {
                          //jQuery("#like_counter").html(response.like_count);
                          //alert("Like added");
                        }
                        else {
                          //alert(response.message);
                          jQuery(".woocommerce-notices-wrapper").html("<ul class=\"woocommerce-error\" role=\"alert\"><li>Sorry, we do not have sufficient stock of Another No SKU. Please update your cart and try again.</li></ul>")
                        }
                    }
                  })




                  // $.ajax({
                  //   type: "POST",
                  //   url: ajaxurl,
                  //   data: { action: "xwcposImpCats" },
                  //   success: function (response) {
                  //     alert("Output: " + response);
                  //     var obj = {};
                  //     jQuery(".output").html("<pre>Xava</pre>");
                  //     try {
                  //       obj = JSON.parse(response);
                  //     } catch (err) {
                  //       console.log("Not JSON");
                  //     }

                  //     //jQuery(".output").html("<pre>" + obj + "</pre>");
                  //     jQuery(".spinner").hide();
                  //     if (obj) {
                  //       if (obj.cat_count) {
                  //         jQuery(".errosmessage").hide();
                  //         jQuery(".success_message").show();
                  //         jQuery(".success_message").html("<p>" + obj.cat_count + "</p>");
                  //       } else if (obj.err) {
                  //         jQuery(".success_message").hide();
                  //         jQuery(".errosmessage").show();
                  //         jQuery(".errosmessage").html("<p>" + obj.err + "</p>");
                  //       }
                  //     }
                  //   },
                  // });
                });
            </script>';
            }
        }

        public function xwcposCheckCart()
        {
            $result['type'] = "success";
            $messages = $this->xwcpos_update_inventory_checkout();

            if($messages != ""){
                $result['type'] = "error";
                $result['message'] = $messages;
            };


            echo $result;
            die();
        }

        public function xwcposSyncOrder(){
            //delete existing Kounta Order ID for testing purposes
            //update_post_meta( $_REQUEST["order_id"], '_kounta_id', '' );
            $order_id = $_REQUEST["order_id"];
            $response = $this->xwcpos_manual_add_order_to_kounta($order_id);


            if($response['success']){
                $result['success'] = true;
                $result['message'] = "Kounta Order ID: ".$response['order_id'];
            } else if($response['error']){
                $result['error'] = "Error: ".$response['error_type'];
                $result['message'] = $response['error_description'];
            }
            $result = json_encode($result);
            echo $result;
            die();
        }

        public function xwcpos_init()
        {
            if (function_exists('load_plugin_textdomain')) {
                load_plugin_textdomain('xwcpos', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            }

        }

        public function xwcpos_globconstants()
        {

            if (!defined('XWCPOS_URL')) {
                define('XWCPOS_URL', plugin_dir_url(__FILE__));
            }

            if (!defined('XWCPOS_BASENAME')) {
                define('XWCPOS_BASENAME', plugin_basename(__FILE__));
            }

            if (!defined('XWCPOS_PLUGIN_DIR')) {
                define('XWCPOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
            }

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

        public function xwcpos_mod_tables()
        {

            global $wpdb;

            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            // $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            // $wpdb->xwcpos_item_ecomm = $wpdb->prefix . 'xwcpos_item_ecomm';

            $charset_collate = '';

            if (!empty($wpdb->charset)) {
                $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
            }

            if (!empty($wpdb->collate)) {
                $charset_collate .= " COLLATE $wpdb->collate";
            }

            if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_items'") != $wpdb->xwcpos_items) {
                $sql1 = "CREATE TABLE " . $wpdb->xwcpos_items . " (
							id                      int(25) NOT NULL auto_increment,
							wc_prod_id              varchar(255) NULL,
              item_id                 varchar(255) NULL,
              code                    varchar(255) NULL,
							number                  varchar(255) NULL,
							sku                     varchar(255) NULL,
              name                    varchar(255) NULL,
							show_online             varchar(255) NULL,
							friendly_name           varchar(255) NULL,
							top_seller              varchar(255) NULL,
							unit_of_measure         varchar(255) NULL,
							measure                 varchar(255) NULL,
							is_sold                 varchar(255) NULL,
							deleted                 varchar(255) NULL,
							xwcpos_import_date      datetime,
							xwcpos_last_sync_date   datetime,
							xwcpos_is_synced		    int(25),
              create_time             datetime,
							time_stamp              datetime,
              description             text,
              tags                    text,
              categories              text,
							image                   varchar(255) NULL,
              special_item            varchar(255) NULL,
              updated_at              timestamp,
							created_at              timestamp,

							PRIMARY KEY (id)
							) $charset_collate;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql1);
            }

            if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_shops'") != $wpdb->xwcpos_item_shops) {
                $sql2 = "CREATE TABLE " . $wpdb->xwcpos_item_shops . " (
							id int(25) NOT NULL auto_increment,
							xwcpos_item_id          varchar(255) NULL,
							shop_id                 varchar(255) NULL,
							qoh                     varchar(255) NULL,
							item_id                 varchar(255) NULL,
				      updated_at              timestamp,
				      created_at              timestamp,
							PRIMARY KEY (id)
							) $charset_collate;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql2);
            }

            if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_prices'") != $wpdb->xwcpos_item_prices) {
                $sql3 = "CREATE TABLE " . $wpdb->xwcpos_item_prices . " (
							id int(25) NOT NULL auto_increment,
							xwcpos_item_id          varchar(255) NULL,
							site_id                 varchar(255) NULL,
							amount                  varchar(255) NULL,
				      updated_at              timestamp,
							created_at              timestamp,
							PRIMARY KEY (id)
							) $charset_collate;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql3);
            }

            // if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_images'") != $wpdb->xwcpos_item_images) {
            //     $sql4 = "CREATE TABLE " . $wpdb->xwcpos_item_images . " (
            //                 id int(25) NOT NULL auto_increment,
            //                 xwcpos_item_id varchar(255) NULL,
            //                 wp_attachment_id varchar(255) NULL,
            //                 item_id varchar(255) NULL,
            //                 item_matrix_id varchar(255) NULL,
            //                 image_id varchar(255) NULL,
            //                 description text,
            //                 filename varchar(255) NULL,
            //                 ordering varchar(255) NULL,
            //                 public_id varchar(255) NULL,
            //                 base_image_url varchar(255) NULL,
            //                 size varchar(255) NULL,
            //                 create_time datetime,
            //                 time_stamp datetime,
            //                 updated_at timestamp,
            //                 created_at timestamp,

            //                 PRIMARY KEY (id)
            //                 ) $charset_collate;";

            //     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            //     dbDelta($sql4);
            // }

            if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_categories'") != $wpdb->xwcpos_item_categories) {
                $sql5 = "CREATE TABLE " . $wpdb->xwcpos_item_categories . " (
							id int(25) NOT NULL auto_increment,
							wc_cat_id               varchar(255) NULL,
							cat_id                  varchar(255) NULL,
							name                    varchar(255) NULL,
							description             varchar(255) NULL,
							image                   varchar(255) NULL,
							show_online             varchar(255) NULL,
							friendly_name           varchar(255) NULL,
				      updated_at              timestamp,
							created_at              timestamp,

							PRIMARY KEY (id)
							) $charset_collate;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql5);
            }

            // if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_attributes'") != $wpdb->xwcpos_item_attributes) {
            //     $sql6 = "CREATE TABLE " . $wpdb->xwcpos_item_attributes . " (
            // 	id int(25) NOT NULL auto_increment,
            // 	item_attribute_set_id         varchar(255) NULL,
            // 	name                          varchar(255) NULL,
            // 	attribute_name_1              varchar(255) NULL,
            // 	attribute_name_2              varchar(255) NULL,
            // 	attribute_name_3              varchar(255) NULL,
            // 	system                        varchar(255) NULL,
            // 	archived                      varchar(255) NULL,
            //   updated_at                    timestamp,
            // 	created_at                    timestamp,

            // 	PRIMARY KEY (id)
            // 	) $charset_collate;";

            //     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            //     dbDelta($sql6);
            // }

            // if ($wpdb->get_var("SHOW TABLES LIKE '$wpdb->xwcpos_item_ecomm'") != $wpdb->xwcpos_item_ecomm) {
            //     $sql7 = "CREATE TABLE " . $wpdb->xwcpos_item_ecomm . " (
            // 	id int(25) NOT NULL auto_increment,
            // 	xwcpos_item_id                varchar(255) NULL,
            // 	item_e_commerce_id            varchar(255) NULL,
            // 	long_description              text,
            // 	short_description             text,
            // 	weight varchar(255)           NULL,
            // 	width varchar(255)            NULL,
            // 	height varchar(255)           NULL,
            // 	length varchar(255)           NULL,
            // 	list_on_store varchar(255)    NULL,
            //   updated_at                    timestamp,
            // 	created_at                    timestamp,

            // 	PRIMARY KEY (id)
            // 	) $charset_collate;";

            //     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            //     dbDelta($sql7);
            // }

        }

        /**
         * AJAX import categories
         */
        public function xwcposImpCats()
        {
            $result = $this->xwcpos_offset_categories_import();

            if ($result == '401') {

                echo json_encode(
                    array(
                        'err' => esc_html__($result . " Invalid access token. Please check API connection with Kounta POS."),
                    )
                );
                exit();
            }

            if (isset($result)) {

                $k_cats = $result;

                if (is_object($k_cats)) {
                    $single_lspos_category = $k_cats;
                    $k_cats = array($single_lspos_category);
                }

                foreach ($k_cats as $k_category) {
                    $this->insert_lspos_category($k_category);
                }

                // Generate the categories on the last call
                $this->xwcpos_save_categories_woo();
            }

            echo json_encode(
                array(
                    'cat_count' => $result->{'@attributes'}->count . esc_html__(" Categories Imported/Updated Successfully!", "xwcpos"),
                )
            );

            die();
        }

        public function insert_lspos_category($k_cat)
        {
            global $wpdb;
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

            $xwcpos_res = $wpdb->get_row("SELECT COUNT(*) as total FROM " . $wpdb->xwcpos_item_categories . " WHERE cat_id = " . $k_cat->id);

            if ($xwcpos_res->total == 0) {
                //insert row

                $fields = array(
                    '%d', '%d', '%s', '%s', '%s', '%d', '%s','%s'
                );

                $args = array(
                    'wc_cat_id' => isset($k_cat->wc_cat_id) ? $k_cat->wc_cat_id : null,
                    'cat_id' => isset($k_cat->id) ? $k_cat->id : null,
                    'name' => isset($k_cat->name) ? $k_cat->name : null,
                    'description' => isset($k_cat->description) ? $k_cat->description : null,
                    'image' => isset($k_cat->image) ? $k_cat->image : null,
                    'show_online' => $k_cat->show_online == true ? 1 : 0,
                    'friendly_name' => isset($k_cat->friendly_name) ? $k_cat->friendly_name : null,
                    'created_at' => current_time('mysql'),
                );
                $result = $wpdb->insert($wpdb->xwcpos_item_categories, $args, $fields);

                return $result;

            } else {
                //do nothing
            }

        }

        public function xwcpos_offset_categories_import()
        {

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

            $resource = 'companies/' . $xwcpos_account_id . '/categories';

            return $this->xwcpos_make_api_call($resource, 'Read', '');
        }

        public function xwcpos_make_api_call($controlname, $action, $query_str = '', $data = array(), $unique_id = null, Closure $callback = null)
        {
            //$this->plugin_log('Making API call. '.$controlname.' Query:'.$query_str);
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $xwcpos_token = esc_attr(get_option('xwcpos_access_token'));
            $client_id = esc_attr(get_option('xwcpos_client_id'));
            $client_secret = esc_attr(get_option('xwcpos_client_secret'));
            $refresh_token = esc_attr(get_option('xwcpos_refresh_token'));

            if (!empty($xwcpos_account_id)) {
                if (!empty($xwcpos_token)) {
                    $MOSAPI = new WP_MOSAPICall(null, $xwcpos_account_id, $xwcpos_token);
                } else if (!empty($client_id) && !empty($client_secret)) {
                    $MOSAPI = new WP_MOSAPICall((object) [
                        'username' => $client_id,
                        'password' => $client_secret,
                    ], '');
                }
            }

            $result = $MOSAPI->makeAPICall($controlname, $action, $unique_id, $data, $query_str, $callback);

            if (isset($result->httpCode) && $result->httpCode != '200') {

            }
            return $result;
        }

        public function xwcpos_save_categories_woo()
        {

            global $wpdb;
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

            $xwcpos_categories = $wpdb->get_results("SELECT * FROM " . $wpdb->xwcpos_item_categories);

            if (!empty($xwcpos_categories)) {
                foreach ($xwcpos_categories as $xwcpos_cat) {

                    // If category previously inserted
                    if (property_exists($xwcpos_cat, 'wc_cat_id') && $xwcpos_cat->wc_cat_id > 0) {
                        //if it still exists
                        if(get_term($xwcpos_cat->wc_cat_id)){
                            continue;
                        }
                    }

                    $slug = sanitize_title_with_dashes(str_replace('/', '-', $xwcpos_cat->name));

                    $term = wp_insert_term(
                        $xwcpos_cat->name,
                        'product_cat',
                        array('slug' => $slug)
                    );

                    if (!is_wp_error($term)) {
                        $wpdb->update(
                            $wpdb->xwcpos_item_categories,
                            array('wc_cat_id' => $term['term_id']),
                            array('id' => $xwcpos_cat->id),
                            array('%d'),
                            array('%d')
                        );
                    }
                }
            }
        }

        public function xwcposImpProds()
        {
            $items = $this->xwcpos_fetch_simple_items();
            $inventory = $this->get_kounta_inventory();

            if (isset($items->error)) {
                if($items->error == 'Limit exceeded'){
                    echo json_encode(
                        array(
                            'err' => esc_html__("API limited exceeded. Please wait and try again later", "xwcpos"),
                        )
                    );
                }else{
                    echo json_encode(
                        array(
                            'err' => esc_html__($items . " Invalid access token. Please check API connection with Kounta POS.", "xwcpos"),
                        )
                    );
                }
                exit();
            }

            // Add items to database
            if (!isset($items->error) && count($items)>0) {

                $percent = ceil(count($items) / 100) * 100;
                //echo $items->{'@attributes'}->count;
                $count = count($items);
                $xwcpos_items = $items;

                if (isset($xwcpos_items)) {
                    if (is_object($xwcpos_items)) {
                        $single_item = $xwcpos_items;
                        $xwcpos_items = array($single_item);
                    }

                    foreach ($xwcpos_items as $item) {
                        $this->xwcpos_insert_item($item, $inventory);
                    }
                }

                update_option('xwcpos_load_timestamp', date(DATE_ATOM));

                echo json_encode(
                    array(
                        'percent' => $percent,
                        'count' => $count . esc_html__(" Products Imported/Updated Successfully!", "xwcpos"),
                    )
                );
            }

            die();
        }

        public function xwcposSyncAllProdsCRON(){
            // Check if a sync is already running
            $sync_lock = get_transient('xwcpos_sync_in_progress');
            if ($sync_lock) {
                $this->plugin_log('CRON: Sync already in progress, skipping. Lock info: ' . json_encode($sync_lock));
                return;
            }

            $this->plugin_log('/**** CRON Process initiated: Optimized Sync ****/ ');

            // Set lock with timestamp and source
            $lock_info = array(
                'started' => current_time('mysql'),
                'source' => 'cron',
            );
            set_transient('xwcpos_sync_in_progress', $lock_info, 600); // 10 minute lock

            // Use the new optimized sync instead of the old method
            // This prevents duplicate API calls when CRON runs during manual sync
            try {
                $sync_service = new Kounta_Sync_Service();

                // First sync inventory
                $inventory_result = $sync_service->sync_inventory_optimized();

                if (!$inventory_result['success']) {
                    $this->plugin_log('CRON ERROR: Inventory sync failed - ' . $inventory_result['error']);
                    delete_transient('xwcpos_sync_in_progress');
                    return;
                }

                // Then sync products
                $product_result = $sync_service->sync_products_optimized(0); // 0 = all products

                $this->plugin_log(sprintf(
                    'CRON: Optimized sync completed - %d products updated in %.2f seconds',
                    $product_result['updated'],
                    $product_result['duration']
                ));

                // Release lock after successful completion
                delete_transient('xwcpos_sync_in_progress');

            } catch (Exception $e) {
                $this->plugin_log('CRON ERROR: ' . $e->getMessage());
                // Release lock on error
                delete_transient('xwcpos_sync_in_progress');
            }
        }

        public function xwcposSyncAllProds()
        {
            //get inventory from Kounta and update database
            $this->sync_inventory();

            //sync wc_product quantity with kounta database
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $site_id = get_option('xwcpos_site_id');


            $this->plugin_log('/**** Process initiated: Sync all ****/ ');
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            //get products with oldest sync date
            $xwcpos_items = $wpdb->get_results("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE xwcpos_last_sync_date > 0 ORDER BY xwcpos_last_sync_date ASC");
            $this->plugin_log('Products found: '.count( $xwcpos_items));
            $percent = ceil(count( $xwcpos_items) / 100) * 100;
            $checked_count = 0;
            $count = 0;
            $lapsed_count = 0;
            $refresh_time = 300;//seconds

            foreach($xwcpos_items as $item){
                $time = time();
                $date = new DateTime($item->xwcpos_last_sync_date, new DateTimeZone('Pacific/Auckland'));

                $last_sync = intval($date->format('U'));
                $lapsed = time() - $last_sync;
                //$this->plugin_log('Product ID:'.$item->id .' Lapsed: '.$lapsed);

                //if($item->wc_prod_id != null){
                if($item->wc_prod_id != null && $lapsed > $refresh_time){

                    //if($item->wc_prod_id == 4630){
                    $checked_count++;
                    $result = $this->xwcpos_fetch_item_inventory($item->wc_prod_id);
                    //get item price from Kounta
                    $k_product = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/products/'.$item->item_id, 'Read', '');
                    $site_index = array_search($site_id, array_column($k_product->sites, 'id'));
                    if($site_index !== null){
                        $prod_data = $k_product->sites[$site_index];
                        if($prod_data){
                            $old_item = (object)[];
                            $old_item->product_id = $item->id;
                            $old_item->wc_prod_id = $item->wc_prod_id;
                            $old_item->xwcpos_item_id = $item->id;

                            $site = (object)[];
                            $site = $prod_data;

                            $current_price = floatval($this->xwcpos_item_price_check($site, $old_item));
                            $kounta_price = $prod_data->unit_price;

                            if($current_price !== $kounta_price){
                                //if price is different
                                $this->xwcpos_update_item_price($site, $old_item);
                                $this->xwcpos_update_item_prices($k_product, $old_item);
                            }
                        }
                    }

                    if($result)
                        $count++;
                    //$this->plugin_log('Product updated. ID:'.$item->id . ' Last sync date: '.$item->xwcpos_last_sync_date . ' Is_synced: '.$item->xwcpos_is_synced .' Result: '.$result);
                    //usleep(250000);
                }
                if($lapsed < $refresh_time){
                    //$this->plugin_log('Product not updated. ID:'.$item->id . ' Last sync date: '.$item->xwcpos_last_sync_date . ' Is_synced: '.$item->xwcpos_is_synced .' Lapsed: '.$lapsed);
                    $lapsed_count++;
                }
            }

            $this->plugin_log('Products updated recently: '.$lapsed_count);
            $this->plugin_log('Products sync attempted: '.$checked_count);
            $this->plugin_log('Products sync successful: '.$count);

            $this->plugin_log('/**** Process completed: Sync all ****/ ');

            echo json_encode(
                array(
                    'percent' => $percent,
                    'count' => $count . esc_html__(" Products Synced Successfully!", "xwcpos"),
                )
            );
            die();
        }

        public function sync_inventory(){
            $inventory = $this->get_kounta_inventory();
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $site_id = get_option('xwcpos_site_id');
            $site = (object)[];
            $old_item = (object)[];

            foreach($inventory as $item){
                //get item with kounta id
                $result = $wpdb->get_var("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_id = $item->id");
                if($result){
                    $old_item->xwcpos_item_id = $result;
                    $site->id = $site_id;
                    $site->stock = $item->stock;
                    //$site->stock = 72;

                    $this->xwcpos_update_item_shop($site, $old_item );
                }
            }
        }

        public function get_kounta_inventory(){
            $this->plugin_log('Started getting inventory');
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $products = array();
            $products_remaining = true;
            $last_product_id = false;
            $site_id = get_option('xwcpos_site_id');

            while($products_remaining){
                if(!$last_product_id){
                    $paging = "";
                } else {
                    $paging = 'start='.$last_product_id;
                }

                $new_products = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/sites/'.$site_id.'/inventory', 'Read', $paging);

                if($new_products !== "Limit exceeded"){
                    if($new_products !== null && $new_products !== "" && !isset($new_products->error)){
                        $products = array_merge($products, $new_products);
                        $last_product_id = end($products)->id;
                    }
                    // } else if (isset($new_products->error)){
                    //   return $new_products;
                    // }

                    if(isset($new_products) && $new_products !== ""){
                        $count = count($new_products);
                        if($count < 100){
                            $products_remaining = false;
                        }
                        // $page++;
                    } else {
                        $products_remaining = false;
                    }
                } else {
                    $this->plugin_log('API limit exceeded while getting inventory');

                    $products_remaining = false;
                    //$return['error'] = "Limit exceeded";
                    // if(count($products)>0){

                    // }
                    return $products;
                }

            }

            return $products;
        }


        public function xwcpos_fetch_simple_items()
        {


            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

            // global $wpdb;
            // $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            // $xwcpos_categories = $wpdb->get_results("SELECT * FROM " . $wpdb->xwcpos_item_categories);

            // $products = array();

            // foreach($xwcpos_categories as $cat){
            //   $new_products = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/categories/'. $cat->cat_id . '/products', 'Read');
            //   if(!isset($new_products->error)){
            //     $products = array_merge($products, $new_products);
            //   }

            // }

            // return $products;

            //"https://api.kounta.com/v1/companies/27154/products.json?page=2"


            $products = array();
            $products_remaining = true;
            $page = 1;

            while($products_remaining){
                $new_products = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/products', 'Read', 'page='.$page);

                if($new_products !== "Limit exceeded"){
                    if($new_products !== null && !isset($new_products->error)){
                        $products = array_merge($products, $new_products);
                    } else if (isset($new_products->error)){
                        return $new_products;
                    }

                    if(isset($new_products)){
                        $count = count($new_products);
                        if($count < 100){
                            $products_remaining = false;
                        }
                        $page++;
                    } else {
                        $products_remaining = false;
                    }
                } else {
                    $products_remaining = false;
                    $return = array();
                    $return->error = "Limit exceeded";
                    return $return;
                }

            }


            return $products;

            //return $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/products', 'Read');

            wp_die();
        }

        public function xwcpos_fetch_variable_items()
        {

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

            $xwcpos_matrix_relation = array(
                "ItemECommerce",
                "Tags",
                "Images",
            );

            $search_data = array(
                'load_relations' => json_encode($xwcpos_matrix_relation),
            );

            $search_params = array_merge($search_data, array('ItemShops.shopID' => 'IN,[1,2,3,4,5,6,7,8,9,10]'));
            $search_data = apply_filters('xwcpos_import_prod_params', $search_data);
            return $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/products', 'Read', $search_data);
            die();
        }

        public function xwcpos_insert_item($item, $inventory)
        {

            global $wpdb;
            //get tables
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            // $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            // $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            // $wpdb->xwcpos_item_ecomm = $wpdb->prefix . 'xwcpos_item_ecomm';

            if ($item->name == "" || $item->name == null){
                return;
            }

            // Chceck if item in products table already
            $result = $this->xwcpos_item_already_exists($item);

            //if it is, then bail
            if ($result > 0) {
                return $result;
            }

            //get detailed product data
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $fullitem = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/products/' . $item->id, 'Read');

            $inv = array_search($fullitem->id, array_column($inventory,'id'));
            $stock = $inventory[$inv]->stock;

            if($fullitem){
                //collect product data for adding to database
                $mysql_args = $this->xwcpos_get_item_database_entries($fullitem);

                //insert into database
                $wpdb->insert($wpdb->xwcpos_items, $mysql_args, '');

                //the id of the record just added
                $xwcpos_last_insert_id = $wpdb->insert_id;

                //Insert shop items
                $shops = $this->xwcpos_add_shops_item($fullitem, $xwcpos_last_insert_id, $stock);
                //Item Prices
                $prices = $this->xwcpos_add_item_prices($fullitem, $xwcpos_last_insert_id);

                if(!$shops || !$prices){
                    //delete the item because something went wrong
                    $wpdb->delete( $wpdb->xwcpos_items, " WHERE id = $xwcpos_last_insert_id" );
                    if($shops){
                        $wpdb->delete( $wpdb->xwcpos_item_shops, " WHERE id = $xwcpos_last_insert_id" );
                    }
                    if($prices){
                        $wpdb->delete( $wpdb->xwcpos_item_prices, " WHERE id = $xwcpos_last_insert_id" );
                    }
                    return;

                }

                return $xwcpos_last_insert_id;
            }

            die();
        }

        public function xwcpos_item_already_exists($item)
        {

            //get product id
            $item_id = isset($item->id) ? $item->id : null;

            //check table
            $result = $this->xwcpos_item_id_check($item_id);

            return $result;
        }

        public function xwcpos_item_id_check($item_id)
        {
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $result = 0;
            if (empty($item_id)) {
                $result = $wpdb->get_var("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_id IS NULL");
            } else if ($item_id > 0) {
                $result = $wpdb->get_var("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_id = $item_id");
            }
            return $result;

            die();
        }

        public function xwcpos_get_item_database_entries($item, $custom_id = null, $custom_value = null)
        {
            //collate tag data
            $tags = null;
            if (isset($item->tags)) {
                if (is_object($item->tags)) {
                    $tags = array($item->tags->tag);
                } else if (is_array($item->tags)) {
                    $tags = $item->tags;
                }
            }

            //collate category data
            $categories = null;
            if (isset($item->categories)) {
                if (is_object($item->categories)) {
                    $categories = array($item->categories);
                } else if (is_array($item->categories)) {
                    $categories = $item->categories;
                }
            }

            // echo 'the item id: ' . $item->unit_of_measure . $item->measure;

            $fields = array(
                '%d', '%s', '%s', '%s', '%s', '%d',
                '%s', '%d', '%s', '%d', '%d', '%d',
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%d'
            );

            $args = array(
                'item_id' => isset($item->id) ? $item->id : null,
                'code' => isset($item->code) ? $item->code : null,
                'number' => isset($item->number) ? $item->number : null,
                'sku' => isset($item->sku) ? $item->sku : null,
                'name' => isset($item->name) ? $item->name : null,
                'show_online' => isset($item->show_online) && $item->show_online == true ? 1 : 0,

                'friendly_name' => isset($item->friendly_name) ? $item->friendly_name : null,
                'top_seller' => isset($item->top_seller) && $item->top_seller == true ? 1 : 0,
                'unit_of_measure' => isset($item->unit_of_measure) ? $item->unit_of_measure : null,
                'measure' => isset($item->measure) ? $item->measure : null,
                'is_sold' => isset($item->is_sold) && $item->is_sold == true ? 1 : 0,
                'deleted' => isset($item->deleted) && $item->deleted == true ? 1 : 0,

                'xwcpos_import_date' => isset($item->xwcpos_import_date) ? $item->xwcpos_import_date : null,
                'xwcpos_last_sync_date' => isset($item->xwcpos_last_sync_date) ? $item->xwcpos_last_sync_date : null,
                'xwcpos_is_synced' => isset($item->xwcpos_is_synced) ? $item->xwcpos_is_synced : null,
                'create_time' => isset($item->created_at) ? $item->created_at : null,
                'time_stamp' => isset($item->updated_at) ? $item->updated_at : null,
                'description' => isset($item->online_description) && $item->online_description !== "" ? $item->online_description : (isset($item->description)?$item->description:null),

                'tags' => maybe_serialize($tags),
                'categories' => maybe_serialize($categories),
                'image' => isset($item->image) ? $item->image : null,
                'special_item' => (isset($item->special_item) &&  $item->special_item) == true ? 1 : 0

            );

            return $args;
        }

        public function xwcpos_item_attribute_sets()
        {

            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $xwcpos_attribs = $this->xwcpos_make_api_call('Account/' . $xwcpos_account_id . '/ItemAttributeSet', 'Read', '');
            if (!empty($xwcpos_attribs->ItemAttributeSet) && is_array($xwcpos_attribs->ItemAttributeSet)) {
                foreach ($xwcpos_attribs->ItemAttributeSet as $xwcpos_attr) {
                    $this->xwcpos_insert_item_attribute_set($xwcpos_attr);
                }
            }
            die();
        }

        public function xwcpos_insert_item_attribute_set($xwcpos_attr)
        {

            global $wpdb;
            $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';
            $mysql_args = array(
                'item_attribute_set_id' => isset($xwcpos_attr->itemAttributeSetID) ? $xwcpos_attr->itemAttributeSetID : null,
                'name' => isset($xwcpos_attr->name) ? $xwcpos_attr->name : null,
                'attribute_name_1' => isset($xwcpos_attr->attributeName1) ? $xwcpos_attr->attributeName1 : null,
                'attribute_name_2' => isset($xwcpos_attr->attributeName2) ? $xwcpos_attr->attributeName2 : null,
                'attribute_name_3' => isset($xwcpos_attr->attributeName3) ? $xwcpos_attr->attributeName3 : null,
                'system' => isset($xwcpos_attr->system) ? $xwcpos_attr->system : null,
                'archived' => isset($xwcpos_attr->archived) ? $xwcpos_attr->archived : null,
                'created_at' => current_time('mysql'),
            );
            $fields = array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s');

            $wpdb->replace($wpdb->xwcpos_item_attributes, $mysql_args, $fields);
        }

        public function xwcpos_add_shops_item($item, $xwcpos_last_insert_id, $stock)
        {
            //add sites data to table

            if (!isset($item->sites)){
                return false;
            }
            else if (is_array($item->sites)) {
                $success = false;
                foreach ($item->sites as $item_shop) {
                    $item_shop->stock = $stock;
                    $res = $this->xwcpos_insert_shop_item($item->id, $item_shop, $xwcpos_last_insert_id);
                    if($res){
                        $success = true;
                    }
                }
                return $success;
            }

            return false;
        }

        public function xwcpos_insert_shop_item($item_id, $site, $xwcpos_item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $mysql_args = array(
                'xwcpos_item_id' => $xwcpos_item_id,
                'shop_id' => isset($site->id) ? $site->id : null,
                'qoh' => isset($site->stock) ? $site->stock : null,
                'item_id' => $item_id,
                'created_at' => current_time('mysql'),
            );

            $fields = array(
                '%d', '%d', '%d', '%d', '%s',
            );

            return $wpdb->insert($wpdb->xwcpos_item_shops, $mysql_args, $fields);
        }

        public function xwcpos_add_item_prices($item, $xwcpos_last_insert_id)
        {

            if (empty($item->sites)) {
                return false;
            } else {
                $success = false;
                $res = $this->xwcpos_insert_item_price($item->sites[0], $xwcpos_last_insert_id);
                if($res){
                    $success = true;
                }
                return $success;
            }

            return false;

        }

        public function xwcpos_insert_item_price($site, $xwcpos_item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $mysql_args = array(
                'xwcpos_item_id' => $xwcpos_item_id,
                'site_id'=> $site->id,
                'amount' => isset($site->unit_price) ? $site->unit_price : null,
                'created_at' => current_time('mysql'),
            );

            $fields = array(
                '%d', '%d', '%f', '%s',
            );

            $result = $wpdb->insert($wpdb->xwcpos_item_prices, $mysql_args, $fields);
            return $result;
        }

        // public function xwcpos_add_item_images($item, $xwcpos_last_insert_id)
        // {

        //     if (!isset($item->image)) {return;} else{
        //         $this->xwcpos_insert_item_image($item->image, $xwcpos_last_insert_id);
        //     }

        // }

        // public function xwcpos_insert_item_image($itemImage, $xwcpos_item_id)
        // {

        //     global $wpdb;
        //     $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

        //     $mysql_args = array(
        //         'xwcpos_item_id' => $xwcpos_item_id,
        //         'item_id' => isset($itemImage->itemID) ? $itemImage->itemID : null,
        //         'item_matrix_id' => isset($itemImage->itemMatrixID) ? $itemImage->itemMatrixID : null,
        //         'image_id' => isset($itemImage->imageID) ? $itemImage->imageID : null,
        //         'description' => isset($itemImage->description) ? $itemImage->description : null,
        //         'filename' => isset($itemImage->filename) ? $itemImage->filename : null,
        //         'ordering' => isset($itemImage->ordering) ? $itemImage->ordering : null,
        //         'public_id' => isset($itemImage->publicID) ? $itemImage->publicID : null,
        //         'base_image_url' => isset($itemImage->baseImageURL) ? $itemImage->baseImageURL : null,
        //         'size' => isset($itemImage->size) ? $itemImage->size : null,
        //         'create_time' => isset($itemImage->createTime) ? $itemImage->createTime : null,
        //         'time_stamp' => isset($itemImage->timeStamp) ? $itemImage->timeStamp : null,
        //         'created_at' => current_time('mysql'),
        //     );

        //     $fields = array(
        //         '%d', '%d', '%d', '%d', '%s', '%s',
        //         '%d', '%s', '%s', '%d', '%s', '%s',
        //         '%s',
        //     );

        //     $wpdb->insert($wpdb->xwcpos_item_images, $mysql_args, $fields);

        // }

        // public function xwcpos_add_ecommerce_item($itemEcommerce, $xwcpos_item_id)
        // {

        //     global $wpdb;
        //     $wpdb->xwcpos_item_ecomm = $wpdb->prefix . 'xwcpos_item_ecomm';

        //     $mysql_args = array(
        //         'xwcpos_item_id' => $xwcpos_item_id,
        //         'item_e_commerce_id' => isset($itemEcommerce->itemECommerceID) ? $itemEcommerce->itemECommerceID : null,
        //         'long_description' => isset($itemEcommerce->longDescription) ? $itemEcommerce->longDescription : null,
        //         'short_description' => isset($itemEcommerce->shortDescription) ? $itemEcommerce->shortDescription : null,
        //         'weight' => isset($itemEcommerce->weight) ? $itemEcommerce->weight : null,
        //         'width' => isset($itemEcommerce->width) ? $itemEcommerce->width : null,
        //         'height' => isset($itemEcommerce->height) ? $itemEcommerce->height : null,
        //         'length' => isset($itemEcommerce->length) ? $itemEcommerce->length : null,
        //         'list_on_store' => isset($itemEcommerce->listOnStore) ? $itemEcommerce->listOnStore : null,
        //         'created_at' => current_time('mysql'),
        //     );

        //     $fields = array('%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%s');

        //     $wpdb->insert($wpdb->xwcpos_item_ecomm, $mysql_args, $fields);

        // }

        // public function xwcposSyncProds()
        // {

        //     if (isset($_POST['product_id'])) {
        //         $product_id = (int) $_POST['product_id'];
        //     } else {
        //         return esc_html__('Could not find a product ID to sync with.', 'xwcpos');
        //     }

        //     $this->xwcpos_send_product_to_kounta($product_id);
        //     echo json_encode(
        //         array(
        //             'succ' => esc_html__('Product successfully synced with Kounta.', 'xwcpos'),
        //         )
        //     );

        //     die();
        // }

        // public function xwcpos_send_product_to_kounta($product_id)
        // {
        //     $wc_product = wc_get_product($product_id);

        //     if ($wc_product->is_type('variable')) {
        //         return $this->xwcpos_send_matrix_product($wc_product);
        //     } else if ($wc_product->is_type('simple')) {
        //         return $this->xwcpos_send_simple_product($wc_product);
        //     }

        //     return false;
        // }

        // public function xwcpos_send_simple_product($wc_product)
        // {

        //     $prepare_data_json = $this->xwcpos_product_json_data($wc_product);
        //     $k_prod_id = get_post_meta($wc_product->id, '_xwcpos_item_id', true );
        //     $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

        //     if($k_prod_id){
        //       $result = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id .'/products/'.$k_prod_id, 'Update', '', json_encode($prepare_data_json));
        //     } else {
        //       $result = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id .'/products', 'Create', '', json_encode($prepare_data_json));
        //     }


        //     if ($result == '401') {

        //       echo json_encode(
        //           array(
        //               'err' => esc_html__($result . "Connection with Kounta POS API is lost, please connect it first."),
        //           )
        //       );
        //       exit();
        //     } else {
        //       $this->xwcpos_process_images_upload($result->Item, $wc_product);
        //     }


        //     $this->xwcpos_map_woocommerce_product_with_ls($result->Item, $wc_product->get_id());

        // }

        // public function xwcpos_send_matrix_product($wc_product)
        // {

        //     $wc_attributes = $wc_product->get_attributes();
        //     if (count($wc_attributes) > 3) {

        //         echo json_encode(
        //             array(
        //                 'err' => esc_html__("Kounta POS only allows a maximum of 3 attributes for matrix products, your product has more than 3 attributes."),
        //             )
        //         );

        //         return false;
        //     }

        //     $matrix_product_json_data = $this->xwcpos_product_json_data($wc_product);
        //     $matrix_product_json_data->itemAttributeSetID = 4;
        //     $result = $this->xwcpos_make_api_call('Account.ItemMatrix', 'Create', '', json_encode($matrix_product_json_data));

        //     if (!is_wp_error($result) && isset($result->ItemMatrix->itemMatrixID)) {
        //         $this->xwcpos_process_images_upload($result->ItemMatrix, $wc_product);
        //         $this->xwcpos_map_woocommerce_product_with_ls($result->ItemMatrix, $wc_product->get_id());
        //     } else {
        //         return false;
        //     }

        //     $variations = $wc_product->get_available_variations();
        //     if (!empty($variations) && isset($result->ItemMatrix->itemMatrixID)) {

        //         foreach ($variations as $variation) {

        //             $variable_product = wc_get_product($variation['variation_id']);
        //             $prepare_data_json = $this->xwcpos_product_json_data($variable_product, $result->ItemMatrix->itemMatrixID);
        //             $this->prepare_product_attributes($prepare_data_json, $variable_product->get_variation_attributes());

        //             $res_variation = $this->xwcpos_make_api_call('Account.Item', 'Create', '', json_encode($prepare_data_json));
        //             if (!is_wp_error($res_variation) && isset($res_variation->Item)) {
        //                 $this->xwcpos_process_images_upload($res_variation->Item, $variable_product);
        //                 $this->xwcpos_map_woocommerce_product_with_ls($res_variation->Item, $variation['variation_id']);
        //             }
        //         }
        //     }

        //     return true;
        // }

        // public function prepare_product_attributes($item, $wc_pro_attributes)
        // {

        //     $ItemAttributes = new stdClass();
        //     $ItemAttributes->itemAttributeSetID = 4;
        //     $id = 1;
        //     foreach ($wc_pro_attributes as $key => $attr_val) {
        //         $ItemAttributes->{'attribute' . $id++} = $attr_val;
        //     }
        //     for ($i = 1; $i < 4; $i++) {
        //         if (!isset($ItemAttributes->{'attribute' . $i})) {
        //             $ItemAttributes->{'attribute' . $i} = "";
        //         }
        //     }

        //     $item->ItemAttributes = $ItemAttributes;

        // }

        // public function xwcpos_process_images_upload($Item, $wc_product)
        // {

        //     $wc_images = array();
        //     $images_results = array();

        //     if (!$wc_product->is_type('variation')) {
        //         $wc_images = $wc_product->get_gallery_image_ids();
        //     } else {
        //         $variation_id = $wc_product->variation_id;
        //     }
        //     array_unshift($wc_images, $wc_product->get_image_id());
        //     if (empty($wc_images)) {
        //         return;
        //     }

        //     $matrix_id = 0;
        //     if (!isset($Item->itemID) && isset($Item->itemMatrixID) && $Item->itemMatrixID > 0) {
        //         $re_id = "itemMatrixID";
        //         $matrix_id = $Item->itemMatrixID;
        //     } elseif ($Item->itemID > 0) {
        //         $re_id = "itemID";
        //     }

        //     $images_errors = array();
        //     if (!empty($wc_images) && is_array($wc_images) && !empty($re_id)) {
        //         foreach ($wc_images as $img_wc) {
        //             $image_result = $this->xwcpos_upload_image_on_ls($Item->{$re_id}, $img_wc, $matrix_id);

        //             if (is_wp_error($image_result)) {
        //                 $images_errors[] = $image_result;
        //             } else {
        //                 $images_results[] = $image_result;
        //             }
        //         }

        //     } else {
        //         $single_image_id = isset($variation_id) ? get_post_thumbnail_id($variation_id) : $wc_product->get_image_id();

        //         if ($single_image_id > 0 && !empty($re_id)) {
        //             $image_result = $this->xwcpos_upload_image_on_ls($Item->{$re_id}, $single_image_id, $matrix_id);
        //             if (is_wp_error($image_result)) {
        //                 $images_errors[] = $image_result;
        //             } else {
        //                 $images_results[] = $image_result;
        //             }
        //             $images_results[] = $image_result;
        //         }
        //     }

        //     if (!empty($images_results)) {
        //         $Item->Images = new stdClass();
        //         if (count($images_results) > 1) {
        //             $Item->Images->Image = $images_results;
        //         } else if (count($images_results) == 1) {
        //             $Item->Images->Image = $images_results[0];
        //         }
        //     }
        // }

        // public function xwcpos_upload_image_on_ls($ls_product_id, $wc_image_product_id, $matrix_id = 0)
        // {

        //     $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

        //     if (!function_exists('curl_init') || !function_exists('curl_exec') || !class_exists('CURLFile')) {
        //         return false;
        //     }

        //     $add_headers = function (WP_MOScURL &$curl, &$body) use ($wc_image_product_id, $matrix_id) {

        //         $headers = array(
        //             'accept' => 'application/xml',
        //             'wc-img-id' => $wc_image_product_id,
        //         );

        //         if ($matrix_id > 0) {
        //             $headers['matrix-id'] = $matrix_id;
        //         }

        //         $curl->setHTTPHeader($headers);
        //     };

        //     $item_path = $matrix_id > 0 ? "/ItemMatrix/" : "/Item/";

        //     $result = $this->xwcpos_make_api_call(
        //         'Account/' . $xwcpos_account_id . $item_path . $ls_product_id . '/Image',
        //         'Create',
        //         null,
        //         null,
        //         null,
        //         $add_headers
        //     );

        //     return $result;

        // }

        // public function xwcpos_product_json_data($wc_product, $matrix_id = 0)
        // {

        //     global $wpdb;
        //     $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';

        //     $kounta_product = new stdClass();
        //     $kounta_product->sku = $wc_product->get_sku();
        //     $kounta_product->name = $wc_product->get_title();
        //     //$kounta_product->defaultCost = empty($wc_product->get_regular_price()) ? '0.00' : $wc_product->get_regular_price();

        //     //$price = wc_get_price_excluding_tax($wc_product->id);

        //     if ($matrix_id > 0) {
        //         $attributes = $wc_product->get_variation_attributes();
        //         $kounta_product->description = $kounta_product->description . ' ' . implode(' ', $attributes);
        //     }

        //     if ($matrix_id > 0) {
        //         $kounta_product->itemMatrixID = $matrix_id;
        //     }

        //     //Product Categories
        //     $wc_categories = wp_get_post_terms($wc_product->get_id(), 'product_cat');
        //     if (!empty($wc_categories)) {

        //         if (isset($wc_categories[0]->term_id)) {

        //             $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_item_categories WHERE wc_cat_id = %d", $wc_categories[0]->term_id));

        //             if (!empty($result)) {

        //                 $kounta_product->categoryID = $result->category_id;
        //             }

        //         }

        //     }

        //     $this->xwcpos_build_json_item_e_commerce($kounta_product, $wc_product);

        //     $this->xwcpos_build_json_item_pricing($kounta_product, $wc_product, false);

        //     if ($wc_product->is_type('simple')) {
        //         $this->xwcpos_build_json_item_shop_data($kounta_product, $wc_product);
        //         return apply_filters('xwcpos_sync_with_ls_simple_product', $kounta_product, $wc_product);
        //     } else if ($wc_product->is_type('variable')) {
        //         return apply_filters('xwcpos_sync_with_ls_matrix_product', $kounta_product, $wc_product);
        //     } else if ($wc_product->is_type('variation')) {
        //         $this->xwcpos_build_json_item_shop_data($kounta_product, $wc_product);
        //         return apply_filters('xwcpos_sync_with_ls_variation_product', $kounta_product, $wc_product);
        //     } else {
        //         return false;
        //     }

        // }

        // public function xwcpos_build_json_item_e_commerce($lspeed_product, $wc_product)
        // {

        //     $itemeCommerce = new stdClass();
        //     $itemeCommerce->longDescription = $wc_product->get_description();
        //     $itemeCommerce->shortDescription = $wc_product->get_short_description();
        //     $weight = $wc_product->get_weight();
        //     $width = $wc_product->get_width();
        //     $height = $wc_product->get_height();
        //     $length = $wc_product->get_length();
        //     $itemeCommerce->weight = empty($weight) ? 0 : $weight;
        //     $itemeCommerce->width = empty($width) ? 0 : $width;
        //     $itemeCommerce->height = empty($height) ? 0 : $height;
        //     $itemeCommerce->length = empty($length) ? 0 : $length;
        //     $lspeed_product->ItemECommerce = $itemeCommerce;
        // }

        // public function xwcpos_build_json_item_pricing($lspeed_product, $wc_product, $set_sale_price = true)
        // {

        //     $lspeed_product->Prices = array();

        //     $ItemPriceDefault = new stdClass();
        //     $ItemPriceDefault->ItemPrice = new stdClass();
        //     $ItemPriceDefault->ItemPrice->useType = 'default';
        //     $ItemPriceDefault->ItemPrice->amount = empty($wc_product->get_regular_price()) ? '0.00' : $wc_product->get_regular_price();

        //     $ItemPriceMSRP = new stdClass();
        //     $ItemPriceMSRP->ItemPrice = new stdClass();
        //     $ItemPriceMSRP->ItemPrice->useType = 'MSRP';
        //     $ItemPriceMSRP->ItemPrice->amount = empty($wc_product->get_regular_price()) ? '0.00' : $wc_product->get_regular_price();

        //     $lspeed_product->Prices[] = $ItemPriceDefault;
        //     $lspeed_product->Prices[] = $ItemPriceMSRP;

        //     if ($set_sale_price) {
        //         $ItemPriceSale = new stdClass();
        //         $ItemPriceSale->ItemPrice = new stdClass();

        //         $ItemPriceSale->ItemPrice->amount =
        //         empty($wc_product->get_sale_price()) ? '0.00' : $wc_product->get_sale_price();

        //         $ItemPriceSale->ItemPrice->useType = 'Sale';
        //         $lspeed_product->Prices[] = $ItemPriceSale;
        //     }
        // }

        // public function xwcpos_build_json_item_shop_data($lspeed_product, $wc_product)
        // {

        //     $shop_data = get_option('xwcpos_site_data');
        //     if (isset($shop_data['xwcpos_store_data'])) {
        //         $shop_data = $shop_data['xwcpos_store_data'];
        //     } else {
        //         $shop_data = false;
        //     }

        //     $inventory = $wc_product->get_stock_quantity();

        //     $ItemShops = array();
        //     if (false !== $shop_data && isset($shop_data->Shop) && is_array($shop_data->Shop)) {
        //         foreach ($shop_data->Shop as $shop) {
        //             $ItemShop = new stdClass();
        //             $ItemShop->ItemShop = new stdClass();
        //             $ItemShop->ItemShop->shopID = $shop->shopID;
        //             $ItemShop->ItemShop->qoh = empty($inventory) ? 0 : $inventory;
        //             $ItemShops[] = $ItemShop;
        //         }
        //     } else if (false !== $shop_data && isset($shop_data->Shop) && is_object($shop_data->Shop)) {
        //         $ItemShop = new stdClass();
        //         $ItemShop->ItemShop = new stdClass();
        //         $ItemShop->ItemShop->shopID = $shop_data->Shop->shopID;
        //         $ItemShop->ItemShop->qoh = empty($inventory) ? 0 : $inventory;
        //         $ItemShops[] = $ItemShop;
        //     } else {
        //         return false;
        //     }
        //     $lspeed_product->ItemShops = $ItemShops;

        //     return true;

        // }

        // public function xwcpos_map_woocommerce_product_with_ls($ls_Item, $wc_product_id)
        // {

        //     $ls_Item->wc_prod_id = $wc_product_id;
        //     $ls_Item->xwcpos_is_synced = true;
        //     $ls_Item->xwcpos_import_date = current_time('mysql');
        //     $ls_Item->xwcpos_last_sync_date = current_time('mysql');
        //     update_post_meta($wc_product_id, '_xwcpos_sync', true);

        //     $xwcpos_id = $this->xwcpos_insert_item($ls_Item);

        //     $item = $this->xwcpos_get_sing_item($xwcpos_id);

        //     if ($item->item_id > 0 && $item->item_matrix_id == 0) {
        //         //single item
        //         update_post_meta($wc_product_id, '_xwcpos_item_id', $item->item_id);
        //     } else if ($item->item_id > 0 && $item->item_matrix_id > 0) {
        //         //single variation
        //         update_post_meta($wc_product_id, '_xwcpos_item_id', $item->item_id);
        //     } else if ((is_null($item->item_id) || $item->item_id == 0) && $item->item_matrix_id > 0) {
        //         //matrix item
        //         update_post_meta($wc_product_id, '_xwcpos_matrix_id', $item->item_matrix_id);
        //     }

        // }

        public function xwcpos_get_sing_item($item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

            $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_items WHERE id = %d", $item_id));

            return $result;

        }

        // public function xwcpos_curl_img_upload($curl_handle, $r, $url)
        // {

        //     if (isset($r['headers']['wc-img-id'])) {

        //         $wc_img_prod_id = (int) $r['headers']['wc-img-id'];

        //         $file_path = get_attached_file($wc_img_prod_id);

        //         $img_meta_data = wp_get_attachment_metadata($wc_img_prod_id);
        //         $thumb = false;
        //         if (isset($img_meta_data['sizes']['shop_single'])) {
        //             $thumb = $img_meta_data['sizes']['shop_single']['file'];
        //         } else if (isset($img_meta_data['sizes']['shop_catalog'])) {
        //             $thumb = $img_meta_data['sizes']['shop_catalog']['file'];
        //         } else if (isset($img_meta_data['sizes']['thumbnail'])) {
        //             $thumb = $img_meta_data['sizes']['thumbnail']['file'];
        //         }

        //         $file_path = $thumb ? str_replace(basename($file_path), $thumb, $file_path) : $file_path;

        //         $img_file = apply_filters('xwcpos_sync_to_ls_img_path', $file_path);

        //         if (false !== $img_file) {

        //             $matrix_id = isset($r['headers']['matrix-id']) ? (int) $r['headers']['matrix-id'] : false;
        //             $matrix_id_xml = $matrix_id > 0 ? '<itemMatrixID>' . $matrix_id . '</itemMatrixID>' : '';

        //             $body = array(
        //                 "data" => "<Image><filename>" . basename($img_file) . "</filename>" . $matrix_id_xml . "</Image>",
        //                 "image" => new CURLFile($img_file, mime_content_type($img_file), basename($img_file)),
        //             );

        //             unset($r['headers']['Content-Length']);
        //             unset($r['headers']['wc-img-id']);
        //             unset($r['headers']['matrix-id']);

        //             $headers = array();
        //             foreach ($r['headers'] as $name => $value) {
        //                 $headers[] = "{$name}: $value";
        //             }

        //             curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $headers);
        //             curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $body);
        //         }
        //     }
        // }

        public function get_product_data_by_id($wc_prod_id)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            // $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            //$wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            // $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

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
                item.wc_prod_id AS wc_product_id,
                item.sku AS product_sku,
                item.image AS product_image
              FROM $wpdb->xwcpos_items as item
               LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id
              LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
              WHERE item.wc_prod_id = %d", $wc_prod_id
            ));

            return $result;

        }

        public function xwcpos_product_inventory_sync($wc_product)
        {

            //if ( is_admin() ) { return; }

            if (!empty($wc_product->get_id())) {

                $xwcpos_product = $this->get_product_data_by_id($wc_product->get_id());

                if ($xwcpos_product && $xwcpos_product->product_ls_id > 0 && $xwcpos_product->product_matrix_item_id == 0) {

                    $data = array();
                    $data['itemID'] = $xwcpos_product->product_ls_id;

                    $stock = $wc_product->get_stock_quantity();
                    $data['ItemShops'] = $this->xwcpos_prepare_item_shops($xwcpos_product, $stock);

                    $data = apply_filters('xwcpos_add_wc_inventory_to_ls', $data, $stock, $wc_product->get_id());

                    $this->xwcpos_make_api_call(
                        'Account.Item',
                        'Update',
                        array('load_relations' => json_encode('all')),
                        $data,
                        $xwcpos_product->product_ls_id
                    );

                }

            }
        }

        public function xwcpos_manual_add_order_to_kounta($order_id){
            $order=wc_get_order($order_id);
            $order->add_order_note( 'Manual order sync initialised.');

            // Use optimized order service if available
            $use_optimized = get_option('xwcpos_use_optimized_order_sync', true);
            if ($use_optimized) {
                return $this->xwcpos_add_order_to_kounta_optimized($order_id);
            }

            return $this->xwcpos_add_order_to_kounta($order_id);
        }

        /**
         * Optimized order creation with retry logic (v2.0)
         *
         * @param int $order_id Order ID
         * @return array Response
         */
        public function xwcpos_add_order_to_kounta_optimized($order_id) {
            try {
                $order_service = new Kounta_Order_Service($this);
                return $order_service->create_order_with_retry($order_id);
            } catch (Exception $e) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note('Optimized order sync exception: ' . $e->getMessage());
                }

                return array(
                    'success' => false,
                    'error' => 'exception',
                    'error_description' => $e->getMessage(),
                );
            }
        }

        public function xwcpos_add_order_to_kounta($order_id){
            // Use optimized order service with logging if available
            $use_optimized = get_option('xwcpos_use_optimized_order_sync', true);
            if ($use_optimized) {
                return $this->xwcpos_add_order_to_kounta_optimized($order_id);
            }

            // Legacy order upload (no logging)
            $order=wc_get_order($order_id);
            $kounta_id = $order->get_meta('_kounta_id');

            // Check if order is already being uploaded (prevent race condition)
            $upload_lock = get_transient('xwcpos_uploading_order_' . $order_id);
            if ($upload_lock) {
                $this->plugin_log('Order ' . $order_id . ' is already being uploaded, skipping duplicate attempt');
                return array('error' => true, 'error_type' => 'duplicate_upload_attempt', 'error_description' => 'Order is already being uploaded');
            }

            if($kounta_id == null){
                // Set upload lock (30 second timeout)
                set_transient('xwcpos_uploading_order_' . $order_id, true, 30);

                $order->add_order_note( 'Starting upload of Kounta order');
                //find customer
                $customerID = $this->get_kounta_customer($order);

                //collate order item data
                $order_items = array();
                foreach($order->get_items() as $item){
                    $order_items[] = $this->individual_item_data_for_upload($item);
                }

                //add shipping cost as line item
                if($order->get_shipping_total()){
                    $order_items[] = array(
                        'product_id' => intval(get_option('xwcpos_shipping_product_id')),
                        'quantity' => 1,
                        'unit_price' => floatval($order->get_shipping_total())
                    );
                }



                // collate payment method data
                $payment_data = array($this->get_payment_method_for_upload($order));

                $site_id = get_option('xwcpos_site_id');

                //collate all order data for upload
                $order_data = array();
                $order_data['status'] = 'SUBMITTED';
                $order_data['sale_number'] = strval($order->get_id());
                $order_data['order_type'] = 'Delivery';
                $order_data['customer_id'] = $customerID;
                $order_data['site_id'] = intval($site_id);
                $order_data['lines'] = $order_items;
                $order_data['payments'] = $payment_data;
                $order_data['complete_when_paid'] = false;
                $order_data['pass_thru_printing'] = false;
                $order_data['placed_at'] = $order->get_date_created()->format('Y-m-d\Th:i:s\Z');

                $tries = 1;
                $max_tries = 1;
                while ($tries <= $max_tries) {
                    //Send to Kounta
                    //$order->add_order_note( 'Try:'.$tries);
                    try{
                        $result = $this->upload_order_to_kounta($order_data);
                    } catch (Exception $e) {
                        $order->add_order_note( 'Caught exception: '.$e->getMessage()."\n");
                        break;
                    }

                    //If successful, add metadata and notes to order
                    if(isset($result) && $result !== false && $result !== "" && !isset($result['error'])){
                        update_post_meta( $order_id, '_kounta_id', $result );
                        $order->add_order_note( 'Order uploaded to Kounta. Order#:'.$result);
                        $response['success'] = true;
                        $response['order_id'] = $result;

                        // Clear upload lock on success
                        delete_transient('xwcpos_uploading_order_' . $order_id);
                        break;
                    } elseif(isset($result['error'])){
                        $order->add_order_note( 'Order failed to upload to Kounta. '.$result['error'].' '.$result['error_description'].'Order data: '.json_encode($order_data));
                        $response['error'] = true;
                        $response['error_type'] = $result['error'];
                        $response['error_description'] = $result['error_description'];
                        $tries += 1;
                    } else{
                        $order->add_order_note( 'Order failed to upload to Kounta with no error message.'.json_encode($order_data));
                        $tries += 1;
                    }

                    if($tries > $max_tries){
                        $order->add_order_note( 'All attempts failed. Email sent to David.');
                        $this->send_admin_error_email($order_id, $order_data);
                        //$order->add_order_note( 'Order data: '.json_encode($order_data));

                        // Clear upload lock on failure
                        delete_transient('xwcpos_uploading_order_' . $order_id);
                    }
                }

                // Clear upload lock if we exit the loop without success
                delete_transient('xwcpos_uploading_order_' . $order_id);

            } else {
                $order->add_order_note( 'Upload attempted. Order already exists. Order#:'.$kounta_id);
                $response['error'] = true;
                $response['error_type'] = 'Order already exists. '.$kounta_id;
                $response['error_description'] = 'This order already has a Kounta order ID';
            }
            return $response;
        }

        public function send_admin_error_email($order_id, $order_data){

            $to      = 'david@xavadigital.com';
            $subject = 'BrewHQ Order Upload Error';
            $message = 'An order has failed to sync to Kounta. Order#: '.$order_id.'<br/>'.json_encode($order_data);
            $headers = 'From: webmaster@brewhq.co.nz'       . "\r\n" .
                'Reply-To: webmaster@brewhq.co.nz' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();

            mail($to, $subject, $message, $headers);
        }

        public function upload_order_to_kounta($order_data){
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $endpoint = 'companies/' . $xwcpos_account_id . '/orders';

            try{
                $order = $this->get_order_by_sale_number($order_data);

                if($order == false){

                    $result = $this->xwcpos_make_api_call($endpoint, 'Create','', json_encode($order_data));

                    //check that the follow triggers if order upload successful.
                    if($result == null){
                        //short delay to avoid false positive of the following check
                        usleep(250000);
                        $order = $this->get_order_by_sale_number($order_data);
                        // TODO possibly add multiple order upload attempts if check returns false
                        if($order !== false){
                            return $order->id;
                        } else {
                            $return['error'] = "Order upload failed order check";
                            $return['error_description'] = "Order does not appear to be present";
                            return $return;
                        }
                    } elseif(isset($result->error)) {
                        $return['error'] = $result->error;
                        $return['error_description'] = $result->error_message;
                        return $return;
                    } else{
                        return false;
                    }
                } else return $order->id;
            } catch (Exception $e) {
                return $this->upload_order_to_kounta($order_data);
                //$e->getMessage();
            }
            return false;


        }

        // public function update_order_on_kounta($order_data, $kounta_id){
        //   $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
        //   $endpoint = 'companies/' . $xwcpos_account_id . '/orders/'. $kounta_id;

        //   $result = $this->xwcpos_make_api_call($endpoint, 'Update','', json_encode($order_data));

        //   if($result == null){
        //     return true;
        //   } elseif(isset($result->error)) {
        //     return $result;
        //   }
        //   return false;
        // }

        public function get_order_by_sale_number($order_data){
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $endpoint = 'companies/' . $xwcpos_account_id . '/orders';

            $order_date = new DateTime($order_data['placed_at']);
            $order_date->modify('+1 day');
            $tomorrow = $order_date->format('Y-m-d');
            $order_date->modify('-2 day');
            $yesterday = $order_date->format('Y-m-d');

            $total = 0;

            foreach($order_data['payments'] as $payment){
                $total += $payment['amount'];
            }

            $total_lte = $total+0.05;
            $total_gte = $total-0.05;


            if(isset($order_data['sale_number'])){
                $orders = $this->xwcpos_make_api_call($endpoint, 'Read','created_gte='.$yesterday.'&created_lte='.$tomorrow.'&value_gte='.$total_gte.'&value_lte='.$total_lte);
                if($orders && is_array($orders) && count($orders) > 0){
                    foreach($orders as $order){
                        if(str_starts_with($order->sale_number, $order_data['sale_number'])) return $order;
                        //if($order->sale_number == $saleNum) return $order;
                    }
                }
            }
            return false;
        }

        public function individual_item_data_for_upload($item)
        {
            $product_id = $item->get_product_id();
            $data = array();
            $data['product_id'] = intval(get_post_meta($product_id, '_xwcpos_item_id', true ));
            $data['quantity'] = $item->get_quantity();
            $data['unit_price'] = floatval($item->get_total())/$item->get_quantity();

            return $data;
        }

        public function get_payment_method_for_upload($order)
        {
            $xwcpos_payment_gateways = json_decode(get_option('xwcpos_payment_gateways'), true);
            $wc_method = $order->get_payment_method();
            $payment_data = array();
            $payment_data['method_id'] = intval($xwcpos_payment_gateways[$wc_method]['kounta_pm']);
            $payment_data['amount'] = floatval($order->calculate_totals());
            $payment_data['ref'] = $order->get_transaction_id() ? $order->get_transaction_id() : $order->get_order_number();

            return $payment_data;
        }

        public function get_kounta_customer($order)
        {

            $customerDetails = array(
                "first_name" => $order->get_billing_first_name(),
                "last_name" => $order->get_billing_last_name(),
                "email" => $order->get_billing_email(),
                "phone" => $order->get_billing_phone(),
                'primary_address' => array(
                    'address' => $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postal_code' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country()
                ),
                'shipping_address' => array(
                    'address' => $order->get_shipping_address_1() && $order->get_shipping_address_2()? $order->get_shipping_address_1() . ', ' . $order->get_shipping_address_2(): $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
                    'city' => $order->get_shipping_city()? $order->get_shipping_city(): $order->get_billing_city(),
                    'state' => $order->get_shipping_state()?  $order->get_shipping_state():  $order->get_billing_state(),
                    'postal_code' => $order->get_shipping_postcode()? $order->get_shipping_postcode(): $order->get_billing_postcode(),
                    'country' => $order->get_shipping_country()? $order->get_shipping_country():$order->get_billing_country()
                )
            );

            $customerID = '';
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $endpoint = 'companies/' . $xwcpos_account_id . '/customers';

            if(isset($customerDetails['email'])){
                $result = $this->get_customer_by_email($customerDetails['email']);
                if($result){
                    $customerID = $result;
                }
                //log message
            }

            if(!$customerID){
                $result = $this->get_customer_by_details($customerDetails);
                if($result){
                    $customerID = $result;
                }
                //log message
            }

            //if customer not found, create customer
            if(!$customerID) {
                $result = $this->xwcpos_make_api_call($endpoint, 'Create', '', $customerDetails);
                if (!isset($result->error)) {
                    $customerID = $this->get_customer_by_email($customerDetails['email']);
                } else {
                    //log $result->error, $result->error_description and user details
                }
            }

            return $customerID;
        }

        public function get_customer_by_email($email)
        {
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $endpoint = 'companies/' . $xwcpos_account_id . '/customers';
            $customerID = '';

            $result = $this->xwcpos_make_api_call($endpoint, 'Read', 'email='.$email);
            if($result && !$result->error && $result->id){
                $customerID = $result->id;
            }

            return $customerID;
        }

        public function get_customer_by_details($customerDetails)
        {
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $endpoint = 'companies/' . $xwcpos_account_id . '/customers';
            $customerID = '';

            $fname = $customerDetails['first_name'];
            $lname = $customerDetails['last_name'];
            $phone = $customerDetails['phone'];

            if(isset($phone) && isset($fname) && isset($lname)){
                $query_str = 'first_name='.$fname.'&last_name='.$lname.'&phone='.$phone;
                $result = $this->xwcpos_make_api_call($endpoint, 'Read', $query_str);
                if($result && $result->id){
                    $customerID = $result->id;
                }
            }

            return $customerID;
        }

        public function xwcpos_create_new_item_shop($item_shop_id, $shop_id, $stock)
        {
            $new_item_shop = array();
            $new_item_shop['itemShopID'] = $item_shop_id;
            $new_item_shop['qoh'] = $stock;
            $new_item_shop['shopID'] = $shop_id;

            return $new_item_shop;
        }

        public function xwcpos_prepare_item_shops($xwcpos_product, $stock)
        {

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_item_shops WHERE xwcpos_item_id = %d", $xwcpos_product->product_id));

            $item_shops_data = array();
            $item_shops = $results;

            if (!empty($item_shops)) {
                foreach ($item_shops as $item_shop) {
                    if (0 != $item_shop->shop_id) {
                        $new_item_shop = $this->xwcpos_create_new_item_shop(
                            $item_shop->item_shop_id,
                            $item_shop->shop_id,
                            $stock
                        );

                        $item_shops_data['ItemShop'][] = $new_item_shop;
                    }
                }
            }

            return $item_shops_data;

        }

        /**
         * Check stock levels of items in cart
         */
        public function xwcpos_update_inventory_checkout()
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $site_id = get_option('xwcpos_site_id');
            $site = (object)[];
            $old_item = (object)[];

            $full_message = "";


            $cart_data = WC()->cart->cart_contents;
            if (empty($cart_data)) {
                return;
            }

            $cart_products = array();
            foreach ($cart_data as $item_data) {
                $data_id = $item_data['variation_id'] > 0 ? $item_data['variation_id'] : $item_data['product_id'];
                $k_prod_id = get_post_meta($data_id, '_xwcpos_item_id', true );
                array_push($cart_products,$k_prod_id);
            }

            //$this->sync_inventory();
            $inventory = $this->get_cart_products_kounta_inventory($cart_products);

            if(count($inventory) > 0){
                foreach ($cart_data as $item_data) {
                    //check cart items against retrieved Kounta inventory
                    $data_id = $item_data['variation_id'] > 0 ? $item_data['variation_id'] : $item_data['product_id'];
                    $k_prod_id = get_post_meta($data_id, '_xwcpos_item_id', true );
                    $k_stock_id = array_search($k_prod_id, array_column($inventory, 'id'));
                    $k_stock = $inventory[$k_stock_id]->stock;

                    if($k_stock && $k_stock < $item_data['quantity'] ){
                        $msg = sprintf(
                            esc_html__('Sorry, we do not have sufficient stock of %s. Please update your cart and try again.', 'xwcpos'),
                            get_the_title($data_id)
                        );

                        $full_message .= $msg;

                        wc_add_notice($msg, 'error');
                    }

                    if($k_stock && $k_stock !== $item_data['quantity'] ){
                        //update stock

                        $item = $inventory[$k_stock_id];

                        //get item with kounta id
                        $result = $wpdb->get_var("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_id =". $item->id);
                        $old_item->xwcpos_item_id = $result;

                        if($result){
                            $site->id = $site_id;
                            $site->stock = $item->stock;
                            //$site->stock = 72;

                            $this->xwcpos_update_item_shop($site, $old_item );
                        }
                    }
                }
            }
            return $full_message;
        }

        public function get_cart_products_kounta_inventory($cart_products){
            $this->plugin_log('Started getting inventory');
            $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            $inventory = array();
            $site_id = get_option('xwcpos_site_id');

            foreach ($cart_products as $cart_item) {
                $page = $this->xwcpos_make_api_call('companies/' . $xwcpos_account_id . '/sites/'.$site_id.'/inventory', 'Read',  $paging = 'start='.($cart_item-1));
                if($page[0]->id == $cart_item){
                    array_push($inventory, $page[0]);
                }
            }
            return $inventory;
        }

        public function xwcpos_update_all_inventory_checkout()
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $site_id = get_option('xwcpos_site_id');
            $site = (object)[];
            $old_item = (object)[];

            $full_message = "";


            $cart_data = WC()->cart->cart_contents;
            if (empty($cart_data)) {
                return;
            }
            //$this->sync_inventory();
            $inventory = $this->get_kounta_inventory();

            if(count($inventory) > 0){
                foreach ($cart_data as $item_data) {
                    //check cart items against retrieved Kounta inventory
                    $data_id = $item_data['variation_id'] > 0 ? $item_data['variation_id'] : $item_data['product_id'];
                    $this->xwcpos_fetch_item_inventory($data_id);
                    $product = wc_get_product($data_id);
                    $k_prod_id = get_post_meta($data_id, '_xwcpos_item_id', true );
                    $k_stock_id = array_search($k_prod_id, array_column($inventory, 'id'));
                    $k_stock = $inventory[$k_stock_id]->stock;

                    if($k_stock && $k_stock < $item_data['quantity'] ){
                        $msg = sprintf(
                            esc_html__('Sorry, we do not have sufficient stock of %s. Please update your cart and try again.', 'xwcpos'),
                            get_the_title($data_id)
                        );

                        $full_message .= $msg;

                        wc_add_notice($msg, 'error');
                    }

                    if($k_stock && $k_stock !== $item_data['quantity'] ){
                        //update stock

                        $item = $inventory[$k_stock_id];

                        //get item with kounta id
                        $result = $wpdb->get_var("SELECT * FROM " . $wpdb->xwcpos_items . " WHERE item_id =". $item->id);
                        $old_item->xwcpos_item_id = $result;

                        if($result){
                            $site->id = $site_id;
                            $site->stock = $item->stock;
                            //$site->stock = 72;

                            $this->xwcpos_update_item_shop($site, $old_item );
                        }
                    }
                }
            }
            return $full_message;
        }

        public function xwcpos_fetch_item_inventory($wc_product_id)
        {
            //Get the product data from the Kounta item database tables
            $xwcpos_product = $this->get_product_data_by_id($wc_product_id);

            if($wc_product_id != null){

                $product = wc_get_product($wc_product_id);
                if($product){
                    if($product->get_status() != 'trash'){
                        //the product exists and is not trashed
                        //If the product is not set to sync, just escape
                        $wcp_post_meta = get_post_meta($wc_product_id, '_xwcpos_sync', true);
                        if (!$wcp_post_meta ) {
                            return false;
                        }
                    } else {
                        if($product->get_status() == 'trash'){
                            $result = $this->update_item_disassociate($xwcpos_product);
                        } else {
                            //the product doesn't exist
                            //search for a product with a matching SKU
                            $product_id = wc_get_product_id_by_sku($xwcpos_product->sku);
                            $product = wc_get_product($product_id);
                            if($product && $product->get_status() != 'trash'){
                                $result = $this->update_item_wc_prod_id($xwcpos_product, $product);
                                $update = update_post_meta($product, '_xwcpos_item_id', $xwcpos_product->product_id);
                                if ($xwcpos_product->product_is_synced){
                                    $update = update_post_meta($product, '_xwcpos_sync', 1);
                                }
                                $xwcpos_product = $this->get_product_data_by_id($product);
                            } else {
                                //disassociate the kounta product with any WC products
                                $result = $this->update_item_disassociate($xwcpos_product);
                            }
                        }
                    }



                    //if the product has an equivalent in Kounta
                    if ($xwcpos_product->product_ls_id > 0) {

                        // //Get the product data from the API too
                        // $result = $this->update_data_via_api($xwcpos_product);
                        $this->update_item_sync_date($xwcpos_product);

                        // //if no error
                        // if (is_wp_error($result)) {
                        //     $error_msg = __(
                        //         'Warning: there is a error with syncing the inventory of this product.',
                        //         'xwcpos'
                        //     );
                        //     $error_msg .= $result->get_error_message();

                        //     wc_add_notice(
                        //         apply_filters('xwcpos_inventory_sync_error', $error_msg),
                        //         'error'
                        //     );
                        //     return false;

                        // } else {
                        //set the stock level in WC
                        $this->set_wc_prod_stock($xwcpos_product);
                        return true;

                        // }

                    } else {
                        return false;
                    }
                }
            }

        }

        public function update_item_sync_date($xwcpos_product){

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';

            $wc_prod_id = $xwcpos_product->wc_prod_id;

            $old_item = $this->get_product_data_by_id($wc_prod_id);
            $new_item = $old_item;
            $new_item->xwcpos_last_sync_date = current_time('mysql');

            $mysql_args = $this->xwcpos_get_item_database_entries($new_item);
            if( isset($mysql_args['item_id'])){
                $mysql_args['item_id'] = $old_item->item_id;
                $mysql_args['categories'] = $old_item->categories;
                $mysql_args['tags'] = $old_item->tags;
                $where_args['id'] = isset($old_item) && isset($old_item->product_id) ? $old_item->product_id : '';

                //   $unset_fields = array(
                //     'created_at',
                //     'wc_prod_id',
                //     'xwcpos_import_date',
                //     'xwcpos_is_synced',
                // );

                // foreach ($unset_fields as $field) {
                //     if (array_key_exists($field, $mysql_args)) {
                //         unset($mysql_args[$field]);
                //     }
                // }

                $where_field = array('%d');
                $update_fields = array(
                    '%d', '%s', '%s', '%s', '%s', '%d',
                    '%s', '%d', '%s', '%s', '%d', '%d',
                    '%s', '%s', '%s', '%s', '%s', '%s',
                    '%s', '%s', '%s', '%d'
                );

                $result = $wpdb->update($wpdb->xwcpos_items, $mysql_args, $where_args, $update_fields, $where_field);
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

        public function set_wc_prod_stock($xwcpos_product)
        {

            $quantity = $this->xwcpos_get_ls_product_inventory($xwcpos_product, true);
            $wc_product = wc_get_product($xwcpos_product->wc_product_id);

            if (!is_null($wc_product)) {

                $wc_inventory = $wc_product->get_stock_quantity();
                //do_action('xwcpos_update_wc_stock', $xwcpos_product->wc_product_id, $quantity);

                if (absint($quantity) == absint($wc_inventory)) {return;}

                wc_update_product_stock( $wc_product, $quantity);

                $this->set_woo_product_stock_status($wc_product, $quantity);
            }
        }

        public function set_woo_product_stock_status($wc_product, $quantity = null)
        {

            if (is_null($quantity)) {
                $quantity = $wc_product->get_stock_quantity();
            }

            if ($quantity > 0) {
                $wc_product->set_stock_status('instock');
                wp_remove_object_terms($wc_product->get_id(), array('outofstock'), 'product_visibility');
            } else {
                $wc_product->set_stock_status('outofstock');
                wp_set_post_terms($wc_product->get_id(), array('outofstock'), 'product_visibility');
            }
        }

        public function xwcpos_get_ls_product_inventory($xwcpos_product, $skip_wc_stock = false)
        {

            $inventory = null;

            $siteID = get_option('xwcpos_site_id');

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->xwcpos_item_shops WHERE xwcpos_item_id = %d", $xwcpos_product->product_id));

            if ($xwcpos_product->wc_prod_id > 0 && !$skip_wc_stock) {
                $inventory = (int) get_post_meta($xwcpos_product->wc_product_id, '_stock', true);
            } elseif (!is_null($results)) {
                if (!empty($siteID)) {
                    foreach ($results as $item_shop) {
                        if ($item_shop->shop_id == $siteID) {
                            $inventory = (int) $item_shop->qoh;
                            break;
                        }
                    }

                    if (is_null($inventory)) {
                        $inventory = 0;
                    }
                } else {
                    $inventory = (int) $xwcpos_product->qoh;
                }
            } else {
                $inventory = 0;
            }

            //$inventory = apply_filters('xwcpos_get_kounta_inventory', $inventory, $xwcpos_product);

            return $inventory;
        }

        // public function update_data_via_api($xwcpos_product)
        // {
        //   //get product data via API. Triggered when an item is in the cart at checkout.

        //     $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));

        //     // $api_data = $this->xwcpos_product_api_path($xwcpos_product);
        //     $endpoint = 'companies/' . $xwcpos_account_id . '/products/' . $xwcpos_product->product_ls_id;

        //     $result = $this->xwcpos_make_api_call($endpoint, 'Read', $api_data['search_data']);

        //     if (is_wp_error($result)) {
        //         return $result;
        //     }

        //     if (isset($xwcpos_product->product_ls_id) && $xwcpos_product->product_ls_id > 0) {
        //         $new_data_api_item = $result;
        //     } 
        //     else {
        //         echo esc_html__('Error: invalid Kounta Product.', 'xwcpos');
        //     }

        //     $new_data_api_item->xwcpos_import_date = $xwcpos_product->product_last_import;
        //     $new_data_api_item->xwcpos_last_sync_date = $xwcpos_product->product_last_sync;
        //     $new_data_api_item->xwcpos_is_synced = $xwcpos_product->product_is_synced;
        //     $new_data_api_item->wc_prod_id = $xwcpos_product->wc_product_id;

        //     //$this->update_product_via_api($xwcpos_product);
        //     return $xwcpos_product->item_id;
        // }

        // public function xwcpos_product_api_path($xwcpos_product)
        // {

        //     $xwcpos_single_relation = array(
        //         "sites",
        //         "tags",
        //     );

        //     $search_string = '';
        //     $search_data = array();

        //     if (is_array($xwcpos_product)) {

        //         $search_data = array(
        //             'load_relations' => json_encode($xwcpos_single_relation),
        //             'itemID' => 'IN,' . json_encode($xwcpos_product),
        //         );
        //         $search_string = '/products';

        //     } else if (isset($xwcpos_product->product_ls_id) && $xwcpos_product->product_ls_id > 0) {

        //         $search_data = array(
        //             'load_relations' => json_encode($xwcpos_single_relation),
        //         );
        //         $search_string = '/products/' . $xwcpos_product->product_ls_id;

        //     }

        //     return array('path' => $search_string, 'search_data' => $search_data);

        // }

        public function update_product_via_api($item)
        {

            $xwcpos_single_relation = array(
                "sites",
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

            $ret_data = array('path' => $search_str, 'params' => $search_data);
            $endpoint = 'companies/' . $xwcpos_account_id . $ret_data['path'];

            $result = $this->xwcpos_make_api_call($endpoint, 'Read', $ret_data['params']);

            if ($result == '401') {
                ?>
                <div class="error"><p><?php echo esc_html__("401 Invalid access token. Please check API connection with Kounta POS.", "xwcpos"); ?></p></div>
                <?php
            }
            if(isset($result->id)){
                $this->update_item_data($result, $item);
            } else {
                echo 'there was an error';
            }


            return $item->product_id;
        }

        public function xwcpos_update_variations($new_variations)
        {

            foreach ($new_variations as $new_var) {

                $item_database_id = $this->xwcpos_item_id_check($new_var->itemID, $new_var->itemMatrixID);
                if ($item_database_id > 0) {

                    $old_item = $this->xwcpos_get_sing_item_for_update($item_database_id);
                    $this->update_item_data($new_var, $old_item);
                } else {

                    $this->xwcpos_insert_item($new_var);
                }
            }
        }

        public function xwcpos_get_sing_item_for_update($item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            // $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';
            $wpdb->xwcpos_item_categories = $wpdb->prefix . 'xwcpos_item_categories';
            // $wpdb->xwcpos_item_attributes = $wpdb->prefix . 'xwcpos_item_attributes';

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
					item.item_matrix_id    AS product_matrix_item_id,
					item.wc_prod_id        AS wc_product_id,
					item.custom_sku        AS product_custom_sku,
					item.system_sku        AS product_system_sku,
					item.sku  AS product_sku,
				FROM $wpdb->xwcpos_items as item
				/LEFT JOIN $wpdb->xwcpos_item_categories as category ON item.category_id = category.category_id
				LEFT JOIN $wpdb->xwcpos_item_shops as item_shop ON item.id = item_shop.xwcpos_item_id AND item_shop.shop_id = 0
				LEFT JOIN $wpdb->xwcpos_item_prices as item_price ON item.id = item_price.xwcpos_item_id
				WHERE item.id = %d", $item_id

            ));

            return $result;

        }

        public function update_item_data($new_item, $old_item)
        {
            if(isset($new_item->id)){
                global $wpdb;
                $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
                $new_item->xwcpos_last_sync_date = current_time('mysql');

                $mysql_args = $this->xwcpos_get_item_database_entries($new_item);
                if( isset($mysql_args['item_id'])){
                    $where_args['id'] = isset($old_item) && isset($old_item->product_id) ? $old_item->product_id : '';

                    $unset_fields = array(
                        'created_at',
                        'wc_prod_id',
                        'xwcpos_import_date',
                        'xwcpos_is_synced',
                    );

                    foreach ($unset_fields as $field) {
                        if (array_key_exists($field, $mysql_args)) {
                            unset($mysql_args[$field]);
                        }
                    }

                    $where_field = array('%d');
                    $update_fields = array(
                        '%d', '%s', '%s', '%s', '%s', '%d',
                        '%s', '%d', '%s', '%d', '%d', '%d',
                        '%s', '%s', '%s', '%s', '%s', '%s',
                        '%s', '%d'
                    );

                    $result = $wpdb->update($wpdb->xwcpos_items, $mysql_args, $where_args, $update_fields, $where_field);
                    if($result != 1){
                        echo 'something went wrong';
                    }

                    if(isset($new_item->sites)){

                        //update prices
                        $this->xwcpos_update_item_prices($new_item, $old_item);

                        //Update Shops data
                        $this->xwcpos_update_item_shops($new_item, $old_item);
                    }
                } else {
                    echo ' do something else because the fields from database are empty';
                }
            } else {
                echo 'new_item appears to be empty';
            }

            // //Update Images
            // $this->xwcpos_update_item_images($new_item, $old_item);

        }

        public function xwcpos_update_item_prices($new_item, $old_item)
        {
            //var_dump($new_item);
            // global $wpdb;
            // $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            if (is_object($new_item->sites)) {
                $new_item->sites = array($new_item->sites);
            }

            foreach ($new_item->sites as $site) {

                if ($this->xwcpos_item_price_check($site, $old_item)) {
                    $this->xwcpos_update_item_price($site, $old_item);
                } else {
                    if($old_item){
                        $this->xwcpos_insert_item_price($site, isset($old_item->xwcpos_item_id) ? $old_item->xwcpos_item_id : $old_item->product_id);
                    }

                }
            }

            $store = get_option('xwcpos_site_data');
            $site->id = $store["xwcpos_store_id"];

            $prod_id = $old_item->wc_prod_id;

            if($prod_id ){
                $product = wc_get_product($prod_id);
                if($product){
                    $wc_price = $product->get_price();
                    $wc_saleprice = $product->get_sale_price();

                    $k_price = $this->xwcpos_item_price_check($site, $old_item);

                    if($k_price){
                        if($wc_saleprice != '' && ($k_price < $wc_price)){
                            update_post_meta($prod_id, '_sale_price', number_format((float) $k_price, 2, '.', ''));
                        } else if ($wc_saleprice != '' && ($k_price >= $wc_price)){
                            update_post_meta($prod_id, '_sale_price', '');
                            update_post_meta($prod_id, '_price', number_format((float) $k_price, 2, '.', ''));
                            update_post_meta($prod_id, '_regular_price', number_format((float) $k_price, 2, '.', ''));
                        } else {
                            update_post_meta($prod_id, '_price', number_format((float) $k_price, 2, '.', ''));
                            update_post_meta($prod_id, '_regular_price', number_format((float) $k_price, 2, '.', ''));
                        }
                    }
                }

            }

        }

        public function xwcpos_update_item_shops($new_item, $old_item)
        {

            if (empty($new_item->sites)) {
                return;
            }

            if (is_object($new_item->sites)) {
                $new_item->sites = array($new_item->sites);
            }

            foreach ($new_item->sites as $site) {

                if ($this->xwcpos_item_shop_check($site, $old_item)) {
                    $this->xwcpos_update_item_shop($site, $old_item);
                } else {
                    if($old_item){
                        $this->xwcpos_insert_shop_item($old_item->product_ls_id, $site, isset($old_item->xwcpos_item_id)?$old_item->xwcpos_item_id:$old_item->product_id );
                        //$item_id, $site, $xwcpos_item_id
                    }
                }
            }

            //$this->xwcpos_adjust_old_item_shops($new_item, $old_item);
        }

        public function xwcpos_update_item_images($new_item, $old_item)
        {

            if (empty($new_item->Images->Image)) {
                return;
            }

            if (is_object($new_item->Images->Image)) {
                $new_item->Images->Image = array($new_item->Images->Image);
            }

            if (!empty($new_item->Images->Image)) {

                foreach ($new_item->Images->Image as $image) {

                    if ($this->xwcpos_item_image_check($image->imageID, $old_item)) {

                        $this->xwcpos_update_item_image($image, $old_item->product_id);
                    } else {

                        $this->xwcpos_insert_item_image($image, $old_item->product_id);
                    }
                }
            }

            $this->xwcpos_adjust_old_item_images($new_item, $old_item);
        }

        public function xwcpos_item_price_check($site, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';
            $prod_id = isset($old_item) && isset($old_item->product_id)? $old_item->product_id : '';
            $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_prices . " WHERE xwcpos_item_id = %d", $prod_id));

            foreach ($result as $price) {
                if ($price->site_id == $site->id) {
                    return $price->amount;
                }
            }
            return false;

        }

        public function xwcpos_item_shop_check($site, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            if($old_item){
                $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_shops . " WHERE xwcpos_item_id = %d", $old_item->xwcpos_item_id));
            } else {
                $result = array();
            }


            foreach ($result as $shop) {
                if ($shop->shop_id == $site->id) {
                    return true;
                }
            }
            return false;
        }

        public function xwcpos_item_image_check($new_image_id, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

            $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_images . " WHERE xwcpos_item_id = %d", $old_item->xwcpos_item_id));

            foreach ($result as $image) {
                if ($image->image_id == $new_image_id) {
                    return true;
                }
            }
            return false;

        }

        public function xwcpos_update_item_price($site, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';

            $mysql_args = array(
                'xwcpos_item_id' => $old_item->xwcpos_item_id,
                'amount' => isset($site->unit_price) ? $site->unit_price : null,
                'updated_at' => current_time('mysql'),
            );

            $where_args['xwcpos_item_id'] = $old_item->xwcpos_item_id;
            $where_args['site_id'] = $site->id;

            if (isset($mysql_args['updated_at'])) {
                unset($mysql_args['updated_at']);
            }

            if (isset($mysql_args['xwcpos_item_id'])) {
                unset($mysql_args['xwcpos_item_id']);
            }

            $where_data = array('%d', '%d');
            $update_data = array('%f');

            $result = $wpdb->update($wpdb->xwcpos_item_prices, $mysql_args, $where_args, $update_data, $where_data);

        }

        public function xwcpos_update_item_shop($site, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $mysql_args = array(
                'qoh' => isset($site->stock) ? $site->stock : null,
            );

            // if(!($site->stock >= 0) && !($site->stock <= 0)){
            //   $this->plugin_log('ERROR: Item stock error. Item:'.$old_item->xwcpos_item_id.' Stock:'.$site->stock);
            // } else{
            //   $this->plugin_log('SUCCESS: Item has a valid stock value. Item:'.$old_item->xwcpos_item_id.' Stock:'.$site->stock);
            // }

            $where_args['xwcpos_item_id'] = $old_item->xwcpos_item_id;
            $where_args['shop_id'] = $site->id;
            // if (isset($mysql_args['created_at'])) {
            //     unset($mysql_args['created_at']);
            // }

            $where_data = array('%d','%d');
            $update_data = array('%d');

            $result = $wpdb->update($wpdb->xwcpos_item_shops, $mysql_args, $where_args, $update_data, $where_data);

        }

        public function xwcpos_update_item_image($new_image, $old_item_id)
        {

            global $wpdb;
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

            $mysql_args = array(
                'xwcpos_item_id' => $old_item_id,
                'item_id' => isset($new_image->itemID) ? $new_image->itemID : null,
                'item_matrix_id' => isset($new_image->itemMatrixID) ? $new_image->itemMatrixID : null,
                'image_id' => isset($new_image->imageID) ? $new_image->imageID : null,
                'description' => isset($new_image->description) ? $new_image->description : null,
                'filename' => isset($new_image->filename) ? $new_image->filename : null,
                'ordering' => isset($new_image->ordering) ? $new_image->ordering : null,
                'public_id' => isset($new_image->publicID) ? $new_image->publicID : null,
                'base_image_url' => isset($new_image->baseImageURL) ? $new_image->baseImageURL : null,
                'size' => isset($new_image->size) ? $new_image->size : null,
                'create_time' => isset($new_image->createTime) ? $new_image->createTime : null,
                'time_stamp' => isset($new_image->timeStamp) ? $new_image->timeStamp : null,
                'created_at' => current_time('mysql'),
            );

            $where_args['image_id'] = $new_image->imageID;

            if (isset($mysql_args['created_at'])) {
                unset($mysql_args['created_at']);
            }

            $where_data = array('%d');
            $update_data = array(
                '%d', '%d', '%d', '%d', '%s', '%s',
                '%d', '%s', '%s', '%d', '%s', '%s',
                '%s',
            );
            echo 'sadffs';
            $wpdb->update($wpdb->xwcpos_item_images, $mysql_args, $where_args, $update_data, $where_data);
        }

        public function xwcpos_adjust_old_item_shops($new_item, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';

            $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_shops . " WHERE xwcpos_item_id = %d", $old_item->xwcpos_item_id

            ));

            foreach ($result as $old_item_shop) {
                if ($this->xwcpos_adjust_m_shops($old_item_shop->shop_id, $new_item)) {
                    $this->xwcpos_delete_item_shop($old_item_shop->shop_id);
                }
            }

        }

        public function xwcpos_adjust_m_shops($old_item_shop_id, $new_item)
        {
            foreach ($new_item->sites as $site) {
                if ($site->id == $old_item_shop_id) {
                    return false;
                }
            }
            return true;
        }

        public function xwcpos_delete_item_shop($item_shop_id)
        {
            global $wpdb;
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->delete($wpdb->xwcpos_item_shops, array('item_shop_id' => $item_shop_id), array('%d'));
        }

        public function xwcpos_adjust_old_item_images($new_item, $old_item)
        {

            global $wpdb;
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

            $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->xwcpos_item_images . " WHERE xwcpos_item_id = %d", $old_item->id

            ));

            foreach ($result as $old_image) {
                if ($this->xwcpos_adjust_m_images($old_image->image_id, $new_item)) {
                    $this->xwcpos_delete_item_image($old_image);
                }
            }

        }

        public function xwcpos_adjust_m_images($old_image_id, $new_item)
        {
            foreach ($new_item->Images->Image as $new_item_image) {
                if ($new_item_image->imageID == $old_image_id) {
                    return false;
                }
            }
            return true;
        }

        public function xwcpos_delete_item_image($old_image)
        {
            global $wpdb;
            $wpdb->xwcpos_item_images = $wpdb->prefix . 'xwcpos_item_images';

            if ($old_image->wp_attachment_id > 0) {
                wp_delete_attachment($old_image->wp_attachment_id, true);
            }

            $wpdb->delete($wpdb->xwcpos_item_images, array('image_id' => $old_image->image_id), array('%d'));
        }

        /**
         * ========================================================================
         * OPTIMIZED SYNC METHODS (v2.0)
         * ========================================================================
         * These methods use the new architecture with rate limiting and batch
         * processing for dramatically improved performance.
         */

        /**
         * Optimized sync all products (AJAX handler)
         *
         * Uses new Kounta_Sync_Service for improved performance
         */
        public function xwcposSyncAllProdsOptimized() {
            // Check if a sync is already running
            $sync_lock = get_transient('xwcpos_sync_in_progress');
            if ($sync_lock) {
                echo json_encode(array(
                    'success' => false,
                    'error' => 'A sync is already in progress. Please wait for it to complete.',
                    'locked_by' => $sync_lock,
                ));
                wp_die();
            }

            // Set lock with timestamp and source
            $lock_info = array(
                'started' => current_time('mysql'),
                'source' => 'manual_ajax',
                'user_id' => get_current_user_id(),
            );
            set_transient('xwcpos_sync_in_progress', $lock_info, 600); // 10 minute lock

            try {
                $sync_service = new Kounta_Sync_Service();

                // First sync inventory
                $inventory_result = $sync_service->sync_inventory_optimized();

                if (!$inventory_result['success']) {
                    // Release lock before exiting
                    delete_transient('xwcpos_sync_in_progress');

                    echo json_encode(array(
                        'success' => false,
                        'error' => $inventory_result['error'],
                    ));
                    wp_die();
                }

                // Then sync products
                $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
                $product_result = $sync_service->sync_products_optimized($limit);

                $this->plugin_log(sprintf(
                    'Optimized sync completed: %d products updated in %.2f seconds',
                    $product_result['updated'],
                    $product_result['duration']
                ));

                // Release lock after successful completion
                delete_transient('xwcpos_sync_in_progress');

                echo json_encode(array(
                    'success' => true,
                    'inventory' => $inventory_result,
                    'products' => $product_result,
                    'message' => sprintf(
                        '%d products synced successfully in %.2f seconds!',
                        $product_result['updated'],
                        $product_result['duration']
                    ),
                ));

            } catch (Exception $e) {
                // Release lock on error
                delete_transient('xwcpos_sync_in_progress');

                $this->plugin_log('Optimized sync error: ' . $e->getMessage());
                echo json_encode(array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Optimized inventory sync only (AJAX handler)
         */
        public function xwcposSyncInventoryOptimized() {
            try {
                $sync_service = new Kounta_Sync_Service();
                $result = $sync_service->sync_inventory_optimized();

                if ($result['success']) {
                    $this->plugin_log(sprintf(
                        'Optimized inventory sync completed: %d items updated in %.2f seconds',
                        $result['updated'],
                        $result['duration']
                    ));
                }

                echo json_encode($result);

            } catch (Exception $e) {
                $this->plugin_log('Optimized inventory sync error: ' . $e->getMessage());
                echo json_encode(array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Get sync progress (AJAX handler)
         * Returns real-time progress from transient during active sync
         */
        public function xwcposGetSyncProgress() {
            try {
                // Get real-time progress from transient (set during sync)
                $progress = get_transient('xwcpos_sync_progress');

                if ($progress && isset($progress['active']) && $progress['active']) {
                    // Active sync in progress - return real-time data
                    echo json_encode($progress);
                } else {
                    // No active sync - return overall sync status
                    $sync_service = new Kounta_Sync_Service();
                    $overall_progress = $sync_service->get_sync_progress();

                    echo json_encode(array(
                        'active' => false,
                        'overall' => $overall_progress,
                    ));
                }

            } catch (Exception $e) {
                echo json_encode(array(
                    'active' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Optimized CRON sync handler
         */
        public function xwcposSyncAllProdsOptimizedCRON() {
            $this->plugin_log('/**** CRON Process initiated: Optimized Sync ****/ ');

            try {
                $sync_service = new Kounta_Sync_Service();

                // Sync inventory
                $inventory_result = $sync_service->sync_inventory_optimized();

                // Sync products
                $product_result = $sync_service->sync_products_optimized();

                $this->plugin_log(sprintf(
                    'CRON optimized sync completed: %d products in %.2f seconds',
                    $product_result['updated'],
                    $product_result['duration']
                ));

            } catch (Exception $e) {
                $this->plugin_log('CRON optimized sync error: ' . $e->getMessage());
            }
        }

        /**
         * ========================================================================
         * ORDER RETRY HANDLERS (v2.0)
         * ========================================================================
         */

        /**
         * Get failed orders (AJAX handler)
         */
        public function xwcposGetFailedOrders() {
            try {
                $order_service = new Kounta_Order_Service($this);
                $failed_orders = $order_service->get_failed_orders();

                echo json_encode(array(
                    'success' => true,
                    'failed_orders' => $failed_orders,
                    'count' => count($failed_orders),
                ));

            } catch (Exception $e) {
                echo json_encode(array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Retry failed orders (AJAX handler)
         */
        public function xwcposRetryFailedOrders() {
            try {
                $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;

                $order_service = new Kounta_Order_Service($this);
                $results = $order_service->retry_failed_orders($limit);

                $this->plugin_log(sprintf(
                    'Failed order retry: %d succeeded, %d failed, %d skipped',
                    $results['success'],
                    $results['failed'],
                    $results['skipped']
                ));

                echo json_encode(array(
                    'success' => true,
                    'results' => $results,
                    'message' => sprintf(
                        '%d orders succeeded, %d failed, %d skipped',
                        $results['success'],
                        $results['failed'],
                        $results['skipped']
                    ),
                ));

            } catch (Exception $e) {
                echo json_encode(array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Clear failed order (AJAX handler)
         */
        public function xwcposClearFailedOrder() {
            try {
                $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

                if (!$order_id) {
                    throw new Exception('Invalid order ID');
                }

                $order_service = new Kounta_Order_Service($this);
                $order_service->clear_failed_order($order_id);

                echo json_encode(array(
                    'success' => true,
                    'message' => 'Order removed from failed queue',
                ));

            } catch (Exception $e) {
                echo json_encode(array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ));
            }

            wp_die();
        }

        /**
         * Get debug log (AJAX handler)
         */
        public function xwcposGetDebugLog() {
            $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;

            // Get WordPress uploads directory
            $upload_dir = wp_upload_dir();
            $log_file = $upload_dir['basedir'] . '/brewhq-kounta.log';

            if (!file_exists($log_file)) {
                echo json_encode(array(
                    'success' => false,
                    'error' => 'Log file not found',
                    'path' => $log_file,
                ));
                wp_die();
            }

            // Read last N lines of log file
            $file = new SplFileObject($log_file, 'r');
            $file->seek(PHP_INT_MAX);
            $total_lines = $file->key() + 1;

            $start_line = max(0, $total_lines - $lines);
            $log_lines = array();

            $file->seek($start_line);
            while (!$file->eof()) {
                $line = $file->current();
                if (!empty(trim($line))) {
                    $log_lines[] = rtrim($line);
                }
                $file->next();
            }

            echo json_encode(array(
                'success' => true,
                'lines' => $log_lines,
                'total_lines' => $total_lines,
                'file_size' => filesize($log_file),
                'file_path' => $log_file,
            ));

            wp_die();
        }

        /**
         * Cleanup empty products (AJAX handler)
         */
        public function xwcposCleanupEmptyProducts() {
            global $wpdb;
            $wpdb->xwcpos_items = $wpdb->prefix . 'xwcpos_items';
            $wpdb->xwcpos_item_shops = $wpdb->prefix . 'xwcpos_item_shops';
            $wpdb->xwcpos_item_prices = $wpdb->prefix . 'xwcpos_item_prices';

            // Find empty products
            $empty_products = $wpdb->get_results(
                "SELECT id FROM {$wpdb->xwcpos_items} WHERE name IS NULL OR name = ''"
            );

            if (empty($empty_products)) {
                echo json_encode(array(
                    'success' => true,
                    'deleted' => 0,
                    'message' => 'No empty products found',
                ));
                wp_die();
            }

            $deleted_count = 0;

            foreach ($empty_products as $product) {
                // Delete related records first
                $wpdb->delete($wpdb->xwcpos_item_shops, array('xwcpos_item_id' => $product->id));
                $wpdb->delete($wpdb->xwcpos_item_prices, array('xwcpos_item_id' => $product->id));

                // Delete the product
                $result = $wpdb->delete($wpdb->xwcpos_items, array('id' => $product->id));

                if ($result) {
                    $deleted_count++;
                }
            }

            $this->plugin_log("Cleanup: Deleted {$deleted_count} empty products");

            echo json_encode(array(
                'success' => true,
                'deleted' => $deleted_count,
                'message' => sprintf('%d empty products deleted successfully', $deleted_count),
            ));

            wp_die();
        }

        /**
         * Add meta box for Kounta sync overrides on product edit page
         */
        public function xwcpos_add_sync_override_meta_box() {
            add_meta_box(
                'xwcpos_sync_overrides',
                'Kounta Sync Overrides',
                array($this, 'xwcpos_render_sync_override_meta_box'),
                'product',
                'side',
                'default'
            );
        }

        /**
         * Render the sync override meta box
         */
        public function xwcpos_render_sync_override_meta_box($post) {
            // Add nonce for security
            wp_nonce_field('xwcpos_sync_override_meta_box', 'xwcpos_sync_override_nonce');

            // Get current values
            $disable_image = get_post_meta($post->ID, '_xwcpos_disable_image_sync', true);
            $disable_description = get_post_meta($post->ID, '_xwcpos_disable_description_sync', true);
            $disable_title = get_post_meta($post->ID, '_xwcpos_disable_title_sync', true);
            $disable_price = get_post_meta($post->ID, '_xwcpos_disable_price_sync', true);

            ?>
            <div class="xwcpos-sync-overrides">
                <p style="margin-bottom: 15px; color: #646970; font-size: 13px;">
                    Check any option below to prevent Kounta from updating that field on this product,
                    even if global sync settings are enabled.
                </p>

                <p>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="_xwcpos_disable_image_sync" value="yes" <?php checked($disable_image, 'yes'); ?> />
                        <strong>Disable Image Sync</strong>
                    </label>
                    <span style="display: block; margin-left: 24px; color: #646970; font-size: 12px;">
                        Prevent Kounta from updating product images
                    </span>
                </p>

                <p>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="_xwcpos_disable_description_sync" value="yes" <?php checked($disable_description, 'yes'); ?> />
                        <strong>Disable Description Sync</strong>
                    </label>
                    <span style="display: block; margin-left: 24px; color: #646970; font-size: 12px;">
                        Prevent Kounta from updating product description
                    </span>
                </p>

                <p>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="_xwcpos_disable_title_sync" value="yes" <?php checked($disable_title, 'yes'); ?> />
                        <strong>Disable Title Sync</strong>
                    </label>
                    <span style="display: block; margin-left: 24px; color: #646970; font-size: 12px;">
                        Prevent Kounta from updating product title/name
                    </span>
                </p>

                <p>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="_xwcpos_disable_price_sync" value="yes" <?php checked($disable_price, 'yes'); ?> />
                        <strong>Disable Price Sync</strong>
                    </label>
                    <span style="display: block; margin-left: 24px; color: #646970; font-size: 12px;">
                        Prevent Kounta from updating product price
                    </span>
                </p>

                <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dcdcde; color: #646970; font-size: 12px;">
                    <strong>Note:</strong> Stock levels will always sync from Kounta regardless of these settings.
                </p>
            </div>
            <?php
        }

        /**
         * Save sync override meta box data
         */
        public function xwcpos_save_sync_override_meta($post_id) {
            // Check nonce
            if (!isset($_POST['xwcpos_sync_override_nonce']) ||
                !wp_verify_nonce($_POST['xwcpos_sync_override_nonce'], 'xwcpos_sync_override_meta_box')) {
                return;
            }

            // Check autosave
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // Check permissions
            if (!current_user_can('edit_product', $post_id)) {
                return;
            }

            // Save each checkbox value
            $fields = array(
                '_xwcpos_disable_image_sync',
                '_xwcpos_disable_description_sync',
                '_xwcpos_disable_title_sync',
                '_xwcpos_disable_price_sync',
            );

            foreach ($fields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] === 'yes') {
                    update_post_meta($post_id, $field, 'yes');
                } else {
                    delete_post_meta($post_id, $field);
                }
            }
        }

    }

    // Store instance globally so other classes can access it
    global $xwcpos_plugin_instance;
    $xwcpos_plugin_instance = new BrewHQ_Kounta_POS_Int();

}
