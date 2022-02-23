<?php
if (!defined('WPINC')) {
    die;
}

if (!class_exists('BrewHQ_Kounta_POS_Int_Admin')) {

    class BrewHQ_Kounta_POS_Int_Admin extends BrewHQ_Kounta_POS_Int
    {

        private $mod_errors = array();
        const EXT_CONNECT_URL = 'https://my.kounta.com/authorize';
        public function __construct()
        {

            add_action('admin_enqueue_scripts', array($this, 'xwcpos_admin_scripts'));
            add_action('admin_menu', array($this, 'xwcpos_create_admin_menu'));
            add_action('admin_notices', array($this, 'xwcpos_display_mod_errors'));
            add_filter('set-screen-option', array($this, 'xwcpos_set_screen'), 10, 3);

            add_filter( 'manage_edit-product_columns', array($this, 'xwcpos_admin_product_id_column'), 9999 );
            add_action( 'manage_product_posts_custom_column', array($this, 'xwcpos_admin_product_id_column_content'), 10, 2 );
            add_filter( 'manage_edit-product_sortable_columns', array($this, 'sortable_product_id_column') );
            add_action( 'posts_orderby', array($this,'xwcpos_item_id_orderby'), 10, 2 );
            add_filter('posts_join_paged', array($this,'xwcpos_item_id_join_paged'),10, 2);

            add_filter( 'manage_edit-shop_order_columns', array($this, 'xwcpos_admin_order_id_column'), 9999 );
            add_action( 'manage_shop_order_posts_custom_column', array($this, 'xwcpos_admin_order_id_column_content'), 10, 2 );
            add_filter( 'manage_edit-shop_order_sortable_columns', array($this, 'sortable_order_id_column') );
            add_action( 'posts_orderby', array($this,'xwcpos_order_item_id_orderby'), 10, 2 );
            add_filter('posts_join_paged', array($this,'xwcpos_order_item_id_join_paged'),10, 2);
            
            //add_action( 'pre_get_posts', array($this,'xwcpos_item_id_orderby') );

            add_action('add_meta_boxes_product', array($this, 'add_xwcpos_meta_box'), 10);
            add_action( 'add_meta_boxes', array($this, 'xwcpos_add_order_meta_boxes'), 10 );

            if (isset($_POST['xwcpos_submit']) && $_POST['xwcpos_submit'] != '') {

                $this->xwcpos_saveAPI();
            }
        }

        public function xwcpos_add_order_meta_boxes()
        {
            
            add_meta_box( 'xwcpos_other_fields', __('Kounta Sync','woocommerce'), array($this, 'xwcpos_render_order_meta_box'), 'shop_order', 'side', 'core' );
        }

        // public function xwcpos_add_other_fields_for_packaging()
        // {
        //     global $post;

        //     $meta_field_data = get_post_meta( $post->ID, '_my_field_slug', true ) ? get_post_meta( $post->ID, '_my_field_slug', true ) : '';

        //     echo '<input type="hidden" name="xwcpos_other_meta_field_nonce" value="' . wp_create_nonce() . '">
        //     <p style="border-bottom:solid 1px #eee;padding-bottom:13px;">
        //         <input type="text" style="width:250px;";" name="my_field_name" placeholder="' . $meta_field_data . '" value="' . $meta_field_data . '"></p>';

        // }

        public function xwcpos_render_order_meta_box($post)
        {
            $order_id = intval(get_post_meta($post->ID, '_kounta_id', true ));
            ?>
            <p>Kounta Order ID: <?php echo $order_id;?>
			<p>
				<button onclick="syncOrderWithKounta('<?php echo esc_attr($post->ID); ?>')" class="button-secondary button" type="button" id="xwcpos-sync-to-ls">
					<?php echo esc_html__('Sync with Kounta', 'xwcpos'); ?>
				</button>
				<img class="help_tip tips xwcpos-load-prod-tip" data-tip="<?php echo esc_html__('Sync this order with Kounta!', 'xwcpos'); ?>" src="<?php echo esc_attr(WC()->plugin_url()); ?>/assets/images/help.png" height="16" width="16">
				<span class="spinner"></span>
				<div id="xwcpos-sync-status"></div>

				<div class="error_message errpro"></div>
				<div class="success_message successpro"></div>
			</p>

		<?php }

        public function xwcpos_set_screen($status, $option, $value)
        {
            return $value;
        }

        public function xwcpos_admin_scripts()
        {

            wp_enqueue_style('xwcpos-admin', plugins_url('../assets/css/xwcpos_admin_style.css', __FILE__), false);
            wp_enqueue_script('xwcpos-adminsc', plugins_url('../assets/js/xwcpos_admin.js', __FILE__), false);
            $xwcpos_data = array(
                'admin_url' => admin_url('admin-ajax.php'),
            );
            wp_localize_script('xwcpos-adminsc', 'xwcpos_php_vars', $xwcpos_data);
        }

        public function xwcpos_create_admin_menu()
        {

            add_menu_page('Kounta POS Integration', esc_html__('Kounta POS Integration', 'xwcpos'), apply_filters('xwcpos_capability', 'manage_options'), 'xwcpos-integration', array($this, 'xwcpos_kounta_integration_callback'), plugins_url('assets/images/ext_icon.png', dirname(__FILE__)), apply_filters('xwcpos_menu_position', 7));

            add_submenu_page('xwcpos-integration', esc_html__('API Settings', 'xwcpos'), esc_html__('API Settings', 'xwcpos'), 'manage_options', 'xwcpos-integration', array($this, 'xwcpos_kounta_integration_callback'));

            add_submenu_page('xwcpos-integration', esc_html__('Import Categories', 'xwcpos'), esc_html__('Import Categories', 'xwcpos'), 'manage_options', 'xwcpos-integration-cats', array($this, 'xwcpos_ewlops_cats_import_callback'));

            $hook = add_submenu_page('xwcpos-integration', esc_html__('Import Products', 'xwcpos'), esc_html__('Import Products', 'xwcpos'), 'manage_options', 'xwcpos-integration-products', array($this, 'xwcpos_ewlops_products_import_callback'));

            add_action("load-$hook", array($this, 'xwcpos_screen_option'));
        }

        public function xwcpos_screen_option()
        {

            $option = 'per_page';
            $args = [
                'label' => 'Products',
                'default' => 20,
                'option' => 'xwcpos_per_page',
            ];

            add_screen_option($option, $args);
            require_once XWCPOS_PLUGIN_DIR . 'admin/class-wp-list-table.php';

        }

        public function get_shop_data($xwcpos_account_id, $merchantos){
          // Get Shop Data
          try {
            $shop = $merchantos->makeAPICall('companies/' . $xwcpos_account_id . '/sites', 'Read');
          } catch (Exception $e) {
              $this->mod_errors[] = $e->getMessage();
          }

          if (isset($shop->error)) {
              $this->mod_errors[] = sprintf('%s %d %s %s %s %s', 'Kounta API Error - ', $shop->error, $shop->error_description, 'Read', ' - Payload: ', '/Account');
          } else {
              $this->xwcpos_get_site_data($shop);
          }
        }

        public function xwcpos_kounta_integration_callback()
        {

            if (get_option('xwcpos_client_id') != '') {
                $client_id = esc_attr(get_option('xwcpos_client_id'));
            } else {
                $client_id = '';
            }

            if (get_option('xwcpos_client_secret') != '') {
                $client_secret = esc_attr(get_option('xwcpos_client_secret'));
            } else {
                $client_secret = '';
            }

            if (get_option('xwcpos_site_id') != '') {
                $site_id = esc_attr(get_option('xwcpos_site_id'));
            } else {
                $site_id = '';
            }

            if (get_option('xwcpos_account_id') != '') {
                $xwcpos_account_id = esc_attr(get_option('xwcpos_account_id'));
            } else {
                $xwcpos_account_id = '';
            }

            if (get_option('xwcpos_shipping_product_id') != '') {
                $xwcpos_shipping_product_id = esc_attr(get_option('xwcpos_shipping_product_id'));
            } else {
                $xwcpos_shipping_product_id = '';
            }

            if (!empty(get_option('xwcpos_access_token'))) {
                $token = esc_attr(get_option('xwcpos_access_token'));
            } else {
                $token = '';
            }

            $merchantos = new WP_MOSAPICall((object) [
                'username' => $client_id,
                'password' => $client_secret,
            ], '');
            //Account settings
            try {
                $account = $merchantos->makeAPICall('companies/me', 'Read');
            } catch (Exception $e) {
                $this->mod_errors[] = $e->getMessage();
            }

            if (isset($account->error)) {
                $this->init_errors[] = sprintf('%s %d %s %s %s %s', 'Kounta API Error - ', $account->error, $account->error_description, 'Read', ' - Payload: ', '/companies/me.json');
            } else {
                $this->xwcpos_get_account($account, $token);
                $xwcpos_account_id = $account->id;
                $shop = $this->get_shop_data($xwcpos_account_id, $merchantos);
            }

            $xwcpos_payment_gateways = json_decode(get_option('xwcpos_payment_gateways'), true);
            
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $enabled_gateways = isset($xwcpos_payment_gateways) ? $xwcpos_payment_gateways :array();
            foreach( $enabled_gateways as $gateway ) {
              if(!isset($gateways[$gateway['id']])){
                $kounta_pm = $gateway['kounta_pm'];
                $kpm = isset($kounta_pm) ? $kounta_pm : '';
                $enabled_gateways[$gateway['id']] = array(
                  'title'=> $gateway['title'],
                  'id'=> $gateway['id'],
                  'kounta_pm'=> $kpm,
                  'enabled' => false
                );
              }
              
            }

            if( $gateways ) {
                foreach( $gateways as $gateway ) {
                  if($gateway->id){
                    if(isset($xwcpos_payment_gateways[$gateway->id])){
                      $kounta_pm = $xwcpos_payment_gateways[$gateway->id]['kounta_pm'];
                    }
                    $kpm = isset($kounta_pm) ? $kounta_pm : '';

                    $enabled_gateways[$gateway->id] = array(
                      'title'=> $gateway->title,
                      'id'=> $gateway->id,
                      'kounta_pm'=> $kpm,
                      'enabled' => $gateway->enabled == 'yes'? true : false
                    );
                  }
                }
              }
               
            
            update_option('xwcpos_payment_gateways', json_encode($enabled_gateways));
            update_option('xwcpos_initialized', true);
            update_option('xwcpos_oauth_token', $token);


            ?>

			<h1><?php echo esc_html__("WooCommerce Kounta POS Integration", "xwcpos"); ?></h1>
			<p><?php echo esc_html__("Import Kounta POS Cloud data to your WooCommerce store.", "xwcpos"); ?></p>
			<form action="" method="post">
        <div class="xwcpos_section">
          <h2>API key</h2>
          <p class="xwcpos_main">
            <label><?php echo esc_html__("Client ID:", "xwcpos"); ?></label>
            <input type="text" name="xwcpos_client_id" id="xwcpos_client_id" class="xwcpos_field" value="<?php echo $client_id ?>" />
          </p>

          <p class="xwcpos_main">
            <label><?php echo esc_html__("Client Secret:", "xwcpos"); ?></label>
            <input type="text" name="xwcpos_client_secret" id="xwcpos_client_secret" class="xwcpos_field" value="<?php echo $client_secret ?>" />
          </p>
        </div>
        <div class="xwcpos_section">
          <h2>Site</h2>
          <p>The Kounta site ID that you'd like orders to be added to</p> 
          <p class="xwcpos_main">
            <label><?php echo esc_html__("Site ID:", "xwcpos"); ?></label>
            <input type="text" name="xwcpos_site_id" id="xwcpos_site_id" class="xwcpos_field" value="<?php echo $site_id ?>" />
          </p>
        </div>

        <div class="xwcpos_section">
          <h2>Shipping</h2>
          <p>The Kounta product ID to use for shipping</p> 
          <p class="xwcpos_main">
            <label><?php echo esc_html__("Shipping Product ID:", "xwcpos"); ?></label>
            <input type="text" name="xwcpos_shipping_product_id" id="xwcpos_shipping_product_id" class="xwcpos_field" value="<?php echo $xwcpos_shipping_product_id ?>" />
          </p>
        </div>
        
        <div class="xwcpos_section">
          <h2>Payment Methods</h2>
          <p>Enter the corresponding Kounta payment method ID for each payment method available on the website so we can match the payment methods for reporting</p>
          <?php
          foreach($enabled_gateways as $gateway){
            if($gateway['enabled'] == 'true'){
            ?>
            <p class="xwcpos_main">
              <label><?php echo esc_html__($gateway['title'], "xwcpos"); ?></label>
              <input type="text" name="<?php echo "gateway-".$gateway['id'];?>" id="<?php echo "gateway-".$gateway['id'];?>" class="xwcpos_field" value="<?php echo $gateway['kounta_pm'];?>" />
            </p>
          <?php
          }}
          ?>
        </div>
        <p class="xwcpos_main">
            <input type="submit" class="button button-primary button-large" name="xwcpos_submit" value="<?php echo esc_html__("Save Setting", "xwcpos"); ?>">
          </p>
			</form>

			<?php
          //OAuth2 fields
            // if ($this->api_connection_success_message($shop) != '') {

            //     $this->api_connection_success_message($shop);

            // } else {

            //     echo esc_html__("Connection lost with API, please reconnect.", "xwcpos");
            // }
            ?>

			<!-- <p class="xwcpos_main">
				<label><?php echo esc_html__("API Connect:", "xwcpos"); ?></label>
				<a href="<?php echo $woo_connect_full_url ?>" class="xwcpos_ls"><?php echo esc_html__("Connect with Kounta POS", "xwcpos"); ?></a>
			</p> -->

			<?php

        }

        public function api_connection_success_message($shop)
        {

            if (!empty($shop->Shop->name) && !empty($shop->Shop->timeZone)) {

                echo esc_html__("API Settings successfully initialized! Store name(s): " . $shop->Shop->name . ", Time Zone: " . $shop->Shop->timeZone, "xwcpos");
            }

        }

        public function xwcpos_saveAPI()
        {

            if (isset($_POST['xwcpos_client_id']) && $_POST['xwcpos_client_id'] != '') {
                $client_id = sanitize_text_field($_POST['xwcpos_client_id']);
            } else {
                $client_id = '';
            }

            if (isset($_POST['xwcpos_client_secret']) && $_POST['xwcpos_client_secret'] != '') {
                $client_secret = sanitize_text_field($_POST['xwcpos_client_secret']);
            } else {
                $client_secret = '';
            }

            if (isset($_POST['xwcpos_site_id']) && $_POST['xwcpos_site_id'] != '') {
                $site_id = sanitize_text_field($_POST['xwcpos_site_id']);
            } else {
                $site_id = '';
            }

            if (isset($_POST['xwcpos_shipping_product_id']) && $_POST['xwcpos_shipping_product_id'] != '') {
                $xwcpos_shipping_product_id = sanitize_text_field($_POST['xwcpos_shipping_product_id']);
            } else {
                $xwcpos_shipping_product_id = '';
            }

            // $post = $_POST;

            //get enabled gateways
            $xwcpos_payment_gateways = json_decode(get_option('xwcpos_payment_gateways'), true);
            $gateways = isset($xwcpos_payment_gateways) ? $xwcpos_payment_gateways : [];
            $updated_gateways = $gateways;


            //for each gateway
            foreach( $gateways as $gateway ) {
              $field_id = "gateway-".$gateway['id'];
              //check to see if the field was filled
              if (isset($_POST[$field_id]) && $_POST[$field_id] != ''){
                $updated_gateways[$gateway['id']]['kounta_pm'] = sanitize_text_field($_POST[$field_id]);
              } else {
                $updated_gateways[$gateway['id']]['kounta_pm'] = '';
              }
            }

            //save gateways option
            
            $result = update_option('xwcpos_payment_gateways',  json_encode( $updated_gateways));
            update_option('xwcpos_client_id', $client_id);
            update_option('xwcpos_client_secret', $client_secret);
            update_option('xwcpos_site_id', $site_id);
            update_option('xwcpos_shipping_product_id', $xwcpos_shipping_product_id);

        }

        public function xwcpos_get_account($account, $token)
        {

            if (isset($account->id)) {
                // Save the account ID
                update_option('xwcpos_account_id', $account->id);
            } else {
                $this->mod_errors[] = __('Could not find an company associated with this API key.', 'xwcpos');
            }
        }

        public function xwcpos_get_site_data($siteData)
        {
            // echo '<br/>';
            // var_dump($siteData);
            // echo '<br/>';

            if (!empty($siteData)) {
                // Add it to the settings
                $site_data = array();
                $site_data['xwcpos_store_name'] = $siteData[0]->name;
                $site_data['xwcpos_store_id'] = $siteData[0]->id;
                $site_data['xwcpos_store_data'] = $siteData;

                update_option('xwcpos_site_data', $site_data);
                //update_option('xwcpos_site_id', $siteData[0]->id);
            }
        }

        public function xwcpos_display_mod_errors()
        {
            $displayed = wp_cache_get('xwcpos_init_errors_displayed');
            if (is_array($this->mod_errors) && !$displayed) {
                foreach ($this->mod_errors as $key => $error) {
                    ?>
	                <div class="error is-dismissible'">
	                    <p><?php echo $error ?></p>
	                </div>
	                <?php
                };
                wp_cache_set('xwcpos_init_errors_displayed', true);
            }
        }

        public function xwcpos_ewlops_cats_import_callback()
        {

            require_once XWCPOS_PLUGIN_DIR . 'admin/class-xwcpos-import-categories.php';
        }

        public function xwcpos_ewlops_products_import_callback()
        {
            require_once XWCPOS_PLUGIN_DIR . 'admin/class-xwcpos-import-products.php';
        }

        public function add_xwcpos_meta_box($product)
        {

            $allowed_statuses = array('publish', 'future', 'draft', 'pending', 'private');
            
            if (isset($product->post_status) && in_array($product->post_status, $allowed_statuses)) {
                //TODO add this back when/if we want to be able to push changes/new products from WC to LS
              add_meta_box(
                    'xwcpos_meta_box',
                    esc_html__('Kounta POS', 'xwcpos'),
                    array($this, 'xwcpos_render_productID_meta_box'),
                    $product->post_type,
                    'side'
                );
            }

        }

        public function xwcpos_render_productID_meta_box($post)
        {
            $product_id = intval(get_post_meta($post->ID, '_xwcpos_item_id', true ));
            ?>
            <p>Kounta Product ID: <?php echo $product_id;?></p>
		<?php }

        public function xwcpos_render_meta_box($post)
        {
            
            $product_id = intval(get_post_meta($product_id, '_xwcpos_item_id', true ));
            ?>
            <p>Kounta Product ID<?php echo $product_id;?></p>
			<p>
                
				<button onclick="syncWithLS('<?php echo esc_attr($post->ID); ?>')" class="button-secondary button" type="button" id="xwcpos-sync-to-ls">
					<?php echo esc_html__('Sync with Kounta', 'xwcpos'); ?>
				</button>
				<img class="help_tip tips xwcpos-load-prod-tip" data-tip="<?php echo esc_html__('Sync this product with Kounta!', 'xwcpos'); ?>" src="<?php echo esc_attr(WC()->plugin_url()); ?>/assets/images/help.png" height="16" width="16">
				<span class="spinner"></span>
				<div id="xwcpos-sync-status"></div>

				<div class="errosmessage errpro"></div>
				<div class="success_message successpro"></div>
			</p>

		<?php }

        
        
        function xwcpos_admin_product_id_column( $columns ){
        $columns['_xwcpos_item_id'] = __( 'Kounta Product ID'); 
        return $columns;
        }
        
        function xwcpos_admin_product_id_column_content( $column, $product_id ){

            $kounta_product_id = get_post_meta($product_id, '_xwcpos_item_id', true );
            //$store_info = dokan_get_store_info( $seller );
            //$store_name = $store_info['store_name'];

            if ( $column == '_xwcpos_item_id' ) {
                echo __($kounta_product_id);
                
            }
        }
        
        function sortable_product_id_column( $columns ) {
            $columns['_xwcpos_item_id'] = 'xwcpos_item_id';
         
            //To make a column 'un-sortable' remove it from the array
            //unset($columns['date']);
         
            return $columns;
        }

        
        function xwcpos_item_id_orderby( $orderby_statement, $wp_query ) {
            if( ! is_admin() )
                return $orderby_statement;
                
            global $pagenow;
            if ($pagenow == 'edit.php' && isset($_GET['orderby']) && $wp_query->get("post_type") === "product" && str_contains($_GET['orderby'], 'xwcpos_item_id') && !isset($_GET['filter_action'])) {
                if( str_contains($_GET['order'], 'asc'))return "(m1.meta_value) ASC";
                if( str_contains($_GET['order'], 'desc'))return "(m1.meta_value) DESC";
            } else {
                # Use provided statement instead 
                return $orderby_statement;
            }
        }

        function xwcpos_item_id_join_paged($join_paged_statement, $wp_query){
            if( ! is_admin() )
                return $join_paged_statement;

                global $pagenow;
              
                if ($pagenow == 'edit.php' && isset($_GET['orderby']) && $wp_query->get("post_type") === "product" && str_contains($_GET['orderby'], 'xwcpos_item_id')) {
                    # In this trivial example add a reverse menu order sort
                    return "LEFT JOIN wp_postmeta m1 ON wp_posts.ID = m1.post_id AND m1.meta_key='_xwcpos_item_id'";
                } else {
                    # Use provided statement instead 
                    return $join_paged_statement;
                }
        }

        // function xwcpos_item_id_orderby( $query ) {
        //     if( ! is_admin() )
        //         return;
        
        //     $orderby = $query->get( 'orderby');
        
        //     if( 'xwcpos_item_id' == $orderby ) {
        //         $query->set('meta_key','_xwcpos_item_id');
        //         $query->set('orderby','meta_value');
        //     }
        // }

        function xwcpos_admin_order_id_column( $columns ){
            $columns['_kounta_id'] = __( 'Kounta order ID'); 
            return $columns;
            }
            
            function xwcpos_admin_order_id_column_content( $column, $order_id ){
    
                $kounta_order_id = get_post_meta($order_id, '_kounta_id', true );
                //$store_info = dokan_get_store_info( $seller );
                //$store_name = $store_info['store_name'];
    
                if ( $column == '_kounta_id' ) {
                    echo __($kounta_order_id);
                    
                }
            }
            
            function sortable_order_id_column( $columns ) {
                $columns['_kounta_id'] = 'kounta_id';
             
                //To make a column 'un-sortable' remove it from the array
                //unset($columns['date']);
             
                return $columns;
            }
    
            
            function xwcpos_order_item_id_orderby( $orderby_statement, $wp_query ) {
                if( ! is_admin() )
                    return $orderby_statement;
                    
                global $pagenow;
                if ($pagenow == 'edit.php' && $wp_query->get("post_type") === "shop_order" && str_contains($_GET['orderby'], 'kounta_id')) {
                    if( str_contains($_GET['order'], 'asc'))return "(m1.meta_value) ASC";
                    if( str_contains($_GET['order'], 'desc'))return "(m1.meta_value) DESC";
                    //return $orderby_statement;
                } else {
                    # Use provided statement instead 
                    return $orderby_statement;
                }
            }
    
            function xwcpos_order_item_id_join_paged($join_paged_statement, $wp_query){
                if( ! is_admin() )
                    return $join_paged_statement;
    
                    global $pagenow;
    
                    if ($pagenow == 'edit.php' && $wp_query->get("post_type") === "shop_order" && str_contains($_GET['orderby'], 'kounta_id')) {
                        return "LEFT JOIN wp_postmeta m1 ON wp_posts.ID = m1.post_id AND m1.meta_key='_kounta_id'";
                        //return $join_paged_statement;
                    } else {
                        # Use provided statement instead 
                        return $join_paged_statement;
                    }
            }

    }
    new BrewHQ_Kounta_POS_Int_Admin();

}