<?php
/**
 * Tap Payment Gateway Blocks Support
 * 
 * Provides compatibility with WooCommerce Checkout Block
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Tap Payment Gateway Blocks Support Class
 */
final class Tap_Payment_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment gateway instance
     */
    private $gateway;

    /**
     * Payment method name/id
     */
    protected $name = 'tap_payment';

    /**
     * Initialize the payment method type
     */
    public function initialize() {
        // Get payment gateway settings
        $this->settings = get_option("woocommerce_{$this->name}_settings", array());
        
        // Initialize the gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Tap Payment Blocks Support initialized');
        }
    }

    /**
     * Check if the payment method is active
     */
    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        $script_path = 'assets/js/blocks/tap-payment-block.js';
        $script_asset_path = TAP_PAYMENT_PLUGIN_DIR . 'assets/js/blocks/tap-payment-block.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array('wc-blocks-registry', 'wp-element', 'wp-i18n'),
                'version' => TAP_PAYMENT_VERSION
            );

        $script_handle = 'tap-payment-block';
        
        // Only register if not already registered
        if (!wp_script_is($script_handle, 'registered')) {
            wp_register_script(
                $script_handle,
                TAP_PAYMENT_PLUGIN_URL . $script_path,
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );

            // Localize script with payment data
            wp_localize_script(
                $script_handle,
                'tapPaymentBlocksData',
                $this->get_payment_method_data()
            );
        }

        return array($script_handle);
    }

    /**
     * Get payment method data to pass to the frontend
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports'    => $this->gateway ? $this->gateway->supports : array(),
            'testmode'    => $this->get_setting('testmode') === 'yes',
            'icon'        => $this->gateway ? $this->gateway->icon : '',
            'method_id'   => $this->name,
        );
    }

    /**
     * Get setting value
     */
    protected function get_setting($key, $default = '') {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
}