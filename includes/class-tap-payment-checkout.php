<?php
/**
 * Tap Payment Checkout Integration
 *
 * @package TapPayment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Tap_Payment_Checkout
 */
class Tap_Payment_Checkout {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_review_order_after_payment', array($this, 'display_installment_summary'));
        add_action('woocommerce_thankyou', array($this, 'display_thankyou_installments'), 10, 1);
        add_action('wp_ajax_tap_get_checkout_installments', array($this, 'ajax_get_checkout_installments'));
        add_action('wp_ajax_nopriv_tap_get_checkout_installments', array($this, 'ajax_get_checkout_installments'));
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        if (is_checkout() || is_account_page() || is_product()) {
            wp_enqueue_script(
                'tap-payment-frontend',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/frontend.js',
                array('jquery'),
                TAP_PAYMENT_VERSION,
                true
            );

            wp_localize_script('tap-payment-frontend', 'tapFrontendAjax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tap_frontend_nonce'),
                'payment_url' => wc_get_checkout_url()
            ));
        }
    }

    /**
     * Display installment summary on checkout page
     */
    public function display_installment_summary() {
        if (!$this->has_installment_products()) {
            return;
        }

        echo '<div class="tap-installment-summary" style="display: none;">';
        echo '<h3>' . __('Installment Plan Summary', 'tap-payment') . '</h3>';
        echo '<div class="installment-content"></div>';
        echo '</div>';
    }

    /**
     * Check if cart has installment products
     */
    private function has_installment_products() {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            if ($product->is_type('variable')) {
                $variation_id = $cart_item['variation_id'];
                $enable_installment = get_post_meta($variation_id, '_tap_enable_installment', true);
            } else {
                $enable_installment = get_post_meta($product->get_id(), '_tap_enable_installment', true);
            }

            if ($enable_installment === 'yes') {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX handler for getting checkout installments
     */
    public function ajax_get_checkout_installments() {
        check_ajax_referer('tap_frontend_nonce', 'nonce');

        if (!WC()->cart) {
            wp_send_json_error('Cart not available');
        }

        $installment_items = array();
        $total_initial_payment = 0;
        $total_installment_amount = 0;
        $max_installments = 0;

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];

            if ($product->is_type('variable')) {
                $variation_id = $cart_item['variation_id'];
                $enable_installment = get_post_meta($variation_id, '_tap_enable_installment', true);
                $full_amount = get_post_meta($variation_id, '_tap_full_amount', true);
                $installments = get_post_meta($variation_id, '_tap_installments', true);
            } else {
                $enable_installment = get_post_meta($product->get_id(), '_tap_enable_installment', true);
                $full_amount = get_post_meta($product->get_id(), '_tap_full_amount', true);
                $installments = get_post_meta($product->get_id(), '_tap_installments', true);
            }

            if ($enable_installment === 'yes' && $full_amount && $installments) {
                $current_price = $product->get_price();
                
                // Use standardized calculation method for consistency
                $calc_data = Tap_Payment_Installments::calculate_installment_data($current_price, $full_amount, $installments, $quantity);
                
                if ($calc_data !== false) {
                    $installment_items[] = array(
                        'name' => $product->get_name(),
                        'quantity' => $calc_data['quantity'],
                        'current_price' => $calc_data['current_price'],
                        'full_amount' => $calc_data['full_amount'],
                        'remaining_amount' => $calc_data['remaining_amount'],
                        'installments' => $calc_data['installments'],
                        'installment_amount' => $calc_data['installment_amount']
                    );

                    $total_initial_payment += $calc_data['current_price'] * $calc_data['quantity'];
                    $total_installment_amount += $calc_data['remaining_amount'];
                    $max_installments = max($max_installments, $calc_data['installments']);
                }
            }
        }

        if (empty($installment_items)) {
            wp_send_json_error('No installment products found');
        }

        $html = $this->generate_installment_summary_html($installment_items, $total_initial_payment, $total_installment_amount, $max_installments);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Generate installment summary HTML
     */
    private function generate_installment_summary_html($items, $total_initial, $total_installment, $max_installments) {
        ob_start();
        ?>
        <div class="tap-installment-details">
            <div class="installment-overview">
                <div class="overview-item">
                    <span class="label"><?php _e('Pay Today:', 'tap-payment'); ?></span>
                    <span class="amount"><?php echo wc_price($total_initial); ?></span>
                </div>
                <div class="overview-item">
                    <span class="label"><?php _e('Remaining Amount:', 'tap-payment'); ?></span>
                    <span class="amount"><?php echo wc_price($total_installment); ?></span>
                </div>
                <div class="overview-item">
                    <span class="label"><?php printf(__('Monthly Payment (%d installments):', 'tap-payment'), $max_installments); ?></span>
                    <span class="amount highlight"><?php echo wc_price($max_installments > 0 ? round($total_installment / $max_installments, 2) : 0); ?></span>
                </div>
                <div class="overview-item total">
                    <span class="label"><?php _e('Total Amount:', 'tap-payment'); ?></span>
                    <span class="amount"><?php echo wc_price($total_initial + $total_installment); ?></span>
                </div>
            </div>

            <div class="installment-breakdown">
                <h4><?php _e('Installment Breakdown:', 'tap-payment'); ?></h4>
                <?php foreach ($items as $item): ?>
                    <div class="item-installment">
                        <div class="item-name">
                            <?php echo esc_html($item['name']); ?>
                            <?php if ($item['quantity'] > 1): ?>
                                <span class="quantity">(x<?php echo $item['quantity']; ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-details">
                            <span><?php echo wc_price($item['current_price']); ?> + <?php echo $item['installments']; ?> × <?php echo wc_price($item['installment_amount']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="installment-schedule">
                <h4><?php _e('Payment Schedule:', 'tap-payment'); ?></h4>
                <div class="schedule-item">
                    <span class="date"><?php _e('Today', 'tap-payment'); ?></span>
                    <span class="amount"><?php echo wc_price($total_initial); ?></span>
                </div>
                <?php for ($i = 1; $i <= $max_installments; $i++): ?>
                    <div class="schedule-item">
                        <span class="date">
                            <?php 
                            $date = date('M j, Y', strtotime("+{$i} month"));
                            echo $date;
                            ?>
                        </span>
                        <span class="amount"><?php echo wc_price($max_installments > 0 ? round($total_installment / $max_installments, 2) : 0); ?></span>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="installment-notice">
                <p><?php _e('* Installment invoices will be sent automatically each month. You can manage your installments from your account dashboard.', 'tap-payment'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Display installments on thank you page
     */
    public function display_thankyou_installments($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'tap_payment') {
            return;
        }

        $database = Tap_Payment_Database::get_instance();
        $installment_plans = $database->get_installment_plans_by_order($order_id);

        if (empty($installment_plans)) {
            return;
        }

        ?>
        <div class="tap-thankyou-installments">
            <h2><?php _e('Your Installment Plans', 'tap-payment'); ?></h2>
            
            <?php foreach ($installment_plans as $plan): ?>
                <div class="installment-plan">
                    <h3><?php echo esc_html($plan->product_name); ?></h3>
                    
                    <div class="plan-summary">
                        <div class="summary-item">
                            <span class="label"><?php _e('Initial Payment:', 'tap-payment'); ?></span>
                            <span class="amount"><?php echo wc_price($plan->initial_payment); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label"><?php _e('Remaining Amount:', 'tap-payment'); ?></span>
                            <span class="amount"><?php echo wc_price($plan->remaining_amount); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label"><?php _e('Monthly Payment:', 'tap-payment'); ?></span>
                            <span class="amount"><?php echo wc_price($plan->installment_amount); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label"><?php _e('Number of Installments:', 'tap-payment'); ?></span>
                            <span class="amount"><?php echo $plan->total_installments; ?></span>
                        </div>
                    </div>

                    <?php
                    $installments = $database->get_installments_by_plan($plan->id);
                    if (!empty($installments)):
                    ?>
                        <div class="installment-schedule">
                            <h4><?php _e('Payment Schedule:', 'tap-payment'); ?></h4>
                            <table class="installment-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Installment', 'tap-payment'); ?></th>
                                        <th><?php _e('Due Date', 'tap-payment'); ?></th>
                                        <th><?php _e('Amount', 'tap-payment'); ?></th>
                                        <th><?php _e('Status', 'tap-payment'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($installments as $installment): ?>
                                        <tr>
                                            <td><?php echo $installment->installment_number; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($installment->due_date)); ?></td>
                                            <td><?php echo wc_price($installment->amount); ?></td>
                                            <td>
                                                <span class="status status-<?php echo esc_attr($installment->status); ?>">
                                                    <?php echo ucfirst($installment->status); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="installment-info">
                <p><?php _e('You can view and manage your installments from your account dashboard.', 'tap-payment'); ?></p>
                <a href="<?php echo wc_get_account_endpoint_url('tap-installments'); ?>" class="button">
                    <?php _e('Manage Installments', 'tap-payment'); ?>
                </a>
            </div>
        </div>
        <?php
    }
}