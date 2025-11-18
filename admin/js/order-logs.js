/**
 * Kounta Order Logs Page JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Refresh page
        $('#refresh-logs').on('click', function() {
            location.reload();
        });
        
        // Clear logs
        $('#clear-logs').on('click', function() {
            if (!confirm('Are you sure you want to clear all order logs? This cannot be undone.')) {
                return;
            }
            
            var $button = $(this);
            $button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: kountaOrderLogs.ajax_url,
                type: 'POST',
                data: {
                    action: 'kounta_clear_order_logs',
                    nonce: kountaOrderLogs.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Logs cleared successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).text('🗑️ Clear Logs');
                    }
                },
                error: function() {
                    alert('An error occurred while clearing logs.');
                    $button.prop('disabled', false).text('🗑️ Clear Logs');
                }
            });
        });
        
        // Download log
        $('#download-log').on('click', function() {
            var url = kountaOrderLogs.ajax_url + '?action=kounta_download_order_log&nonce=' + kountaOrderLogs.nonce;
            window.location.href = url;
        });
        
        // Get diagnostic report
        $('.get-diagnostic-report').on('click', function() {
            var orderId = $(this).data('order-id');
            
            $('#diagnostic-modal').show();
            $('#diagnostic-report-content').html('<p>Loading diagnostic report...</p>');
            
            $.ajax({
                url: kountaOrderLogs.ajax_url,
                type: 'POST',
                data: {
                    action: 'kounta_get_diagnostic_report',
                    nonce: kountaOrderLogs.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        $('#diagnostic-report-content').html('<pre>' + escapeHtml(response.data.report) + '</pre>');
                        
                        // Store order ID for download
                        $('#download-diagnostic').data('order-id', orderId);
                        $('#download-diagnostic').data('report', response.data.report);
                    } else {
                        $('#diagnostic-report-content').html('<p class="error">Error: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    $('#diagnostic-report-content').html('<p class="error">An error occurred while loading the diagnostic report.</p>');
                }
            });
        });
        
        // Close modal
        $('.kounta-modal-close, #close-diagnostic').on('click', function() {
            $('#diagnostic-modal').hide();
        });
        
        // Close modal on outside click
        $(window).on('click', function(event) {
            if ($(event.target).is('#diagnostic-modal')) {
                $('#diagnostic-modal').hide();
            }
        });
        
        // Download diagnostic report
        $('#download-diagnostic').on('click', function() {
            var orderId = $(this).data('order-id');
            var report = $(this).data('report');
            
            if (!report) {
                alert('No report data available');
                return;
            }
            
            // Create blob and download
            var blob = new Blob([report], { type: 'text/plain' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'kounta-order-' + orderId + '-diagnostic-' + getTimestamp() + '.txt';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        });
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Helper function to get timestamp
        function getTimestamp() {
            var now = new Date();
            return now.getFullYear() + 
                   pad(now.getMonth() + 1) + 
                   pad(now.getDate()) + '-' + 
                   pad(now.getHours()) + 
                   pad(now.getMinutes()) + 
                   pad(now.getSeconds());
        }
        
        function pad(num) {
            return (num < 10 ? '0' : '') + num;
        }
    });
    
})(jQuery);

