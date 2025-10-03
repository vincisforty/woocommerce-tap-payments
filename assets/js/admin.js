jQuery(document).ready(function($) {
    'use strict';

    // Product installment fields toggle
    function toggleInstallmentFields() {
        var enableInstallment = $('#_tap_enable_installment').is(':checked');
        var $installmentFields = $('.tap-installment-fields');
        
        if (enableInstallment) {
            $installmentFields.show();
        } else {
            $installmentFields.hide();
        }
    }

    // Variable product installment fields toggle
    function toggleVariationInstallmentFields(variationId) {
        var enableInstallment = $('#variable_tap_enable_installment' + variationId).is(':checked');
        var $installmentFields = $('.tap-variation-installment-fields-' + variationId);
        
        if (enableInstallment) {
            $installmentFields.show();
        } else {
            $installmentFields.hide();
        }
    }

    // Initialize on page load
    toggleInstallmentFields();

    // Handle simple product installment toggle
    $(document).on('change', '#_tap_enable_installment', function() {
        toggleInstallmentFields();
    });

    // Handle variable product installment toggle
    $(document).on('change', '[id^="variable_tap_enable_installment"]', function() {
        var variationId = $(this).attr('id').replace('variable_tap_enable_installment', '');
        toggleVariationInstallmentFields(variationId);
    });

    // Initialize variation fields when variations are loaded
    $(document).on('woocommerce_variations_loaded', function() {
        $('[id^="variable_tap_enable_installment"]').each(function() {
            var variationId = $(this).attr('id').replace('variable_tap_enable_installment', '');
            toggleVariationInstallmentFields(variationId);
        });
    });

    // Validate installment settings
    function validateInstallmentSettings() {
        var errors = [];
        
        // Simple product validation
        if ($('#_tap_enable_installment').is(':checked')) {
            var fullAmount = parseFloat($('#_tap_full_amount').val()) || 0;
            var installments = parseInt($('#_tap_installments').val()) || 0;
            var regularPrice = parseFloat($('#_regular_price').val()) || 0;
            var salePrice = parseFloat($('#_sale_price').val()) || 0;
            var currentPrice = salePrice > 0 ? salePrice : regularPrice;
            
            if (fullAmount <= currentPrice) {
                errors.push('Full amount must be greater than the product price for installments to work.');
            }
            
            if (installments < 2) {
                errors.push('Number of installments must be at least 2.');
            }
            
            if (installments > 12) {
                errors.push('Number of installments cannot exceed 12.');
            }
        }
        
        // Variable product validation
        $('[id^="variable_tap_enable_installment"]:checked').each(function() {
            var variationId = $(this).attr('id').replace('variable_tap_enable_installment', '');
            var fullAmount = parseFloat($('#variable_tap_full_amount' + variationId).val()) || 0;
            var installments = parseInt($('#variable_tap_installments' + variationId).val()) || 0;
            var regularPrice = parseFloat($('#variable_regular_price' + variationId).val()) || 0;
            var salePrice = parseFloat($('#variable_sale_price' + variationId).val()) || 0;
            var currentPrice = salePrice > 0 ? salePrice : regularPrice;
            
            if (fullAmount <= currentPrice) {
                errors.push('Full amount must be greater than the product price for variation ' + variationId + '.');
            }
            
            if (installments < 2) {
                errors.push('Number of installments must be at least 2 for variation ' + variationId + '.');
            }
            
            if (installments > 12) {
                errors.push('Number of installments cannot exceed 12 for variation ' + variationId + '.');
            }
        });
        
        if (errors.length > 0) {
            alert('Installment Settings Errors:\n\n' + errors.join('\n'));
            return false;
        }
        
        return true;
    }

    // Validate on form submit
    $(document).on('submit', '#post', function(e) {
        if (!validateInstallmentSettings()) {
            e.preventDefault();
            return false;
        }
    });

    // Calculate installment preview
    function calculateInstallmentPreview(container) {
        var $container = $(container);
        var fullAmount = parseFloat($container.find('[id*="full_amount"]').val()) || 0;
        var installments = parseInt($container.find('[id*="installments"]').val()) || 0;
        var regularPrice = parseFloat($container.find('[id*="regular_price"]').val()) || 0;
        var salePrice = parseFloat($container.find('[id*="sale_price"]').val()) || 0;
        var currentPrice = salePrice > 0 ? salePrice : regularPrice;
        
        var $preview = $container.find('.tap-installment-preview');
        
        if (fullAmount > currentPrice && installments >= 2) {
            var remainingAmount = fullAmount - currentPrice;
            var installmentAmount = remainingAmount / installments;
            
            var previewHtml = '<div class="tap-preview-content">';
            previewHtml += '<h4>Installment Preview:</h4>';
            previewHtml += '<p><strong>Initial Payment:</strong> $' + currentPrice.toFixed(2) + '</p>';
            previewHtml += '<p><strong>Remaining Amount:</strong> $' + remainingAmount.toFixed(2) + '</p>';
            previewHtml += '<p><strong>Per Installment:</strong> $' + installmentAmount.toFixed(2) + '</p>';
            previewHtml += '<p><strong>Total Installments:</strong> ' + installments + '</p>';
            previewHtml += '</div>';
            
            $preview.html(previewHtml).show();
        } else {
            $preview.hide();
        }
    }

    // Update preview on field changes
    $(document).on('input change', '[id*="full_amount"], [id*="installments"], [id*="regular_price"], [id*="sale_price"]', function() {
        var $container = $(this).closest('.options_group, .woocommerce_variation');
        calculateInstallmentPreview($container);
    });

    // Admin settings page functionality
    if ($('.tap-payment-admin-page').length > 0) {
        // Test API connection
        $('#tap-test-connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $status = $('#tap-connection-status');
            var apiKey = $('#tap_test_api_key').val();
            var merchantId = $('#tap_merchant_id').val();
            
            if (!apiKey || !merchantId) {
                $status.html('<span class="error">Please enter API key and Merchant ID first.</span>');
                return;
            }
            
            $button.prop('disabled', true).text('Testing...');
            $status.html('<span class="testing">Testing connection...</span>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tap_test_connection',
                    api_key: apiKey,
                    merchant_id: merchantId,
                    nonce: tapAdminAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span class="success">✓ Connection successful!</span>');
                    } else {
                        $status.html('<span class="error">✗ Connection failed: ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $status.html('<span class="error">✗ Connection test failed. Please try again.</span>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });
        
        // Toggle live/test mode fields
        function toggleApiFields() {
            var isLive = $('#tap_live_mode').is(':checked');
            
            if (isLive) {
                $('.tap-test-fields').hide();
                $('.tap-live-fields').show();
            } else {
                $('.tap-test-fields').show();
                $('.tap-live-fields').hide();
            }
        }
        
        toggleApiFields();
        $('#tap_live_mode').on('change', toggleApiFields);
    }

    // Order details page - installment management
    if ($('.tap-order-installments').length > 0) {
        // Send installment invoice manually
        $('.tap-send-invoice').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var installmentId = $button.data('installment-id');
            
            if (!confirm('Are you sure you want to send this installment invoice?')) {
                return;
            }
            
            $button.prop('disabled', true).text('Sending...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tap_send_installment_invoice',
                    installment_id: installmentId,
                    nonce: tapAdminAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Invoice sent successfully!');
                        location.reload();
                    } else {
                        alert('Failed to send invoice: ' + response.data);
                    }
                },
                error: function() {
                    alert('Failed to send invoice. Please try again.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Send Invoice');
                }
            });
        });
        
        // Mark installment as paid manually
        $('.tap-mark-paid').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var installmentId = $button.data('installment-id');
            
            if (!confirm('Are you sure you want to mark this installment as paid?')) {
                return;
            }
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'tap_mark_installment_paid',
                    installment_id: installmentId,
                    nonce: tapAdminAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Installment marked as paid!');
                        location.reload();
                    } else {
                        alert('Failed to mark as paid: ' + response.data);
                    }
                },
                error: function() {
                    alert('Failed to process request. Please try again.');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Mark as Paid');
                }
            });
        });
    }
});