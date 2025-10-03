<?php
/**
 * Tap API Client Class
 * 
 * Handles communication with Tap Charges and Invoice APIs
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_API_Client {

    /**
     * Instance
     */
    private static $instance = null;

    /**
     * API Base URLs
     */
    const CHARGES_API_URL = 'https://api.tap.company/v2/charges';
    const INVOICE_API_URL = 'https://api.tap.company/v2/invoices';

    /**
     * Settings
     */
    private $settings;

    /**
     * Get instance
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
        $this->settings = get_option('woocommerce_tap_payment_settings', array());
    }

    /**
     * Get API key based on environment
     */
    private function get_api_key() {
        $test_mode = isset($this->settings['testmode']) && 'yes' === $this->settings['testmode'];
        
        if ($test_mode) {
            return isset($this->settings['test_secret_key']) ? $this->settings['test_secret_key'] : '';
        } else {
            return isset($this->settings['live_secret_key']) ? $this->settings['live_secret_key'] : '';
        }
    }

    /**
     * Get merchant ID
     */
    private function get_merchant_id() {
        return isset($this->settings['merchant_id']) ? $this->settings['merchant_id'] : '';
    }

    /**
     * Make API request
     */
    private function make_request($url, $data = array(), $method = 'POST') {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Tap API key is not configured');
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true,
        );

        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('API Request Error', array(
                'url' => $url,
                'error' => $response->get_error_message(),
                'data' => $data
            ));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($response_body, true);

        if ($response_code >= 400) {
            $error_message = isset($decoded_response['error']['message']) 
                ? $decoded_response['error']['message'] 
                : 'Unknown API error';
            
            $this->log_error('API Response Error', array(
                'url' => $url,
                'response_code' => $response_code,
                'response_body' => $response_body,
                'data' => $data
            ));
            
            return new WP_Error('api_error', $error_message, array('response_code' => $response_code));
        }

        return $decoded_response;
    }

    /**
     * Create charge
     */
    public function create_charge($charge_data) {
        $default_data = array(
            'amount' => 0,
            'currency' => get_woocommerce_currency(),
            'threeDSecure' => true,
            'save_card' => false,
            'description' => '',
            'statement_descriptor' => get_bloginfo('name'),
            'metadata' => array(),
            'reference' => array(),
            'receipt' => array(
                'email' => false,
                'sms' => false
            ),
            'customer' => array(),
            'merchant' => array(
                'id' => $this->get_merchant_id()
            ),
            'source' => array(
                'id' => 'src_all'
            ),
            'post' => array(),
            'redirect' => array()
        );

        $data = wp_parse_args($charge_data, $default_data);

        // Validate required fields
        if (empty($data['amount']) || $data['amount'] <= 0) {
            return new WP_Error('invalid_amount', 'Amount must be greater than 0');
        }

        if (empty($data['merchant']['id'])) {
            return new WP_Error('no_merchant_id', 'Merchant ID is required');
        }

        return $this->make_request(self::CHARGES_API_URL, $data, 'POST');
    }

    /**
     * Retrieve charge
     */
    public function retrieve_charge($charge_id) {
        if (empty($charge_id)) {
            return new WP_Error('invalid_charge_id', 'Charge ID is required');
        }

        $url = self::CHARGES_API_URL . '/' . $charge_id;
        return $this->make_request($url, array(), 'GET');
    }

    /**
     * Update charge
     */
    public function update_charge($charge_id, $update_data) {
        if (empty($charge_id)) {
            return new WP_Error('invalid_charge_id', 'Charge ID is required');
        }

        $url = self::CHARGES_API_URL . '/' . $charge_id;
        return $this->make_request($url, $update_data, 'PUT');
    }

    /**
     * Create invoice
     */
    public function create_invoice($invoice_data) {
        $default_data = array(
            'draft' => false,
            'due' => date('Y-m-d', strtotime('+30 days')),
            'expiry' => date('Y-m-d', strtotime('+60 days')),
            'description' => '',
            'mode' => 'INVOICE',
            'note' => '',
            'notifications' => array(
                'channels' => array('EMAIL', 'SMS'),
                'dispatch' => true
            ),
            'currencies' => array(
                get_woocommerce_currency()
            ),
            'metadata' => array(),
            'charge' => array(
                'receipt' => array(
                    'email' => true,
                    'sms' => true
                ),
                'statement_descriptor' => get_bloginfo('name')
            ),
            'customer' => array(),
            'merchant' => array(
                'id' => $this->get_merchant_id()
            ),
            'invoice' => array(
                'order' => array()
            ),
            'post' => array(),
            'redirect' => array()
        );

        $data = wp_parse_args($invoice_data, $default_data);

        // Validate required fields
        if (empty($data['merchant']['id'])) {
            return new WP_Error('no_merchant_id', 'Merchant ID is required');
        }

        if (empty($data['customer'])) {
            return new WP_Error('no_customer', 'Customer information is required');
        }

        return $this->make_request(self::INVOICE_API_URL, $data, 'POST');
    }

    /**
     * Retrieve invoice
     */
    public function retrieve_invoice($invoice_id) {
        if (empty($invoice_id)) {
            return new WP_Error('invalid_invoice_id', 'Invoice ID is required');
        }

        $url = self::INVOICE_API_URL . '/' . $invoice_id;
        return $this->make_request($url, array(), 'GET');
    }

    /**
     * Update invoice
     */
    public function update_invoice($invoice_id, $update_data) {
        if (empty($invoice_id)) {
            return new WP_Error('invalid_invoice_id', 'Invoice ID is required');
        }

        $url = self::INVOICE_API_URL . '/' . $invoice_id;
        return $this->make_request($url, $update_data, 'PUT');
    }

    /**
     * Send invoice
     */
    public function send_invoice($invoice_id) {
        if (empty($invoice_id)) {
            return new WP_Error('invalid_invoice_id', 'Invoice ID is required');
        }

        $url = self::INVOICE_API_URL . '/' . $invoice_id . '/send';
        return $this->make_request($url, array(), 'POST');
    }

    /**
     * Cancel invoice
     */
    public function cancel_invoice($invoice_id) {
        if (empty($invoice_id)) {
            return new WP_Error('invalid_invoice_id', 'Invoice ID is required');
        }

        $url = self::INVOICE_API_URL . '/' . $invoice_id . '/cancel';
        return $this->make_request($url, array(), 'POST');
    }

    /**
     * Prepare charge data for WooCommerce order
     */
    public function prepare_charge_data($order, $return_url = '', $cancel_url = '') {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', 'Invalid order object');
        }

        $order_id = $order->get_id();
        $total = $order->get_total();
        $currency = $order->get_currency();

        // Customer data
        $customer_data = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => array(
                'country_code' => '965', // Default to Kuwait, should be configurable
                'number' => $order->get_billing_phone()
            )
        );

        // Billing address
        if ($order->get_billing_address_1()) {
            $customer_data['contact'] = array(
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone()
            );
        }

        // Metadata
        $metadata = array(
            'order_id' => $order_id,
            'customer_id' => $order->get_customer_id(),
            'site_url' => get_site_url()
        );

        // Reference
        $reference = array(
            'transaction' => 'order_' . $order_id,
            'order' => (string) $order_id
        );

        // Redirect URLs
        $redirect_data = array();
        if (!empty($return_url)) {
            $redirect_data['url'] = $return_url;
        }

        // Post URL for webhook
        $post_data = array();
        $webhook_url = $this->get_webhook_url();
        if (!empty($webhook_url)) {
            $post_data['url'] = $webhook_url;
        }

        return array(
            'amount' => $total,
            'currency' => $currency,
            'description' => sprintf('Order #%s from %s', $order_id, get_bloginfo('name')),
            'metadata' => $metadata,
            'reference' => $reference,
            'customer' => $customer_data,
            'post' => $post_data,
            'redirect' => $redirect_data
        );
    }

    /**
     * Prepare invoice data for installment
     */
    public function prepare_invoice_data($installment, $order, $customer_data = array()) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', 'Invalid order object');
        }

        $order_id = $order->get_id();
        $due_date = date('Y-m-d', strtotime($installment['due_date']));
        $expiry_date = date('Y-m-d', strtotime($due_date . ' +7 days'));

        // Customer data
        if (empty($customer_data)) {
            $customer_data = array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => array(
                    'country_code' => '965',
                    'number' => $order->get_billing_phone()
                )
            );
        }

        // Invoice order items
        $invoice_order = array(
            'amount' => $installment['amount'],
            'currency' => $order->get_currency(),
            'items' => array(
                array(
                    'amount' => $installment['amount'],
                    'currency' => $order->get_currency(),
                    'description' => sprintf(
                        'Installment #%d for Order #%s',
                        $installment['installment_number'],
                        $order_id
                    ),
                    'discount' => array(
                        'type' => 'F',
                        'value' => 0
                    ),
                    'quantity' => 1,
                    'taxes' => array()
                )
            )
        );

        // Metadata
        $metadata = array(
            'order_id' => $order_id,
            'installment_id' => $installment['id'],
            'installment_number' => $installment['installment_number'],
            'plan_id' => $installment['plan_id'],
            'customer_id' => $order->get_customer_id(),
            'site_url' => get_site_url()
        );

        // Post URL for webhook
        $post_data = array();
        $webhook_url = $this->get_webhook_url();
        if (!empty($webhook_url)) {
            $post_data['url'] = $webhook_url;
        }

        return array(
            'due' => $due_date,
            'expiry' => $expiry_date,
            'description' => sprintf(
                'Installment payment #%d for Order #%s',
                $installment['installment_number'],
                $order_id
            ),
            'note' => sprintf(
                'This is installment #%d of %d for your order #%s.',
                $installment['installment_number'],
                $installment['total_installments'] ?? 1,
                $order_id
            ),
            'metadata' => $metadata,
            'customer' => $customer_data,
            'invoice' => array(
                'order' => $invoice_order
            ),
            'post' => $post_data
        );
    }

    /**
     * Get webhook URL
     */
    private function get_webhook_url() {
        return add_query_arg('wc-api', 'tap_payment_webhook', home_url('/'));
    }

    /**
     * Validate webhook signature
     */
    public function validate_webhook_signature($payload, $signature) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return false;
        }

        // Tap uses HMAC-SHA256 for webhook signatures
        $expected_signature = hash_hmac('sha256', $payload, $api_key);
        
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Log error
     */
    private function log_error($message, $data = array()) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = array('source' => 'tap_payment_api');
            
            $log_message = $message;
            if (!empty($data)) {
                $log_message .= ' - Data: ' . wp_json_encode($data);
            }
            
            $logger->error($log_message, $context);
        }
    }

    /**
     * Get test mode status
     */
    public function is_test_mode() {
        return isset($this->settings['testmode']) && 'yes' === $this->settings['testmode'];
    }

    /**
     * Get supported currencies
     */
    public function get_supported_currencies() {
        return array(
            'KWD', 'SAR', 'AED', 'BHD', 'EGP', 'EUR', 'GBP', 'QAR', 'USD', 'OMR', 'JOD'
        );
    }

    /**
     * Check if currency is supported
     */
    public function is_currency_supported($currency) {
        return in_array($currency, $this->get_supported_currencies());
    }

    /**
     * Format amount for API (convert to smallest currency unit)
     */
    public function format_amount($amount, $currency) {
        // Currencies with 3 decimal places
        $three_decimal_currencies = array('KWD', 'BHD', 'OMR', 'JOD');
        
        if (in_array($currency, $three_decimal_currencies)) {
            return round($amount * 1000);
        }
        
        // Default: 2 decimal places
        return round($amount * 100);
    }

    /**
     * Parse amount from API response (convert from smallest currency unit)
     */
    public function parse_amount($amount, $currency) {
        // Currencies with 3 decimal places
        $three_decimal_currencies = array('KWD', 'BHD', 'OMR', 'JOD');
        
        if (in_array($currency, $three_decimal_currencies)) {
            return $amount / 1000;
        }
        
        // Default: 2 decimal places
        return $amount / 100;
    }
}