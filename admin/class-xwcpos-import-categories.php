<?php
if (!defined('WPINC')) {
    die;
}

if (!class_exists('BrewHQ_Kounta_POS_Import_Categories')) {

    class BrewHQ_Kounta_POS_Import_Categories extends BrewHQ_Kounta_POS_Int
    {

        public function __construct()
        {

            $this->xwcpos_Import_Kounta_Categories();
        }

        public function xwcpos_Import_Kounta_Categories()
        {

            ?>
			<h1 class="import_ls_cats"><?php echo esc_html__("Import Kounta Categories", "xwcpos"); ?></h1>
			<div class="output"></div>
			<div class="errosmessage error"></div>
			<div class="success_message updated"></div>
			<a href="javascript:void(0)" class="lsclass button button-primary button-large" onclick="xwcpos_importCats()"><?php echo esc_html__("Import Kounta Categories", "xwcpos"); ?></a>
			<span class="spinner"></span>
			<?php
}

    }

    new BrewHQ_Kounta_POS_Import_Categories();
}