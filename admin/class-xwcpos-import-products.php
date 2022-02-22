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
			<div class="loadbtu">
				<a href="javascript:void(0)" class="button button-primary button-large" onclick="xwcpos_importPros()"><?php echo esc_html__("Load Kounta Products", "xwcpos"); ?></a>
        <a href="javascript:void(0)" class="button button-primary button-large" onclick="xwcpos_syncAllProducts()"><?php echo esc_html__("Sync All Products", "xwcpos"); ?></a>
				<div class="spinner_wrapper"><span class="spinner"></span></div>
			</div>
			<div class="last_load">
				<b><?php echo esc_html__("Last load at: ", "xwcpos"); ?></b>
                <?php date_default_timezone_set('NZ');?>
				<?php echo date('d-m-Y h:i:s a', strtotime(get_option('xwcpos_load_timestamp'))); ?>

				</div>

			<form id="xwcpos-list-table-form" method="post">

			<?php
$import_table = new BrewHQ_Kounta_Import_Table();
            $import_table->prepare_items();
            $import_table->display();
            ?>
			</form>
			<?php
}

    }

    new BrewHQ_Kounta_POS_Import_Products();
}