<?php
/**
 * Plugin Name: Tap Payment Gateway for WooCommerce
 * Plugin URI: https://tap.company
 * Description: Accept payments through Tap Payment Gateway with support for installment plans. Supports KNET, mada, Benefit, and international payment methods.
 * Version: 1.0.0
 * Author: Tap Payments
 * Author URI: https://tap.company
 * Text Domain: tap-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TAP_PAYMENT_VERSION', '1.0.0');
define('TAP_PAYMENT_PLUGIN_FILE', __FILE__);
define('TAP_PAYMENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAP_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TAP_PAYMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Tap Payment Gateway Plugin Class
 */
class Tap_Payment_Gateway_Plugin {

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('Tap_Payment_Gateway_Plugin', 'uninstall'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Load plugin text domain
        load_plugin_textdomain('tap-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Include required files
        $this->includes();

        // Initialize components
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-database.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-api-client.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-gateway.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-installments.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-admin.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-product-fields.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-checkout.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-cron.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-webhook.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-customer-dashboard.php';
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-blocks-support.php';
        
        // Include HPOS test file for development/testing
        if (defined('WP_DEBUG') && WP_DEBUG && file_exists(TAP_PAYMENT_PLUGIN_DIR . 'tests/test-hpos-compatibility.php')) {
            require_once TAP_PAYMENT_PLUGIN_DIR . 'tests/test-hpos-compatibility.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add payment gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        
        // Initialize database
        Tap_Payment_Database::get_instance();
        
        // Initialize admin
        if (is_admin()) {
            Tap_Payment_Admin::get_instance();
            Tap_Payment_Product_Fields::get_instance();
        }
        
        // Initialize checkout
        new Tap_Payment_Checkout();
        
        // Initialize installments
        Tap_Payment_Installments::get_instance();
        
        // Initialize cron
        Tap_Payment_Cron::get_instance();
        
        // Initialize webhook handler
        new Tap_Payment_Webhook();
        
        // Initialize customer dashboard
        Tap_Payment_Customer_Dashboard::get_instance();
        
        // Add WooCommerce Blocks support
        add_action('woocommerce_blocks_loaded', array($this, 'register_block_support'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add gateway class to WooCommerce
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'Tap_Payment_Gateway';
        // Debug: Log that gateway is being added
        if (function_exists('error_log')) {
            error_log('Tap Payment Gateway: Adding gateway to WooCommerce. Total gateways: ' . count($gateways));
        }
        return $gateways;
    }

    /**
     * Register WooCommerce Blocks support
     */
    public function register_block_support() {
        // Check if WooCommerce Blocks is available
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
            // Register the block support class directly
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                    $payment_method_registry->register( new Tap_Payment_Blocks_Support() );
                }
            );
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_style(
            'tap-payment-frontend',
            TAP_PAYMENT_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            TAP_PAYMENT_VERSION
        );

        wp_enqueue_script(
            'tap-payment-frontend',
            TAP_PAYMENT_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            TAP_PAYMENT_VERSION,
            true
        );

        wp_localize_script('tap-payment-frontend', 'tap_payment_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tap_payment_nonce'),
        ));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'tap-payment') === false && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_style(
            'tap-payment-admin',
            TAP_PAYMENT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            TAP_PAYMENT_VERSION
        );

        wp_enqueue_script(
            'tap-payment-admin',
            TAP_PAYMENT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            TAP_PAYMENT_VERSION,
            true
        );

        wp_localize_script('tap-payment-admin', 'tap_payment_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tap_payment_admin_nonce'),
        ));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-database.php';
        Tap_Payment_Database::create_tables();

        // Set default options
        $this->set_default_options();

        // Schedule cron events
        if (!wp_next_scheduled('tap_payment_process_installments')) {
            wp_schedule_event(time(), 'daily', 'tap_payment_process_installments');
        }

        // Add webhook rewrite rules
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-webhook.php';
        Tap_Payment_Webhook::flush_rewrite_rules();

        // Add customer dashboard rewrite rules
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-customer-dashboard.php';
        Tap_Payment_Customer_Dashboard::flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron events
        wp_clear_scheduled_hook('tap_payment_process_installments');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Remove database tables
        require_once TAP_PAYMENT_PLUGIN_DIR . 'includes/class-tap-payment-database.php';
        Tap_Payment_Database::drop_tables();

        // Remove options
        delete_option('tap_payment_settings');
        delete_option('tap_payment_version');

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_settings = array(
            'enabled' => 'yes',
            'title' => __('Tap Payment', 'tap-payment-gateway'),
            'description' => __('Pay securely using Tap Payment Gateway', 'tap-payment-gateway'),
            'test_mode' => 'yes',
            'test_secret_key' => '',
            'test_publishable_key' => '',
            'live_secret_key' => '',
            'live_publishable_key' => '',
            'merchant_id' => '',
            'success_page' => '',
            'failure_page' => '',
            'webhook_secret' => '',
            'enabled_methods' => 'src_all',
            'installment_enabled' => 'yes',
        );

        add_option('tap_payment_settings', $default_settings);
        add_option('tap_payment_version', TAP_PAYMENT_VERSION);
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
            
            // Declare blocks compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                __FILE__,
                true
            );
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . 
             sprintf(
                 esc_html__('Tap Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'tap-payment-gateway'),
                 '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
             ) . 
             '</strong></p></div>';
    }

    /**
     * Get plugin settings
     */
    public static function get_settings() {
        return get_option('tap_payment_settings', array());
    }

    /**
     * Update plugin settings
     */
    public static function update_settings($settings) {
        return update_option('tap_payment_settings', $settings);
    }

    /**
     * Get setting value
     */
    public static function get_setting($key, $default = '') {
        $settings = self::get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Check if test mode is enabled
     */
    public static function is_test_mode() {
        return self::get_setting('test_mode') === 'yes';
    }

    /**
     * Get API secret key based on mode
     */
    public static function get_secret_key() {
        if (self::is_test_mode()) {
            return self::get_setting('test_secret_key');
        }
        return self::get_setting('live_secret_key');
    }

    /**
     * Get API publishable key based on mode
     */
    public static function get_publishable_key() {
        if (self::is_test_mode()) {
            return self::get_setting('test_publishable_key');
        }
        return self::get_setting('live_publishable_key');
    }

    /**
     * Log messages
     */
    public static function log($message, $level = 'info') {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'tap-payment-gateway'));
        }
    }
}

// Initialize the plugin
Tap_Payment_Gateway_Plugin::get_instance();