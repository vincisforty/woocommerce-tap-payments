<?php
/**
 * Tap Payment Installments Management
 *
 * @package TapPayment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Tap_Payment_Installments
 */
class Tap_Payment_Installments {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Database instance
     */
    private $database;

    /**
     * API client instance
     */
    private $api_client;

    /**
     * Get single instance
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
        $this->database = Tap_Payment_Database::get_instance();
        $this->api_client = Tap_API_Client::get_instance();
        
        add_action('woocommerce_order_status_completed', array($this, 'create_installment_plan'), 10, 1);
        add_action('woocommerce_payment_complete', array($this, 'create_installment_plan'), 10, 1);
        add_action('wp_ajax_tap_pay_installment', array($this, 'ajax_pay_installment'));
        add_action('wp_ajax_nopriv_tap_pay_installment', array($this, 'ajax_pay_installment'));
    }

    /**
     * Create installment plan after successful payment
     */
    public function create_installment_plan($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'tap_payment') {
            return;
        }

        // Check if installment plan already exists
        $existing_plans = $this->database->get_installment_plans_by_order($order_id);
        if (!empty($existing_plans)) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $quantity = $item->get_quantity();

            if ($product->is_type('variable')) {
                $variation_id = $item->get_variation_id();
                $enable_installment = get_post_meta($variation_id, '_tap_enable_installment', true);
                $full_amount = get_post_meta($variation_id, '_tap_full_amount', true);
                $installments = get_post_meta($variation_id, '_tap_installments', true);
                $product_id = $variation_id;
            } else {
                $enable_installment = get_post_meta($product->get_id(), '_tap_enable_installment', true);
                $full_amount = get_post_meta($product->get_id(), '_tap_full_amount', true);
                $installments = get_post_meta($product->get_id(), '_tap_installments', true);
                $product_id = $product->get_id();
            }

            if ($enable_installment === 'yes' && $full_amount && $installments) {
                $this->create_product_installment_plan($order, $item, $product_id, $full_amount, $installments, $quantity);
            }
        }
    }

    /**
     * Create installment plan for a specific product
     */
    private function create_product_installment_plan($order, $item, $product_id, $full_amount, $installments, $quantity) {
        // Calculate current price per unit
        $current_price = $quantity > 0 ? $item->get_total() / $quantity : 0;
        
        // Use standardized calculation method for consistency
        $calc_data = self::calculate_installment_data($current_price, $full_amount, $installments, $quantity);
        
        if ($calc_data === false) {
            return;
        }

        // Create installment plan
        $plan_data = array(
            'order_id' => $order->get_id(),
            'customer_id' => $order->get_customer_id(),
            'product_id' => $product_id,
            'product_name' => $item->get_name(),
            'initial_payment' => $calc_data['current_price'] * $calc_data['quantity'],
            'remaining_amount' => $calc_data['remaining_amount'],
            'total_installments' => $calc_data['installments'],
            'installment_amount' => $calc_data['installment_amount'],
            'status' => 'active',
            'created_at' => current_time('mysql')
        );

        $plan_id = $this->database->insert_installment_plan($plan_data);

        if ($plan_id) {
            $this->create_individual_installments($plan_id, $calc_data['installments'], $calc_data['installment_amount']);
        }
    }

    /**
     * Create individual installments for a plan
     */
    private function create_individual_installments($plan_id, $total_installments, $installment_amount) {
        for ($i = 1; $i <= $total_installments; $i++) {
            $due_date = date('Y-m-d', strtotime("+{$i} month"));
            
            $installment_data = array(
                'plan_id' => $plan_id,
                'installment_number' => $i,
                'amount' => $installment_amount,
                'due_date' => $due_date,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            );

            $this->database->insert_installment($installment_data);
        }
    }

    /**
     * Process installment payment
     */
    public function process_installment_payment($installment_id) {
        $installment = $this->database->get_installment($installment_id);
        
        if (!$installment || $installment->status !== 'pending') {
            return new WP_Error('invalid_installment', __('Invalid installment or already processed.', 'tap-payment'));
        }

        $plan = $this->database->get_installment_plan($installment->plan_id);
        if (!$plan) {
            return new WP_Error('invalid_plan', __('Installment plan not found.', 'tap-payment'));
        }

        // Create Tap invoice for this installment
        $invoice_data = $this->prepare_invoice_data($installment, $plan);
        $response = $this->api_client->create_invoice($invoice_data);

        if (is_wp_error($response)) {
            return $response;
        }

        // Update installment with invoice details
        $this->database->update_installment($installment_id, array(
            'tap_invoice_id' => $response['id'],
            'invoice_url' => $response['url'],
            'status' => 'invoiced',
            'updated_at' => current_time('mysql')
        ));

        return $response;
    }

    /**
     * Prepare invoice data for Tap API
     */
    private function prepare_invoice_data($installment, $plan) {
        $order = wc_get_order($plan->order_id);
        $customer = $order->get_user();

        $invoice_data = array(
            'draft' => false,
            'due' => $installment->due_date,
            'expiry' => date('Y-m-d', strtotime($installment->due_date . ' +7 days')),
            'description' => sprintf(
                __('Installment %d of %d for %s', 'tap-payment'),
                $installment->installment_number,
                $plan->total_installments,
                $plan->product_name
            ),
            'mode' => 'INVOICE',
            'note' => sprintf(
                __('Order #%s - Installment payment', 'tap-payment'),
                $order->get_order_number()
            ),
            'notifications' => array(
                'channels' => array('EMAIL', 'SMS'),
                'dispatch' => true
            ),
            'currencies' => array(
                get_woocommerce_currency()
            ),
            'metadata' => array(
                'order_id' => $plan->order_id,
                'plan_id' => $plan->id,
                'installment_id' => $installment->id,
                'installment_number' => $installment->installment_number
            )
        );

        // Add customer information
        if ($customer) {
            $invoice_data['customer'] = array(
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->user_email,
                'phone' => array(
                    'country_code' => '965', // Default to Kuwait
                    'number' => $order->get_billing_phone()
                )
            );
        }

        // Add order items
        $invoice_data['order'] = array(
            'amount' => $installment->amount,
            'currency' => get_woocommerce_currency(),
            'items' => array(
                array(
                    'amount' => $installment->amount,
                    'currency' => get_woocommerce_currency(),
                    'description' => sprintf(
                        __('Installment %d - %s', 'tap-payment'),
                        $installment->installment_number,
                        $plan->product_name
                    ),
                    'discount' => array(
                        'type' => 'F',
                        'value' => 0
                    ),
                    'quantity' => 1
                )
            )
        );

        return $invoice_data;
    }

    /**
     * Mark installment as paid
     */
    public function mark_installment_paid($installment_id, $tap_charge_id = null) {
        $installment = $this->database->get_installment($installment_id);
        
        if (!$installment) {
            return false;
        }

        $update_data = array(
            'status' => 'paid',
            'paid_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        if ($tap_charge_id) {
            $update_data['tap_charge_id'] = $tap_charge_id;
        }

        $result = $this->database->update_installment($installment_id, $update_data);

        if ($result) {
            // Check if all installments are paid
            $this->check_plan_completion($installment->plan_id);
            
            // Add order note
            $plan = $this->database->get_installment_plan($installment->plan_id);
            if ($plan) {
                $order = wc_get_order($plan->order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('Installment %d of %d paid for %s', 'tap-payment'),
                        $installment->installment_number,
                        $plan->total_installments,
                        $plan->product_name
                    ));
                }
            }
        }

        return $result;
    }

    /**
     * Check if installment plan is completed
     */
    private function check_plan_completion($plan_id) {
        $installments = $this->database->get_installments_by_plan($plan_id);
        $all_paid = true;

        foreach ($installments as $installment) {
            if ($installment->status !== 'paid') {
                $all_paid = false;
                break;
            }
        }

        if ($all_paid) {
            $this->database->update_installment_plan($plan_id, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));

            // Add order note
            $plan = $this->database->get_installment_plan($plan_id);
            if ($plan) {
                $order = wc_get_order($plan->order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('All installments completed for %s', 'tap-payment'),
                        $plan->product_name
                    ));
                }
            }
        }
    }

    /**
     * Get overdue installments
     */
    public function get_overdue_installments() {
        return $this->database->get_overdue_installments();
    }

    /**
     * Get upcoming installments (due in next 7 days)
     */
    public function get_upcoming_installments() {
        return $this->database->get_upcoming_installments();
    }

    /**
     * Send installment reminder
     */
    public function send_installment_reminder($installment_id) {
        $installment = $this->database->get_installment($installment_id);
        
        if (!$installment || $installment->status !== 'pending') {
            return false;
        }

        $plan = $this->database->get_installment_plan($installment->plan_id);
        if (!$plan) {
            return false;
        }

        $order = wc_get_order($plan->order_id);
        if (!$order) {
            return false;
        }

        // Send email reminder
        $customer_email = $order->get_billing_email();
        $subject = sprintf(__('Payment Reminder - Installment Due for Order #%s', 'tap-payment'), $order->get_order_number());
        
        $message = sprintf(
            __('Dear %s,

This is a reminder that your installment payment is due.

Order: #%s
Product: %s
Installment: %d of %d
Amount: %s
Due Date: %s

Please make your payment to avoid any late fees.

Thank you!', 'tap-payment'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $plan->product_name,
            $installment->installment_number,
            $plan->total_installments,
            wc_price($installment->amount),
            date('F j, Y', strtotime($installment->due_date))
        );

        return wp_mail($customer_email, $subject, $message);
    }

    /**
     * AJAX handler for installment payment
     */
    public function ajax_pay_installment() {
        check_ajax_referer('tap_payment_nonce', 'nonce');

        $installment_id = intval($_POST['installment_id']);
        
        if (!$installment_id) {
            wp_send_json_error(__('Invalid installment ID.', 'tap-payment'));
        }

        $result = $this->process_installment_payment($installment_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Payment processed successfully.', 'tap-payment'),
            'redirect_url' => $result['url']
        ));
    }

    /**
     * Get customer installment summary
     */
    public function get_customer_installment_summary($customer_id) {
        $plans = $this->database->get_installment_plans_by_customer($customer_id);
        
        $summary = array(
            'total_plans' => 0,
            'active_plans' => 0,
            'completed_plans' => 0,
            'total_remaining' => 0,
            'overdue_amount' => 0,
            'next_payment_date' => null,
            'next_payment_amount' => 0
        );

        foreach ($plans as $plan) {
            $summary['total_plans']++;
            
            if ($plan->status === 'active') {
                $summary['active_plans']++;
                
                // Get remaining installments
                $installments = $this->database->get_installments_by_plan($plan->id);
                foreach ($installments as $installment) {
                    if ($installment->status === 'pending') {
                        $summary['total_remaining'] += $installment->amount;
                        
                        // Check if overdue
                        if (strtotime($installment->due_date) < time()) {
                            $summary['overdue_amount'] += $installment->amount;
                        }
                        
                        // Find next payment
                        if (!$summary['next_payment_date'] || strtotime($installment->due_date) < strtotime($summary['next_payment_date'])) {
                            $summary['next_payment_date'] = $installment->due_date;
                            $summary['next_payment_amount'] = $installment->amount;
                        }
                    }
                }
            } elseif ($plan->status === 'completed') {
                $summary['completed_plans']++;
            }
        }

        return $summary;
    }

    /**
     * Standardized calculation helper method for installment amounts
     * This ensures consistent calculation logic across all classes
     */
    public static function calculate_installment_data($current_price, $full_amount, $installments, $quantity = 1) {
        // Standardized validation
        $current_price = floatval($current_price);
        $full_amount = floatval($full_amount);
        $installments = intval($installments);
        $quantity = intval($quantity);

        // Enhanced validation to prevent invalid calculations
        if ($full_amount <= 0 || $current_price <= 0 || $installments < 2 || $full_amount <= $current_price || $quantity <= 0) {
            return false;
        }

        $remaining_amount = round(($full_amount - $current_price) * $quantity, 2);
        $installment_amount = round($remaining_amount / $installments, 2);

        return array(
            'current_price' => $current_price,
            'full_amount' => $full_amount,
            'remaining_amount' => $remaining_amount,
            'installment_amount' => $installment_amount,
            'installments' => $installments,
            'quantity' => $quantity
        );
    }
}