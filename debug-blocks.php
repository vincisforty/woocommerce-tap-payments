<?php
/**
 * Debug WooCommerce Blocks Integration
 * 
 * Add this to functions.php temporarily or run as a standalone script
 */

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    echo "WooCommerce is not active\n";
    return;
}

// Check if WooCommerce Blocks is available
if (!class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
    echo "WooCommerce Blocks PaymentMethodRegistry is not available\n";
} else {
    echo "WooCommerce Blocks PaymentMethodRegistry is available\n";
}

// Check if our gateway is registered
$gateways = WC()->payment_gateways()->payment_gateways();
if (isset($gateways['tap_payment'])) {
    echo "Tap Payment Gateway is registered\n";
    $gateway = $gateways['tap_payment'];
    echo "Gateway supports: " . implode(', ', $gateway->supports) . "\n";
} else {
    echo "Tap Payment Gateway is NOT registered\n";
}

// Check if blocks support class exists
if (class_exists('Tap_Payment_Blocks_Support')) {
    echo "Tap_Payment_Blocks_Support class exists\n";
} else {
    echo "Tap_Payment_Blocks_Support class does NOT exist\n";
}

// Check if the block script file exists
$script_path = plugin_dir_path(__FILE__) . 'assets/js/blocks/tap-payment-block.js';
if (file_exists($script_path)) {
    echo "Block script file exists at: $script_path\n";
} else {
    echo "Block script file does NOT exist at: $script_path\n";
}

// Check if the asset file exists
$asset_path = plugin_dir_path(__FILE__) . 'assets/js/blocks/tap-payment-block.asset.php';
if (file_exists($asset_path)) {
    echo "Block asset file exists at: $asset_path\n";
} else {
    echo "Block asset file does NOT exist at: $asset_path\n";
}

// Check current hooks
echo "Current action hooks for woocommerce_blocks_loaded:\n";
global $wp_filter;
if (isset($wp_filter['woocommerce_blocks_loaded'])) {
    foreach ($wp_filter['woocommerce_blocks_loaded']->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            if (is_array($callback['function']) && is_object($callback['function'][0])) {
                echo "Priority $priority: " . get_class($callback['function'][0]) . "::" . $callback['function'][1] . "\n";
            }
        }
    }
} else {
    echo "No hooks registered for woocommerce_blocks_loaded\n";
}
?>