<?php
/**
 * Tap Payment Webhook Handler
 *
 * @package TapPayment
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Webhook {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'add_webhook_endpoint'));
        add_action('parse_request', array($this, 'handle_webhook_request'));
        add_action('wp_ajax_tap_test_webhook', array($this, 'test_webhook'));
        add_action('wp_ajax_nopriv_tap_webhook', array($this, 'handle_webhook'));
    }

    /**
     * Add webhook endpoint
     */
    public function add_webhook_endpoint() {
        add_rewrite_rule(
            '^tap-webhook/?$',
            'index.php?tap_webhook=1',
            'top'
        );
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'tap_webhook';
            return $vars;
        });
    }

    /**
     * Handle webhook request
     */
    public function handle_webhook_request($wp) {
        if (!isset($wp->query_vars['tap_webhook'])) {
            return;
        }

        // Verify request method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            status_header(405);
            exit('Method Not Allowed');
        }

        // Rate limiting check
        if (!$this->check_rate_limit()) {
            status_header(429);
            exit('Too Many Requests');
        }

        // Get payload and signature
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_TAP_SIGNATURE']) ? sanitize_text_field($_SERVER['HTTP_X_TAP_SIGNATURE']) : '';

        try {
            // Validate payload
            $validated_data = $this->validate_webhook_payload($payload);
            
            // Verify webhook signature
            if (!$this->verify_webhook_signature($payload, $signature)) {
                $this->log_security_event('webhook_signature_failed', array(
                    'ip' => $this->get_client_ip(),
                    'signature' => substr($signature, 0, 20) . '...'
                ));
                status_header(401);
                exit('Unauthorized');
            }

            // Process webhook
            $this->process_webhook($validated_data);

            status_header(200);
            exit('OK');

        } catch (InvalidArgumentException $e) {
            $this->log_webhook_error('Invalid webhook payload', $e->getMessage());
            status_header(400);
            exit('Bad Request');
        } catch (Exception $e) {
            $this->log_webhook_error('Webhook processing failed', $e->getMessage());
            status_header(500);
            exit('Internal Server Error');
        }
    }

    /**
     * Handle incoming webhook
     */
    public function handle_webhook() {
        // Verify request method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->send_response(405, 'Method not allowed');
            return;
        }

        // Get raw POST data
        $raw_body = file_get_contents('php://input');
        $data = json_decode($raw_body, true);

        if (!$data) {
            $this->log_webhook_error('Invalid JSON payload', $raw_body);
            $this->send_response(400, 'Invalid JSON');
            return;
        }

        // Verify webhook signature
        if (!$this->verify_webhook_signature($raw_body)) {
            $this->log_webhook_error('Invalid webhook signature', $data);
            $this->send_response(401, 'Unauthorized');
            return;
        }

        // Log webhook received
        $this->log_webhook('Webhook received', $data);

        try {
            $this->process_webhook($data);
            $this->send_response(200, 'OK');
        } catch (Exception $e) {
            $this->log_webhook_error('Webhook processing failed: ' . $e->getMessage(), $data);
            $this->send_response(500, 'Internal Server Error');
        }
    }

    /**
     * Verify webhook signature
     */
    private function verify_webhook_signature($raw_body) {
        $webhook_secret = get_option('tap_payment_webhook_secret');
        
        if (empty($webhook_secret)) {
            return true; // Skip verification if no secret is set
        }

        $signature = $_SERVER['HTTP_X_TAP_SIGNATURE'] ?? '';
        
        if (empty($signature)) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $raw_body, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        if (!is_array($data) || !isset($data['id'])) {
            throw new InvalidArgumentException('Invalid webhook data structure');
        }

        $object_type = isset($data['object']) ? sanitize_text_field($data['object']) : '';

        try {
            switch ($object_type) {
                case 'charge':
                    $this->handle_charge_webhook($data);
                    break;

                case 'invoice':
                    $this->handle_invoice_webhook($data);
                    break;

                default:
                    $this->log_webhook('Unknown webhook object type: ' . $object_type, $data);
                    throw new InvalidArgumentException('Unsupported webhook object type: ' . $object_type);
            }
        } catch (Exception $e) {
            $this->log_webhook_error('Webhook processing error for ' . $object_type, array(
                'error' => $e->getMessage(),
                'data' => $data
            ));
            throw $e;
        }
    }

    /**
     * Handle charge webhook
     */
    private function handle_charge_webhook($data) {
        $charge_data = $data['data'] ?? array();
        $charge_id = $charge_data['id'] ?? '';
        $status = $charge_data['status'] ?? '';

        if (empty($charge_id)) {
            throw new Exception('Missing charge ID in webhook data');
        }

        // Find payment record
        $payment = Tap_Payment_Database::get_payment_by_charge_id($charge_id);
        
        if (!$payment) {
            $this->log_webhook('Payment not found for charge: ' . $charge_id, $data);
            return;
        }

        // Update payment status
        $update_data = array(
            'status' => $status,
            'response_data' => json_encode($charge_data),
            'updated_at' => current_time('mysql')
        );

        Tap_Payment_Database::update_payment($payment->id, $update_data);

        // Update WooCommerce order
        if (!function_exists('wc_get_order')) {
            throw new Exception('WooCommerce not available');
        }
        $order = call_user_func('wc_get_order', $payment->order_id);
        
        if (!$order) {
            throw new Exception('Order not found: ' . $payment->order_id);
        }

        switch ($status) {
            case 'CAPTURED':
                if ($payment->payment_type === 'initial') {
                    $order->payment_complete($charge_id);
                    $order->add_order_note(
                        sprintf(__('Tap payment completed. Charge ID: %s', 'tap-payment'), $charge_id)
                    );
                } else {
                    $order->add_order_note(
                        sprintf(__('Tap installment payment completed. Charge ID: %s', 'tap-payment'), $charge_id)
                    );
                }
                break;
                
            case 'FAILED':
                $order->update_status('failed');
                $order->add_order_note(
                    sprintf(__('Tap payment failed. Charge ID: %s', 'tap-payment'), $charge_id)
                );
                break;
                
            case 'CANCELLED':
                $order->update_status('cancelled');
                $order->add_order_note(
                    sprintf(__('Tap payment cancelled. Charge ID: %s', 'tap-payment'), $charge_id)
                );
                break;
        }

        $this->log_webhook('Charge webhook processed successfully', array(
            'charge_id' => $charge_id,
            'order_id' => $payment->order_id,
            'status' => $status
        ));
    }

    /**
     * Handle invoice webhook
     */
    private function handle_invoice_webhook($data) {
        $invoice_data = $data['data'] ?? array();
        $invoice_id = $invoice_data['id'] ?? '';
        $status = $invoice_data['status'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice ID in webhook data');
        }

        // Find installment by invoice ID
        global $wpdb;
        $table = $wpdb->prefix . 'tap_installments';
        
        $installment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE tap_invoice_id = %s",
                $invoice_id
            )
        );

        if (!$installment) {
            $this->log_webhook('Installment not found for invoice: ' . $invoice_id, $data);
            return;
        }

        // Update installment status based on invoice status
        $installment_status = $this->map_invoice_status_to_installment($status);
        
        if ($installment_status) {
            $update_data = array(
                'status' => $installment_status,
                'updated_at' => current_time('mysql')
            );

            if ($status === 'PAID') {
                $update_data['paid_at'] = current_time('mysql');
            }

            Tap_Payment_Database::update_installment($installment->id, $update_data);

            // Get installment plan and order
            $plan = Tap_Payment_Database::get_installment_plan($installment->plan_id);
            $order = wc_get_order($plan->order_id);

            if ($order) {
                $order->add_order_note(
                    sprintf(
                        __('Installment #%d %s. Invoice ID: %s', 'tap-payment'),
                        $installment->installment_number,
                        $installment_status,
                        $invoice_id
                    )
                );

                // Check if all installments are paid
                if ($installment_status === 'paid') {
                    $this->check_installment_plan_completion($installment->plan_id);
                }
            }
        }

        $this->log_webhook('Invoice webhook processed successfully', array(
            'invoice_id' => $invoice_id,
            'installment_id' => $installment->id,
            'status' => $status
        ));
    }

    /**
     * Map invoice status to installment status
     */
    private function map_invoice_status_to_installment($invoice_status) {
        $status_map = array(
            'PAID' => 'paid',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'failed'
        );

        return $status_map[$invoice_status] ?? null;
    }

    /**
     * Check if installment plan is completed
     */
    private function check_installment_plan_completion($plan_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_installments';
        
        $pending_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE plan_id = %d AND status != 'paid'",
                $plan_id
            )
        );

        if ($pending_count == 0) {
            // All installments are paid, update plan status
            Tap_Payment_Database::update_installment_plan($plan_id, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ));

            // Add order note
            $plan = Tap_Payment_Database::get_installment_plan($plan_id);
            $order = wc_get_order($plan->order_id);
            
            if ($order) {
                $order->add_order_note(__('All installments have been paid. Installment plan completed.', 'tap-payment'));
            }
        }
    }

    /**
     * Test webhook endpoint
     */
    public function test_webhook() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'tap-payment'));
        }

        check_ajax_referer('tap_admin_nonce', 'nonce');

        $webhook_url = home_url('/tap-webhook/');
        
        $test_data = array(
            'event' => 'test.webhook',
            'data' => array(
                'id' => 'test_' . time(),
                'status' => 'TEST',
                'timestamp' => current_time('mysql')
            )
        );

        $response = wp_remote_post($webhook_url, array(
            'body' => json_encode($test_data),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => __('Webhook test failed: ', 'tap-payment') . $response->get_error_message()
            ));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            wp_send_json_success(array(
                'message' => __('Webhook test successful!', 'tap-payment')
            ));
        } else {
            wp_send_json_error(array(
                'message' => sprintf(__('Webhook test failed with status code: %d', 'tap-payment'), $response_code)
            ));
        }
    }

    /**
     * Send HTTP response
     */
    private function send_response($code, $message) {
        status_header($code);
        echo $message;
        exit;
    }

    /**
     * Log webhook activity
     */
    private function log_webhook($message, $data = array()) {
        if (get_option('tap_payment_enable_logging', 'yes') === 'yes') {
            $log_entry = array(
                'timestamp' => current_time('mysql'),
                'message' => $message,
                'data' => $data
            );
            
            error_log('Tap Payment Webhook: ' . json_encode($log_entry));
        }
    }

    /**
     * Check rate limit for webhook requests
     */
    private function check_rate_limit() {
        $ip_address = $this->get_client_ip();
        $transient_key = 'tap_webhook_rate_' . md5($ip_address);
        $requests = get_transient($transient_key) ?: 0;
        
        if ($requests >= 100) { // 100 requests per hour
            return false;
        }
        
        set_transient($transient_key, $requests + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Validate webhook payload
     */
    private function validate_webhook_payload($payload) {
        if (empty($payload)) {
            throw new InvalidArgumentException('Empty webhook payload');
        }
        
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON payload: ' . json_last_error_msg());
        }
        
        // Validate required fields
        if (!isset($data['id']) || empty($data['id'])) {
            throw new InvalidArgumentException('Missing required field: id');
        }
        
        if (!isset($data['object']) || empty($data['object'])) {
            throw new InvalidArgumentException('Missing required field: object');
        }
        
        return $data;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    }

    /**
     * Log security events
     */
    private function log_security_event($event_type, $details = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event_type' => $event_type,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'details' => $details
        );
        
        error_log('TAP_SECURITY: ' . wp_json_encode($log_entry));
    }

    /**
     * Log webhook errors
     */
    private function log_webhook_error($message, $data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'error' => $message,
            'data' => $data,
            'ip' => $this->get_client_ip()
        );
        
        error_log('Tap Payment Webhook Error: ' . wp_json_encode($log_entry));
    }

    /**
     * Get webhook URL
     */
    public static function get_webhook_url() {
        return home_url('/tap-webhook/');
    }

    /**
     * Flush rewrite rules on activation
     */
    public static function flush_rewrite_rules() {
        add_rewrite_rule(
            '^tap-webhook/?$',
            'index.php?tap_webhook=1',
            'top'
        );
        flush_rewrite_rules();
    }
}