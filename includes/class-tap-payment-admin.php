<?php
/**
 * Tap Payment Admin Class
 * 
 * Handles admin functionality and settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Admin {

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_order_installment_info'));
        add_action('add_meta_boxes', array($this, 'add_order_meta_boxes'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Tap Payment Settings', 'tap-payment'),
            __('Tap Payment', 'tap-payment'),
            'manage_woocommerce',
            'tap-payment-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin init
     */
    public function admin_init() {
        register_setting('tap_payment_settings', 'tap_payment_settings');
        
        // Add settings sections
        add_settings_section(
            'tap_payment_general',
            __('General Settings', 'tap-payment'),
            array($this, 'general_section_callback'),
            'tap_payment_settings'
        );

        add_settings_section(
            'tap_payment_installments',
            __('Installment Settings', 'tap-payment'),
            array($this, 'installments_section_callback'),
            'tap_payment_settings'
        );

        // Add settings fields
        add_settings_field(
            'enable_installments',
            __('Enable Installments', 'tap-payment'),
            array($this, 'enable_installments_callback'),
            'tap_payment_settings',
            'tap_payment_installments'
        );

        add_settings_field(
            'default_installment_count',
            __('Default Installment Count', 'tap-payment'),
            array($this, 'default_installment_count_callback'),
            'tap_payment_settings',
            'tap_payment_installments'
        );

        add_settings_field(
            'max_installment_count',
            __('Maximum Installment Count', 'tap-payment'),
            array($this, 'max_installment_count_callback'),
            'tap_payment_settings',
            'tap_payment_installments'
        );

        add_settings_field(
            'min_installment_amount',
            __('Minimum Installment Amount', 'tap-payment'),
            array($this, 'min_installment_amount_callback'),
            'tap_payment_settings',
            'tap_payment_installments'
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_tap-payment-settings' === $hook || 'post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_script(
                'tap-payment-admin',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
                array('jquery'),
                TAP_PAYMENT_VERSION,
                true
            );

            wp_enqueue_style(
                'tap-payment-admin',
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
                array(),
                TAP_PAYMENT_VERSION
            );

            wp_localize_script('tap-payment-admin', 'tapPaymentAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tap_payment_admin'),
                'strings' => array(
                    'confirmDelete' => __('Are you sure you want to delete this installment plan?', 'tap-payment'),
                    'processing' => __('Processing...', 'tap-payment'),
                    'error' => __('An error occurred. Please try again.', 'tap-payment')
                )
            ));
        }
    }



    /**
     * Admin page
     */
    public function admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tap Payment Settings', 'tap-payment'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=tap-payment-settings&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'tap-payment'); ?>
                </a>
                <a href="?page=tap-payment-settings&tab=installments" class="nav-tab <?php echo $active_tab === 'installments' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Installments', 'tap-payment'); ?>
                </a>
                <a href="?page=tap-payment-settings&tab=reports" class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Reports', 'tap-payment'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'installments':
                        $this->installments_tab();
                        break;
                    case 'reports':
                        $this->reports_tab();
                        break;
                    default:
                        $this->settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Settings tab
     */
    private function settings_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('tap_payment_settings');
            do_settings_sections('tap_payment_settings');
            submit_button();
            ?>
        </form>

        <div class="tap-payment-info">
            <h3><?php esc_html_e('Gateway Configuration', 'tap-payment'); ?></h3>
            <p><?php esc_html_e('The main payment gateway settings can be configured in WooCommerce > Settings > Payments > Tap Payment.', 'tap-payment'); ?></p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=tap_payment')); ?>" class="button button-primary">
                    <?php esc_html_e('Configure Payment Gateway', 'tap-payment'); ?>
                </a>
            </p>

            <h3><?php esc_html_e('Webhook URL', 'tap-payment'); ?></h3>
            <p><?php esc_html_e('Configure this webhook URL in your Tap dashboard:', 'tap-payment'); ?></p>
            <code><?php echo esc_url(add_query_arg('wc-api', 'tap_payment_webhook', home_url('/'))); ?></code>
        </div>
        <?php
    }

    /**
     * Installments tab
     */
    private function installments_tab() {
        $installment_plans = $this->get_recent_installment_plans();
        ?>
        <div class="tap-installments-overview">
            <h3><?php esc_html_e('Recent Installment Plans', 'tap-payment'); ?></h3>
            
            <?php if (!empty($installment_plans)) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Customer', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Product', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Total Amount', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Down Payment', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Installments', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Status', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Created', 'tap-payment'); ?></th>
                            <th><?php esc_html_e('Actions', 'tap-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($installment_plans as $plan) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $plan->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html($plan->order_id); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $user = get_user_by('id', $plan->user_id);
                                    echo $user ? esc_html($user->display_name) : esc_html__('Guest', 'tap-payment');
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $product = wc_get_product($plan->product_id);
                                    echo $product ? esc_html($product->get_name()) : esc_html__('Product not found', 'tap-payment');
                                    ?>
                                </td>
                                <td><?php echo wc_price($plan->total_amount); ?></td>
                                <td><?php echo wc_price($plan->down_payment); ?></td>
                                <td><?php echo esc_html($plan->installment_count); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($plan->status); ?>">
                                        <?php echo esc_html(ucfirst($plan->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($plan->created_at))); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tap-payment-settings&tab=installments&action=view&plan_id=' . $plan->id)); ?>" class="button button-small">
                                        <?php esc_html_e('View', 'tap-payment'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e('No installment plans found.', 'tap-payment'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Reports tab
     */
    private function reports_tab() {
        $stats = $this->get_payment_statistics();
        ?>
        <div class="tap-payment-reports">
            <h3><?php esc_html_e('Payment Statistics', 'tap-payment'); ?></h3>
            
            <div class="tap-stats-grid">
                <div class="stat-box">
                    <h4><?php esc_html_e('Total Payments', 'tap-payment'); ?></h4>
                    <span class="stat-number"><?php echo esc_html($stats->total_payments ?? 0); ?></span>
                </div>
                
                <div class="stat-box">
                    <h4><?php esc_html_e('Total Amount', 'tap-payment'); ?></h4>
                    <span class="stat-number"><?php echo wc_price($stats->total_amount ?? 0); ?></span>
                </div>
                
                <div class="stat-box">
                    <h4><?php esc_html_e('Initial Payments', 'tap-payment'); ?></h4>
                    <span class="stat-number"><?php echo esc_html($stats->initial_payments ?? 0); ?></span>
                </div>
                
                <div class="stat-box">
                    <h4><?php esc_html_e('Installment Payments', 'tap-payment'); ?></h4>
                    <span class="stat-number"><?php echo esc_html($stats->installment_payments ?? 0); ?></span>
                </div>
                
                <div class="stat-box">
                    <h4><?php esc_html_e('Active Plans', 'tap-payment'); ?></h4>
                    <span class="stat-number"><?php echo esc_html($stats->total_plans ?? 0); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * General section callback
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure general Tap Payment settings.', 'tap-payment') . '</p>';
    }

    /**
     * Installments section callback
     */
    public function installments_section_callback() {
        echo '<p>' . esc_html__('Configure installment-specific settings.', 'tap-payment') . '</p>';
    }

    /**
     * Enable installments callback
     */
    public function enable_installments_callback() {
        $options = get_option('tap_payment_settings', array());
        $value = isset($options['enable_installments']) ? $options['enable_installments'] : 'yes';
        ?>
        <input type="checkbox" id="enable_installments" name="tap_payment_settings[enable_installments]" value="yes" <?php checked($value, 'yes'); ?> />
        <label for="enable_installments"><?php esc_html_e('Enable installment functionality', 'tap-payment'); ?></label>
        <?php
    }

    /**
     * Default installment count callback
     */
    public function default_installment_count_callback() {
        $options = get_option('tap_payment_settings', array());
        $value = isset($options['default_installment_count']) ? $options['default_installment_count'] : 3;
        ?>
        <input type="number" id="default_installment_count" name="tap_payment_settings[default_installment_count]" value="<?php echo esc_attr($value); ?>" min="1" max="12" />
        <p class="description"><?php esc_html_e('Default number of installments for products.', 'tap-payment'); ?></p>
        <?php
    }

    /**
     * Max installment count callback
     */
    public function max_installment_count_callback() {
        $options = get_option('tap_payment_settings', array());
        $value = isset($options['max_installment_count']) ? $options['max_installment_count'] : 12;
        ?>
        <input type="number" id="max_installment_count" name="tap_payment_settings[max_installment_count]" value="<?php echo esc_attr($value); ?>" min="1" max="24" />
        <p class="description"><?php esc_html_e('Maximum number of installments allowed.', 'tap-payment'); ?></p>
        <?php
    }

    /**
     * Min installment amount callback
     */
    public function min_installment_amount_callback() {
        $options = get_option('tap_payment_settings', array());
        $value = isset($options['min_installment_amount']) ? $options['min_installment_amount'] : 10;
        ?>
        <input type="number" id="min_installment_amount" name="tap_payment_settings[min_installment_amount]" value="<?php echo esc_attr($value); ?>" min="1" step="0.01" />
        <p class="description"><?php esc_html_e('Minimum amount per installment.', 'tap-payment'); ?></p>
        <?php
    }

    /**
     * Display order installment info
     */
    public function display_order_installment_info($order) {
        $payment_type = $order->get_meta('_tap_payment_type');
        
        if ($payment_type !== 'installment') {
            return;
        }

        $plans = Tap_Payment_Database::get_installment_plans_by_order($order->get_id());
        
        if (empty($plans)) {
            return;
        }

        echo '<div class="tap-order-installments">';
        echo '<h3>' . esc_html__('Installment Plans', 'tap-payment') . '</h3>';
        
        foreach ($plans as $plan) {
            $installments = Tap_Payment_Database::get_installments_by_plan($plan->id);
            $product = wc_get_product($plan->product_id);
            
            echo '<div class="installment-plan">';
            echo '<h4>' . esc_html($product ? $product->get_name() : 'Product not found') . '</h4>';
            echo '<p><strong>' . esc_html__('Status:', 'tap-payment') . '</strong> ' . esc_html(ucfirst($plan->status)) . '</p>';
            echo '<p><strong>' . esc_html__('Total Amount:', 'tap-payment') . '</strong> ' . wc_price($plan->total_amount) . '</p>';
            echo '<p><strong>' . esc_html__('Down Payment:', 'tap-payment') . '</strong> ' . wc_price($plan->down_payment) . '</p>';
            echo '<p><strong>' . esc_html__('Remaining:', 'tap-payment') . '</strong> ' . wc_price(round($plan->total_amount - $plan->down_payment, 2)) . '</p>';
            
            if (!empty($installments)) {
                echo '<table class="widefat">';
                echo '<thead><tr><th>' . esc_html__('Installment', 'tap-payment') . '</th><th>' . esc_html__('Amount', 'tap-payment') . '</th><th>' . esc_html__('Due Date', 'tap-payment') . '</th><th>' . esc_html__('Status', 'tap-payment') . '</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($installments as $installment) {
                    echo '<tr>';
                    echo '<td>' . esc_html($installment->installment_number) . '</td>';
                    echo '<td>' . wc_price($installment->amount) . '</td>';
                    echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($installment->due_date))) . '</td>';
                    echo '<td><span class="status-' . esc_attr($installment->status) . '">' . esc_html(ucfirst($installment->status)) . '</span></td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * Add order meta boxes
     */
    public function add_order_meta_boxes() {
        // Traditional post-based orders
        add_meta_box(
            'tap-payment-actions',
            __('Tap Payment Actions', 'tap-payment'),
            array($this, 'order_actions_meta_box'),
            'shop_order',
            'side',
            'high'
        );

        // HPOS orders - register for the HPOS screen
        if (function_exists('wc_get_page_screen_id')) {
            $hpos_screen = wc_get_page_screen_id('shop-order');
            if ($hpos_screen && $hpos_screen !== 'shop_order') {
                add_meta_box(
                    'tap-payment-actions',
                    __('Tap Payment Actions', 'tap-payment'),
                    array($this, 'order_actions_meta_box'),
                    $hpos_screen,
                    'side',
                    'high'
                );
            }
        }
    }

    /**
     * Order actions meta box
     */
    public function order_actions_meta_box($post) {
        $order = wc_get_order($post->ID);
        
        if (!$order || $order->get_payment_method() !== 'tap_payment') {
            echo '<p>' . esc_html__('This order was not paid with Tap Payment.', 'tap-payment') . '</p>';
            return;
        }

        $charge_id = $order->get_meta('_tap_charge_id');
        $payment_type = $order->get_meta('_tap_payment_type');
        
        echo '<div class="tap-order-actions">';
        
        if ($charge_id) {
            echo '<p><strong>' . esc_html__('Charge ID:', 'tap-payment') . '</strong><br><code>' . esc_html($charge_id) . '</code></p>';
        }
        
        if ($payment_type) {
            echo '<p><strong>' . esc_html__('Payment Type:', 'tap-payment') . '</strong><br>' . esc_html(ucfirst($payment_type)) . '</p>';
        }
        
        echo '</div>';
    }

    /**
     * Get recent installment plans
     */
    private function get_recent_installment_plans($limit = 20) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Get payment statistics
     */
    private function get_payment_statistics() {
        return Tap_Payment_Database::get_payment_stats();
    }
}