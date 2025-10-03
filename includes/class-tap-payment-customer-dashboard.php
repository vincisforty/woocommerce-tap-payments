<?php
/**
 * Tap Payment Customer Dashboard
 *
 * @package TapPayment
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Customer_Dashboard {

    /**
     * Instance
     */
    private static $instance = null;

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
        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize
     */
    public function init() {
        // Add installments tab to My Account
        add_filter('woocommerce_account_menu_items', array($this, 'add_installments_tab'));
        add_action('woocommerce_account_installments_endpoint', array($this, 'installments_content'));
        
        // Add rewrite endpoint
        add_rewrite_endpoint('installments', EP_ROOT | EP_PAGES);
        
        // AJAX handlers
        add_action('wp_ajax_tap_pay_installment', array($this, 'handle_installment_payment'));
        add_action('wp_ajax_tap_get_installment_details', array($this, 'get_installment_details'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add installments tab to My Account menu
     */
    public function add_installments_tab($items) {
        // Insert installments tab before logout
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['installments'] = __('Installments', 'tap-payment');
        $items['customer-logout'] = $logout;
        
        return $items;
    }

    /**
     * Installments tab content
     */
    public function installments_content() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            return;
        }

        // Get customer's installment plans
        $installment_plans = Tap_Payment_Database::get_customer_installment_plans($customer_id);
        
        $this->display_installments_dashboard($installment_plans);
    }

    /**
     * Display installments dashboard
     */
    private function display_installments_dashboard($installment_plans) {
        ?>
        <div class="tap-installments-dashboard">
            <h3><?php _e('My Installment Plans', 'tap-payment'); ?></h3>
            
            <?php if (empty($installment_plans)): ?>
                <div class="woocommerce-message">
                    <?php _e('You have no installment plans yet.', 'tap-payment'); ?>
                </div>
            <?php else: ?>
                <div class="tap-installments-summary">
                    <?php $this->display_installments_summary($installment_plans); ?>
                </div>
                
                <div class="tap-installments-list">
                    <?php foreach ($installment_plans as $plan): ?>
                        <?php $this->display_installment_plan($plan); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display installments summary
     */
    private function display_installments_summary($plans) {
        $total_plans = count($plans);
        $active_plans = 0;
        $completed_plans = 0;
        $total_remaining = 0;
        $overdue_count = 0;

        foreach ($plans as $plan) {
            if ($plan->status === 'active') {
                $active_plans++;
                $remaining = $this->get_plan_remaining_amount($plan->id);
                $total_remaining += round($remaining, 2);
                
                if ($this->has_overdue_installments($plan->id)) {
                    $overdue_count++;
                }
            } elseif ($plan->status === 'completed') {
                $completed_plans++;
            }
        }
        ?>
        <div class="tap-summary-cards">
            <div class="tap-summary-card">
                <h4><?php _e('Total Plans', 'tap-payment'); ?></h4>
                <span class="tap-summary-number"><?php echo $total_plans; ?></span>
            </div>
            <div class="tap-summary-card">
                <h4><?php _e('Active Plans', 'tap-payment'); ?></h4>
                <span class="tap-summary-number"><?php echo $active_plans; ?></span>
            </div>
            <div class="tap-summary-card">
                <h4><?php _e('Completed Plans', 'tap-payment'); ?></h4>
                <span class="tap-summary-number"><?php echo $completed_plans; ?></span>
            </div>
            <div class="tap-summary-card">
                <h4><?php _e('Total Remaining', 'tap-payment'); ?></h4>
                <span class="tap-summary-amount"><?php echo wc_price($total_remaining); ?></span>
            </div>
            <?php if ($overdue_count > 0): ?>
            <div class="tap-summary-card tap-overdue">
                <h4><?php _e('Overdue Plans', 'tap-payment'); ?></h4>
                <span class="tap-summary-number"><?php echo $overdue_count; ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display single installment plan
     */
    private function display_installment_plan($plan) {
        $order = wc_get_order($plan->order_id);
        $installments = Tap_Payment_Database::get_installments_by_plan($plan->id);
        $next_installment = $this->get_next_installment($installments);
        $progress = $this->calculate_plan_progress($installments);
        ?>
        <div class="tap-installment-plan" data-plan-id="<?php echo esc_attr($plan->id); ?>">
            <div class="tap-plan-header">
                <div class="tap-plan-info">
                    <h4>
                        <?php printf(__('Order #%s', 'tap-payment'), $order ? $order->get_order_number() : $plan->order_id); ?>
                        <span class="tap-plan-status tap-status-<?php echo esc_attr($plan->status); ?>">
                            <?php echo esc_html(ucfirst($plan->status)); ?>
                        </span>
                    </h4>
                    <p class="tap-plan-details">
                        <?php printf(
                            __('%d installments of %s', 'tap-payment'),
                            $plan->total_installments,
                            wc_price($plan->installment_amount)
                        ); ?>
                    </p>
                </div>
                <div class="tap-plan-actions">
                    <button type="button" class="button tap-toggle-details" data-plan-id="<?php echo esc_attr($plan->id); ?>">
                        <?php _e('View Details', 'tap-payment'); ?>
                    </button>
                </div>
            </div>
            
            <div class="tap-plan-progress">
                <div class="tap-progress-bar">
                    <div class="tap-progress-fill" style="width: <?php echo esc_attr($progress); ?>%"></div>
                </div>
                <span class="tap-progress-text"><?php echo esc_html($progress); ?>% <?php _e('Complete', 'tap-payment'); ?></span>
            </div>

            <?php if ($next_installment && $plan->status === 'active'): ?>
            <div class="tap-next-payment">
                <div class="tap-next-info">
                    <strong><?php _e('Next Payment:', 'tap-payment'); ?></strong>
                    <?php echo wc_price($next_installment->amount); ?>
                    <span class="tap-due-date">
                        <?php printf(__('Due: %s', 'tap-payment'), date_i18n(get_option('date_format'), strtotime($next_installment->due_date))); ?>
                    </span>
                </div>
                <?php if ($next_installment->status === 'pending'): ?>
                <button type="button" class="button button-primary tap-pay-now" 
                        data-installment-id="<?php echo esc_attr($next_installment->id); ?>">
                    <?php _e('Pay Now', 'tap-payment'); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tap-plan-details" id="plan-details-<?php echo esc_attr($plan->id); ?>" style="display: none;">
                <div class="tap-installments-table">
                    <table class="shop_table">
                        <thead>
                            <tr>
                                <th><?php _e('#', 'tap-payment'); ?></th>
                                <th><?php _e('Amount', 'tap-payment'); ?></th>
                                <th><?php _e('Due Date', 'tap-payment'); ?></th>
                                <th><?php _e('Status', 'tap-payment'); ?></th>
                                <th><?php _e('Action', 'tap-payment'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($installments as $installment): ?>
                            <tr class="tap-installment-row tap-status-<?php echo esc_attr($installment->status); ?>">
                                <td><?php echo esc_html($installment->installment_number); ?></td>
                                <td><?php echo wc_price($installment->amount); ?></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($installment->due_date)); ?></td>
                                <td>
                                    <span class="tap-installment-status tap-status-<?php echo esc_attr($installment->status); ?>">
                                        <?php echo esc_html(ucfirst($installment->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($installment->status === 'pending'): ?>
                                        <button type="button" class="button button-small tap-pay-installment" 
                                                data-installment-id="<?php echo esc_attr($installment->id); ?>">
                                            <?php _e('Pay', 'tap-payment'); ?>
                                        </button>
                                    <?php elseif ($installment->status === 'paid'): ?>
                                        <span class="tap-paid-date">
                                            <?php echo date_i18n(get_option('date_format'), strtotime($installment->paid_at)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle installment payment AJAX
     */
    public function handle_installment_payment() {
        check_ajax_referer('tap_payment_nonce', 'nonce');

        $installment_id = intval($_POST['installment_id']);
        $customer_id = get_current_user_id();

        if (!$customer_id || !$installment_id) {
            wp_send_json_error(array('message' => __('Invalid request', 'tap-payment')));
        }

        // Get installment details
        $installment = Tap_Payment_Database::get_installment($installment_id);
        
        if (!$installment) {
            wp_send_json_error(array('message' => __('Installment not found', 'tap-payment')));
        }

        // Get plan and verify ownership
        $plan = Tap_Payment_Database::get_installment_plan($installment->plan_id);
        
        if (!$plan || $plan->customer_id != $customer_id) {
            wp_send_json_error(array('message' => __('Access denied', 'tap-payment')));
        }

        // Process payment through Tap API
        try {
            $api_client = Tap_API_Client::get_instance();
            $charge_data = $api_client->create_charge_for_installment($installment, $plan);

            if ($charge_data && isset($charge_data['transaction']['url'])) {
                wp_send_json_success(array(
                    'redirect_url' => $charge_data['transaction']['url']
                ));
            } else {
                wp_send_json_error(array('message' => __('Payment processing failed', 'tap-payment')));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Get installment details AJAX
     */
    public function get_installment_details() {
        check_ajax_referer('tap_payment_nonce', 'nonce');

        $installment_id = intval($_POST['installment_id']);
        $customer_id = get_current_user_id();

        if (!$customer_id || !$installment_id) {
            wp_send_json_error(array('message' => __('Invalid request', 'tap-payment')));
        }

        $installment = Tap_Payment_Database::get_installment($installment_id);
        
        if (!$installment) {
            wp_send_json_error(array('message' => __('Installment not found', 'tap-payment')));
        }

        // Get plan and verify ownership
        $plan = Tap_Payment_Database::get_installment_plan($installment->plan_id);
        
        if (!$plan || $plan->customer_id != $customer_id) {
            wp_send_json_error(array('message' => __('Access denied', 'tap-payment')));
        }

        wp_send_json_success(array(
            'installment' => $installment,
            'plan' => $plan
        ));
    }

    /**
     * Get next pending installment
     */
    private function get_next_installment($installments) {
        foreach ($installments as $installment) {
            if ($installment->status === 'pending') {
                return $installment;
            }
        }
        return null;
    }

    /**
     * Calculate plan progress percentage
     */
    private function calculate_plan_progress($installments) {
        $total = count($installments);
        $paid = 0;

        foreach ($installments as $installment) {
            if ($installment->status === 'paid') {
                $paid++;
            }
        }

        return $total > 0 ? round(($paid / $total) * 100, 2) : 0;
    }

    /**
     * Get plan remaining amount
     */
    private function get_plan_remaining_amount($plan_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_installments';
        
        $remaining = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(amount) FROM $table WHERE plan_id = %d AND status = 'pending'",
                $plan_id
            )
        );

        return $remaining ? round(floatval($remaining), 2) : 0;
    }

    /**
     * Check if plan has overdue installments
     */
    private function has_overdue_installments($plan_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'tap_installments';
        
        $overdue_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE plan_id = %d AND status = 'pending' AND due_date < %s",
                $plan_id,
                current_time('Y-m-d')
            )
        );

        return $overdue_count > 0;
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if (is_account_page()) {
            wp_enqueue_script(
                'tap-customer-dashboard',
                TAP_PAYMENT_PLUGIN_URL . 'assets/js/customer-dashboard.js',
                array('jquery'),
                TAP_PAYMENT_VERSION,
                true
            );

            wp_localize_script('tap-customer-dashboard', 'tap_customer_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tap_payment_nonce'),
                'messages' => array(
                    'confirm_payment' => __('Are you sure you want to proceed with this payment?', 'tap-payment'),
                    'processing' => __('Processing...', 'tap-payment'),
                    'error' => __('An error occurred. Please try again.', 'tap-payment')
                )
            ));
        }
    }

    /**
     * Flush rewrite rules on activation
     */
    public static function flush_rewrite_rules() {
        add_rewrite_endpoint('installments', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }
}