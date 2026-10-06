<?php
/**
 * Missing Products Report
 *
 * Finds WooCommerce products that cannot be sent to Kounta as an order line,
 * because they have no Kounta product ID (`_xwcpos_item_id`) or because the ID
 * they have is no longer an active Kounta product. Kounta's product list
 * leaves out deleted products, so a deleted product counts as missing. The order service drops lines like
 * these, and an order made up only of such lines fails with "no_items".
 *
 * For each problem product it also counts the paid orders containing it that
 * have not synced to Kounta, so the products blocking real orders can be fixed
 * first.
 *
 * @package BrewHQ_Kounta
 * @since 2.1.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Missing_Products_Report {

    const REASON_NO_MAPPING = 'no_kounta_id';
    const REASON_NOT_IN_KOUNTA = 'not_in_kounta';

    /**
     * Kounta API client
     *
     * @var Kounta_API_Client
     */
    private $api_client;

    public function __construct() {
        $this->api_client = new Kounta_API_Client();
    }

    /**
     * Fetch every product ID in the Kounta company
     *
     * @param callable|null $progress Called with the page number and running total after each page
     * @return array|WP_Error Map of Kounta product ID => product name
     */
    public function fetch_kounta_products($progress = null) {
        $account_id = get_option('xwcpos_account_id');
        $products = array();
        $page = 1;
        $max_pages = 1000;

        while ($page <= $max_pages) {
            $result = $this->api_client->make_request('companies/' . $account_id . '/products', 'GET', array('page' => $page));

            if (is_wp_error($result)) {
                return $result;
            }

            if (!is_array($result)) {
                return new WP_Error('unexpected_response', 'Unexpected response from Kounta when listing products (page ' . $page . ')');
            }

            $new_ids = 0;
            foreach ($result as $k_product) {
                if (!isset($k_product->id)) {
                    continue;
                }
                if (!isset($products[$k_product->id])) {
                    $new_ids++;
                }
                $products[$k_product->id] = isset($k_product->name) ? $k_product->name : '';
            }

            if ($progress) {
                call_user_func($progress, $page, count($products));
            }

            // A short page is the last page. A page with no new IDs means the
            // API is ignoring the page parameter, so stop rather than loop forever.
            if (count($result) < 100 || $new_ids === 0) {
                break;
            }
            $page++;
        }

        return $products;
    }

    /**
     * Find purchasable WooCommerce products with no working Kounta link
     *
     * Variable parent products are skipped because customers buy their
     * variations, and the order service looks up the variation's own
     * `_xwcpos_item_id`.
     *
     * @param array $kounta_products Map of Kounta product ID => name, from fetch_kounta_products()
     * @param array $statuses        Post statuses to include
     * @return array List of problem product rows
     */
    public function find_problem_products($kounta_products, $statuses = array('publish', 'private')) {
        global $wpdb;

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_type, p.post_status, p.post_parent, p.post_title,
                    sku.meta_value AS sku,
                    item.meta_value AS kounta_id,
                    missing.meta_value AS missing_since
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             LEFT JOIN {$wpdb->postmeta} item ON item.post_id = p.ID AND item.meta_key = '_xwcpos_item_id'
             LEFT JOIN {$wpdb->postmeta} missing ON missing.post_id = p.ID AND missing.meta_key = '_xwcpos_missing_from_kounta'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ($status_placeholders)
             ORDER BY p.post_title ASC",
            $statuses
        ));

        // Parents of variations are not bought directly
        $variable_parents = array();
        $kounta_id_by_post = array();
        foreach ($rows as $row) {
            if ($row->post_type === 'product_variation' && $row->post_parent) {
                $variable_parents[$row->post_parent] = true;
            }
            $kounta_id_by_post[$row->ID] = trim((string) $row->kounta_id);
        }

        // Gift card variations are sent as their parent's Kounta product
        $gift_card_kounta_id = (string) get_option('xwcpos_gift_card_product_id', '');

        // Active Kounta products by name, to suggest a product to re-link to
        $kounta_by_name = array();
        foreach ($kounta_products as $k_id => $k_name) {
            $kounta_by_name[$this->normalise_name($k_name)][] = $k_id;
        }

        $problems = array();
        foreach ($rows as $row) {
            if ($row->post_type === 'product' && isset($variable_parents[$row->ID])) {
                continue;
            }

            $kounta_id = trim((string) $row->kounta_id);

            if ($kounta_id === '' && $gift_card_kounta_id !== '' && $row->post_type === 'product_variation'
                && isset($kounta_id_by_post[$row->post_parent]) && $kounta_id_by_post[$row->post_parent] === $gift_card_kounta_id) {
                $kounta_id = $gift_card_kounta_id;
            }

            if ($kounta_id === '' || intval($kounta_id) === 0) {
                $reason = self::REASON_NO_MAPPING;
            } elseif (!isset($kounta_products[$kounta_id])) {
                $reason = self::REASON_NOT_IN_KOUNTA;
            } else {
                continue;
            }

            $name = $this->get_display_name($row);
            $key = $this->normalise_name($name);

            $problems[] = array(
                'product_id' => intval($row->ID),
                'parent_id' => intval($row->post_parent),
                'name' => $name,
                'sku' => (string) $row->sku,
                'product_type' => $this->get_product_type($row),
                'status' => $row->post_status,
                'kounta_id' => $kounta_id,
                'reason' => $reason,
                'possible_match' => isset($kounta_by_name[$key]) ? implode(' ', $kounta_by_name[$key]) : '',
                'missing_since' => (string) $row->missing_since,
                'unsynced_orders' => 0,
                'latest_unsynced_order' => '',
            );
        }

        return $problems;
    }

    /**
     * Count paid orders since a date that contain each problem product and
     * have no Kounta order ID
     *
     * @param array  $problems Rows from find_problem_products(), updated in place
     * @param string $since    Earliest order date (Y-m-d)
     * @return array Order IDs that have not synced, newest first
     */
    public function attach_unsynced_orders(&$problems, $since) {
        global $wpdb;

        if (empty($problems)) {
            return array();
        }

        $index = array();
        foreach ($problems as $i => $problem) {
            $index[$problem['product_id']] = $i;
        }

        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $stats_table = $wpdb->prefix . 'wc_order_stats';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT l.order_id, l.product_id, l.variation_id
             FROM {$lookup_table} l
             INNER JOIN {$stats_table} s ON s.order_id = l.order_id
             WHERE s.date_created >= %s
               AND s.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
             ORDER BY l.order_id DESC",
            $since . ' 00:00:00'
        ));

        $unsynced = array();
        $synced_cache = array();
        $counted = array();

        foreach ($lines as $line) {
            $line_product = intval($line->variation_id) ? intval($line->variation_id) : intval($line->product_id);
            if (!isset($index[$line_product])) {
                continue;
            }

            $order_id = intval($line->order_id);
            if (!isset($synced_cache[$order_id])) {
                $synced_cache[$order_id] = $this->order_has_kounta_id($order_id);
            }
            if ($synced_cache[$order_id]) {
                continue;
            }

            $key = $line_product . ':' . $order_id;
            if (isset($counted[$key])) {
                continue;
            }
            $counted[$key] = true;

            $i = $index[$line_product];
            $problems[$i]['unsynced_orders']++;
            if ($problems[$i]['latest_unsynced_order'] === '') {
                $problems[$i]['latest_unsynced_order'] = (string) $order_id;
            }
            $unsynced[$order_id] = true;
        }

        return array_keys($unsynced);
    }

    /**
     * Compare the plugin's local copy of Kounta products (shown on the Import
     * Products page) with the active Kounta product list
     *
     * @param array $kounta_products Map of Kounta product ID => name, from fetch_kounta_products()
     * @return array|WP_Error Kounta IDs to flag as deleted ('remove') and to unflag ('restore')
     */
    public function plan_local_item_flags($kounta_products) {
        global $wpdb;
        $table = $wpdb->prefix . 'xwcpos_items';

        $rows = $wpdb->get_results("SELECT DISTINCT item_id, deleted FROM {$table} WHERE item_id IS NOT NULL AND item_id <> ''");

        $active_rows = 0;
        foreach ($rows as $row) {
            if ((string) $row->deleted !== '1') {
                $active_rows++;
            }
        }

        // A partial product list would flag most of the table; refuse rather than guess
        if (count($kounta_products) < $active_rows / 2) {
            return new WP_Error('partial_list', sprintf(
                'Kounta returned %d products but the local table has %d active rows; not changing the local table',
                count($kounta_products), $active_rows
            ));
        }

        $plan = array('remove' => array(), 'restore' => array());
        foreach ($rows as $row) {
            $gone = !isset($kounta_products[$row->item_id]);
            $flagged = ((string) $row->deleted === '1');
            if ($gone && !$flagged) {
                $plan['remove'][] = $row->item_id;
            } elseif (!$gone && $flagged) {
                $plan['restore'][] = $row->item_id;
            }
        }
        $plan['remove'] = array_values(array_unique($plan['remove']));
        $plan['restore'] = array_values(array_unique($plan['restore']));

        return $plan;
    }

    /**
     * Check both order meta storage locations for a Kounta order ID
     *
     * Successful uploads are written with update_post_meta(), so on a store
     * using HPOS without compatibility sync the ID may only be in postmeta.
     *
     * @param int $order_id Order ID
     * @return bool
     */
    private function order_has_kounta_id($order_id) {
        if (get_post_meta($order_id, '_kounta_id', true)) {
            return true;
        }
        $order = wc_get_order($order_id);
        return $order && $order->get_meta('_kounta_id');
    }

    /**
     * @param string $name Product name
     * @return string Lower-case name with whitespace collapsed
     */
    private function normalise_name($name) {
        return strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $name, ENT_QUOTES))));
    }

    /**
     * @param object $row Product row
     * @return string
     */
    private function get_display_name($row) {
        if ($row->post_type !== 'product_variation') {
            return $row->post_title;
        }
        $product = wc_get_product($row->ID);
        return $product ? $product->get_name() : $row->post_title;
    }

    /**
     * @param object $row Product row
     * @return string
     */
    private function get_product_type($row) {
        if ($row->post_type === 'product_variation') {
            return 'variation';
        }
        $terms = wp_get_object_terms($row->ID, 'product_type', array('fields' => 'names'));
        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : 'simple';
    }
}
