<?php
if (!defined('WPINC')) {
    die;
}

if (!class_exists('BrewHQ_Kounta_POS_Import_Products')) {

    class BrewHQ_Kounta_POS_Import_Products extends BrewHQ_Kounta_POS_Int
    {

        public function __construct()
        {
            $this->xwcpos_Load_Kounta_Products();
        }

        public function xwcpos_Load_Kounta_Products()
        {
            ?>
			<h1 class="import_ls_cats"><?php echo esc_html__("Load & Import Kounta Products", "xwcpos"); ?></h1>
			<div class="output"></div>
      <div class="errosmessage error"></div>
			<div class="success_message updated"></div>

			<!-- Progress Indicator -->
			<div id="xwcpos-sync-progress" style="display: none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="margin-bottom: 15px;">
					<strong id="xwcpos-progress-title" style="font-size: 14px; color: #1d2327;">Syncing...</strong>
					<span id="xwcpos-progress-stats" style="float: right; font-size: 13px; color: #646970;"></span>
				</div>
				<div style="background: #f0f0f1; border-radius: 4px; height: 24px; overflow: hidden; position: relative;">
					<div id="xwcpos-progress-bar" style="background: linear-gradient(90deg, #00a32a 0%, #00ba37 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
						<span id="xwcpos-progress-percent" style="color: #fff; font-weight: 600; font-size: 12px; text-shadow: 0 1px 2px rgba(0,0,0,0.2);"></span>
					</div>
				</div>
				<div id="xwcpos-progress-details" style="margin-top: 10px; font-size: 12px; color: #646970;">
					<div id="xwcpos-progress-phase" style="margin-bottom: 5px;"></div>
					<div id="xwcpos-progress-current"></div>
				</div>
			</div>

			<div class="loadbtu">
				<a href="javascript:void(0)" class="button button-primary button-large" onclick="xwcpos_importPros()"><?php echo esc_html__("Load Kounta Products", "xwcpos"); ?></a>
        <a href="javascript:void(0)" class="button button-primary button-large" onclick="xwcpos_syncAllProducts()"><?php echo esc_html__("Sync All Products", "xwcpos"); ?></a>
        <a href="javascript:void(0)" class="button button-primary button-large" onclick="xwcpos_syncAllProductsOptimized()" style="background-color: #00a32a; border-color: #00a32a;"><?php echo esc_html__("⚡ Optimized Sync (Fast)", "xwcpos"); ?></a>
        <a href="javascript:void(0)" class="button button-large" onclick="xwcpos_showDebugLog()" style="margin-left: 20px;"><?php echo esc_html__("📋 View Debug Log", "xwcpos"); ?></a>
        <a href="javascript:void(0)" class="button button-large" onclick="xwcpos_cleanupEmptyProducts()" style="margin-left: 10px; color: #d63638;"><?php echo esc_html__("🗑️ Cleanup Empty Products", "xwcpos"); ?></a>
				<div class="spinner_wrapper"><span class="spinner"></span></div>
			</div>

			<!-- Debug Log Modal -->
			<div id="xwcpos-debug-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999;">
				<div style="position:relative; width:90%; max-width:1200px; height:80%; margin:5% auto; background:#fff; border-radius:8px; padding:20px; overflow:hidden;">
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:2px solid #0073aa; padding-bottom:10px;">
						<h2 style="margin:0;">🔍 Debug Log</h2>
						<div>
							<button onclick="xwcpos_refreshDebugLog()" class="button">🔄 Refresh</button>
							<button onclick="xwcpos_closeDebugLog()" class="button">✖ Close</button>
						</div>
					</div>
					<div id="xwcpos-debug-log-content" style="height:calc(100% - 80px); overflow-y:auto; background:#f5f5f5; padding:15px; font-family:monospace; font-size:12px; line-height:1.6; border:1px solid #ddd; border-radius:4px; white-space:pre-wrap;">
						<p style="text-align:center; color:#666;">Loading...</p>
					</div>
					<div style="margin-top:10px; padding-top:10px; border-top:1px solid #ddd; font-size:11px; color:#666;">
						<span id="xwcpos-log-info"></span>
					</div>
				</div>
			</div>
			<div class="last_load">
				<b><?php echo esc_html__("Last load at: ", "xwcpos"); ?></b>
                <?php date_default_timezone_set('NZ');?>
				<?php echo date('d-m-Y h:i:s a', strtotime(get_option('xwcpos_load_timestamp'))); ?>

				</div>

			<form id="xwcpos-list-table-form" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
				<?php
$import_table = new BrewHQ_Kounta_Import_Table();
            $import_table->prepare_items();
            $import_table->search_box(esc_html__('Search Products', 'xwcpos'), 'search_id');
            $import_table->display_filter_summary();
            $import_table->display();
            ?>
			</form>
			<?php
}

    }

    new BrewHQ_Kounta_POS_Import_Products();
}