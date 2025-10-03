<?php
/**
 * HPOS Compatibility Test
 * 
 * This file contains tests to verify that the Tap Payment Gateway
 * is compatible with WooCommerce High-Performance Order Storage (HPOS).
 * 
 * To run these tests, activate the plugin and visit:
 * /wp-admin/admin.php?page=tap-payment-hpos-test
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_HPOS_Test {

    /**
     * Initialize the test
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_test_page'));
    }

    /**
     * Add test page to admin menu
     */
    public static function add_test_page() {
        add_submenu_page(
            'woocommerce',
            __('Tap Payment HPOS Test', 'tap-payment'),
            __('HPOS Test', 'tap-payment'),
            'manage_woocommerce',
            'tap-payment-hpos-test',
            array(__CLASS__, 'test_page')
        );
    }

    /**
     * Test page content
     */
    public static function test_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tap Payment HPOS Compatibility Test', 'tap-payment') . '</h1>';
        
        // Check if HPOS is enabled
        $hpos_enabled = self::is_hpos_enabled();
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>' . esc_html__('HPOS Status:', 'tap-payment') . '</strong> ';
        if ($hpos_enabled) {
            echo '<span style="color: green;">' . esc_html__('Enabled', 'tap-payment') . '</span>';
        } else {
            echo '<span style="color: orange;">' . esc_html__('Disabled (using traditional post-based orders)', 'tap-payment') . '</span>';
        }
        echo '</p></div>';

        // Check compatibility declaration
        echo '<h2>' . esc_html__('Compatibility Declaration', 'tap-payment') . '</h2>';
        $compatibility_declared = self::check_compatibility_declaration();
        
        echo '<p><strong>' . esc_html__('HPOS Compatibility Declared:', 'tap-payment') . '</strong> ';
        if ($compatibility_declared) {
            echo '<span style="color: green;">✓ ' . esc_html__('Yes', 'tap-payment') . '</span>';
        } else {
            echo '<span style="color: red;">✗ ' . esc_html__('No', 'tap-payment') . '</span>';
        }
        echo '</p>';

        // Test order data access
        echo '<h2>' . esc_html__('Order Data Access Test', 'tap-payment') . '</h2>';
        self::test_order_data_access();

        echo '</div>';
    }

    /**
     * Check if HPOS is enabled
     */
    private static function is_hpos_enabled() {
        if (!class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return false;
        }
        
        if (!method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
            return false;
        }
        
        return call_user_func(array('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled'));
    }

    /**
     * Check if compatibility is declared
     */
    private static function check_compatibility_declaration() {
        if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            return false;
        }
        
        if (!method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'get_compatible_features_for_plugin')) {
            return false;
        }
        
        if (!defined('TAP_PAYMENT_PLUGIN_BASENAME')) {
            return false;
        }
        
        $features = call_user_func(array('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'get_compatible_features_for_plugin'), TAP_PAYMENT_PLUGIN_BASENAME);
        return is_array($features) && in_array('custom_order_tables', $features, true);
    }



    /**
     * Test order data access methods
     */
    private static function test_order_data_access() {
        // Check if WooCommerce functions exist
        if (!function_exists('wc_get_orders')) {
            echo '<p>' . esc_html__('WooCommerce functions not available. Please ensure WooCommerce is active.', 'tap-payment') . '</p>';
            return;
        }
        
        // Get a recent order for testing
        $orders = call_user_func('wc_get_orders', array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
        ));

        if (empty($orders)) {
            echo '<p>' . esc_html__('No orders found for testing. Create a test order first.', 'tap-payment') . '</p>';
            return;
        }

        $order = $orders[0];
        $order_id = $order->get_id();

        echo '<h4>' . esc_html__('Order Data Access Test (Order ID: ', 'tap-payment') . $order_id . ')</h4>';

        // Test order retrieval
        if (!function_exists('wc_get_order')) {
            echo '<p><strong>' . esc_html__('Order Retrieval:', 'tap-payment') . '</strong> ';
            echo '<span style="color: red;">✗ ' . esc_html__('Failed - wc_get_order function not available', 'tap-payment') . '</span>';
            echo '</p>';
            return;
        }
        
        $retrieved_order = call_user_func('wc_get_order', $order_id);
        echo '<p><strong>' . esc_html__('Order Retrieval:', 'tap-payment') . '</strong> ';
        if ($retrieved_order && method_exists($retrieved_order, 'get_id') && $retrieved_order->get_id() == $order_id) {
            echo '<span style="color: green;">✓ ' . esc_html__('Success', 'tap-payment') . '</span>';
        } else {
            echo '<span style="color: red;">✗ ' . esc_html__('Failed', 'tap-payment') . '</span>';
        }
        echo '</p>';

        // Test meta data access
        $test_meta_key = '_tap_test_meta';
        $test_meta_value = 'hpos_test_' . time();
        
        // Set meta data
        $order->update_meta_data($test_meta_key, $test_meta_value);
        $order->save();

        // Retrieve meta data
        $retrieved_meta = $order->get_meta($test_meta_key);
        echo '<p><strong>' . esc_html__('Meta Data Access:', 'tap-payment') . '</strong> ';
        if ($retrieved_meta === $test_meta_value) {
            echo '<span style="color: green;">✓ ' . esc_html__('Success', 'tap-payment') . '</span>';
        } else {
            echo '<span style="color: red;">✗ ' . esc_html__('Failed', 'tap-payment') . '</span>';
        }
        echo '</p>';

        // Clean up test meta
        $order->delete_meta_data($test_meta_key);
        $order->save();

        // Test order properties
        $properties_test = array(
            'get_total()' => $order->get_total(),
            'get_currency()' => $order->get_currency(),
            'get_status()' => $order->get_status(),
            'get_billing_email()' => $order->get_billing_email(),
        );

        echo '<p><strong>' . esc_html__('Order Properties Access:', 'tap-payment') . '</strong></p>';
        echo '<ul>';
        foreach ($properties_test as $method => $value) {
            echo '<li>' . esc_html($method) . ': ';
            if (!empty($value)) {
                echo '<span style="color: green;">✓ ' . esc_html($value) . '</span>';
            } else {
                echo '<span style="color: orange;">⚠ ' . esc_html__('Empty/null', 'tap-payment') . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

// Initialize the test if we're in admin
if (is_admin()) {
    Tap_Payment_HPOS_Test::init();
}