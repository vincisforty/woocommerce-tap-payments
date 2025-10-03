<?php
/**
 * Test WooCommerce Blocks Integration
 * 
 * This script can be run via WP-CLI or included in a test page
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    // If running via WP-CLI or direct access, load WordPress
    require_once('../../../wp-load.php');
}

echo "=== Tap Payment WooCommerce Blocks Integration Test ===\n\n";

// 1. Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    echo "❌ WooCommerce is not active\n";
    exit;
}
echo "✅ WooCommerce is active\n";

// 2. Check if WooCommerce Blocks is available
if (!class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
    echo "❌ WooCommerce Blocks PaymentMethodRegistry is not available\n";
    echo "   This might mean WooCommerce Blocks plugin is not installed or active\n";
} else {
    echo "✅ WooCommerce Blocks PaymentMethodRegistry is available\n";
}

// 3. Check if our gateway is registered
$gateways = WC()->payment_gateways()->payment_gateways();
if (isset($gateways['tap_payment'])) {
    echo "✅ Tap Payment Gateway is registered\n";
    $gateway = $gateways['tap_payment'];
    echo "   Gateway supports: " . implode(', ', $gateway->supports) . "\n";
    
    // Check if blocks is in supports
    if (in_array('blocks', $gateway->supports)) {
        echo "✅ Gateway declares blocks support\n";
    } else {
        echo "❌ Gateway does NOT declare blocks support\n";
    }
} else {
    echo "❌ Tap Payment Gateway is NOT registered\n";
}

// 4. Check if blocks support class exists
if (class_exists('Tap_Payment_Blocks_Support')) {
    echo "✅ Tap_Payment_Blocks_Support class exists\n";
} else {
    echo "❌ Tap_Payment_Blocks_Support class does NOT exist\n";
}

// 5. Check if the block script files exist
$script_path = plugin_dir_path(__FILE__) . 'assets/js/blocks/tap-payment-block.js';
if (file_exists($script_path)) {
    echo "✅ Block script file exists\n";
} else {
    echo "❌ Block script file does NOT exist at: $script_path\n";
}

$asset_path = plugin_dir_path(__FILE__) . 'assets/js/blocks/tap-payment-block.asset.php';
if (file_exists($asset_path)) {
    echo "✅ Block asset file exists\n";
} else {
    echo "❌ Block asset file does NOT exist at: $asset_path\n";
}

// 6. Check if hooks are registered
global $wp_filter;
if (isset($wp_filter['woocommerce_blocks_loaded'])) {
    echo "✅ woocommerce_blocks_loaded hooks are registered\n";
    $found_tap_hook = false;
    foreach ($wp_filter['woocommerce_blocks_loaded']->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            if (is_array($callback['function']) && is_object($callback['function'][0])) {
                $class_name = get_class($callback['function'][0]);
                if (strpos($class_name, 'Tap_Payment') !== false) {
                    echo "   Found Tap Payment hook: $class_name::" . $callback['function'][1] . " (priority: $priority)\n";
                    $found_tap_hook = true;
                }
            }
        }
    }
    if (!$found_tap_hook) {
        echo "   ❌ No Tap Payment hooks found in woocommerce_blocks_loaded\n";
    }
} else {
    echo "❌ No hooks registered for woocommerce_blocks_loaded\n";
}

// 7. Check if payment method type registration hooks exist
if (isset($wp_filter['woocommerce_blocks_payment_method_type_registration'])) {
    echo "✅ woocommerce_blocks_payment_method_type_registration hooks are registered\n";
} else {
    echo "❌ No hooks registered for woocommerce_blocks_payment_method_type_registration\n";
}

// 8. Test if we can create an instance of the blocks support class
try {
    $blocks_support = new Tap_Payment_Blocks_Support();
    echo "✅ Can create Tap_Payment_Blocks_Support instance\n";
    
    // Test the methods
    $script_handles = $blocks_support->get_payment_method_script_handles();
    echo "   Script handles: " . implode(', ', $script_handles) . "\n";
    
    $payment_data = $blocks_support->get_payment_method_data();
    echo "   Payment method data keys: " . implode(', ', array_keys($payment_data)) . "\n";
    
} catch (Exception $e) {
    echo "❌ Cannot create Tap_Payment_Blocks_Support instance: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>