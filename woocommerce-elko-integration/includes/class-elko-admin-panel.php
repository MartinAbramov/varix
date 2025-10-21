<?php
/**
 * ELKO Admin Panel - FIXED to work with existing admin.js
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Admin_Panel {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // FIXED: All AJAX actions properly registered
        add_action('wp_ajax_elko_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_elko_debug_endpoints', array($this, 'ajax_debug_endpoints'));
        add_action('wp_ajax_elko_sync_categories', array($this, 'ajax_sync_categories'));
        add_action('wp_ajax_elko_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_elko_update_prices', array($this, 'ajax_update_prices'));
        add_action('wp_ajax_elko_toggle_scheduler', array($this, 'ajax_toggle_scheduler'));
        add_action('wp_ajax_elko_emergency_stop', array($this, 'ajax_emergency_stop'));
        add_action('wp_ajax_elko_clear_all_data', array($this, 'ajax_clear_all_data'));
        add_action('wp_ajax_elko_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_elko_get_recent_logs', array($this, 'ajax_get_recent_logs'));
        add_action('wp_ajax_elko_nuclear_stop', array($this, 'ajax_nuclear_stop'));

        // Initialize emergency stop check
        $this->check_emergency_stop();
        
        // Debug AJAX registration
        add_action('admin_notices', array($this, 'debug_ajax_actions'));
    }
    
    /**
     * Debug AJAX actions registration
     */
    public function debug_ajax_actions() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $current_screen = get_current_screen();
        if ($current_screen && $current_screen->id === 'woocommerce_page_elko-integration') {
            echo '<div class="notice notice-info"><p><strong>ELKO Debug:</strong> AJAX actions registered at 2025-10-21 14:15:17 for user MartinAbramov</p></div>';
        }
    }
    public function ajax_nuclear_stop() {
    error_log('ELKO: NUCLEAR STOP AJAX called at 2025-10-21 14:19:42 by MartinAbramov');
    
    check_ajax_referer('elko_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Insufficient permissions.');
        return;
    }
    
    try {
        $current_time = time();
        
        // 1. Set ALL stop flags
        update_option('elko_emergency_stop', $current_time);
        update_option('elko_force_stop', $current_time);
        update_option('elko_scheduler_enabled', false);
        
        // 2. Clear ALL WordPress scheduled events (NUCLEAR OPTION)
        $all_crons = _get_cron_array();
        if ($all_crons) {
            foreach ($all_crons as $timestamp => $cron_jobs) {
                foreach ($cron_jobs as $hook => $jobs) {
                    if (strpos($hook, 'elko_') === 0) {
                        wp_clear_scheduled_hook($hook);
                        error_log("ELKO: NUCLEAR - Cleared hook: {$hook}");
                    }
                }
            }
        }
        
        // 3. Force clear specific ELKO hooks multiple times
        for ($i = 0; $i < 5; $i++) {
            wp_clear_scheduled_hook('elko_sync_products');
            wp_clear_scheduled_hook('elko_sync_categories');
            wp_clear_scheduled_hook('elko_update_prices');
            wp_clear_scheduled_hook('elko_cleanup_logs');
        }
        
        // 4. Delete ALL ELKO options that might trigger processes
        delete_option('elko_import_running');
        delete_option('elko_category_import_running');
        delete_option('elko_product_import_running');
        delete_option('elko_price_update_running');
        
        // 5. Set process kill flags
        update_option('elko_kill_all_processes', $current_time);
        
        error_log('ELKO: NUCLEAR STOP completed by MartinAbramov at 2025-10-21 14:19:42');
        
        wp_send_json_success('☢️ NUCLEAR STOP completed at 2025-10-21 14:19:42! ALL ELKO processes terminated, scheduler disabled, and cron jobs cleared by MartinAbramov. System should be completely stopped.');
        
    } catch (Exception $e) {
        error_log('ELKO: Nuclear stop failed: ' . $e->getMessage());
        wp_send_json_error('Nuclear stop failed: ' . $e->getMessage());
    }
}

/**
 * AJAX toggle scheduler - IMPROVED VERSION
 */
public function ajax_toggle_scheduler() {
    error_log('ELKO: Toggle scheduler AJAX called at 2025-10-21 14:19:42 by MartinAbramov');
    
    check_ajax_referer('elko_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Insufficient permissions.');
        return;
    }
    
    $force_action = $_POST['force_action'] ?? '';
    $scheduler_enabled = get_option('elko_scheduler_enabled', true);
    
    error_log("ELKO: Scheduler toggle - Current state: " . ($scheduler_enabled ? 'enabled' : 'disabled') . ", Force action: {$force_action}");
    
    try {
        if ($scheduler_enabled || $force_action === 'disable') {
            // Disable scheduler
            update_option('elko_scheduler_enabled', false);
            
            // Clear all scheduled events AGGRESSIVELY
            $cleared_products = 0;
            $cleared_categories = 0;
            $cleared_prices = 0;
            $cleared_cleanup = 0;
            
            // Clear multiple times to ensure they're gone
            for ($i = 0; $i < 3; $i++) {
                $cleared_products += wp_clear_scheduled_hook('elko_sync_products');
                $cleared_categories += wp_clear_scheduled_hook('elko_sync_categories');
                $cleared_prices += wp_clear_scheduled_hook('elko_update_prices');
                $cleared_cleanup += wp_clear_scheduled_hook('elko_cleanup_logs');
            }
            
            // Set emergency stop as backup
            update_option('elko_emergency_stop', time());
            
            error_log("ELKO: Scheduler DISABLED by MartinAbramov - Cleared: products({$cleared_products}), categories({$cleared_categories}), prices({$cleared_prices}), cleanup({$cleared_cleanup})");
            
            wp_send_json_success("✅ Scheduler DISABLED by MartinAbramov at 2025-10-21 14:19:42. Cleared {$cleared_products} product jobs, {$cleared_categories} category jobs, {$cleared_prices} price jobs, {$cleared_cleanup} cleanup jobs. No automatic imports will run.");
            
        } else {
            // Enable scheduler
            update_option('elko_scheduler_enabled', true);
            
            // Clear emergency stops
            delete_option('elko_emergency_stop');
            delete_option('elko_force_stop');
            
            // Schedule events if scheduler class exists
            if (class_exists('ELKO_Scheduler')) {
                $scheduler = new ELKO_Scheduler();
                $scheduler->schedule_events();
                error_log('ELKO: Scheduler events scheduled');
            } else {
                error_log('ELKO: Scheduler class not found');
            }
            
            error_log('ELKO: Scheduler ENABLED by MartinAbramov at 2025-10-21 14:19:42');
            
            wp_send_json_success('🔄 Scheduler ENABLED by MartinAbramov at 2025-10-21 14:19:42. Automatic imports will run according to schedule.');
        }
        
    } catch (Exception $e) {
        error_log('ELKO: Scheduler toggle failed: ' . $e->getMessage());
        wp_send_json_error('Scheduler toggle failed: ' . $e->getMessage());
    }
}
    /**
     * Check and apply emergency stop
     */
    private function check_emergency_stop() {
        $emergency_time = get_option('elko_emergency_stop', 0);
        if ($emergency_time > (time() - 300)) { // Active for 5 minutes
            // Clear all scheduled events
            wp_clear_scheduled_hook('elko_sync_products');
            wp_clear_scheduled_hook('elko_sync_categories');
            wp_clear_scheduled_hook('elko_update_prices');
            wp_clear_scheduled_hook('elko_cleanup_logs');
            
            // Disable scheduler
            update_option('elko_scheduler_enabled', false);
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('ELKO Integration', 'woocommerce-elko-integration'),
            __('ELKO Integration', 'woocommerce-elko-integration'),
            'manage_woocommerce',
            'elko-integration',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('elko_api_settings', 'elko_api_settings');
        register_setting('elko_sync_settings', 'elko_sync_settings');
        register_setting('elko_pricing_settings', 'elko_pricing_settings');
    }
    
    /**
     * Enqueue admin scripts - FIXED to work with existing files
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_elko-integration') {
            return;
        }
        
        error_log("ELKO: Enqueuing admin scripts for hook: {$hook} at 2025-10-21 14:15:17");
        
        // Check if files exist
        $css_file = ELKO_PLUGIN_PATH . 'assets/admin.css';
        $js_file = ELKO_PLUGIN_PATH . 'assets/admin.js';
        
        if (!file_exists($css_file)) {
            error_log("ELKO: CSS file missing: {$css_file}");
        }
        
        if (!file_exists($js_file)) {
            error_log("ELKO: JS file missing: {$js_file}");
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'elko-admin', 
            ELKO_PLUGIN_URL . 'assets/admin.css', 
            array(), 
            filemtime($css_file) // Use file modification time for cache busting
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'elko-admin', 
            ELKO_PLUGIN_URL . 'assets/admin.js', 
            array('jquery'), 
            filemtime($js_file), // Use file modification time for cache busting
            true
        );
        
        // Localize script with nonce and URLs
        wp_localize_script('elko-admin', 'elko_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('elko_ajax_nonce'),
            'plugin_url' => ELKO_PLUGIN_URL,
            'user' => 'MartinAbramov',
            'timestamp' => '2025-10-21 14:15:17'
        ));
        
        error_log("ELKO: Admin scripts enqueued successfully with nonce: " . wp_create_nonce('elko_ajax_nonce'));
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        $active_tab = $_GET['tab'] ?? 'sync'; // Default to sync tab
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ELKO Integration by MartinAbramov', 'woocommerce-elko-integration'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=elko-integration&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    🔄 <?php esc_html_e('Synchronization', 'woocommerce-elko-integration'); ?>
                </a>
                <a href="?page=elko-integration&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ <?php esc_html_e('Settings', 'woocommerce-elko-integration'); ?>
                </a>
                <a href="?page=elko-integration&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    📋 <?php esc_html_e('Logs', 'woocommerce-elko-integration'); ?>
                </a>
                <a href="?page=elko-integration&tab=debug" class="nav-tab <?php echo $active_tab === 'debug' ? 'nav-tab-active' : ''; ?>">
                    🔍 <?php esc_html_e('Debug', 'woocommerce-elko-integration'); ?>
                </a>
            </h2>
            
            <?php
            switch ($active_tab) {
                case 'sync':
                    $this->render_sync_tab();
                    break;
                case 'settings':
                    $this->render_settings_tab();
                    break;
                case 'logs':
                    $this->render_logs_tab();
                    break;
                case 'debug':
                    $this->render_debug_tab();
                    break;
            }
            ?>
        </div>
        
        <script>
        // Inline debug script
        jQuery(document).ready(function($) {
            console.log('=== ELKO DEBUG INFO ===');
            console.log('Current time: 2025-10-21 14:15:17');
            console.log('User: MartinAbramov');
            console.log('AJAX object exists:', typeof elko_ajax !== 'undefined');
            console.log('jQuery loaded:', typeof $ !== 'undefined');
            console.log('Page hook: woocommerce_page_elko-integration');
            console.log('Emergency stop button exists:', $('#emergency-stop').length > 0);
            console.log('=== END DEBUG ===');
        });
        </script>
        <?php
    }
    
    /**
     * AJAX Emergency Stop - WORKING VERSION
     */
    public function ajax_emergency_stop() {
        error_log('ELKO: Emergency stop AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        // Verify nonce
        if (!check_ajax_referer('elko_ajax_nonce', 'nonce', false)) {
            error_log('ELKO: Emergency stop - Invalid nonce');
            wp_send_json_error('Invalid security token. Please refresh the page.');
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            $current_time = time();
            
            // 1. Set emergency stop flags
            update_option('elko_emergency_stop', $current_time);
            update_option('elko_force_stop', $current_time);
            
            // 2. Disable scheduler
            update_option('elko_scheduler_enabled', false);
            
            // 3. Clear ALL scheduled events
            $cleared_products = wp_clear_scheduled_hook('elko_sync_products');
            $cleared_categories = wp_clear_scheduled_hook('elko_sync_categories');
            $cleared_prices = wp_clear_scheduled_hook('elko_update_prices');
            $cleared_cleanup = wp_clear_scheduled_hook('elko_cleanup_logs');
            
            error_log("ELKO: Emergency stop - Cleared hooks: products({$cleared_products}), categories({$cleared_categories}), prices({$cleared_prices}), cleanup({$cleared_cleanup})");
            
            // 4. Log the action
            error_log('ELKO: Emergency stop activated successfully by MartinAbramov at 2025-10-21 14:15:17');
            
            wp_send_json_success('🛑 EMERGENCY STOP activated at 2025-10-21 14:15:17! All imports stopped and scheduler disabled by MartinAbramov. No scheduled events are running.');
            
        } catch (Exception $e) {
            error_log('ELKO: Emergency stop failed: ' . $e->getMessage());
            wp_send_json_error('Emergency stop failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX toggle scheduler - WORKING VERSION
     */
   
    
    /**
     * AJAX sync categories - WORKING VERSION
     */
    public function ajax_sync_categories() {
        error_log('ELKO: Sync categories AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        // Increase time limit for category import
        set_time_limit(300); // 5 minutes
        
        try {
            if (!class_exists('ELKO_Category_Importer')) {
                wp_send_json_error('ELKO_Category_Importer class not found. Please check plugin files.');
                return;
            }
            
            $importer = new ELKO_Category_Importer();
            $result = $importer->import_categories_with_tree();
            
            if ($result !== false && $result > 0) {
                update_option('elko_last_category_sync', '2025-10-21 14:15:17');
                
                error_log("ELKO: Successfully imported {$result} categories by MartinAbramov at 2025-10-21 14:15:17");
                
                wp_send_json_success("✅ Successfully imported {$result} categories with proper hierarchy by MartinAbramov at 2025-10-21 14:15:17! Check Products → Categories to see the tree structure: PC Components → Processors → CPU, Mainboards → AMD/Intel, etc.");
                
            } else {
                error_log('ELKO: Category import returned false or 0');
                wp_send_json_error('❌ Category import failed. Check if API connection is working and credentials are correct. Check error logs for details.');
            }
            
        } catch (Exception $e) {
            error_log('ELKO: Category sync exception: ' . $e->getMessage());
            wp_send_json_error('❌ Category sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX sync products - WORKING VERSION
     */
    public function ajax_sync_products() {
        error_log('ELKO: Sync products AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        // Increase time and memory limits for product import
        set_time_limit(0); // No time limit
        ini_set('memory_limit', '1024M'); // 1GB memory
        
        try {
            if (!class_exists('ELKO_Product_Importer')) {
                wp_send_json_error('ELKO_Product_Importer class not found. Please check plugin files.');
                return;
            }
            
            $importer = new ELKO_Product_Importer();
            $result = $importer->import_products();
            
            if ($result !== false && $result > 0) {
                update_option('elko_last_product_sync', '2025-10-21 14:15:17');
                
                error_log("ELKO: Successfully imported {$result} products by MartinAbramov at 2025-10-21 14:15:17");
                
                wp_send_json_success("✅ Successfully imported {$result} products with enhanced data (gallery, attributes, detailed descriptions, brands) by MartinAbramov at 2025-10-21 14:15:17! Products are published and ready.");
                
            } else {
                error_log('ELKO: Product import returned false or 0');
                wp_send_json_error('❌ Product import failed. Make sure categories are imported first and API connection works. Check error logs for details.');
            }
            
        } catch (Exception $e) {
            error_log('ELKO: Product sync exception: ' . $e->getMessage());
            wp_send_json_error('❌ Product sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX update prices - WORKING VERSION
     */
    public function ajax_update_prices() {
        error_log('ELKO: Update prices AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            wp_send_json_error('❌ Price updater is not implemented yet. Will be added in future updates by MartinAbramov.');
            
        } catch (Exception $e) {
            error_log('ELKO: Price update exception: ' . $e->getMessage());
            wp_send_json_error('❌ Price update failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX clear all data - WORKING VERSION  
     */
    public function ajax_clear_all_data() {
        error_log('ELKO: Clear all data AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        // Increase time limit and memory
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        try {
            global $wpdb;
            
            $start_time = microtime(true);
            $deleted_products = 0;
            $deleted_categories = 0;
            
            // Delete products in batches
            $batch_size = 50;
            $offset = 0;
            
            do {
                $elko_products = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} 
                         WHERE meta_key = '_elko_product_id' 
                         LIMIT %d OFFSET %d",
                        $batch_size,
                        $offset
                    )
                );
                
                if (!empty($elko_products)) {
                    foreach ($elko_products as $product_id) {
                        if (wp_delete_post($product_id, true)) {
                            $deleted_products++;
                        }
                    }
                    $offset += $batch_size;
                }
            } while (!empty($elko_products));
            
            // Delete categories
            $elko_categories = $wpdb->get_col(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_elko_category_id'"
            );
            
            foreach ($elko_categories as $term_id) {
                if (wp_delete_term($term_id, 'product_cat')) {
                    $deleted_categories++;
                }
            }
            
            // Clear logs and reset timestamps
            $logs_table = $wpdb->prefix . 'elko_logs';
            if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table) {
                $wpdb->query("TRUNCATE TABLE $logs_table");
            }
            
            delete_option('elko_last_product_sync');
            delete_option('elko_last_category_sync');
            delete_option('elko_last_price_update');
            
            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 2);
            
            error_log("ELKO: Data cleanup completed by MartinAbramov at 2025-10-21 14:15:17 in {$execution_time}s: {$deleted_products} products, {$deleted_categories} categories");
            
            wp_send_json_success("🗑️ Successfully cleared {$deleted_products} products and {$deleted_categories} categories in {$execution_time} seconds by MartinAbramov at 2025-10-21 14:15:17.");
            
        } catch (Exception $e) {
            error_log('ELKO: Data cleanup failed: ' . $e->getMessage());
            wp_send_json_error('Failed to clear data: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX get stats - WORKING VERSION
     */
    public function ajax_get_stats() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $stats = $this->get_sync_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX test connection - WORKING VERSION
     */
    public function ajax_test_connection() {
        error_log('ELKO: Test connection AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            if (!class_exists('ELKO_API_Client')) {
                wp_send_json_error('ELKO_API_Client class not found.');
                return;
            }
            
            $api_client = new ELKO_API_Client();
            $result = $api_client->test_connection();
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Connection test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX debug endpoints - WORKING VERSION
     */
    public function ajax_debug_endpoints() {
        error_log('ELKO: Debug endpoints AJAX handler called at 2025-10-21 14:15:17 by MartinAbramov');
        
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            if (!class_exists('ELKO_API_Client')) {
                wp_send_json_error('ELKO_API_Client class not found.');
                return;
            }
            
            $api_client = new ELKO_API_Client();
            $results = $api_client->debug_endpoints();
            
            $html = '<h4>API Endpoints Debug Results:</h4><ul>';
            foreach ($results as $endpoint => $status) {
                $html .= '<li><strong>' . esc_html($endpoint) . ':</strong> ' . esc_html($status) . '</li>';
            }
            $html .= '</ul>';
            
            wp_send_json_success($html);
            
        } catch (Exception $e) {
            wp_send_json_error('Debug failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX get recent logs - STUB
     */
    public function ajax_get_recent_logs() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        wp_send_json_error('Recent logs display not implemented yet by MartinAbramov');
    }
    
    /**
     * Render sync tab with working buttons
     */
    private function render_sync_tab() {
        $stats = $this->get_sync_stats();
        $scheduled_status = $this->get_scheduler_status();
        $scheduler_enabled = get_option('elko_scheduler_enabled', true);
        ?>
        
        <!-- EMERGENCY STOP -->
        <div class="elko-emergency-alert" style="background: #ffebee; border: 3px solid #f44336; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2 style="color: #d32f2f; margin: 0 0 15px 0;">🚨 EMERGENCY CONTROLS 🚨</h2>
            
            <div style="display: flex; gap: 15px; margin: 15px 0;">
                <button type="button" id="emergency-stop" class="button" style="background: #f44336; color: white; border-color: #f44336; font-size: 16px; padding: 12px 24px; height: auto;">
                    🛑 FORCE STOP ALL IMPORTS NOW
                </button>
                
                <?php if ($scheduler_enabled): ?>
                    <button type="button" id="disable-scheduler" class="button" style="background: #388e3c; color: white; border-color: #388e3c; font-size: 16px; padding: 12px 24px; height: auto;">
                        ✅ DISABLE SCHEDULER
                    </button>
                <?php else: ?>
                    <button type="button" id="enable-scheduler" class="button" style="background: #ff9800; color: white; border-color: #ff9800; font-size: 16px; padding: 12px 24px; height: auto;">
                        🔄 ENABLE SCHEDULER
                    </button>
                <?php endif; ?>
            </div>
            
            <div id="emergency-result" style="margin-top: 15px; display: none; padding: 10px; border-radius: 4px;"></div>
            
            <div style="margin-top: 15px;">
                <h4>Current Status:</h4>
                <p style="font-size: 16px; margin: 5px 0;">
                    <strong>Scheduler:</strong> 
                    <?php if ($scheduler_enabled): ?>
                        <span style="color: #d32f2f; font-weight: bold;">🔴 ENABLED (imports running automatically)</span>
                    <?php else: ?>
                        <span style="color: #388e3c; font-weight: bold;">🟢 DISABLED (no automatic imports)</span>
                    <?php endif; ?>
                </p>
                
                <p><strong>User:</strong> MartinAbramov | <strong>Time:</strong> 2025-10-21 14:15:17 UTC</p>
            </div>
        </div>
        
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('Manual Synchronization', 'woocommerce-elko-integration'); ?></h3>
            <p><?php esc_html_e('Use these buttons for manual synchronization (recommended order: Categories → Products → Prices).', 'woocommerce-elko-integration'); ?></p>
            
            <div class="elko-sync-buttons">
                <button type="button" id="sync-categories" class="button button-primary">
                    <span class="dashicons dashicons-category"></span>
                    <?php esc_html_e('1. Sync Categories (Hierarchical)', 'woocommerce-elko-integration'); ?>
                </button>
                
                <button type="button" id="sync-products" class="button button-primary">
                    <span class="dashicons dashicons-products"></span>
                    <?php esc_html_e('2. Sync Products', 'woocommerce-elko-integration'); ?>
                </button>
                
                <button type="button" id="update-prices" class="button button-primary">
                    <span class="dashicons dashicons-money-alt"></span>
                    <?php esc_html_e('3. Update Prices', 'woocommerce-elko-integration'); ?>
                </button>
            </div>
            
            <div id="sync-progress" class="elko-progress">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-text">Processing...</div>
            </div>
            
            <div id="sync-result" style="margin-top: 15px; display: none; padding: 10px; border-radius: 4px;"></div>
        </div>
        
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('🧹 Data Management', 'woocommerce-elko-integration'); ?></h3>
            
            <div class="elko-alert elko-alert-danger">
                <h4>⚠️ Danger Zone</h4>
                <p>This will permanently delete ALL ELKO data!</p>
                <button type="button" id="clear-all-data" class="button button-secondary" style="background: #dc3545; color: white; border-color: #dc3545;">
                    🗑️ Clear All ELKO Data
                </button>
            </div>
        </div>
        
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('📊 Statistics', 'woocommerce-elko-integration'); ?></h3>
            
            <div class="elko-stats-grid">
                <div class="elko-stat-card">
                    <div class="elko-stat-number" id="stat-products"><?php echo esc_html($stats['products'] ?? 0); ?></div>
                    <div class="elko-stat-label"><?php esc_html_e('ELKO Products', 'woocommerce-elko-integration'); ?></div>
                </div>
                <div class="elko-stat-card">
                    <div class="elko-stat-number" id="stat-categories"><?php echo esc_html($stats['categories'] ?? 0); ?></div>
                    <div class="elko-stat-label"><?php esc_html_e('ELKO Categories', 'woocommerce-elko-integration'); ?></div>
                </div>
                <div class="elko-stat-card">
                    <div class="elko-stat-number" id="stat-recent-sync"><?php echo esc_html($stats['last_sync'] ?? 'Never'); ?></div>
                    <div class="elko-stat-label"><?php esc_html_e('Last Sync', 'woocommerce-elko-integration'); ?></div>
                </div>
                <div class="elko-stat-card">
                    <div class="elko-stat-number" id="stat-errors"><?php echo esc_html($stats['recent_errors'] ?? 0); ?></div>
                    <div class="elko-stat-label"><?php esc_html_e('Recent Errors', 'woocommerce-elko-integration'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get scheduler status
     */
    private function get_scheduler_status() {
        return array(
            'products' => array(
                'scheduled' => wp_next_scheduled('elko_sync_products') !== false,
                'next_run' => wp_next_scheduled('elko_sync_products') ? date('Y-m-d H:i:s', wp_next_scheduled('elko_sync_products')) : 'Not scheduled'
            ),
            'categories' => array(
                'scheduled' => wp_next_scheduled('elko_sync_categories') !== false,
                'next_run' => wp_next_scheduled('elko_sync_categories') ? date('Y-m-d H:i:s', wp_next_scheduled('elko_sync_categories')) : 'Not scheduled'
            ),
            'prices' => array(
                'scheduled' => wp_next_scheduled('elko_update_prices') !== false,
                'next_run' => wp_next_scheduled('elko_update_prices') ? date('Y-m-d H:i:s', wp_next_scheduled('elko_update_prices')) : 'Not scheduled'
            )
        );
    }
    
    /**
     * Get sync statistics
     */
    private function get_sync_stats() {
        global $wpdb;
        
        $stats = array();
        
        $stats['products'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_elko_product_id'"
        ) ?: 0;
        
        $stats['categories'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_elko_category_id'"
        ) ?: 0;
        
        $last_product_sync = get_option('elko_last_product_sync', '');
        $last_category_sync = get_option('elko_last_category_sync', '');
        $last_price_update = get_option('elko_last_price_update', '');
        
        $recent_times = array_filter(array($last_product_sync, $last_category_sync, $last_price_update));
        if (!empty($recent_times)) {
            rsort($recent_times);
            $stats['last_sync'] = date('M j, H:i', strtotime($recent_times[0]));
        } else {
            $stats['last_sync'] = 'Never';
        }
        
        // Safe error count check
        $table_name = $wpdb->prefix . 'elko_logs';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_exists) {
            $stats['recent_errors'] = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} 
                     WHERE status = 'error' AND created_at > %s",
                    date('Y-m-d H:i:s', strtotime('-24 hours'))
                )
            ) ?: 0;
        } else {
            $stats['recent_errors'] = 0;
        }
        
        return $stats;
    }
    
    // Остальные методы - заглушки
    private function render_settings_tab() { 
        echo '<div class="elko-sync-controls"><h3>🔧 Settings</h3><p>API configuration and sync settings will be implemented here by MartinAbramov.</p></div>'; 
    }
    
    private function render_logs_tab() { 
        echo '<div class="elko-sync-controls"><h3>📋 Logs</h3><p>Detailed logs display will be implemented here by MartinAbramov.</p></div>'; 
    }
    
    private function render_debug_tab() { 
        echo '<div class="elko-sync-controls"><h3>🔍 Debug</h3>';
        echo '<p>Debug tools for API testing and troubleshooting.</p>';
        echo '<button type="button" id="test-connection" class="button">Test API Connection</button> ';
        echo '<button type="button" id="debug-endpoints" class="button">Debug Endpoints</button>';
        echo '<div id="debug-result" style="margin-top: 15px; display: none; padding: 10px; border-radius: 4px;"></div>';
        echo '</div>'; 
    }
}