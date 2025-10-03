jQuery(document).ready(function($) {
    'use strict';

    // Checkout page functionality
    if ($('body').hasClass('woocommerce-checkout')) {
        
        // Update installment display when payment method changes
        $(document).on('change', 'input[name="payment_method"]', function() {
            updateInstallmentDisplay();
        });
        
        // Update installment display when checkout updates
        $(document).on('updated_checkout', function() {
            updateInstallmentDisplay();
        });
        
        function updateInstallmentDisplay() {
            var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
            
            if (selectedPaymentMethod === 'tap_payment') {
                $('.tap-installment-summary').show();
                loadInstallmentSummary();
            } else {
                $('.tap-installment-summary').hide();
            }
        }
        
        function loadInstallmentSummary() {
            var $summary = $('.tap-installment-summary');
            
            if ($summary.length === 0) {
                return;
            }
            
            $summary.html('<div class="loading">Loading installment information...</div>');
            
            $.ajax({
                url: tapFrontendAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'tap_get_checkout_installments',
                    nonce: tapFrontendAjax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $summary.html(response.data.html);
                    } else {
                        $summary.html('<div class="no-installments">No installment products in cart.</div>');
                    }
                },
                error: function() {
                    $summary.html('<div class="error">Failed to load installment information.</div>');
                }
            });
        }
        
        // Initialize on page load
        updateInstallmentDisplay();
    }
    
    // Product page functionality
    if ($('body').hasClass('single-product')) {
        
        // Update installment display when variation changes
        $(document).on('found_variation', function(event, variation) {
            updateProductInstallmentDisplay(variation);
        });
        
        // Clear installment display when variation is reset
        $(document).on('reset_data', function() {
            $('.tap-product-installments').hide();
        });
        
        function updateProductInstallmentDisplay(variation) {
            var $installmentDiv = $('.tap-product-installments');
            
            if (!$installmentDiv.length) {
                return;
            }
            
            if (variation.tap_enable_installment && variation.tap_full_amount && variation.tap_installments) {
                var fullAmount = parseFloat(variation.tap_full_amount);
                var installments = parseInt(variation.tap_installments);
                var currentPrice = parseFloat(variation.display_price);
                
                if (fullAmount > currentPrice && installments >= 2) {
                    var remainingAmount = fullAmount - currentPrice;
                    var installmentAmount = remainingAmount / installments;
                    
                    var html = '<div class="tap-installment-info">';
                    html += '<h4>Installment Plan Available</h4>';
                    html += '<div class="installment-details">';
                    html += '<div class="installment-row">';
                    html += '<span class="label">Pay now:</span>';
                    html += '<span class="amount">$' + currentPrice.toFixed(2) + '</span>';
                    html += '</div>';
                    html += '<div class="installment-row">';
                    html += '<span class="label">Remaining amount:</span>';
                    html += '<span class="amount">$' + remainingAmount.toFixed(2) + '</span>';
                    html += '</div>';
                    html += '<div class="installment-row">';
                    html += '<span class="label">' + installments + ' monthly payments of:</span>';
                    html += '<span class="amount highlight">$' + installmentAmount.toFixed(2) + '</span>';
                    html += '</div>';
                    html += '<div class="installment-row total">';
                    html += '<span class="label">Total amount:</span>';
                    html += '<span class="amount">$' + fullAmount.toFixed(2) + '</span>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                    
                    $installmentDiv.html(html).show();
                } else {
                    $installmentDiv.hide();
                }
            } else {
                $installmentDiv.hide();
            }
        }
        
        // Handle simple products with installments
        if ($('.tap-product-installments').length && !$('.variations_form').length) {
            var installmentData = $('.tap-product-installments').data('installment');
            
            if (installmentData && installmentData.enabled) {
                updateProductInstallmentDisplay({
                    tap_enable_installment: true,
                    tap_full_amount: installmentData.full_amount,
                    tap_installments: installmentData.installments,
                    display_price: installmentData.current_price
                });
            }
        }
    }
    
    // Customer account page functionality
    if ($('body').hasClass('woocommerce-account')) {
        
        // Installment payment button
        $('.tap-pay-installment').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var installmentId = $button.data('installment-id');
            
            if (!installmentId) {
                alert('Invalid installment ID.');
                return;
            }
            
            $button.prop('disabled', true).text('Processing...');
            
            // Redirect to payment page
            window.location.href = tapFrontendAjax.payment_url + '?installment_id=' + installmentId;
        });
        
        // View installment details
        $('.tap-view-installment-details').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var planId = $button.data('plan-id');
            var $details = $('#installment-details-' + planId);
            
            if ($details.is(':visible')) {
                $details.slideUp();
                $button.text('View Details');
            } else {
                $details.slideDown();
                $button.text('Hide Details');
            }
        });
    }
    
    // Utility functions
    function formatCurrency(amount) {
        return '$' + parseFloat(amount).toFixed(2);
    }
    
    function showNotice(message, type) {
        type = type || 'info';
        
        var noticeHtml = '<div class="woocommerce-' + type + '">' + message + '</div>';
        
        $('.woocommerce-notices-wrapper').first().html(noticeHtml);
        
        $('html, body').animate({
            scrollTop: $('.woocommerce-notices-wrapper').first().offset().top - 100
        }, 500);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('.woocommerce-' + type).fadeOut();
        }, 5000);
    }
    
    // Handle AJAX errors globally
    $(document).ajaxError(function(event, xhr, settings, thrownError) {
        if (settings.url.indexOf('tap_') !== -1) {
            console.error('Tap Payment AJAX Error:', thrownError);
        }
    });
});