<?php
/**
 * Tap Payment Cron Jobs
 *
 * @package TapPayment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Tap_Payment_Cron
 */
class Tap_Payment_Cron {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Database instance
     */
    private $database;

    /**
     * Installments instance
     */
    private $installments;

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
        $this->installments = Tap_Payment_Installments::get_instance();
        $this->api_client = Tap_API_Client::get_instance();
        
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Main cron job for processing installments
        add_action('tap_payment_process_installments', array($this, 'process_installments'));
        
        // Daily reminder cron job
        add_action('tap_payment_send_reminders', array($this, 'send_payment_reminders'));
        
        // Weekly overdue notifications
        add_action('tap_payment_overdue_notifications', array($this, 'send_overdue_notifications'));
        
        // Monthly report generation
        add_action('tap_payment_monthly_report', array($this, 'generate_monthly_report'));
        
        // Schedule cron events on plugin activation
        add_action('wp', array($this, 'schedule_cron_events'));
        
        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Admin actions for manual cron execution
        add_action('wp_ajax_tap_run_cron_manually', array($this, 'ajax_run_cron_manually'));
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['weekly'] = array(
            'interval' => 604800, // 7 days
            'display' => __('Weekly', 'tap-payment')
        );
        
        $schedules['monthly'] = array(
            'interval' => 2635200, // 30.5 days
            'display' => __('Monthly', 'tap-payment')
        );
        
        return $schedules;
    }

    /**
     * Schedule cron events
     */
    public function schedule_cron_events() {
        // Daily installment processing
        if (!wp_next_scheduled('tap_payment_process_installments')) {
            wp_schedule_event(time(), 'daily', 'tap_payment_process_installments');
        }
        
        // Daily payment reminders (3 days before due date)
        if (!wp_next_scheduled('tap_payment_send_reminders')) {
            wp_schedule_event(time(), 'daily', 'tap_payment_send_reminders');
        }
        
        // Weekly overdue notifications
        if (!wp_next_scheduled('tap_payment_overdue_notifications')) {
            wp_schedule_event(time(), 'weekly', 'tap_payment_overdue_notifications');
        }
        
        // Monthly reports
        if (!wp_next_scheduled('tap_payment_monthly_report')) {
            wp_schedule_event(time(), 'monthly', 'tap_payment_monthly_report');
        }
    }

    /**
     * Main cron job to process installments
     */
    public function process_installments() {
        $this->log_cron_start('process_installments');
        
        try {
            // Get installments due today
            $due_installments = $this->database->get_installments_due_today();
            
            $processed = 0;
            $errors = 0;
            
            foreach ($due_installments as $installment) {
                $result = $this->process_single_installment($installment);
                
                if (is_wp_error($result)) {
                    $errors++;
                    $this->log_error('Failed to process installment ' . $installment->id . ': ' . $result->get_error_message());
                } else {
                    $processed++;
                }
                
                // Add small delay to avoid API rate limits
                sleep(1);
            }
            
            $this->log_cron_result('process_installments', array(
                'due_installments' => count($due_installments),
                'processed' => $processed,
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            $this->log_error('Cron process_installments failed: ' . $e->getMessage());
        }
    }

    /**
     * Process a single installment
     */
    private function process_single_installment($installment) {
        // Check if installment is still pending
        if ($installment->status !== 'pending') {
            return new WP_Error('invalid_status', 'Installment is not pending');
        }
        
        // Get installment plan
        $plan = $this->database->get_installment_plan($installment->plan_id);
        if (!$plan) {
            return new WP_Error('plan_not_found', 'Installment plan not found');
        }
        
        // Check if plan is still active
        if ($plan->status !== 'active') {
            return new WP_Error('plan_inactive', 'Installment plan is not active');
        }
        
        // Create Tap invoice
        $invoice_data = $this->prepare_installment_invoice_data($installment, $plan);
        $response = $this->api_client->create_invoice($invoice_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Update installment with invoice details
        $update_result = $this->database->update_installment($installment->id, array(
            'tap_invoice_id' => $response['id'],
            'invoice_url' => $response['url'],
            'status' => 'invoiced',
            'updated_at' => current_time('mysql')
        ));
        
        if (!$update_result) {
            return new WP_Error('update_failed', 'Failed to update installment');
        }
        
        // Send notification email
        $this->send_installment_invoice_email($installment, $plan, $response);
        
        return $response;
    }

    /**
     * Prepare invoice data for installment
     */
    private function prepare_installment_invoice_data($installment, $plan) {
        $order = wc_get_order($plan->order_id);
        
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
                __('Automated installment payment for Order #%s', 'tap-payment'),
                $order->get_order_number()
            ),
            'notifications' => array(
                'channels' => array('EMAIL'),
                'dispatch' => true
            ),
            'currencies' => array(
                get_woocommerce_currency()
            ),
            'metadata' => array(
                'order_id' => $plan->order_id,
                'plan_id' => $plan->id,
                'installment_id' => $installment->id,
                'installment_number' => $installment->installment_number,
                'automated' => true
            )
        );
        
        // Add customer information
        if ($order) {
            $invoice_data['customer'] = array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
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
     * Send payment reminders
     */
    public function send_payment_reminders() {
        $this->log_cron_start('send_reminders');
        
        try {
            // Get installments due in 3 days
            $upcoming_installments = $this->database->get_installments_due_in_days(3);
            
            $sent = 0;
            $errors = 0;
            
            foreach ($upcoming_installments as $installment) {
                $result = $this->installments->send_installment_reminder($installment->id);
                
                if ($result) {
                    $sent++;
                    
                    // Update installment to mark reminder sent
                    $this->database->update_installment($installment->id, array(
                        'reminder_sent' => 1,
                        'reminder_sent_at' => current_time('mysql')
                    ));
                } else {
                    $errors++;
                }
            }
            
            $this->log_cron_result('send_reminders', array(
                'upcoming_installments' => count($upcoming_installments),
                'sent' => $sent,
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            $this->log_error('Cron send_reminders failed: ' . $e->getMessage());
        }
    }

    /**
     * Send overdue notifications
     */
    public function send_overdue_notifications() {
        $this->log_cron_start('overdue_notifications');
        
        try {
            $overdue_installments = $this->installments->get_overdue_installments();
            
            $notifications_sent = 0;
            $errors = 0;
            
            foreach ($overdue_installments as $installment) {
                $result = $this->send_overdue_notification($installment);
                
                if ($result) {
                    $notifications_sent++;
                } else {
                    $errors++;
                }
            }
            
            $this->log_cron_result('overdue_notifications', array(
                'overdue_installments' => count($overdue_installments),
                'notifications_sent' => $notifications_sent,
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            $this->log_error('Cron overdue_notifications failed: ' . $e->getMessage());
        }
    }

    /**
     * Send overdue notification for a single installment
     */
    private function send_overdue_notification($installment) {
        $plan = $this->database->get_installment_plan($installment->plan_id);
        if (!$plan) {
            return false;
        }
        
        $order = wc_get_order($plan->order_id);
        if (!$order) {
            return false;
        }
        
        $days_overdue = floor((time() - strtotime($installment->due_date)) / (60 * 60 * 24));
        
        $customer_email = $order->get_billing_email();
        $subject = sprintf(__('OVERDUE: Payment Required for Order #%s', 'tap-payment'), $order->get_order_number());
        
        $message = sprintf(
            __('Dear %s,

Your installment payment is now %d days overdue.

Order: #%s
Product: %s
Installment: %d of %d
Amount: %s
Original Due Date: %s

Please make your payment immediately to avoid additional fees.

If you have already made this payment, please disregard this notice.

Thank you!', 'tap-payment'),
            $order->get_billing_first_name(),
            $days_overdue,
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
     * Generate monthly report
     */
    public function generate_monthly_report() {
        $this->log_cron_start('monthly_report');
        
        try {
            $report_data = $this->compile_monthly_report_data();
            $this->send_monthly_report_email($report_data);
            
            $this->log_cron_result('monthly_report', array(
                'report_generated' => true,
                'total_plans' => $report_data['total_plans'],
                'total_revenue' => $report_data['total_revenue']
            ));
            
        } catch (Exception $e) {
            $this->log_error('Cron monthly_report failed: ' . $e->getMessage());
        }
    }

    /**
     * Compile monthly report data
     */
    private function compile_monthly_report_data() {
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        
        return array(
            'period' => date('F Y', strtotime('-1 month')),
            'total_plans' => $this->database->count_installment_plans_in_period($start_date, $end_date),
            'completed_plans' => $this->database->count_completed_plans_in_period($start_date, $end_date),
            'total_revenue' => $this->database->sum_installment_payments_in_period($start_date, $end_date),
            'overdue_amount' => $this->database->sum_overdue_installments(),
            'active_plans' => $this->database->count_active_plans()
        );
    }

    /**
     * Send monthly report email
     */
    private function send_monthly_report_email($data) {
        $admin_email = get_option('admin_email');
        $subject = sprintf(__('Tap Payment Monthly Report - %s', 'tap-payment'), $data['period']);
        
        $message = sprintf(
            __('Monthly Installment Report for %s

Total New Plans: %d
Completed Plans: %d
Total Revenue: %s
Outstanding Overdue: %s
Active Plans: %d

This is an automated report generated by Tap Payment Gateway.', 'tap-payment'),
            $data['period'],
            $data['total_plans'],
            $data['completed_plans'],
            wc_price($data['total_revenue']),
            wc_price($data['overdue_amount']),
            $data['active_plans']
        );
        
        return wp_mail($admin_email, $subject, $message);
    }

    /**
     * Send installment invoice email
     */
    private function send_installment_invoice_email($installment, $plan, $invoice_response) {
        $order = wc_get_order($plan->order_id);
        if (!$order) {
            return false;
        }
        
        $customer_email = $order->get_billing_email();
        $subject = sprintf(__('Payment Due - Installment for Order #%s', 'tap-payment'), $order->get_order_number());
        
        $message = sprintf(
            __('Dear %s,

Your installment payment is now due.

Order: #%s
Product: %s
Installment: %d of %d
Amount: %s
Due Date: %s

Please click the link below to make your payment:
%s

Thank you!', 'tap-payment'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $plan->product_name,
            $installment->installment_number,
            $plan->total_installments,
            wc_price($installment->amount),
            date('F j, Y', strtotime($installment->due_date)),
            $invoice_response['url']
        );
        
        return wp_mail($customer_email, $subject, $message);
    }

    /**
     * Log cron job start
     */
    private function log_cron_start($job_name) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Tap Payment Cron: Starting {$job_name} at " . current_time('mysql'));
        }
    }

    /**
     * Log cron job result
     */
    private function log_cron_result($job_name, $data) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Tap Payment Cron: Completed {$job_name} - " . json_encode($data));
        }
    }

    /**
     * Log error
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Tap Payment Cron Error: {$message}");
        }
    }

    /**
     * AJAX handler for manual cron execution
     */
    public function ajax_run_cron_manually() {
        check_ajax_referer('tap_payment_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'tap-payment'));
        }
        
        $job = sanitize_text_field($_POST['job']);
        
        switch ($job) {
            case 'process_installments':
                $this->process_installments();
                break;
            case 'send_reminders':
                $this->send_payment_reminders();
                break;
            case 'overdue_notifications':
                $this->send_overdue_notifications();
                break;
            case 'monthly_report':
                $this->generate_monthly_report();
                break;
            default:
                wp_send_json_error(__('Invalid cron job.', 'tap-payment'));
        }
        
        wp_send_json_success(__('Cron job executed successfully.', 'tap-payment'));
    }

    /**
     * Clear all scheduled cron events
     */
    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook('tap_payment_process_installments');
        wp_clear_scheduled_hook('tap_payment_send_reminders');
        wp_clear_scheduled_hook('tap_payment_overdue_notifications');
        wp_clear_scheduled_hook('tap_payment_monthly_report');
    }
}