// Advanced filtering functionality
jQuery(document).ready(function ($) {
  // Auto-submit form when filter dropdowns change
  $(
    "#filter-category, #filter-import-status, #filter-sync-status, #filter-stock-status"
  ).on("change", function () {
    $("#xwcpos-list-table-form").submit();
  });

  // Debounced search for price filters
  var priceFilterTimeout;
  $('input[name="filter_price_min"], input[name="filter_price_max"]').on(
    "input",
    function () {
      clearTimeout(priceFilterTimeout);
      priceFilterTimeout = setTimeout(function () {
        // Auto-submit is optional for price - user can click Filter button
      }, 500);
    }
  );

  // Add visual feedback for active filters
  function highlightActiveFilters() {
    $(".xwcpos-filter-group select, .xwcpos-filter-group input").each(
      function () {
        var $input = $(this);
        var hasValue = false;

        if ($input.is("select")) {
          hasValue = $input.val() !== "all" && $input.val() !== "";
        } else if ($input.is("input")) {
          hasValue = $input.val() !== "";
        }

        if (hasValue) {
          $input.css({
            "border-color": "#00a32a",
            "background-color": "#f0f9f4",
          });
        } else {
          $input.css({
            "border-color": "",
            "background-color": "",
          });
        }
      }
    );
  }

  highlightActiveFilters();

  // Update highlights when filters change
  $(".xwcpos-filter-group select, .xwcpos-filter-group input").on(
    "change input",
    function () {
      highlightActiveFilters();
    }
  );
});

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

/**
 * Optimized sync function (v2.0)
 * Uses new architecture with rate limiting and batch processing
 */
function xwcpos_syncAllProductsOptimized() {
  "use strict";

  jQuery(".spinner").show();
  jQuery(".success_message").hide();
  jQuery(".errosmessage").hide();

  // Show progress indicator
  jQuery("#xwcpos-sync-progress").show();
  jQuery("#xwcpos-progress-title").text("Starting sync...");
  jQuery("#xwcpos-progress-bar").css("width", "0%");
  jQuery("#xwcpos-progress-percent").text("0%");
  jQuery("#xwcpos-progress-stats").text("");
  jQuery("#xwcpos-progress-phase").text("");
  jQuery("#xwcpos-progress-current").text("");

  var ajaxurl = xwcpos_php_vars.admin_url;
  var startTime = Date.now();
  var progressInterval = null;

  // Start polling for progress
  progressInterval = setInterval(function () {
    xwcpos_updateSyncProgress();
  }, 1000); // Poll every second

  jQuery.ajax({
    type: "POST",
    url: ajaxurl,
    data: { action: "xwcposSyncAllProdsOptimized" },
    timeout: 300000, // 5 minutes timeout
    success: function (response) {
      // Stop polling
      if (progressInterval) {
        clearInterval(progressInterval);
      }

      var obj = {};
      try {
        obj = JSON.parse(response);
      } catch (err) {
        console.log("Not JSON", response);
        jQuery(".spinner").hide();
        jQuery("#xwcpos-sync-progress").hide();
        jQuery(".errosmessage").show();
        jQuery(".errosmessage").html("<p>Error parsing response</p>");
        return;
      }

      jQuery(".spinner").hide();

      // Update progress bar to 100% on completion
      jQuery("#xwcpos-progress-bar").css("width", "100%");
      jQuery("#xwcpos-progress-percent").text("100%");

      if (obj.success) {
        jQuery(".success_message").show();
        jQuery(".errosmessage").hide();

        var duration = ((Date.now() - startTime) / 1000).toFixed(2);
        var message = "<p><strong>✓ Optimized Sync Complete!</strong></p>";

        if (obj.products) {
          message +=
            "<p>Products: " +
            obj.products.updated +
            " updated, " +
            obj.products.skipped +
            " skipped, " +
            obj.products.errors +
            " errors</p>";
        }

        if (obj.inventory) {
          message +=
            "<p>Inventory: " + obj.inventory.updated + " items updated</p>";
        }

        message += "<p>Duration: " + duration + " seconds</p>";

        jQuery(".success_message").html(message);

        // Hide progress bar after 2 seconds
        setTimeout(function () {
          jQuery("#xwcpos-sync-progress").fadeOut(300);
        }, 2000);
      } else {
        jQuery(".success_message").hide();
        jQuery(".errosmessage").show();
        jQuery("#xwcpos-sync-progress").hide();
        var errorMsg = "<p>Error: " + (obj.error || "Unknown error") + "</p>";
        if (obj.locked_by) {
          errorMsg +=
            "<p><small>Lock info: " +
            JSON.stringify(obj.locked_by) +
            "</small></p>";
        }
        errorMsg +=
          '<p style="margin-top:10px;"><a href="javascript:void(0)" onclick="xwcpos_showDebugLog()" class="button">📋 View Debug Log</a></p>';
        jQuery(".errosmessage").html(errorMsg);
      }
    },
    error: function (xhr, status, error) {
      // Stop polling
      if (progressInterval) {
        clearInterval(progressInterval);
      }

      jQuery(".spinner").hide();
      jQuery("#xwcpos-sync-progress").hide();
      jQuery(".success_message").hide();
      jQuery(".errosmessage").show();
      var errorMsg = "<p>AJAX Error: " + error + "</p>";
      errorMsg +=
        '<p style="margin-top:10px;"><a href="javascript:void(0)" onclick="xwcpos_showDebugLog()" class="button">📋 View Debug Log</a></p>';
      jQuery(".errosmessage").html(errorMsg);
    },
  });
}

/**
 * Update sync progress by polling the server
 */
function xwcpos_updateSyncProgress() {
  "use strict";

  var ajaxurl = xwcpos_php_vars.admin_url;

  jQuery.ajax({
    type: "POST",
    url: ajaxurl,
    data: { action: "xwcposGetSyncProgress" },
    timeout: 5000,
    success: function (response) {
      try {
        var progress = JSON.parse(response);

        if (progress && progress.active) {
          // Update progress bar
          var percent = progress.percent || 0;
          jQuery("#xwcpos-progress-bar").css("width", percent + "%");
          jQuery("#xwcpos-progress-percent").text(percent + "%");

          // Update title
          if (progress.phase) {
            jQuery("#xwcpos-progress-title").text(progress.phase);
          }

          // Update stats
          if (progress.current && progress.total) {
            jQuery("#xwcpos-progress-stats").text(
              progress.current + " / " + progress.total
            );
          }

          // Update details
          if (progress.current_item) {
            jQuery("#xwcpos-progress-current").text(
              "Processing: " + progress.current_item
            );
          }

          if (progress.batch_info) {
            jQuery("#xwcpos-progress-phase").text(progress.batch_info);
          }
        }
      } catch (err) {
        // Silently fail - progress polling is non-critical
        console.log("Progress update error:", err);
      }
    },
    error: function () {
      // Silently fail - progress polling is non-critical
    },
  });
}

// Debug log viewer functions
function xwcpos_showDebugLog() {
  jQuery("#xwcpos-debug-modal").fadeIn(200);
  xwcpos_refreshDebugLog();
}

function xwcpos_closeDebugLog() {
  jQuery("#xwcpos-debug-modal").fadeOut(200);
}

function xwcpos_refreshDebugLog() {
  jQuery("#xwcpos-debug-log-content").html(
    '<p style="text-align:center; color:#666;">Loading...</p>'
  );

  var ajaxurl = xwcpos_php_vars.admin_url;

  jQuery.ajax({
    type: "POST",
    url: ajaxurl,
    data: {
      action: "xwcposGetDebugLog",
      lines: 200, // Get last 200 lines
    },
    success: function (response) {
      var data = JSON.parse(response);

      if (data.success) {
        var logHtml = "";
        if (data.lines && data.lines.length > 0) {
          data.lines.forEach(function (line) {
            // Color code different log levels
            var coloredLine = line;
            if (line.includes("ERROR")) {
              coloredLine = '<span style="color:#d63638;">' + line + "</span>";
            } else if (line.includes("WARNING")) {
              coloredLine = '<span style="color:#dba617;">' + line + "</span>";
            } else if (
              line.includes("[API Client]") ||
              line.includes("[Optimized Sync]")
            ) {
              coloredLine = '<span style="color:#2271b1;">' + line + "</span>";
            }
            logHtml += coloredLine + "\n";
          });
        } else {
          logHtml =
            '<p style="text-align:center; color:#666;">No log entries found</p>';
        }

        jQuery("#xwcpos-debug-log-content").html(logHtml);

        // Update info
        var fileSize = (data.file_size / 1024).toFixed(2);
        var info =
          "Total lines: " +
          data.total_lines +
          " | File size: " +
          fileSize +
          " KB | Path: " +
          data.file_path;
        jQuery("#xwcpos-log-info").html(info);

        // Auto-scroll to bottom
        var logContent = document.getElementById("xwcpos-debug-log-content");
        logContent.scrollTop = logContent.scrollHeight;
      } else {
        jQuery("#xwcpos-debug-log-content").html(
          '<p style="color:#d63638;">Error: ' +
            (data.error || "Failed to load log") +
            "</p>"
        );
      }
    },
    error: function (xhr, status, error) {
      jQuery("#xwcpos-debug-log-content").html(
        '<p style="color:#d63638;">Failed to load debug log: ' + error + "</p>"
      );
    },
  });
}

// Cleanup empty products function
function xwcpos_cleanupEmptyProducts() {
  if (
    !confirm(
      "This will permanently delete all products with empty names from the database. Are you sure?"
    )
  ) {
    return;
  }

  jQuery(".spinner").show();
  jQuery(".success_message").hide();
  jQuery(".errosmessage").hide();

  var ajaxurl = xwcpos_php_vars.admin_url;

  jQuery.ajax({
    type: "POST",
    url: ajaxurl,
    data: { action: "xwcposCleanupEmptyProducts" },
    success: function (response) {
      jQuery(".spinner").hide();

      try {
        var data = JSON.parse(response);

        if (data.success) {
          jQuery(".success_message").show();
          jQuery(".success_message").html(
            "<p>✅ " +
              data.message +
              "</p><p>Refresh the page to see updated list.</p>"
          );

          // Refresh the page after 2 seconds
          setTimeout(function () {
            location.reload();
          }, 2000);
        } else {
          jQuery(".errosmessage").show();
          jQuery(".errosmessage").html(
            "<p>❌ Error: " + (data.error || "Unknown error") + "</p>"
          );
        }
      } catch (err) {
        jQuery(".errosmessage").show();
        jQuery(".errosmessage").html("<p>❌ Error parsing response</p>");
      }
    },
    error: function (xhr, status, error) {
      jQuery(".spinner").hide();
      jQuery(".errosmessage").show();
      jQuery(".errosmessage").html("<p>❌ AJAX Error: " + error + "</p>");
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
