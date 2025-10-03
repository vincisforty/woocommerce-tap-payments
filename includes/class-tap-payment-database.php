<?php
/**
 * Tap Payment Database Class
 * 
 * Handles database operations for installment plans, installments, and payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tap_Payment_Database {

    /**
     * Instance
     */
    private static $instance = null;

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

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
        add_action('init', array($this, 'check_database_version'));
    }

    /**
     * Check database version and update if needed
     */
    public function check_database_version() {
        $installed_version = get_option('tap_payment_db_version', '0');
        
        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            self::create_tables();
            update_option('tap_payment_db_version', self::DB_VERSION);
        }
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Installment Plans Table
        $table_installment_plans = $wpdb->prefix . 'tap_installment_plans';
        $sql_plans = "CREATE TABLE $table_installment_plans (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            down_payment DECIMAL(10,2) NOT NULL,
            installment_count INT(11) NOT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_user_id (user_id),
            KEY idx_product_id (product_id),
            KEY idx_variation_id (variation_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        // Installments Table
        $table_installments = $wpdb->prefix . 'tap_installments';
        $sql_installments = "CREATE TABLE $table_installments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT(20) UNSIGNED NOT NULL,
            installment_number INT(11) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            tap_invoice_id VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_plan_id (plan_id),
            KEY idx_installment_number (installment_number),
            KEY idx_due_date (due_date),
            KEY idx_status (status),
            KEY idx_tap_invoice_id (tap_invoice_id),
            KEY idx_due_pending (due_date, status),
            FOREIGN KEY (plan_id) REFERENCES $table_installment_plans(id) ON DELETE CASCADE
        ) $charset_collate;";

        // Payments Table
        $table_payments = $wpdb->prefix . 'tap_payments';
        $sql_payments = "CREATE TABLE $table_payments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            installment_id BIGINT(20) UNSIGNED DEFAULT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            tap_charge_id VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status VARCHAR(20) NOT NULL,
            payment_type ENUM('initial', 'installment') DEFAULT 'initial',
            response_data TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_installment_id (installment_id),
            KEY idx_order_id (order_id),
            KEY idx_tap_charge_id (tap_charge_id),
            KEY idx_status (status),
            KEY idx_payment_type (payment_type),
            KEY idx_created_status (created_at DESC, status),
            FOREIGN KEY (installment_id) REFERENCES $table_installments(id) ON DELETE SET NULL
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_plans);
        dbDelta($sql_installments);
        dbDelta($sql_payments);

        // Create additional indexes for performance
        self::create_additional_indexes();
    }

    /**
     * Create additional indexes for performance optimization
     */
    private static function create_additional_indexes() {
        global $wpdb;

        $table_plans = $wpdb->prefix . 'tap_installment_plans';
        $table_installments = $wpdb->prefix . 'tap_installments';
        $table_payments = $wpdb->prefix . 'tap_payments';

        // Composite indexes for common queries
        $indexes = array(
            "CREATE INDEX idx_plans_user_status ON $table_plans(user_id, status)",
            "CREATE INDEX idx_installments_due_pending ON $table_installments(due_date, status)",
            "CREATE INDEX idx_payments_order_type ON $table_payments(order_id, payment_type)",
        );

        foreach ($indexes as $index_sql) {
            $wpdb->query($index_sql);
        }
    }

    /**
     * Drop database tables
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'tap_payments',
            $wpdb->prefix . 'tap_installments',
            $wpdb->prefix . 'tap_installment_plans'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }

        delete_option('tap_payment_db_version');
    }

    /**
     * Get installment plan by ID
     */
    public static function get_installment_plan($plan_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $plan_id)
        );
    }

    /**
     * Get installment plans by order ID
     */
    public static function get_installment_plans_by_order($order_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE order_id = %d", $order_id)
        );
    }

    /**
     * Get installment plans by user ID
     */
    public static function get_installment_plans_by_user($user_id, $status = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        $sql = "SELECT * FROM $table WHERE user_id = %d";
        $params = array($user_id);
        
        if ($status) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        return $wpdb->get_results(
            $wpdb->prepare($sql, $params)
        );
    }

    /**
     * Create installment plan
     */
    public static function create_installment_plan($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        $result = $wpdb->insert(
            $table,
            array(
                'order_id' => $data['order_id'],
                'user_id' => $data['user_id'],
                'product_id' => $data['product_id'],
                'variation_id' => $data['variation_id'],
                'total_amount' => $data['total_amount'],
                'down_payment' => $data['down_payment'],
                'installment_count' => $data['installment_count'],
                'status' => isset($data['status']) ? $data['status'] : 'active',
            ),
            array('%d', '%d', '%d', '%d', '%f', '%f', '%d', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }

    /**
     * Update installment plan
     */
    public static function update_installment_plan($plan_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->update(
            $table,
            $data,
            array('id' => $plan_id),
            null,
            array('%d')
        );
    }

    /**
     * Get installments by plan ID
     */
    public static function get_installments_by_plan($plan_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE plan_id = %d ORDER BY installment_number ASC",
                $plan_id
            )
        );
    }

    /**
     * Get pending installments due for processing
     */
    public static function get_pending_installments($date = null) {
        global $wpdb;
        
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        $table = $wpdb->prefix . 'tap_installments';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE status = 'pending' AND due_date <= %s ORDER BY due_date ASC",
                $date
            )
        );
    }

    /**
     * Create installment
     */
    public static function create_installment($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        
        $result = $wpdb->insert(
            $table,
            array(
                'plan_id' => $data['plan_id'],
                'installment_number' => $data['installment_number'],
                'amount' => $data['amount'],
                'due_date' => $data['due_date'],
                'status' => isset($data['status']) ? $data['status'] : 'pending',
                'tap_invoice_id' => isset($data['tap_invoice_id']) ? $data['tap_invoice_id'] : null,
            ),
            array('%d', '%d', '%f', '%s', '%s', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }

    /**
     * Update installment
     */
    public static function update_installment($installment_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        
        return $wpdb->update(
            $table,
            $data,
            array('id' => $installment_id),
            null,
            array('%d')
        );
    }

    /**
     * Get payment by ID
     */
    public static function get_payment($payment_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_payments';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $payment_id)
        );
    }

    /**
     * Get payments by order ID
     */
    public static function get_payments_by_order($order_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_payments';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE order_id = %d ORDER BY created_at DESC",
                $order_id
            )
        );
    }

    /**
     * Get payment by Tap charge ID
     */
    public static function get_payment_by_charge_id($charge_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_payments';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE tap_charge_id = %s", $charge_id)
        );
    }

    /**
     * Create payment record
     */
    public static function create_payment($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_payments';
        
        $result = $wpdb->insert(
            $table,
            array(
                'installment_id' => isset($data['installment_id']) ? $data['installment_id'] : null,
                'order_id' => $data['order_id'],
                'tap_charge_id' => $data['tap_charge_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'status' => $data['status'],
                'payment_type' => isset($data['payment_type']) ? $data['payment_type'] : 'initial',
                'response_data' => isset($data['response_data']) ? $data['response_data'] : null,
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }

    /**
     * Update payment record
     */
    public static function update_payment($payment_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_payments';
        
        return $wpdb->update(
            $table,
            $data,
            array('id' => $payment_id),
            null,
            array('%d')
        );
    }

    /**
     * Get payment statistics
     */
    public static function get_payment_stats($date_from = null, $date_to = null) {
        global $wpdb;
        
        $table_payments = $wpdb->prefix . 'tap_payments';
        $table_plans = $wpdb->prefix . 'tap_installment_plans';
        
        $where_clause = "WHERE p.status = 'CAPTURED'";
        $params = array();
        
        if ($date_from && $date_to) {
            $where_clause .= " AND p.created_at BETWEEN %s AND %s";
            $params[] = $date_from;
            $params[] = $date_to;
        }
        
        $sql = "
            SELECT 
                COUNT(p.id) as total_payments,
                SUM(p.amount) as total_amount,
                COUNT(CASE WHEN p.payment_type = 'initial' THEN 1 END) as initial_payments,
                COUNT(CASE WHEN p.payment_type = 'installment' THEN 1 END) as installment_payments,
                COUNT(DISTINCT pl.id) as total_plans
            FROM $table_payments p
            LEFT JOIN $table_plans pl ON p.order_id = pl.order_id
            $where_clause
        ";
        
        if (!empty($params)) {
            return $wpdb->get_row($wpdb->prepare($sql, $params));
        }
        
        return $wpdb->get_row($sql);
    }

    /**
     * Get overdue installments
     */
    public static function get_overdue_installments($days_overdue = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        $date = date('Y-m-d', strtotime("-$days_overdue days"));
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE status = 'pending' AND due_date < %s ORDER BY due_date ASC",
                $date
            )
        );
    }

    /**
     * Get upcoming installments
     */
    public static function get_upcoming_installments($days_ahead = 3) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        $date_from = current_time('Y-m-d');
        $date_to = date('Y-m-d', strtotime("+$days_ahead days"));
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE status = 'pending' AND due_date BETWEEN %s AND %s ORDER BY due_date ASC",
                $date_from,
                $date_to
            )
        );
    }

    /**
     * Get installment by ID
     */
    public static function get_installment($installment_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installments';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $installment_id)
        );
    }

    /**
     * Get installment plan by order ID
     */
    public static function get_installment_plan_by_order($order_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE order_id = %d", $order_id)
        );
    }

    /**
     * Get customer installment plans
     */
    public static function get_customer_installment_plans($customer_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'tap_installment_plans';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE customer_id = %d ORDER BY created_at DESC",
                $customer_id
            )
        );
    }

    /**
     * Get installment plan with installments
     */
    public static function get_installment_plan_with_installments($plan_id) {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'tap_installment_plans';
        $installment_table = $wpdb->prefix . 'tap_installments';
        
        $plan = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $plan_table WHERE id = %d", $plan_id)
        );
        
        if ($plan) {
            $plan->installments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $installment_table WHERE plan_id = %d ORDER BY installment_number ASC",
                    $plan_id
                )
            );
        }
        
        return $plan;
    }
}