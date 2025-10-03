<?php
/**
 * Tap Payment Gateway Class
 * 
 * WooCommerce payment gateway for Tap Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * API Client
     */
    private $api_client;

    /**
     * Constructor
     */
    public function __construct() {
        // Debug: Log gateway instantiation
        if (function_exists('error_log')) {
            error_log('Tap Payment Gateway: Constructor called');
        }
        
        $this->id = 'tap_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Tap Payment Gateway', 'tap-payment');
        $this->method_description = __('Accept payments via Tap Payment Gateway with installment support.', 'tap-payment');

        // Supports
        $this->supports = array(
            'products',
            'refunds',
            'blocks',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->test_secret_key = $this->get_option('test_secret_key');
        $this->test_publishable_key = $this->get_option('test_publishable_key');
        $this->live_secret_key = $this->get_option('live_secret_key');
        $this->live_publishable_key = $this->get_option('live_publishable_key');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->success_page = $this->get_option('success_page');
        $this->failure_page = $this->get_option('failure_page');

        // Initialize API client
        $this->api_client = Tap_API_Client::get_instance();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_tap_payment_webhook', array($this, 'handle_webhook'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        // Check if gateway can be used
        if (!$this->is_valid_for_use()) {
            $this->enabled = 'no';
        }
    }

    /**
     * Check if gateway is valid for use
     */
    public function is_valid_for_use() {
        // Temporarily return true for testing - TODO: fix currency support check
        return true;
        // return $this->api_client->is_currency_supported(get_woocommerce_currency());
    }

    /**
     * Get the current publishable key based on test mode
     */
    public function get_publishable_key() {
        return $this->testmode ? $this->test_publishable_key : $this->live_publishable_key;
    }

    /**
     * Get the current secret key based on test mode
     */
    public function get_secret_key() {
        return $this->testmode ? $this->test_secret_key : $this->live_secret_key;
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        if ($this->is_valid_for_use()) {
            parent::admin_options();
        } else {
            ?>
            <div class="inline error">
                <p>
                    <strong><?php esc_html_e('Gateway Disabled', 'tap-payment'); ?></strong>: 
                    <?php esc_html_e('Tap Payment does not support your store currency.', 'tap-payment'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Get available pages for dropdown selection
     */
    private function get_available_pages() {
        $pages = array(
            '' => __('Default (WooCommerce default)', 'tap-payment')
        );

        // Get all published pages
        $all_pages = get_pages(array(
            'post_status' => 'publish',
            'number' => 100
        ));

        foreach ($all_pages as $page) {
            $pages[$page->ID] = $page->post_title;
        }

        // Add WooCommerce specific pages
        $wc_pages = array(
            'shop' => __('Shop Page', 'tap-payment'),
            'cart' => __('Cart Page', 'tap-payment'),
            'checkout' => __('Checkout Page', 'tap-payment'),
            'myaccount' => __('My Account Page', 'tap-payment'),
            'thankyou' => __('Thank You Page (Order Received)', 'tap-payment')
        );

        foreach ($wc_pages as $key => $label) {
            $page_id = wc_get_page_id($key);
            if ($page_id && $page_id > 0) {
                $pages['wc_' . $key] = $label;
            }
        }

        return $pages;
    }

    /**
     * Get URL from page selection
     */
    private function get_page_url($page_selection, $order = null) {
        if (empty($page_selection)) {
            return '';
        }

        // Handle WooCommerce specific pages
        if (strpos($page_selection, 'wc_') === 0) {
            $wc_page = str_replace('wc_', '', $page_selection);
            switch ($wc_page) {
                case 'thankyou':
                    return $order ? $this->get_return_url($order) : wc_get_checkout_url();
                case 'shop':
                    return wc_get_page_permalink('shop');
                case 'cart':
                    return wc_get_cart_url();
                case 'checkout':
                    return wc_get_checkout_url();
                case 'myaccount':
                    return wc_get_page_permalink('myaccount');
                default:
                    return '';
            }
        }

        // Handle regular page ID
        if (is_numeric($page_selection)) {
            return get_permalink($page_selection);
        }

        return '';
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'tap-payment'),
                'type' => 'checkbox',
                'label' => __('Enable Tap Payment Gateway', 'tap-payment'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'tap-payment'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'tap-payment'),
                'default' => __('Tap Payment', 'tap-payment'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'tap-payment'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'tap-payment'),
                'default' => __('Pay securely using your credit/debit card via Tap Payment Gateway.', 'tap-payment'),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test mode', 'tap-payment'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'tap-payment'),
                'default' => 'yes',
                'description' => sprintf(__('Place the payment gateway in test mode using test API keys.', 'tap-payment')),
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'tap-payment'),
                'type' => 'password',
                'description' => __('Get your API keys from your Tap account.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_publishable_key' => array(
                'title' => __('Test Publishable Key', 'tap-payment'),
                'type' => 'text',
                'description' => __('Get your API keys from your Tap account.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_secret_key' => array(
                'title' => __('Live Secret Key', 'tap-payment'),
                'type' => 'password',
                'description' => __('Get your API keys from your Tap account.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_publishable_key' => array(
                'title' => __('Live Publishable Key', 'tap-payment'),
                'type' => 'text',
                'description' => __('Get your API keys from your Tap account.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'tap-payment'),
                'type' => 'text',
                'description' => __('Your Tap Merchant ID.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
            'success_page' => array(
                'title' => __('Success Return Page', 'tap-payment'),
                'type' => 'select',
                'description' => __('Page to redirect customers after successful payment.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
                'options' => $this->get_available_pages(),
            ),
            'failure_page' => array(
                'title' => __('Failure Return Page', 'tap-payment'),
                'type' => 'select',
                'description' => __('Page to redirect customers after failed payment.', 'tap-payment'),
                'default' => '',
                'desc_tip' => true,
                'options' => $this->get_available_pages(),
            ),
            'webhook_section' => array(
                'title' => __('Webhook Configuration', 'tap-payment'),
                'type' => 'title',
                'description' => sprintf(
                    __('Configure this webhook URL in your Tap dashboard: %s', 'tap-payment'),
                    '<code>' . add_query_arg('wc-api', 'tap_payment_webhook', home_url('/')) . '</code>'
                ),
            ),
        );
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'tap-payment'), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Check for installment products
        $installment_items = $this->get_installment_items($order);
        
        if (!empty($installment_items)) {
            return $this->process_installment_payment($order, $installment_items);
        } else {
            return $this->process_regular_payment($order);
        }
    }

    /**
     * Process regular payment (no installments)
     */
    private function process_regular_payment($order) {
        $order_id = $order->get_id();

        // Prepare return URLs
        $return_url = $this->get_return_url($order);
        $cancel_url = $order->get_cancel_order_url();

        // Use custom success page if selected
        $success_page_url = $this->get_page_url($this->success_page, $order);
        if (!empty($success_page_url)) {
            $return_url = add_query_arg('order_id', $order_id, $success_page_url);
        }

        // Use custom failure page if selected
        $failure_page_url = $this->get_page_url($this->failure_page, $order);
        if (!empty($failure_page_url)) {
            $cancel_url = add_query_arg('order_id', $order_id, $failure_page_url);
        }

        // Prepare charge data
        $charge_data = $this->api_client->prepare_charge_data($order, $return_url, $cancel_url);

        if (is_wp_error($charge_data)) {
            wc_add_notice($charge_data->get_error_message(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Create charge
        $response = $this->api_client->create_charge($charge_data);

        if (is_wp_error($response)) {
            wc_add_notice($response->get_error_message(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Store charge ID in order meta
        $order->update_meta_data('_tap_charge_id', $response['id']);
        $order->update_meta_data('_tap_payment_type', 'regular');
        $order->save();

        // Store payment record
        Tap_Payment_Database::create_payment(array(
            'order_id' => $order_id,
            'tap_charge_id' => $response['id'],
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => $response['status'],
            'payment_type' => 'initial',
            'response_data' => wp_json_encode($response)
        ));

        // Update order status
        $order->update_status('pending', __('Awaiting Tap payment confirmation.', 'tap-payment'));

        // Redirect to Tap payment page
        return array(
            'result' => 'success',
            'redirect' => $response['transaction']['url']
        );
    }

    /**
     * Process installment payment
     */
    private function process_installment_payment($order, $installment_items) {
        $order_id = $order->get_id();
        $user_id = $order->get_customer_id();

        // Calculate down payment (sum of regular prices)
        $down_payment = 0;
        foreach ($installment_items as $item) {
            $down_payment += $item['down_payment'];
        }

        // Prepare return URLs
        $return_url = $this->get_return_url($order);
        $cancel_url = $order->get_cancel_order_url();

        // Use custom success page if selected
        $success_page_url = $this->get_page_url($this->success_page, $order);
        if (!empty($success_page_url)) {
            $return_url = add_query_arg('order_id', $order_id, $success_page_url);
        }

        // Use custom failure page if selected
        $failure_page_url = $this->get_page_url($this->failure_page, $order);
        if (!empty($failure_page_url)) {
            $cancel_url = add_query_arg('order_id', $order_id, $failure_page_url);
        }

        // Create charge for down payment
        $charge_data = $this->api_client->prepare_charge_data($order, $return_url, $cancel_url);
        
        if (is_wp_error($charge_data)) {
            wc_add_notice($charge_data->get_error_message(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Update charge amount to down payment only
        $charge_data['amount'] = $down_payment;
        $charge_data['description'] = sprintf('Down payment for Order #%s', $order_id);

        $response = $this->api_client->create_charge($charge_data);

        if (is_wp_error($response)) {
            wc_add_notice($response->get_error_message(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }

        // Store charge ID in order meta
        $order->update_meta_data('_tap_charge_id', $response['id']);
        $order->update_meta_data('_tap_payment_type', 'installment');
        $order->update_meta_data('_tap_down_payment', $down_payment);
        $order->save();

        // Create installment plans for each item
        foreach ($installment_items as $item) {
            $plan_id = Tap_Payment_Database::create_installment_plan(array(
                'order_id' => $order_id,
                'user_id' => $user_id,
                'product_id' => $item['product_id'],
                'variation_id' => $item['variation_id'],
                'total_amount' => $item['full_amount'],
                'down_payment' => $item['down_payment'],
                'installment_count' => $item['installment_count'],
                'status' => 'pending'
            ));

            if ($plan_id) {
                // Create individual installments with proper validation and rounding
                if ($item['installment_count'] > 0) {
                    $installment_amount = round(($item['full_amount'] - $item['down_payment']) / $item['installment_count'], 2);
                } else {
                    continue; // Skip if invalid installment count
                }
                
                for ($i = 1; $i <= $item['installment_count']; $i++) {
                    $due_date = date('Y-m-d', strtotime('+' . $i . ' month'));
                    
                    Tap_Payment_Database::create_installment(array(
                        'plan_id' => $plan_id,
                        'installment_number' => $i,
                        'amount' => $installment_amount,
                        'due_date' => $due_date,
                        'status' => 'pending'
                    ));
                }
            }
        }

        // Store payment record for down payment
        Tap_Payment_Database::create_payment(array(
            'order_id' => $order_id,
            'tap_charge_id' => $response['id'],
            'amount' => $down_payment,
            'currency' => $order->get_currency(),
            'status' => $response['status'],
            'payment_type' => 'initial',
            'response_data' => wp_json_encode($response)
        ));

        // Update order status
        $order->update_status('pending', __('Awaiting Tap payment confirmation for down payment.', 'tap-payment'));

        // Redirect to Tap payment page
        return array(
            'result' => 'success',
            'redirect' => $response['transaction']['url']
        );
    }

    /**
     * Get installment items from order
     */
    private function get_installment_items($order) {
        $installment_items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            $variation_id = null;

            // Check if it's a variation
            if ($product->is_type('variation')) {
                $variation_id = $product_id;
                $product_id = $product->get_parent_id();
            }

            // Get installment settings
            $enable_installment = get_post_meta($variation_id ?: $product_id, '_tap_enable_installment', true);
            
            if ('yes' === $enable_installment) {
                $full_amount = (float) get_post_meta($variation_id ?: $product_id, '_tap_full_amount', true);
                $installment_count = (int) get_post_meta($variation_id ?: $product_id, '_tap_installment_count', true);
                
                if ($full_amount > 0 && $installment_count > 0) {
                    $item_total = $item->get_total();
                    
                    $installment_items[] = array(
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'full_amount' => $full_amount * $item->get_quantity(),
                        'down_payment' => $item_total,
                        'installment_count' => $installment_count,
                        'item_id' => $item_id
                    );
                }
            }
        }

        return $installment_items;
    }

    /**
     * Handle webhook
     */
    public function handle_webhook() {
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_TAP_SIGNATURE']) ? $_SERVER['HTTP_X_TAP_SIGNATURE'] : '';

        // Validate signature
        if (!$this->api_client->validate_webhook_signature($payload, $signature)) {
            status_header(401);
            exit('Unauthorized');
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['id'])) {
            status_header(400);
            exit('Invalid payload');
        }

        // Process webhook based on object type
        if (isset($data['object']) && $data['object'] === 'charge') {
            $this->process_charge_webhook($data);
        } elseif (isset($data['object']) && $data['object'] === 'invoice') {
            $this->process_invoice_webhook($data);
        }

        status_header(200);
        exit('OK');
    }

    /**
     * Process charge webhook
     */
    private function process_charge_webhook($data) {
        $charge_id = $data['id'];
        $status = $data['status'];

        // Find order by charge ID
        $payment = Tap_Payment_Database::get_payment_by_charge_id($charge_id);
        
        if (!$payment) {
            return;
        }

        $order = wc_get_order($payment->order_id);
        
        if (!$order) {
            return;
        }

        // Update payment status
        Tap_Payment_Database::update_payment($payment->id, array(
            'status' => $status,
            'response_data' => wp_json_encode($data)
        ));

        // Update order based on status
        switch ($status) {
            case 'CAPTURED':
                if ($order->get_status() !== 'processing' && $order->get_status() !== 'completed') {
                    $order->payment_complete($charge_id);
                    $order->add_order_note(sprintf(__('Tap payment completed. Charge ID: %s', 'tap-payment'), $charge_id));
                    
                    // If this is an installment payment, activate the installment plans
                    if ($order->get_meta('_tap_payment_type') === 'installment') {
                        $this->activate_installment_plans($order);
                    }
                }
                break;

            case 'FAILED':
                $order->update_status('failed', sprintf(__('Tap payment failed. Charge ID: %s', 'tap-payment'), $charge_id));
                break;

            case 'CANCELLED':
                $order->update_status('cancelled', sprintf(__('Tap payment cancelled. Charge ID: %s', 'tap-payment'), $charge_id));
                break;
        }
    }

    /**
     * Process invoice webhook
     */
    private function process_invoice_webhook($data) {
        $invoice_id = $data['id'];
        $status = $data['status'];

        // Find installment by invoice ID
        global $wpdb;
        $table = $wpdb->prefix . 'tap_installments';
        
        $installment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE tap_invoice_id = %s", $invoice_id)
        );

        if (!$installment) {
            return;
        }

        // Update installment status based on invoice status
        switch ($status) {
            case 'PAID':
                Tap_Payment_Database::update_installment($installment->id, array(
                    'status' => 'paid',
                    'paid_at' => current_time('mysql')
                ));

                // Create payment record for installment
                if (isset($data['charge']) && isset($data['charge']['id'])) {
                    Tap_Payment_Database::create_payment(array(
                        'installment_id' => $installment->id,
                        'order_id' => $this->get_order_id_from_installment($installment->plan_id),
                        'tap_charge_id' => $data['charge']['id'],
                        'amount' => $installment->amount,
                        'currency' => $data['charge']['currency'] ?? get_woocommerce_currency(),
                        'status' => 'CAPTURED',
                        'payment_type' => 'installment',
                        'response_data' => wp_json_encode($data)
                    ));
                }
                break;

            case 'OVERDUE':
                Tap_Payment_Database::update_installment($installment->id, array(
                    'status' => 'overdue'
                ));
                break;

            case 'CANCELLED':
                Tap_Payment_Database::update_installment($installment->id, array(
                    'status' => 'cancelled'
                ));
                break;
        }
    }

    /**
     * Activate installment plans after successful down payment
     */
    private function activate_installment_plans($order) {
        $plans = Tap_Payment_Database::get_installment_plans_by_order($order->get_id());
        
        foreach ($plans as $plan) {
            Tap_Payment_Database::update_installment_plan($plan->id, array(
                'status' => 'active'
            ));
        }
    }

    /**
     * Get order ID from installment plan ID
     */
    private function get_order_id_from_installment($plan_id) {
        $plan = Tap_Payment_Database::get_installment_plan($plan_id);
        return $plan ? $plan->order_id : 0;
    }

    /**
     * Thank you page
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        $payment_type = $order->get_meta('_tap_payment_type');
        
        if ($payment_type === 'installment') {
            $this->display_installment_summary($order);
        }
    }

    /**
     * Display installment summary on thank you page
     */
    private function display_installment_summary($order) {
        $plans = Tap_Payment_Database::get_installment_plans_by_order($order->get_id());
        
        if (empty($plans)) {
            return;
        }

        echo '<h2>' . esc_html__('Installment Plan Summary', 'tap-payment') . '</h2>';
        echo '<div class="tap-installment-summary">';
        
        foreach ($plans as $plan) {
            $installments = Tap_Payment_Database::get_installments_by_plan($plan->id);
            $product = wc_get_product($plan->product_id);
            
            if (!$product) {
                continue;
            }

            echo '<div class="installment-plan">';
            echo '<h4>' . esc_html($product->get_name()) . '</h4>';
            echo '<p>' . sprintf(
                esc_html__('Down Payment: %s | Remaining: %s in %d installments', 'tap-payment'),
                wc_price($plan->down_payment),
                wc_price($plan->total_amount - $plan->down_payment),
                $plan->installment_count
            ) . '</p>';
            
            if (!empty($installments)) {
                echo '<table class="installment-schedule">';
                echo '<thead><tr><th>' . esc_html__('Installment', 'tap-payment') . '</th><th>' . esc_html__('Amount', 'tap-payment') . '</th><th>' . esc_html__('Due Date', 'tap-payment') . '</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($installments as $installment) {
                    echo '<tr>';
                    echo '<td>' . esc_html($installment->installment_number) . '</td>';
                    echo '<td>' . wc_price($installment->amount) . '</td>';
                    echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($installment->due_date))) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Order not found.', 'tap-payment'));
        }

        $charge_id = $order->get_meta('_tap_charge_id');
        
        if (empty($charge_id)) {
            return new WP_Error('no_charge_id', __('No charge ID found for this order.', 'tap-payment'));
        }

        // For now, return false to indicate manual refund needed
        // Tap API refund functionality can be implemented here
        return false;
    }
}