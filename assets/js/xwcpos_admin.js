function xwcpos_importCats() {
	"use strict";

	jQuery(".spinner").show();
	var ajaxurl = xwcpos_php_vars.admin_url;
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: { action: "xwcposImpCats" },
		success: function (response) {
			//alert("Output: " + response);
			var obj = {};
			//jQuery(".output").html("<pre>" + response + "</pre>");
			try {
				obj = JSON.parse(response);
			} catch (err) {
				console.log("Not JSON");
			}

			//jQuery(".output").html("<pre>" + obj + "</pre>");
			jQuery(".spinner").hide();
			if (obj) {
				if (obj.cat_count) {
					jQuery(".errosmessage").hide();
					jQuery(".success_message").show();
					jQuery(".success_message").html("<p>" + obj.cat_count + "</p>");
				} else if (obj.err) {
					jQuery(".success_message").hide();
					jQuery(".errosmessage").show();
					jQuery(".errosmessage").html("<p>" + obj.err + "</p>");
				}
			}
		},
	});
}

function xwcpos_importPros() {
	"use strict";

	jQuery(".spinner").show();
	var ajaxurl = xwcpos_php_vars.admin_url;
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: { action: "xwcposImpProds" },
		success: function (response) {
			var obj = {};
			//jQuery(".output").html("<pre>" + response + "</pre>");
			try {
				obj = JSON.parse(response);
			} catch (err) {
				console.log("Not JSON");
			}
			jQuery(".spinner").hide();
			if (obj) {
				if (obj.cat_count) {
					jQuery(".errosmessage").hide();
					jQuery(".success_message").show();
					jQuery(".success_message").html("<p>" + obj.cat_count + "</p>");
				} else if (obj.err) {
					jQuery(".success_message").hide();
					jQuery(".errosmessage").show();
					jQuery(".errosmessage").html("<p>" + obj.err + "</p>");
				} else if (obj.count) {
					jQuery(".success_message").show();
					jQuery(".errosmessage").hide();
					jQuery(".success_message").html("<p>" + obj.count + "</p>");
				}
			}
		},
	});
}

function xwcpos_syncAllProducts() {
	"use strict";

	jQuery(".spinner").show();
	var ajaxurl = xwcpos_php_vars.admin_url;
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: { action: "xwcposSyncAllProds" },
		success: function (response) {
			var obj = {};
			//jQuery(".output").html("<pre>" + response + "</pre>");
			try {
				obj = JSON.parse(response);
			} catch (err) {
				console.log("Not JSON");
			}
			jQuery(".spinner").hide();
			if (obj) {
				if (obj.cat_count) {
					jQuery(".errosmessage").hide();
					jQuery(".success_message").show();
					jQuery(".success_message").html("<p>" + obj.cat_count + "</p>");
				} else if (obj.err) {
					jQuery(".success_message").hide();
					jQuery(".errosmessage").show();
					jQuery(".errosmessage").html("<p>" + obj.err + "</p>");
				} else if (obj.count) {
					jQuery(".success_message").show();
					jQuery(".errosmessage").hide();
					jQuery(".success_message").html("<p>" + obj.count + "</p>");
				}
			}
		},
	});
}

function syncWithLS(id) {
	"use strict";

	jQuery(".spinner").show();
	var ajaxurl = xwcpos_php_vars.admin_url;
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: { action: "xwcposSyncProds", product_id: id },
		success: function (response) {
			var obj = {};
			//jQuery(".output").html("<pre>" + response + "</pre>");
			try {
				obj = JSON.parse(response);
			} catch (err) {
				console.log("Not JSON");
			}
			jQuery(".spinner").hide();

			if (obj.err) {
				jQuery(".success_message").hide();
				jQuery(".errosmessage").show();
				jQuery(".errosmessage").html("<p>" + obj.err + "</p>");
			} else if (obj.succ) {
				jQuery(".success_message").show();
				jQuery(".errosmessage").hide();
				jQuery(".success_message").html("<p>" + obj.succ + "</p>");
			}
		},
	});
}

function syncOrderWithKounta(id) {
	"use strict";
	// alert("pressed "+ id);
	jQuery(".spinner").show();
	var ajaxurl = xwcpos_php_vars.admin_url;
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: { action: "xwcposSyncOrder", order_id: id },
		success: function (response) {
			var obj = {};
			// alert(response);
			try {
				obj = JSON.parse(response);
			} catch (err) {
				console.log("Not JSON");
			}
			jQuery(".spinner").hide();

			if (obj.error) {
				jQuery(".success_message").hide();
				jQuery(".error_message").show();
				jQuery(".error_message").html("<p>" + obj.error + "</p>");
			} else if (obj.success) {
				jQuery(".success_message").show();
				jQuery(".error_message").hide();
				jQuery(".success_message").html("<p>" + obj.message + "</p>");
			}
		},
	});
}
