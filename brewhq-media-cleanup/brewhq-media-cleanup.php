<?php
/**
 * Plugin Name: BrewHQ Media Orphan Cleanup
 * Description: Scans the media library for image attachments that are no longer referenced anywhere (featured images, product galleries, post content, meta, options) and lets you delete them in safe, reviewed batches. Built to clean up duplicate product images left behind by POS image syncing.
 * Version: 1.0.0
 * Author: XAVA Digital
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class BrewHQ_Media_Cleanup {

    const BATCH_POSTS = 400;      // posts per content-scan request
    const BATCH_META = 2000;      // postmeta rows per meta-scan request
    const BATCH_ATTACHMENTS = 400; // attachments classified per request
    const BATCH_DELETE = 25;      // attachments deleted per request
    const DEFAULT_MIN_AGE_HOURS = 168; // ignore uploads newer than this (7 days) unless overridden per-scan

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_ajax_bmc_scan_step', array($this, 'ajax_scan_step'));
        add_action('wp_ajax_bmc_delete_batch', array($this, 'ajax_delete_batch'));
        add_action('wp_ajax_bmc_export_csv', array($this, 'ajax_export_csv'));
        add_action('admin_init', array($this, 'maybe_upgrade_tables'));
    }

    /* ---------------------------------------------------------------- */
    /* Tables                                                            */
    /* ---------------------------------------------------------------- */

    public function activate() {
        $this->create_tables();
    }

    public function maybe_upgrade_tables() {
        if (get_option('bmc_db_version') !== '1.0.0') {
            $this->create_tables();
        }
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$wpdb->prefix}bmc_candidates (
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            file VARCHAR(500) NOT NULL DEFAULT '',
            size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'candidate',
            note VARCHAR(190) NOT NULL DEFAULT '',
            PRIMARY KEY  (attachment_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}bmc_used_ids (
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY  (attachment_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}bmc_used_files (
            basename VARCHAR(191) NOT NULL,
            PRIMARY KEY  (basename)
        ) $charset;");

        update_option('bmc_db_version', '1.0.0', false);
    }

    /* ---------------------------------------------------------------- */
    /* Admin page                                                        */
    /* ---------------------------------------------------------------- */

    public function admin_menu() {
        add_management_page(
            'Media Orphan Cleanup',
            'Media Orphan Cleanup',
            'manage_options',
            'bmc-media-cleanup',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;
        $summary = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(size_bytes), 0) AS bytes
             FROM {$wpdb->prefix}bmc_candidates WHERE status = 'candidate'"
        );
        $deleted = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(size_bytes), 0) AS bytes
             FROM {$wpdb->prefix}bmc_candidates WHERE status = 'deleted'"
        );
        $scan_state = get_option('bmc_scan_state', array());
        $last_scan = isset($scan_state['finished_at']) ? $scan_state['finished_at'] : null;
        $preview = $wpdb->get_results(
            "SELECT attachment_id, file, size_bytes FROM {$wpdb->prefix}bmc_candidates
             WHERE status = 'candidate' ORDER BY size_bytes DESC LIMIT 200"
        );
        $nonce = wp_create_nonce('bmc_ajax');
        $export_url = admin_url('admin-ajax.php?action=bmc_export_csv&_wpnonce=' . $nonce);
        ?>
        <div class="wrap">
            <h1>Media Orphan Cleanup</h1>
            <p>
                Finds <strong>image attachments</strong> that are not referenced as a featured image,
                in a product gallery, in post content, in post meta, in term thumbnails, or in site
                options. Uploads newer than the minimum age below are ignored.
            </p>
            <p><em>Deletion is permanent (files are removed from disk). Always run a fresh scan, review the
            report/CSV, and take a backup before deleting.</em></p>

            <hr />
            <h2>Sync health</h2>
            <?php
            $recent = $this->count_recent_images(24);
            $previous = $this->count_recent_images(48, 24);
            $log_summary = $this->sync_log_summary(24);
            ?>
            <p>
                New image attachments — last 24 h: <strong><?php echo esc_html(number_format_i18n($recent)); ?></strong>
                &nbsp;|&nbsp; previous 24 h: <strong><?php echo esc_html(number_format_i18n($previous)); ?></strong>
            </p>
            <?php if ($log_summary !== null): ?>
                <p>
                    Kounta image sync activity, last 24 h:
                    <strong><?php echo esc_html(number_format_i18n($log_summary['downloads'])); ?></strong> downloads,
                    <strong><?php echo esc_html(number_format_i18n($log_summary['skips'])); ?></strong> skipped as unchanged,
                    <?php echo esc_html(number_format_i18n($log_summary['reattached'] + $log_summary['adopted'])); ?> re-used without download,
                    <?php echo esc_html(number_format_i18n($log_summary['errors'])); ?> errors
                </p>
                <p><em>After the sync fix, "downloads" should stay near zero while "skipped as unchanged" keeps counting —
                that combination is the proof the re-download loop is gone.</em></p>
            <?php endif; ?>

            <hr />
            <h2>Step 1 — Scan</h2>
            <p>
                <label>Ignore uploads newer than
                    <input type="number" id="bmc-min-age" value="<?php echo esc_attr(self::DEFAULT_MIN_AGE_HOURS); ?>" min="1" step="1" style="width:90px;" />
                    hours
                </label>
            </p>
            <?php if ($last_scan): ?>
                <p>Last completed scan: <strong><?php echo esc_html($last_scan); ?></strong></p>
            <?php endif; ?>
            <p>
                <button class="button button-primary" id="bmc-scan-btn">Start new scan</button>
                <span id="bmc-scan-status" style="margin-left:12px;"></span>
            </p>

            <hr />
            <h2>Step 2 — Review</h2>
            <p>
                Orphan candidates: <strong id="bmc-count"><?php echo esc_html(number_format_i18n((int) $summary->total)); ?></strong>
                &nbsp;|&nbsp; Reclaimable space: <strong id="bmc-size"><?php echo esc_html(size_format((int) $summary->bytes)); ?></strong>
                <?php if ((int) $deleted->total > 0): ?>
                    &nbsp;|&nbsp; Already deleted: <?php echo esc_html(number_format_i18n((int) $deleted->total)); ?>
                    (<?php echo esc_html(size_format((int) $deleted->bytes)); ?> freed)
                <?php endif; ?>
            </p>
            <p><a href="<?php echo esc_url($export_url); ?>" class="button">Download full CSV report</a></p>

            <?php if ($preview): ?>
                <details>
                    <summary>Preview — 200 largest candidates</summary>
                    <table class="widefat striped" style="max-width:900px;margin-top:8px;">
                        <thead><tr><th>ID</th><th>File</th><th>Size</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($preview as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row->attachment_id); ?></td>
                                <td><?php echo esc_html($row->file); ?></td>
                                <td><?php echo esc_html(size_format((int) $row->size_bytes)); ?></td>
                                <td><a href="<?php echo esc_url(admin_url('upload.php?item=' . $row->attachment_id)); ?>" target="_blank">view</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <hr />
            <h2>Step 3 — Delete</h2>
            <p>
                <label>
                    <input type="checkbox" id="bmc-confirm" />
                    I have reviewed the report and have a current backup.
                </label>
            </p>
            <p>
                <button class="button button-secondary" id="bmc-delete-btn" disabled>Delete all orphan candidates</button>
                <span id="bmc-delete-status" style="margin-left:12px;"></span>
            </p>
            <p id="bmc-delete-progress"></p>
        </div>

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            function post(data) {
                data._wpnonce = nonce;
                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data).toString()
                }).then(function (r) { return r.json(); });
            }

            var scanBtn = document.getElementById('bmc-scan-btn');
            var scanStatus = document.getElementById('bmc-scan-status');

            function scanStep(reset) {
                var minAge = document.getElementById('bmc-min-age').value || 168;
                post({ action: 'bmc_scan_step', reset: reset ? 1 : 0, min_age_hours: minAge }).then(function (res) {
                    if (!res || !res.success) {
                        scanStatus.textContent = 'Scan failed: ' + (res && res.data ? res.data : 'unknown error');
                        scanBtn.disabled = false;
                        return;
                    }
                    scanStatus.textContent = res.data.message;
                    if (res.data.done) {
                        scanStatus.textContent = 'Scan complete — reloading…';
                        window.location.reload();
                    } else {
                        scanStep(false);
                    }
                }).catch(function (e) {
                    scanStatus.textContent = 'Request failed: ' + e;
                    scanBtn.disabled = false;
                });
            }

            scanBtn.addEventListener('click', function () {
                scanBtn.disabled = true;
                scanStatus.textContent = 'Starting scan…';
                scanStep(true);
            });

            var confirmBox = document.getElementById('bmc-confirm');
            var deleteBtn = document.getElementById('bmc-delete-btn');
            var deleteStatus = document.getElementById('bmc-delete-status');
            var deleteProgress = document.getElementById('bmc-delete-progress');

            confirmBox.addEventListener('change', function () {
                deleteBtn.disabled = !confirmBox.checked;
            });

            var totals = { deleted: 0, kept: 0, bytes: 0 };

            function deleteStep() {
                post({ action: 'bmc_delete_batch' }).then(function (res) {
                    if (!res || !res.success) {
                        deleteStatus.textContent = 'Delete failed: ' + (res && res.data ? res.data : 'unknown error');
                        return;
                    }
                    totals.deleted += res.data.deleted;
                    totals.kept += res.data.kept;
                    totals.bytes += res.data.bytes;
                    deleteProgress.textContent = 'Deleted ' + totals.deleted + ' attachments (' +
                        (totals.bytes / 1048576).toFixed(1) + ' MB freed), skipped ' + totals.kept +
                        ' that turned out to still be in use. Remaining: ' + res.data.remaining;
                    if (res.data.remaining > 0) {
                        deleteStep();
                    } else {
                        deleteStatus.textContent = 'Cleanup complete — reloading…';
                        window.location.reload();
                    }
                }).catch(function (e) {
                    deleteStatus.textContent = 'Request failed: ' + e;
                });
            }

            deleteBtn.addEventListener('click', function () {
                if (!confirm('Permanently delete all orphan candidates? This cannot be undone.')) {
                    return;
                }
                deleteBtn.disabled = true;
                deleteStatus.textContent = 'Deleting…';
                deleteStep();
            });
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------- */
    /* Scan                                                              */
    /* ---------------------------------------------------------------- */

    public function ajax_scan_step() {
        $this->check_ajax_access();
        $min_age_hours = isset($_POST['min_age_hours']) ? intval($_POST['min_age_hours']) : 0;
        $result = $this->scan_step(!empty($_POST['reset']), $min_age_hours);
        if (!empty($result['error'])) {
            wp_send_json_error($result['message']);
        }
        wp_send_json_success($result);
    }

    /**
     * Run one scan step. Shared by the AJAX handler and WP-CLI.
     *
     * @param bool $reset Start a fresh scan
     * @param int $min_age_hours Ignore uploads newer than this many hours
     *                           (only honored on reset; 0 = default)
     * @return array {done: bool, message: string, error?: bool}
     */
    public function scan_step($reset = false, $min_age_hours = 0) {
        global $wpdb;

        $state = get_option('bmc_scan_state', array());

        if ($reset || empty($state['phase']) || !empty($state['finished_at'])) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bmc_candidates");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bmc_used_ids");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bmc_used_files");
            $state = array(
                'phase' => 'direct',
                'last_id' => 0,
                'min_age_hours' => $min_age_hours > 0 ? $min_age_hours : self::DEFAULT_MIN_AGE_HOURS,
            );
        }

        switch ($state['phase']) {
            case 'direct':
                $this->scan_direct_references();
                $state['phase'] = 'content';
                $state['last_id'] = 0;
                $message = 'Collected direct references (featured images, galleries, term thumbnails, options)…';
                break;

            case 'content':
                $done = $this->scan_post_content($state);
                $message = 'Scanning post content… (post ID ' . $state['last_id'] . ')';
                if ($done) {
                    $state['phase'] = 'meta';
                    $state['last_id'] = 0;
                    $message = 'Post content scanned. Scanning post meta…';
                }
                break;

            case 'meta':
                $done = $this->scan_post_meta($state);
                $message = 'Scanning post meta… (row ' . $state['last_id'] . ')';
                if ($done) {
                    $state['phase'] = 'options';
                    $state['last_id'] = 0;
                    $message = 'Post meta scanned. Scanning options…';
                }
                break;

            case 'options':
                $this->scan_options();
                $state['phase'] = 'attachments';
                $state['last_id'] = 0;
                $message = 'Options scanned. Classifying attachments…';
                break;

            case 'attachments':
                $result = $this->classify_attachments($state);
                $message = 'Classifying attachments… (' . $result['candidates'] . ' orphan candidates so far)';
                if ($result['done']) {
                    $state['finished_at'] = current_time('mysql');
                    update_option('bmc_scan_state', $state, false);
                    return array('done' => true, 'message' => 'Scan complete — ' . $result['candidates'] . ' orphan candidates');
                }
                break;

            default:
                return array('done' => true, 'error' => true, 'message' => 'Unknown scan phase');
        }

        update_option('bmc_scan_state', $state, false);
        return array('done' => false, 'message' => $message);
    }

    /**
     * Phase 1: attachment IDs referenced directly by ID in known locations.
     */
    private function scan_direct_references() {
        global $wpdb;
        $used = $wpdb->prefix . 'bmc_used_ids';

        // Featured images (products, variations, posts, pages)
        $wpdb->query(
            "INSERT IGNORE INTO $used (attachment_id)
             SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value REGEXP '^[0-9]+$'"
        );

        // Attachments tracked by the Kounta sync plugin as current product images
        $wpdb->query(
            "INSERT IGNORE INTO $used (attachment_id)
             SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta}
             WHERE meta_key = '_xwcpos_image_attachment_id' AND meta_value REGEXP '^[0-9]+$'"
        );

        // Product galleries (comma-separated ID lists)
        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value <> ''"
        );
        $gallery_ids = array();
        foreach ($galleries as $list) {
            foreach (explode(',', $list) as $id) {
                $id = intval(trim($id));
                if ($id > 0) {
                    $gallery_ids[$id] = true;
                }
            }
        }
        $this->insert_used_ids(array_keys($gallery_ids));

        // Term thumbnails (product categories etc.)
        $wpdb->query(
            "INSERT IGNORE INTO $used (attachment_id)
             SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->termmeta}
             WHERE meta_key IN ('thumbnail_id', 'image_id') AND meta_value REGEXP '^[0-9]+$'"
        );

        // Well-known options that store attachment IDs
        $option_ids = array();
        $option_ids[] = intval(get_option('site_icon'));
        $option_ids[] = intval(get_option('woocommerce_placeholder_image'));
        $mods_rows = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%'"
        );
        foreach ($mods_rows as $row) {
            $mods = maybe_unserialize($row);
            if (is_array($mods)) {
                foreach (array('custom_logo', 'site_logo', 'header_image_data', 'background_image') as $key) {
                    if (!empty($mods[$key]) && is_numeric($mods[$key])) {
                        $option_ids[] = intval($mods[$key]);
                    }
                }
            }
        }
        $this->insert_used_ids(array_filter($option_ids));
    }

    /**
     * Phase 2: scan post content for wp-image-N classes and upload URLs.
     * Returns true when finished.
     */
    private function scan_post_content(&$state) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE ID > %d
             AND post_type NOT IN ('attachment', 'revision')
             AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY ID ASC LIMIT %d",
            intval($state['last_id']),
            self::BATCH_POSTS
        ));

        if (!$rows) {
            return true;
        }

        $ids = array();
        $basenames = array();
        foreach ($rows as $row) {
            $state['last_id'] = intval($row->ID);
            $this->extract_references($row->post_content, $ids, $basenames);
        }
        $this->insert_used_ids(array_keys($ids));
        $this->insert_used_files(array_keys($basenames));

        return count($rows) < self::BATCH_POSTS;
    }

    /**
     * Phase 3: scan post meta values that mention the uploads directory or
     * wp-image classes (page builders, ACF fields that store URLs, etc.).
     * Returns true when finished.
     */
    private function scan_post_meta(&$state) {
        global $wpdb;

        $uploads = wp_upload_dir();
        $dir = wp_basename($uploads['baseurl']);
        $like = '%' . $wpdb->esc_like($dir) . '/%';
        // JSON-encoded values (Elementor and other builders) escape slashes: uploads\/2024\/...
        $like_json = '%' . $wpdb->esc_like($dir . '\\/') . '%';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_id > %d
             AND meta_key NOT IN ('_thumbnail_id', '_product_image_gallery', '_wp_attached_file', '_wp_attachment_metadata')
             AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE '%%wp-image-%%'
                  OR meta_key IN ('_elementor_data', '_elementor_page_settings'))
             ORDER BY meta_id ASC LIMIT %d",
            intval($state['last_id']),
            $like,
            $like_json,
            self::BATCH_META
        ));

        if (!$rows) {
            return true;
        }

        $ids = array();
        $basenames = array();
        foreach ($rows as $row) {
            $state['last_id'] = intval($row->meta_id);
            $this->extract_references($row->meta_value, $ids, $basenames);
        }
        $this->insert_used_ids(array_keys($ids));
        $this->insert_used_files(array_keys($basenames));

        return count($rows) < self::BATCH_META;
    }

    /**
     * Phase 4: scan option values mentioning uploads (widgets, customizer, builders).
     */
    private function scan_options() {
        global $wpdb;

        $uploads = wp_upload_dir();
        $dir = wp_basename($uploads['baseurl']);
        $like = '%' . $wpdb->esc_like($dir) . '/%';
        $like_json = '%' . $wpdb->esc_like($dir . '\\/') . '%';

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_value LIKE %s OR option_value LIKE %s",
            $like,
            $like_json
        ));

        $ids = array();
        $basenames = array();
        foreach ($rows as $value) {
            $this->extract_references($value, $ids, $basenames);
        }
        $this->insert_used_ids(array_keys($ids));
        $this->insert_used_files(array_keys($basenames));
    }

    /**
     * Phase 5: walk image attachments and record unreferenced ones as candidates.
     */
    private function classify_attachments(&$state) {
        global $wpdb;

        $min_age_hours = !empty($state['min_age_hours']) ? intval($state['min_age_hours']) : self::DEFAULT_MIN_AGE_HOURS;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $min_age_hours * HOUR_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.ID > %d
             AND p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%%'
             AND p.post_date_gmt < %s
             ORDER BY p.ID ASC LIMIT %d",
            intval($state['last_id']),
            $cutoff,
            self::BATCH_ATTACHMENTS
        ));

        if (!$rows) {
            $count = intval($wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bmc_candidates WHERE status = 'candidate'"
            ));
            return array('done' => true, 'candidates' => $count);
        }

        foreach ($rows as $row) {
            $state['last_id'] = intval($row->ID);

            if ($this->is_used($row->ID, $row->attached_file)) {
                continue;
            }

            $size = $this->attachment_total_size($row->ID, $row->attached_file);
            $wpdb->replace(
                $wpdb->prefix . 'bmc_candidates',
                array(
                    'attachment_id' => $row->ID,
                    'file' => (string) $row->attached_file,
                    'size_bytes' => $size,
                    'status' => 'candidate',
                    'note' => '',
                ),
                array('%d', '%s', '%d', '%s', '%s')
            );
        }

        $count = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bmc_candidates WHERE status = 'candidate'"
        ));

        return array('done' => false, 'candidates' => $count);
    }

    /* ---------------------------------------------------------------- */
    /* Reference helpers                                                 */
    /* ---------------------------------------------------------------- */

    /**
     * Pull attachment IDs (wp-image-N, data-id="N") and upload-file basenames
     * out of an arbitrary blob of content/meta/option text.
     */
    private function extract_references($text, array &$ids, array &$basenames) {
        if (!is_string($text) || $text === '') {
            return;
        }

        // Un-escape JSON-encoded slashes (Elementor stores layouts as JSON in
        // _elementor_data: {"id":123,"url":"https:\/\/...\/uploads\/...jpg"})
        // so the URL regex below sees plain paths.
        if (strpos($text, '\\/') !== false) {
            $text = str_replace('\\/', '/', $text);
        }

        if (preg_match_all('/wp-image-(\d+)/', $text, $m)) {
            foreach ($m[1] as $id) {
                $ids[intval($id)] = true;
            }
        }

        // Attachment-ID references: "id":123 in Gutenberg blocks and Elementor
        // JSON, and "id";i:123 in PHP-serialized values (_elementor_page_settings,
        // widget/customizer options)
        if (preg_match_all('/"id"\s*[:;]\s*(?:i:)?(\d+)/', $text, $m)) {
            foreach ($m[1] as $id) {
                $ids[intval($id)] = true;
            }
        }

        // Any file path that looks like an uploads reference
        if (preg_match_all('#[\w\-/%]+/([\w\-%\.@]+\.(?:jpe?g|png|gif|webp|avif|bmp|svg))#i', $text, $m)) {
            foreach ($m[1] as $file) {
                $basenames[$this->normalize_basename(rawurldecode($file))] = true;
            }
        }
    }

    /**
     * Reduce any size/scaled variant to the base upload filename so
     * "photo-300x200.jpg", "photo-scaled.jpg" and "photo.jpg" all match.
     */
    private function normalize_basename($basename) {
        $basename = preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $basename);
        $basename = preg_replace('/-(scaled|rotated)(?=\.\w+$)/', '', $basename);
        return strtolower(substr($basename, 0, 191));
    }

    private function insert_used_ids(array $ids) {
        global $wpdb;
        if (!$ids) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $values = implode('),(', array_map('intval', $chunk));
            $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}bmc_used_ids (attachment_id) VALUES ($values)");
        }
    }

    private function insert_used_files(array $basenames) {
        global $wpdb;
        if (!$basenames) {
            return;
        }
        foreach (array_chunk($basenames, 500) as $chunk) {
            $placeholders = implode('),(', array_fill(0, count($chunk), '%s'));
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}bmc_used_files (basename) VALUES ($placeholders)",
                $chunk
            ));
        }
    }

    /**
     * Is this attachment referenced anywhere we scanned?
     */
    private function is_used($attachment_id, $attached_file) {
        global $wpdb;

        $hit = $wpdb->get_var($wpdb->prepare(
            "SELECT attachment_id FROM {$wpdb->prefix}bmc_used_ids WHERE attachment_id = %d",
            $attachment_id
        ));
        if ($hit) {
            return true;
        }

        if ($attached_file) {
            $basename = $this->normalize_basename(wp_basename($attached_file));
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT basename FROM {$wpdb->prefix}bmc_used_files WHERE basename = %s",
                $basename
            ));
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total on-disk size of an attachment including generated thumbnail sizes.
     */
    private function attachment_total_size($attachment_id, $attached_file) {
        $meta = wp_get_attachment_metadata($attachment_id);
        $total = 0;

        if (is_array($meta)) {
            if (!empty($meta['filesize'])) {
                $total += intval($meta['filesize']);
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['filesize'])) {
                        $total += intval($size['filesize']);
                    }
                }
            }
        }

        if ($total === 0 && $attached_file) {
            $uploads = wp_upload_dir();
            $path = trailingslashit($uploads['basedir']) . $attached_file;
            if (file_exists($path)) {
                $total = intval(filesize($path));
                // Rough allowance for generated sizes when metadata has no sizes
                if (is_array($meta) && !empty($meta['sizes'])) {
                    $total = intval($total * (1 + 0.5 * count($meta['sizes'])));
                }
            }
        }

        return $total;
    }

    /* ---------------------------------------------------------------- */
    /* Delete                                                            */
    /* ---------------------------------------------------------------- */

    public function ajax_delete_batch() {
        $this->check_ajax_access();
        $result = $this->delete_batch();
        if (!empty($result['error'])) {
            wp_send_json_error($result['message']);
        }
        wp_send_json_success($result);
    }

    /**
     * Delete one batch of orphan candidates. Shared by the AJAX handler and WP-CLI.
     *
     * @param int $batch_size Attachments to process (0 = default)
     * @return array {deleted, kept, bytes, remaining} or {error, message}
     */
    public function delete_batch($batch_size = 0) {
        global $wpdb;

        $scan_state = get_option('bmc_scan_state', array());
        if (empty($scan_state['finished_at'])) {
            return array('error' => true, 'message' => 'No completed scan found. Run a scan first.');
        }

        $batch_size = intval($batch_size) > 0 ? intval($batch_size) : self::BATCH_DELETE;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, size_bytes FROM {$wpdb->prefix}bmc_candidates
             WHERE status = 'candidate' ORDER BY attachment_id ASC LIMIT %d",
            $batch_size
        ));

        $deleted = 0;
        $kept = 0;
        $bytes = 0;

        foreach ($rows as $row) {
            $id = intval($row->attachment_id);

            // Re-verify right before deleting — usage may have changed since the scan
            if ($this->is_used_live($id)) {
                $wpdb->update(
                    $wpdb->prefix . 'bmc_candidates',
                    array('status' => 'kept', 'note' => 'in use at delete time'),
                    array('attachment_id' => $id)
                );
                $kept++;
                continue;
            }

            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $wpdb->update(
                    $wpdb->prefix . 'bmc_candidates',
                    array('status' => 'deleted', 'note' => 'already gone'),
                    array('attachment_id' => $id)
                );
                continue;
            }

            $result = wp_delete_attachment($id, true);
            if ($result) {
                $wpdb->update(
                    $wpdb->prefix . 'bmc_candidates',
                    array('status' => 'deleted'),
                    array('attachment_id' => $id)
                );
                $deleted++;
                $bytes += intval($row->size_bytes);
                $this->log_deletion($id, intval($row->size_bytes));
            } else {
                $wpdb->update(
                    $wpdb->prefix . 'bmc_candidates',
                    array('status' => 'failed', 'note' => 'wp_delete_attachment failed'),
                    array('attachment_id' => $id)
                );
                $kept++;
            }
        }

        $remaining = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bmc_candidates WHERE status = 'candidate'"
        ));

        return array(
            'deleted' => $deleted,
            'kept' => $kept,
            'bytes' => $bytes,
            'remaining' => $remaining,
        );
    }

    /**
     * Live (uncached) usage check for a single attachment, run immediately
     * before deletion. Covers the direct-ID reference points.
     */
    private function is_used_live($attachment_id) {
        global $wpdb;

        $thumb = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_thumbnail_id', '_xwcpos_image_attachment_id') AND meta_value = %s",
            (string) $attachment_id
        ));
        if (intval($thumb) > 0) {
            return true;
        }

        $gallery = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)",
            (string) $attachment_id,
            $wpdb->esc_like($attachment_id) . ',%',
            '%,' . $wpdb->esc_like($attachment_id) . ',%',
            '%,' . $wpdb->esc_like($attachment_id)
        ));
        if (intval($gallery) > 0) {
            return true;
        }

        $term = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta}
             WHERE meta_key IN ('thumbnail_id', 'image_id') AND meta_value = %s",
            (string) $attachment_id
        ));
        if (intval($term) > 0) {
            return true;
        }

        return false;
    }

    private function log_deletion($attachment_id, $bytes) {
        $uploads = wp_upload_dir();
        $line = current_time('mysql') . " deleted attachment {$attachment_id} ({$bytes} bytes)\n";
        error_log($line, 3, $uploads['basedir'] . '/bmc-media-cleanup.log');
    }

    /* ---------------------------------------------------------------- */
    /* CSV export                                                        */
    /* ---------------------------------------------------------------- */

    public function ajax_export_csv() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'bmc_ajax')) {
            wp_die('Not allowed');
        }
        global $wpdb;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=media-orphan-report-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('attachment_id', 'file', 'size_bytes', 'status', 'note', 'media_link'));

        $last_id = 0;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT attachment_id, file, size_bytes, status, note
                 FROM {$wpdb->prefix}bmc_candidates
                 WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT 2000",
                $last_id
            ));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $last_id = intval($row->attachment_id);
                fputcsv($out, array(
                    $row->attachment_id,
                    $row->file,
                    $row->size_bytes,
                    $row->status,
                    $row->note,
                    admin_url('upload.php?item=' . $row->attachment_id),
                ));
            }
        }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------- */
    /* Shrink oversized images                                           */
    /* ---------------------------------------------------------------- */

    /**
     * All attachment IDs used by WooCommerce products: featured images,
     * gallery images, variation images, and attachments uploaded to products.
     *
     * @return int[] Attachment IDs
     */
    public function get_product_image_ids() {
        global $wpdb;
        $ids = array();

        $rows = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND p.post_type IN ('product', 'product_variation')"
        );
        foreach ($rows as $id) {
            if (intval($id) > 0) {
                $ids[intval($id)] = true;
            }
        }

        $galleries = $wpdb->get_col(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND p.post_type = 'product' AND pm.meta_value <> ''"
        );
        foreach ($galleries as $list) {
            foreach (explode(',', $list) as $id) {
                if (intval($id) > 0) {
                    $ids[intval($id)] = true;
                }
            }
        }

        $attached = $wpdb->get_col(
            "SELECT a.ID FROM {$wpdb->posts} a
             JOIN {$wpdb->posts} pr ON a.post_parent = pr.ID
             WHERE a.post_type = 'attachment' AND a.post_mime_type LIKE 'image/%'
             AND pr.post_type IN ('product', 'product_variation')"
        );
        foreach ($attached as $id) {
            $ids[intval($id)] = true;
        }

        return array_keys($ids);
    }

    /**
     * Shrink one attachment so its SHORTEST side is at most $max pixels
     * (aspect ratio preserved — the image keeps at least $max pixels in both
     * dimensions, e.g. 4000x3000 becomes 1365x1024 at $max=1024):
     * - deletes WordPress's stashed pre-scaled original (original_image)
     * - resizes the main file in place (same filename, URLs keep working)
     * - deletes generated size files whose shortest side exceeds $max
     *
     * @param int $attachment_id
     * @param int $max Shortest-side maximum in pixels
     * @param bool $dry_run Report without changing anything
     * @return array {changed: bool, saved: int bytes, note: string}
     */
    public function shrink_attachment($attachment_id, $max, $dry_run = false) {
        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return array('changed' => false, 'saved' => 0, 'note' => 'unsupported type ' . $mime);
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return array('changed' => false, 'saved' => 0, 'note' => 'file missing');
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($meta)) {
            $meta = array();
        }

        $saved = 0;
        $changed = false;
        $dir = trailingslashit(dirname($file));

        // 1. WordPress kept the huge pre-scaled original alongside a -scaled main file
        if (!empty($meta['original_image']) && $meta['original_image'] !== wp_basename($file)) {
            $orig_path = $dir . $meta['original_image'];
            if (file_exists($orig_path)) {
                $saved += intval(filesize($orig_path));
                if (!$dry_run) {
                    @unlink($orig_path);
                    unset($meta['original_image']);
                }
                $changed = true;
            }
        }

        // 2. Resize the main file in place if oversized
        $width = isset($meta['width']) ? intval($meta['width']) : 0;
        $height = isset($meta['height']) ? intval($meta['height']) : 0;
        if (!$width || !$height) {
            $dims = @getimagesize($file);
            if ($dims) {
                $width = intval($dims[0]);
                $height = intval($dims[1]);
            }
        }

        if ($width > 0 && $height > 0 && min($width, $height) > $max) {
            $scale = $max / min($width, $height);
            $new_w = intval(round($width * $scale));
            $new_h = intval(round($height * $scale));
            $before = intval(filesize($file));
            if ($dry_run) {
                // Rough estimate: file size scales with pixel area
                $ratio = $scale * $scale;
                $saved += max(0, $before - intval($before * min(1, $ratio)));
                $changed = true;
            } else {
                $editor = wp_get_image_editor($file);
                if (is_wp_error($editor)) {
                    return array('changed' => $changed, 'saved' => $saved, 'note' => 'editor: ' . $editor->get_error_message());
                }
                $editor->set_quality(82);
                $resized = $editor->resize($new_w, $new_h, false);
                if (is_wp_error($resized)) {
                    return array('changed' => $changed, 'saved' => $saved, 'note' => 'resize: ' . $resized->get_error_message());
                }
                $result = $editor->save($file);
                if (is_wp_error($result)) {
                    return array('changed' => $changed, 'saved' => $saved, 'note' => 'save: ' . $result->get_error_message());
                }
                clearstatcache(true, $file);
                $after = intval(filesize($file));
                $saved += max(0, $before - $after);
                $meta['width'] = intval($result['width']);
                $meta['height'] = intval($result['height']);
                $meta['filesize'] = $after;
                $changed = true;
            }
        }

        // 3. Drop generated sizes whose shortest side exceeds the new maximum
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $name => $size_meta) {
                $sw = isset($size_meta['width']) ? intval($size_meta['width']) : 0;
                $sh = isset($size_meta['height']) ? intval($size_meta['height']) : 0;
                if ($sw > 0 && $sh > 0 && min($sw, $sh) > $max && !empty($size_meta['file'])) {
                    $size_path = $dir . $size_meta['file'];
                    if (file_exists($size_path)) {
                        $saved += intval(filesize($size_path));
                        if (!$dry_run) {
                            @unlink($size_path);
                        }
                    }
                    if (!$dry_run) {
                        unset($meta['sizes'][$name]);
                    }
                    $changed = true;
                }
            }
        }

        if ($changed && !$dry_run) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        return array('changed' => $changed, 'saved' => $saved, 'note' => '');
    }

    /* ---------------------------------------------------------------- */
    /* Sync health                                                       */
    /* ---------------------------------------------------------------- */

    /**
     * Count image attachments created in a time window.
     *
     * @param int $from_hours_ago Window start (hours before now)
     * @param int $to_hours_ago Window end (hours before now, 0 = now)
     * @return int
     */
    public function count_recent_images($from_hours_ago, $to_hours_ago = 0) {
        global $wpdb;
        $from = gmdate('Y-m-d H:i:s', time() - $from_hours_ago * HOUR_IN_SECONDS);
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'
                AND post_date_gmt >= %s";
        $params = array($from);
        if ($to_hours_ago > 0) {
            $sql .= " AND post_date_gmt < %s";
            $params[] = gmdate('Y-m-d H:i:s', time() - $to_hours_ago * HOUR_IN_SECONDS);
        }
        return intval($wpdb->get_var($wpdb->prepare($sql, $params)));
    }

    /**
     * Per-hour counts of new image attachments over the last N hours.
     *
     * @param int $hours
     * @return array rows of {hr, total}
     */
    public function recent_images_by_hour($hours) {
        global $wpdb;
        $from = gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(post_date, '%%Y-%%m-%%d %%H:00') AS hr, COUNT(*) AS total
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'
             AND post_date_gmt >= %s
             GROUP BY hr ORDER BY hr ASC",
            $from
        ));
    }

    /**
     * Of the recent image attachments, how many have a WordPress duplicate
     * suffix (photo-3.jpg) — the signature of the same file being sideloaded
     * repeatedly.
     *
     * @param int $hours
     * @return int
     */
    public function count_recent_dup_suffix($hours) {
        global $wpdb;
        $from = gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS);
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'
             AND p.post_date_gmt >= %s
             AND pm.meta_value REGEXP '-[0-9]+\\.(jpe?g|png|gif|webp|avif)$'",
            $from
        )));
    }

    /**
     * Summarize the Kounta sync plugin's image log for a time window by
     * reading the tail of uploads/brewhq-kounta.log.
     *
     * @param int $hours
     * @return array|null Counts, or null if the log file doesn't exist
     */
    public function sync_log_summary($hours) {
        $uploads = wp_upload_dir();
        $file = $uploads['basedir'] . '/brewhq-kounta.log';
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $size = filesize($file);
        $read = min($size, 5 * 1024 * 1024);
        $fh = fopen($file, 'r');
        if (!$fh) {
            return null;
        }
        if ($size > $read) {
            fseek($fh, $size - $read);
        }
        $data = fread($fh, $read);
        fclose($fh);

        // Log timestamps use the site's local time (current_time('mysql'))
        $cutoff = current_time('timestamp') - $hours * HOUR_IN_SECONDS;
        $counts = array(
            'downloads' => 0,
            'skips' => 0,
            'reattached' => 0,
            'adopted' => 0,
            'replaced_deleted' => 0,
            'errors' => 0,
            'log_truncated' => ($read < $size),
        );

        foreach (explode("\n", $data) as $line) {
            if (strpos($line, '::[Image Sync]') === false) {
                continue;
            }
            $ts = strtotime(substr($line, 0, 19));
            if (!$ts || $ts < $cutoff) {
                continue;
            }
            if (strpos($line, 'Image synced successfully') !== false) {
                $counts['downloads']++;
            } elseif (strpos($line, 'already has this image') !== false) {
                $counts['skips']++;
            } elseif (strpos($line, 'Re-attached existing image') !== false) {
                $counts['reattached']++;
            } elseif (strpos($line, 'adopting it') !== false) {
                $counts['adopted']++;
            } elseif (strpos($line, 'Deleted replaced attachment') !== false) {
                $counts['replaced_deleted']++;
            } elseif (stripos($line, 'ERROR') !== false) {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /* ---------------------------------------------------------------- */
    /* Access control                                                    */
    /* ---------------------------------------------------------------- */

    private function check_ajax_access() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'bmc_ajax')) {
            wp_send_json_error('Invalid nonce');
        }
        // These requests do heavy lifting — give each step room to work
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
    }
}

BrewHQ_Media_Cleanup::instance();

/**
 * WP-CLI interface — for large libraries the browser-driven flow is too slow;
 * run the scan and deletion over SSH instead:
 *
 *   wp media-cleanup scan
 *   wp media-cleanup status
 *   wp media-cleanup delete --yes [--batch-size=100] [--limit=5000]
 */
if (defined('WP_CLI') && WP_CLI) {
    class BrewHQ_Media_Cleanup_CLI {

        /**
         * Run a full scan (fresh) to completion.
         *
         * ## OPTIONS
         *
         * [--min-age-hours=<n>]
         * : Ignore uploads newer than this many hours (default 168 = 7 days).
         */
        public function scan($args, $assoc_args) {
            $cleanup = BrewHQ_Media_Cleanup::instance();
            $step = 0;

            $min_age_hours = isset($assoc_args['min-age-hours']) ? intval($assoc_args['min-age-hours']) : 0;
            $result = $cleanup->scan_step(true, $min_age_hours);
            while (true) {
                $step++;
                if (!empty($result['error'])) {
                    WP_CLI::error($result['message']);
                }
                // Progress lines are frequent on big libraries; print every 10th
                if (!empty($result['done']) || $step % 10 === 0) {
                    WP_CLI::log('[step ' . $step . '] ' . $result['message']);
                }
                if (!empty($result['done'])) {
                    break;
                }
                $result = $cleanup->scan_step(false);
            }
            WP_CLI::success('Scan complete.');
        }

        /**
         * Show candidate/deleted counts and reclaimable size.
         */
        public function status($args, $assoc_args) {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS total, COALESCE(SUM(size_bytes), 0) AS bytes
                 FROM {$wpdb->prefix}bmc_candidates GROUP BY status"
            );
            $state = get_option('bmc_scan_state', array());
            WP_CLI::log('Last completed scan: ' . (isset($state['finished_at']) ? $state['finished_at'] : 'none'));
            if (!$rows) {
                WP_CLI::log('No scan data.');
                return;
            }
            foreach ($rows as $row) {
                WP_CLI::log(sprintf(
                    '%-10s %10s  %s',
                    $row->status,
                    number_format((int) $row->total),
                    size_format((int) $row->bytes)
                ));
            }
        }

        /**
         * Delete orphan candidates in batches until none remain.
         *
         * ## OPTIONS
         *
         * [--yes]
         * : Skip the confirmation prompt.
         *
         * [--batch-size=<n>]
         * : Attachments per batch (default 100).
         *
         * [--limit=<n>]
         * : Stop after deleting this many (default 0 = all). Useful for a
         *   trial run, e.g. --limit=1000, before committing to the rest.
         */
        public function delete($args, $assoc_args) {
            $cleanup = BrewHQ_Media_Cleanup::instance();
            global $wpdb;

            $batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 100;
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 0;

            $remaining = intval($wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bmc_candidates WHERE status = 'candidate'"
            ));
            if ($remaining === 0) {
                WP_CLI::success('No orphan candidates to delete.');
                return;
            }

            $target = ($limit > 0 && $limit < $remaining) ? $limit : $remaining;
            WP_CLI::confirm(
                sprintf('Permanently delete %s orphan attachments (of %s candidates)? This cannot be undone.',
                    number_format($target), number_format($remaining)),
                $assoc_args
            );

            $totals = array('deleted' => 0, 'kept' => 0, 'bytes' => 0);
            $started = microtime(true);

            while (true) {
                $result = $cleanup->delete_batch($batch_size);
                if (!empty($result['error'])) {
                    WP_CLI::error($result['message']);
                }

                $totals['deleted'] += $result['deleted'];
                $totals['kept'] += $result['kept'];
                $totals['bytes'] += $result['bytes'];

                $rate = $totals['deleted'] / max(1, microtime(true) - $started);
                $eta = $rate > 0 ? human_time_diff(0, (int) ($result['remaining'] / $rate)) : '?';
                WP_CLI::log(sprintf(
                    'Deleted %s (%s freed), skipped %s in-use, remaining %s — ~%d/s, ETA %s',
                    number_format($totals['deleted']),
                    size_format($totals['bytes']),
                    number_format($totals['kept']),
                    number_format($result['remaining']),
                    $rate,
                    $eta
                ));

                if ($result['remaining'] === 0) {
                    break;
                }
                if ($limit > 0 && $totals['deleted'] >= $limit) {
                    WP_CLI::log('Reached --limit, stopping.');
                    break;
                }
                if ($result['deleted'] === 0 && $result['kept'] === 0) {
                    WP_CLI::warning('Batch made no progress, stopping to avoid a loop.');
                    break;
                }

                // Let MySQL breathe between batches
                usleep(100000);

                // Keep object caches from ballooning during a long run
                if (function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
            }

            WP_CLI::success(sprintf(
                'Done. Deleted %s attachments, freed %s, skipped %s that were in use.',
                number_format($totals['deleted']),
                size_format($totals['bytes']),
                number_format($totals['kept'])
            ));
        }

        /**
         * Report recent image-attachment activity — the health check for the
         * Kounta sync fix. New downloads should be near zero once the fix is live.
         *
         * ## OPTIONS
         *
         * [--hours=<n>]
         * : Window to inspect (default 24).
         */
        public function recent($args, $assoc_args) {
            $cleanup = BrewHQ_Media_Cleanup::instance();
            $hours = isset($assoc_args['hours']) ? max(1, intval($assoc_args['hours'])) : 24;

            $current = $cleanup->count_recent_images($hours);
            $previous = $cleanup->count_recent_images($hours * 2, $hours);
            $dupes = $cleanup->count_recent_dup_suffix($hours);

            WP_CLI::log(sprintf('New image attachments, last %d h:     %s', $hours, number_format($current)));
            WP_CLI::log(sprintf('New image attachments, prior %d h:    %s', $hours, number_format($previous)));
            WP_CLI::log(sprintf('Of recent, with duplicate suffix:     %s (e.g. photo-3.jpg — repeat sideloads)', number_format($dupes)));

            $by_hour = $cleanup->recent_images_by_hour($hours);
            if ($by_hour) {
                WP_CLI::log('');
                WP_CLI::log('Per-hour breakdown:');
                foreach ($by_hour as $row) {
                    WP_CLI::log(sprintf('  %s  %s', $row->hr, number_format((int) $row->total)));
                }
            }

            $log = $cleanup->sync_log_summary($hours);
            WP_CLI::log('');
            if ($log === null) {
                WP_CLI::warning('Kounta sync log (uploads/brewhq-kounta.log) not found — skipping log analysis.');
            } else {
                WP_CLI::log(sprintf('Kounta image sync activity, last %d h (from plugin log):', $hours));
                WP_CLI::log(sprintf('  Downloads:                 %s', number_format($log['downloads'])));
                WP_CLI::log(sprintf('  Skipped (image unchanged): %s', number_format($log['skips'])));
                WP_CLI::log(sprintf('  Re-attached (no download): %s', number_format($log['reattached'])));
                WP_CLI::log(sprintf('  Adopted existing image:    %s', number_format($log['adopted'])));
                WP_CLI::log(sprintf('  Replaced+cleaned old copy: %s', number_format($log['replaced_deleted'])));
                WP_CLI::log(sprintf('  Errors:                    %s', number_format($log['errors'])));
                if (!empty($log['log_truncated'])) {
                    WP_CLI::log('  (log tail only — very old entries not scanned)');
                }
                WP_CLI::log('');
                if ($log['downloads'] === 0 && ($log['skips'] + $log['reattached'] + $log['adopted']) > 0) {
                    WP_CLI::success('Sync is running and downloading nothing — the re-download fix is working.');
                } elseif ($log['downloads'] > 0 && $log['downloads'] < 50) {
                    WP_CLI::log(sprintf('%d downloads in the window — small numbers are normal (genuinely new/changed images in Kounta).', $log['downloads']));
                } elseif ($log['downloads'] >= 50) {
                    WP_CLI::warning('High download count — the sync may still be re-downloading. Check that the fixed plugin build is deployed and active.');
                } else {
                    WP_CLI::log('No image-sync activity in the window (sync may not have run yet).');
                }
            }
        }

        /**
         * Shrink oversized product images in place. No file is renamed, so all
         * existing URLs keep working; only pixels above the maximum are removed.
         *
         * ## OPTIONS
         *
         * [--max-size=<px>]
         * : Maximum dimension in pixels (default 1024).
         *
         * [--dry-run]
         * : Report how many images would change and the estimated savings.
         *
         * [--limit=<n>]
         * : Stop after shrinking this many images (default 0 = all).
         *
         * [--all]
         * : Process every image in the media library, not just product images.
         *   CAUTION: this will also shrink theme/Elementor banners, which are
         *   often legitimately wider than the maximum.
         *
         * [--yes]
         * : Skip the confirmation prompt.
         */
        public function shrink($args, $assoc_args) {
            $cleanup = BrewHQ_Media_Cleanup::instance();
            global $wpdb;

            $max = isset($assoc_args['max-size']) ? max(200, intval($assoc_args['max-size'])) : 1024;
            $dry_run = isset($assoc_args['dry-run']);
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 0;
            $all = isset($assoc_args['all']);

            if ($all) {
                $ids = $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'attachment'
                     AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                     ORDER BY ID ASC"
                );
                $ids = array_map('intval', $ids);
                WP_CLI::log(sprintf('Scope: ALL %s images in the media library (max %dpx)', number_format(count($ids)), $max));
            } else {
                $ids = $cleanup->get_product_image_ids();
                WP_CLI::log(sprintf('Scope: %s product images (max %dpx). Use --all to include non-product images.', number_format(count($ids)), $max));
            }

            if (!$ids) {
                WP_CLI::success('Nothing to process.');
                return;
            }

            if (!$dry_run) {
                WP_CLI::confirm(
                    sprintf('Resize oversized images down to %dpx in place? Originals are not kept — take a backup first.', $max),
                    $assoc_args
                );
            }

            $processed = 0;
            $shrunk = 0;
            $saved = 0;
            $errors = 0;

            foreach ($ids as $id) {
                $result = $cleanup->shrink_attachment($id, $max, $dry_run);
                $processed++;

                if ($result['changed']) {
                    $shrunk++;
                    $saved += $result['saved'];
                }
                if ($result['note'] !== '' && strpos($result['note'], 'unsupported') === false && $result['note'] !== 'file missing') {
                    $errors++;
                    WP_CLI::warning(sprintf('Attachment %d: %s', $id, $result['note']));
                }

                if ($processed % 200 === 0) {
                    WP_CLI::log(sprintf(
                        '%s/%s processed — %s %s, %s %s',
                        number_format($processed),
                        number_format(count($ids)),
                        number_format($shrunk),
                        $dry_run ? 'would shrink' : 'shrunk',
                        size_format($saved),
                        $dry_run ? 'estimated' : 'freed'
                    ));
                    if (function_exists('wp_cache_flush_runtime')) {
                        wp_cache_flush_runtime();
                    }
                }

                if ($limit > 0 && $shrunk >= $limit) {
                    WP_CLI::log('Reached --limit, stopping.');
                    break;
                }
            }

            WP_CLI::success(sprintf(
                '%s. Processed %s images: %s %s, %s %s%s.',
                $dry_run ? 'Dry run complete' : 'Shrink complete',
                number_format($processed),
                number_format($shrunk),
                $dry_run ? 'would be shrunk' : 'shrunk',
                size_format($saved),
                $dry_run ? 'estimated savings' : 'freed',
                $errors ? ', ' . number_format($errors) . ' errors' : ''
            ));
        }
    }

    WP_CLI::add_command('media-cleanup', 'BrewHQ_Media_Cleanup_CLI');
}
