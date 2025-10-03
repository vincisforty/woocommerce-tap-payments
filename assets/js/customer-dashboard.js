/**
 * Tap Payment Customer Dashboard JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    // Toggle plan details
    $('.tap-toggle-details').on('click', function() {
        var planId = $(this).data('plan-id');
        var detailsDiv = $('#plan-details-' + planId);
        
        if (detailsDiv.is(':visible')) {
            detailsDiv.slideUp();
            $(this).text(tap_customer_ajax.messages.view_details || 'View Details');
        } else {
            detailsDiv.slideDown();
            $(this).text(tap_customer_ajax.messages.hide_details || 'Hide Details');
        }
    });

    // Handle installment payment
    $('.tap-pay-installment, .tap-pay-now').on('click', function() {
        var installmentId = $(this).data('installment-id');
        var button = $(this);
        
        if (!confirm(tap_customer_ajax.messages.confirm_payment)) {
            return;
        }

        // Disable button and show loading
        button.prop('disabled', true);
        var originalText = button.text();
        button.text(tap_customer_ajax.messages.processing);

        $.ajax({
            url: tap_customer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'tap_pay_installment',
                installment_id: installmentId,
                nonce: tap_customer_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        location.reload();
                    }
                } else {
                    alert(response.data.message || tap_customer_ajax.messages.error);
                    button.prop('disabled', false);
                    button.text(originalText);
                }
            },
            error: function() {
                alert(tap_customer_ajax.messages.error);
                button.prop('disabled', false);
                button.text(originalText);
            }
        });
    });

    // Auto-refresh overdue status
    function checkOverdueInstallments() {
        $('.tap-installment-row').each(function() {
            var row = $(this);
            var dueDate = row.find('td:nth-child(3)').text();
            var status = row.find('.tap-installment-status').text().toLowerCase();
            
            if (status === 'pending' && isOverdue(dueDate)) {
                row.addClass('tap-overdue');
                row.find('.tap-installment-status').addClass('tap-overdue');
            }
        });
    }

    // Check if date is overdue
    function isOverdue(dateString) {
        var dueDate = new Date(dateString);
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        
        return dueDate < today;
    }

    // Initialize overdue check
    checkOverdueInstallments();

    // Refresh every 5 minutes
    setInterval(checkOverdueInstallments, 300000);

    // Handle plan status updates
    function updatePlanStatus(planId, status) {
        var planDiv = $('.tap-installment-plan[data-plan-id="' + planId + '"]');
        var statusSpan = planDiv.find('.tap-plan-status');
        
        statusSpan.removeClass('tap-status-active tap-status-completed tap-status-cancelled');
        statusSpan.addClass('tap-status-' + status);
        statusSpan.text(status.charAt(0).toUpperCase() + status.slice(1));
    }

    // Handle installment status updates
    function updateInstallmentStatus(installmentId, status) {
        var row = $('.tap-installment-row').find('[data-installment-id="' + installmentId + '"]').closest('tr');
        var statusCell = row.find('.tap-installment-status');
        var actionCell = row.find('td:last-child');
        
        statusCell.removeClass('tap-status-pending tap-status-paid tap-status-failed');
        statusCell.addClass('tap-status-' + status);
        statusCell.text(status.charAt(0).toUpperCase() + status.slice(1));
        
        if (status === 'paid') {
            actionCell.html('<span class="tap-paid-date">' + new Date().toLocaleDateString() + '</span>');
            row.removeClass('tap-status-pending').addClass('tap-status-paid');
        }
    }

    // Progress bar animation
    function animateProgressBars() {
        $('.tap-progress-fill').each(function() {
            var width = $(this).css('width');
            $(this).css('width', '0%').animate({
                width: width
            }, 1000);
        });
    }

    // Initialize progress bar animation
    animateProgressBars();

    // Handle payment success callback
    if (window.location.hash === '#payment-success') {
        setTimeout(function() {
            location.reload();
        }, 2000);
    }

    // Responsive table handling
    function makeTablesResponsive() {
        $('.tap-installments-table table').each(function() {
            if (!$(this).parent().hasClass('table-responsive')) {
                $(this).wrap('<div class="table-responsive"></div>');
            }
        });
    }

    makeTablesResponsive();

    // Handle window resize
    $(window).on('resize', function() {
        makeTablesResponsive();
    });

    // Tooltip functionality for status indicators
    $('.tap-installment-status, .tap-plan-status').each(function() {
        var status = $(this).text().toLowerCase();
        var tooltip = '';
        
        switch(status) {
            case 'pending':
                tooltip = 'Payment is due';
                break;
            case 'paid':
                tooltip = 'Payment completed successfully';
                break;
            case 'failed':
                tooltip = 'Payment failed or was declined';
                break;
            case 'overdue':
                tooltip = 'Payment is past due date';
                break;
            case 'active':
                tooltip = 'Installment plan is active';
                break;
            case 'completed':
                tooltip = 'All installments have been paid';
                break;
            case 'cancelled':
                tooltip = 'Installment plan was cancelled';
                break;
        }
        
        if (tooltip) {
            $(this).attr('title', tooltip);
        }
    });

    // Print functionality
    $('.tap-print-plan').on('click', function() {
        var planId = $(this).data('plan-id');
        var planContent = $('.tap-installment-plan[data-plan-id="' + planId + '"]').clone();
        
        var printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Installment Plan</title>');
        printWindow.document.write('<style>body{font-family:Arial,sans-serif;margin:20px;}</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(planContent.html());
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.print();
    });

    // Export functionality
    $('.tap-export-plan').on('click', function() {
        var planId = $(this).data('plan-id');
        
        $.ajax({
            url: tap_customer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'tap_export_installment_plan',
                plan_id: planId,
                nonce: tap_customer_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var blob = new Blob([response.data.csv], { type: 'text/csv' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'installment-plan-' + planId + '.csv';
                    a.click();
                    window.URL.revokeObjectURL(url);
                } else {
                    alert(response.data.message || tap_customer_ajax.messages.error);
                }
            },
            error: function() {
                alert(tap_customer_ajax.messages.error);
            }
        });
    });
});