<?php
/**
 * Plugin Name: WooCommerce ELKO Integration
 * Plugin URI: https://yourwebsite.com
 * Description: Integration with ELKO API for WooCommerce products synchronization
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woocommerce-elko-integration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ELKO_PLUGIN_VERSION', '1.0.0');
define('ELKO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ELKO_PLUGIN_PATH', plugin_dir_path(__FILE__));

class WooCommerce_ELKO_Integration {
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Load plugin files
        $this->load_includes();
        
        // Initialize components
        $this->init_components();
        
        // Set up hooks
        $this->setup_hooks();
        
        // Set default options
        $this->set_default_options();
    }
    
    /**
     * Load required files
     */
    private function load_includes() {
        // Core classes
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-logger.php';
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-api-client.php';
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-price-calculator.php';
        
        // Import classes
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-category-importer.php';
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-product-importer.php';
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-price-updater.php';
        
        // Admin and scheduler
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-admin-panel.php';
        require_once ELKO_PLUGIN_PATH . 'includes/class-elko-scheduler.php';
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        if (is_admin()) {
            new ELKO_Admin_Panel();
        }
        
        new ELKO_Scheduler();
    }
    
    /**
     * Setup hooks
     */
    private function setup_hooks() {
        add_action('init', array($this, 'load_textdomain'));
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('woocommerce-elko-integration', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        if (get_option('elko_api_settings') === false) {
            $default_api_settings = array(
                'api_url' => 'https://api.elko.cloud',
                'api_key' => '', 
            );
            add_option('elko_api_settings', $default_api_settings);
        }
        
        if (get_option('elko_pricing_settings') === false) {
            $default_pricing_settings = array(
                'tax_percentage' => 21,
                'markup_percentage' => 15,
                'price_calculation_method' => 'simple',
                'round_prices' => true,
            );
            add_option('elko_pricing_settings', $default_pricing_settings);
        }
        
        if (get_option('elko_sync_settings') === false) {
            $default_sync_settings = array(
                'allowed_categories' => array('CPU', 'Mainboards for AMD CPUs', 'Mainboards for Intel CPUs', 'Memory DIMM', 'Video Cards'),
                'sync_frequency' => 'daily',
                'import_images' => true,
                'update_existing' => true,
                'auto_publish' => false,
            );
            add_option('elko_sync_settings', $default_sync_settings);
        }
        
        if (get_option('elko_category_mapping') === false) {
            add_option('elko_category_mapping', array());
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('elko_sync_products');
        wp_clear_scheduled_hook('elko_sync_categories');
        wp_clear_scheduled_hook('elko_update_prices');
        wp_clear_scheduled_hook('elko_cleanup_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Logs table
        $logs_table = $wpdb->prefix . 'elko_logs';
        $logs_sql = "CREATE TABLE $logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_type (sync_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($logs_sql);
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('ELKO Integration requires WooCommerce to be installed and activated.', 'woocommerce-elko-integration'); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
new WooCommerce_ELKO_Integration();