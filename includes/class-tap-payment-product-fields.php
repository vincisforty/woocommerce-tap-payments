<?php
/**
 * Tap Payment Product Fields Class
 * 
 * Handles product and variation installment fields
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Product_Fields {

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
        // Simple product fields
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_simple_product_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_simple_product_fields'));

        // Variable product fields
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_fields'), 10, 2);

        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Display installment info on frontend
        add_action('woocommerce_single_product_summary', array($this, 'display_installment_info'), 25);
        add_action('woocommerce_after_shop_loop_item_title', array($this, 'display_shop_installment_info'), 15);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        global $post_type;
        
        if ('product' === $post_type && ('post.php' === $hook || 'post-new.php' === $hook)) {
            wp_enqueue_script(
                'tap-payment-product-fields',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/product-fields.js',
                array('jquery'),
                TAP_PAYMENT_VERSION,
                true
            );

            wp_localize_script('tap-payment-product-fields', 'tapPaymentProduct', array(
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'strings' => array(
                    'installment_amount' => __('Installment Amount:', 'tap-payment'),
                    'invalid_amount' => __('Full amount must be greater than the regular price.', 'tap-payment'),
                    'invalid_count' => __('Installment count must be between 1 and 24.', 'tap-payment')
                )
            ));
        }
    }

    /**
     * Add simple product fields
     */
    public function add_simple_product_fields() {
        global $post;

        echo '<div class="options_group tap-installment-fields">';
        
        // Enable installment checkbox
        woocommerce_wp_checkbox(array(
            'id' => '_tap_enable_installment',
            'label' => __('Enable Installments', 'tap-payment'),
            'description' => __('Enable installment payments for this product.', 'tap-payment'),
            'desc_tip' => true,
        ));

        // Full amount field
        woocommerce_wp_text_input(array(
            'id' => '_tap_full_amount',
            'label' => __('Full Amount', 'tap-payment') . ' (' . get_woocommerce_currency_symbol() . ')',
            'description' => __('The total amount to be paid including installments. Must be greater than the regular price.', 'tap-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '0.01',
                'min' => '0'
            ),
            'wrapper_class' => 'tap-installment-field'
        ));

        // Installment count field
        woocommerce_wp_select(array(
            'id' => '_tap_installment_count',
            'label' => __('Number of Installments', 'tap-payment'),
            'description' => __('Number of monthly installments.', 'tap-payment'),
            'desc_tip' => true,
            'options' => $this->get_installment_count_options(),
            'wrapper_class' => 'tap-installment-field'
        ));

        // Installment preview
        echo '<div class="tap-installment-preview" style="display: none;">';
        echo '<p class="form-field">';
        echo '<label>' . esc_html__('Installment Preview', 'tap-payment') . '</label>';
        echo '<span class="tap-installment-preview-text"></span>';
        echo '</p>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Save simple product fields
     */
    public function save_simple_product_fields($post_id) {
        // Enable installment
        $enable_installment = isset($_POST['_tap_enable_installment']) ? 'yes' : 'no';
        update_post_meta($post_id, '_tap_enable_installment', $enable_installment);

        // Full amount
        if (isset($_POST['_tap_full_amount'])) {
            $full_amount = sanitize_text_field($_POST['_tap_full_amount']);
            update_post_meta($post_id, '_tap_full_amount', $full_amount);
        }

        // Installment count
        if (isset($_POST['_tap_installment_count'])) {
            $installment_count = intval($_POST['_tap_installment_count']);
            update_post_meta($post_id, '_tap_installment_count', $installment_count);
        }
    }

    /**
     * Add variation fields
     */
    public function add_variation_fields($loop, $variation_data, $variation) {
        $variation_id = $variation->ID;

        echo '<div class="tap-variation-installment-fields">';
        echo '<h4>' . esc_html__('Tap Payment Installments', 'tap-payment') . '</h4>';

        // Enable installment checkbox
        woocommerce_wp_checkbox(array(
            'id' => '_tap_enable_installment[' . $loop . ']',
            'name' => '_tap_enable_installment[' . $loop . ']',
            'label' => __('Enable Installments', 'tap-payment'),
            'description' => __('Enable installment payments for this variation.', 'tap-payment'),
            'desc_tip' => true,
            'value' => get_post_meta($variation_id, '_tap_enable_installment', true),
            'wrapper_class' => 'form-row form-row-first'
        ));

        // Full amount field
        woocommerce_wp_text_input(array(
            'id' => '_tap_full_amount[' . $loop . ']',
            'name' => '_tap_full_amount[' . $loop . ']',
            'label' => __('Full Amount', 'tap-payment') . ' (' . get_woocommerce_currency_symbol() . ')',
            'description' => __('The total amount to be paid including installments.', 'tap-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '0.01',
                'min' => '0'
            ),
            'value' => get_post_meta($variation_id, '_tap_full_amount', true),
            'wrapper_class' => 'form-row form-row-last tap-variation-installment-field'
        ));

        // Installment count field
        woocommerce_wp_select(array(
            'id' => '_tap_installment_count[' . $loop . ']',
            'name' => '_tap_installment_count[' . $loop . ']',
            'label' => __('Number of Installments', 'tap-payment'),
            'description' => __('Number of monthly installments.', 'tap-payment'),
            'desc_tip' => true,
            'options' => $this->get_installment_count_options(),
            'value' => get_post_meta($variation_id, '_tap_installment_count', true),
            'wrapper_class' => 'form-row form-row-first tap-variation-installment-field'
        ));

        // Installment preview for variation
        echo '<div class="form-row form-row-last">';
        echo '<div class="tap-variation-installment-preview" data-loop="' . esc_attr($loop) . '" style="display: none;">';
        echo '<label>' . esc_html__('Installment Preview', 'tap-payment') . '</label>';
        echo '<span class="tap-variation-installment-preview-text"></span>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Save variation fields
     */
    public function save_variation_fields($variation_id, $loop) {
        // Enable installment
        if (isset($_POST['_tap_enable_installment'][$loop])) {
            update_post_meta($variation_id, '_tap_enable_installment', 'yes');
        } else {
            update_post_meta($variation_id, '_tap_enable_installment', 'no');
        }

        // Full amount
        if (isset($_POST['_tap_full_amount'][$loop])) {
            $full_amount = sanitize_text_field($_POST['_tap_full_amount'][$loop]);
            update_post_meta($variation_id, '_tap_full_amount', $full_amount);
        }

        // Installment count
        if (isset($_POST['_tap_installment_count'][$loop])) {
            $installment_count = intval($_POST['_tap_installment_count'][$loop]);
            update_post_meta($variation_id, '_tap_installment_count', $installment_count);
        }
    }

    /**
     * Get installment count options
     */
    private function get_installment_count_options() {
        $options = array('' => __('Select...', 'tap-payment'));
        
        $max_count = $this->get_max_installment_count();
        
        for ($i = 1; $i <= $max_count; $i++) {
            $options[$i] = sprintf(_n('%d month', '%d months', $i, 'tap-payment'), $i);
        }
        
        return $options;
    }

    /**
     * Get maximum installment count from settings
     */
    private function get_max_installment_count() {
        $settings = get_option('tap_payment_settings', array());
        return isset($settings['max_installment_count']) ? intval($settings['max_installment_count']) : 12;
    }

    /**
     * Display installment info on single product page
     */
    public function display_installment_info() {
        global $product;

        if (!$product) {
            return;
        }

        $installment_info = $this->get_product_installment_info($product);

        if (!$installment_info) {
            return;
        }

        $this->render_installment_info($installment_info);
    }

    /**
     * Display installment info on shop page
     */
    public function display_shop_installment_info() {
        global $product;

        if (!$product) {
            return;
        }

        $installment_info = $this->get_product_installment_info($product);

        if (!$installment_info) {
            return;
        }

        echo '<div class="tap-shop-installment-info">';
        echo '<span class="tap-installment-badge">';
        echo sprintf(
            esc_html__('Or %s/month for %d months', 'tap-payment'),
            wc_price($installment_info['installment_amount']),
            $installment_info['installment_count']
        );
        echo '</span>';
        echo '</div>';
    }

    /**
     * Get product installment info
     */
    private function get_product_installment_info($product) {
        $product_id = $product->get_id();
        $variation_id = null;

        // Check if it's a variation
        if ($product->is_type('variation')) {
            $variation_id = $product_id;
            $product_id = $product->get_parent_id();
        }

        $enable_installment = get_post_meta($variation_id ?: $product_id, '_tap_enable_installment', true);

        if ('yes' !== $enable_installment) {
            return false;
        }

        $full_amount = (float) get_post_meta($variation_id ?: $product_id, '_tap_full_amount', true);
        $installment_count = (int) get_post_meta($variation_id ?: $product_id, '_tap_installment_count', true);

        if ($full_amount <= 0 || $installment_count <= 0) {
            return false;
        }

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $current_price = $sale_price ? $sale_price : $regular_price;

        // Use standardized calculation method for consistency
        $calc_data = Tap_Payment_Installments::calculate_installment_data($current_price, $full_amount, $installment_count, 1);
        
        if ($calc_data === false) {
            return false;
        }

        return array(
            'full_amount' => $calc_data['full_amount'],
            'down_payment' => $calc_data['current_price'],
            'remaining_amount' => $calc_data['remaining_amount'],
            'installment_count' => $calc_data['installments'],
            'installment_amount' => $calc_data['installment_amount']
        );
    }

    /**
     * Render installment info
     */
    private function render_installment_info($info) {
        ?>
        <div class="tap-installment-info">
            <h4><?php esc_html_e('Installment Plan Available', 'tap-payment'); ?></h4>
            <div class="tap-installment-details">
                <div class="installment-row">
                    <span class="label"><?php esc_html_e('Pay today:', 'tap-payment'); ?></span>
                    <span class="value"><?php echo wc_price($info['down_payment']); ?></span>
                </div>
                <div class="installment-row">
                    <span class="label"><?php esc_html_e('Then:', 'tap-payment'); ?></span>
                    <span class="value">
                        <?php 
                        echo sprintf(
                            esc_html__('%s/month for %d months', 'tap-payment'),
                            wc_price($info['installment_amount']),
                            $info['installment_count']
                        ); 
                        ?>
                    </span>
                </div>
                <div class="installment-row total">
                    <span class="label"><?php esc_html_e('Total:', 'tap-payment'); ?></span>
                    <span class="value"><?php echo wc_price($info['full_amount']); ?></span>
                </div>
            </div>
            <div class="tap-installment-note">
                <small><?php esc_html_e('* Installments will be automatically charged monthly via Tap Payment', 'tap-payment'); ?></small>
            </div>
        </div>
        <?php
    }

    /**
     * Get installment info for variation (AJAX)
     */
    public function get_variation_installment_info() {
        check_ajax_referer('tap_payment_variation', 'nonce');

        $variation_id = intval($_POST['variation_id']);
        
        if (!$variation_id) {
            wp_die();
        }

        $variation = wc_get_product($variation_id);
        
        if (!$variation) {
            wp_die();
        }

        $installment_info = $this->get_product_installment_info($variation);

        if ($installment_info) {
            ob_start();
            $this->render_installment_info($installment_info);
            $html = ob_get_clean();
            
            wp_send_json_success(array(
                'html' => $html,
                'info' => $installment_info
            ));
        } else {
            wp_send_json_success(array(
                'html' => '',
                'info' => null
            ));
        }
    }

    /**
     * Validate installment settings
     */
    public function validate_installment_settings($product_id, $full_amount, $installment_count) {
        $errors = array();

        $product = wc_get_product($product_id);
        
        if (!$product) {
            $errors[] = __('Invalid product.', 'tap-payment');
            return $errors;
        }

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $current_price = $sale_price ? $sale_price : $regular_price;

        // Validate full amount
        if ($full_amount <= $current_price) {
            $errors[] = __('Full amount must be greater than the current product price.', 'tap-payment');
        }

        // Validate installment count
        $max_count = $this->get_max_installment_count();
        if ($installment_count < 1 || $installment_count > $max_count) {
            $errors[] = sprintf(__('Installment count must be between 1 and %d.', 'tap-payment'), $max_count);
        }

        // Validate minimum installment amount
        $settings = get_option('tap_payment_settings', array());
        $min_installment_amount = isset($settings['min_installment_amount']) ? floatval($settings['min_installment_amount']) : 10;
        
        // Use standardized calculation method for consistency
        $calc_data = Tap_Payment_Installments::calculate_installment_data($current_price, $full_amount, $installment_count, 1);
        
        if ($calc_data === false) {
            $errors[] = __('Invalid installment configuration.', 'tap-payment');
            return $errors;
        }
        
        $installment_amount = $calc_data['installment_amount'];
        
        if ($installment_amount < $min_installment_amount) {
            $errors[] = sprintf(
                __('Each installment amount (%s) must be at least %s.', 'tap-payment'),
                wc_price($installment_amount),
                wc_price($min_installment_amount)
            );
        }

        return $errors;
    }
}