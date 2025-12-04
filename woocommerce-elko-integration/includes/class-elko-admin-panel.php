<?php
/**
 * ELKO Admin Panel - Enhanced with all new features
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Admin_Panel {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX actions
        add_action('wp_ajax_elko_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_elko_debug_endpoints', array($this, 'ajax_debug_endpoints'));
        add_action('wp_ajax_elko_sync_categories', array($this, 'ajax_sync_categories'));
        add_action('wp_ajax_elko_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_elko_import_attributes', array($this, 'ajax_import_attributes'));
        add_action('wp_ajax_elko_update_prices', array($this, 'ajax_update_prices'));
        add_action('wp_ajax_elko_toggle_scheduler', array($this, 'ajax_toggle_scheduler'));
        add_action('wp_ajax_elko_emergency_stop', array($this, 'ajax_emergency_stop'));
        add_action('wp_ajax_elko_clear_all_data', array($this, 'ajax_clear_all_data'));
        add_action('wp_ajax_elko_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_elko_get_recent_logs', array($this, 'ajax_get_recent_logs'));
        add_action('wp_ajax_elko_nuclear_stop', array($this, 'ajax_nuclear_stop'));
        add_action('wp_ajax_elko_fix_images', array($this, 'ajax_fix_images'));
        add_action('wp_ajax_elko_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_elko_stop_import', array($this, 'ajax_stop_import'));
        add_action('wp_ajax_elko_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_elko_refresh_categories', array($this, 'ajax_refresh_categories'));

        // Initialize emergency stop check
        $this->check_emergency_stop();
    }
    
    /**
     * AJAX Save Settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            // Save API settings
            $api_settings = array(
                'api_url' => sanitize_url($_POST['api_url'] ?? 'https://api.elko.cloud'),
                'api_key' => sanitize_textarea_field($_POST['api_key'] ?? '')
            );
            update_option('elko_api_settings', $api_settings);
            
            // Save pricing settings
            $pricing_settings = array(
                'tax_percentage' => floatval($_POST['tax_percentage'] ?? 21),
                'markup_percentage' => floatval($_POST['markup_percentage'] ?? 15),
                'price_calculation_method' => sanitize_text_field($_POST['price_calculation_method'] ?? 'simple'),
                'round_prices' => !empty($_POST['round_prices'])
            );
            update_option('elko_pricing_settings', $pricing_settings);
            
            // Save sync settings
            $sync_settings = get_option('elko_sync_settings', array());
            $sync_settings['sync_frequency'] = sanitize_text_field($_POST['sync_frequency'] ?? 'daily');
            update_option('elko_sync_settings', $sync_settings);
            
            ELKO_Logger::log_sync('settings', 'success', 'Settings saved successfully');
            
            wp_send_json_success('Settings saved successfully!');
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to save settings: ' . $e->getMessage());
        }
    }
    
    /**
     * Check and apply emergency stop
     */
    private function check_emergency_stop() {
        $emergency_time = get_option('elko_emergency_stop', 0);
        if ($emergency_time > (time() - 300)) {
            wp_clear_scheduled_hook('elko_sync_products');
            wp_clear_scheduled_hook('elko_sync_categories');
            wp_clear_scheduled_hook('elko_update_prices');
            wp_clear_scheduled_hook('elko_cleanup_logs');
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
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_elko-integration') {
            return;
        }
        
        $css_file = ELKO_PLUGIN_PATH . 'assets/admin.css';
        $js_file = ELKO_PLUGIN_PATH . 'assets/admin.js';
        
        wp_enqueue_style(
            'elko-admin', 
            ELKO_PLUGIN_URL . 'assets/admin.css', 
            array(), 
            file_exists($css_file) ? filemtime($css_file) : ELKO_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'elko-admin', 
            ELKO_PLUGIN_URL . 'assets/admin.js', 
            array('jquery'), 
            file_exists($js_file) ? filemtime($js_file) : ELKO_PLUGIN_VERSION,
            true
        );
        
        // Get allowed categories for dropdown
        $api_client = new ELKO_API_Client();
        $allowed_categories = $api_client->get_allowed_categories();
        
        wp_localize_script('elko-admin', 'elko_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('elko_ajax_nonce'),
            'plugin_url' => ELKO_PLUGIN_URL,
            'allowed_categories' => $allowed_categories,
            'current_session' => wp_generate_uuid4()
        ));
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sync';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ELKO Integration', 'woocommerce-elko-integration'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=elko-integration&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    🔄 <?php esc_html_e('Synchronization', 'woocommerce-elko-integration'); ?>
                </a>
                <a href="?page=elko-integration&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ <?php esc_html_e('Settings', 'woocommerce-elko-integration'); ?>
                </a>
                <a href="?page=elko-integration&tab=cron" class="nav-tab <?php echo $active_tab === 'cron' ? 'nav-tab-active' : ''; ?>">
                    ⏰ <?php esc_html_e('Cron Jobs', 'woocommerce-elko-integration'); ?>
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
                case 'cron':
                    $this->render_cron_tab();
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
        <?php
    }
    
    /**
     * AJAX Emergency Stop
     */
    public function ajax_emergency_stop() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            $current_time = time();
            
            update_option('elko_emergency_stop', $current_time);
            update_option('elko_force_stop', $current_time);
            update_option('elko_scheduler_enabled', false);
            update_option('elko_import_stop_requested', true);
            
            wp_clear_scheduled_hook('elko_sync_products');
            wp_clear_scheduled_hook('elko_sync_categories');
            wp_clear_scheduled_hook('elko_update_prices');
            wp_clear_scheduled_hook('elko_cleanup_logs');
            
            // Mark all running imports as stopped
            global $wpdb;
            $progress_table = $wpdb->prefix . 'elko_import_progress';
            $wpdb->update(
                $progress_table,
                array('status' => 'stopped', 'completed_at' => current_time('mysql')),
                array('status' => 'running')
            );
            
            ELKO_Logger::log_sync('emergency', 'success', 'Emergency stop activated');
            
            wp_send_json_success('🛑 EMERGENCY STOP activated! All imports stopped and scheduler disabled.');
            
        } catch (Exception $e) {
            wp_send_json_error('Emergency stop failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Stop Import (graceful)
     */
    public function ajax_stop_import() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        // Set stop flag for graceful shutdown
        update_option('elko_import_stop_requested', true);
        
        if (!empty($session_id)) {
            update_option('elko_stop_session_' . $session_id, true);
        }
        
        ELKO_Logger::log_sync('import', 'info', 'Import stop requested (graceful shutdown)');
        
        wp_send_json_success('⏹️ Stop requested. Import will finish current item and stop gracefully.');
    }
    
    /**
     * AJAX Nuclear Stop
     */
    public function ajax_nuclear_stop() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            $current_time = time();
            
            update_option('elko_emergency_stop', $current_time);
            update_option('elko_force_stop', $current_time);
            update_option('elko_scheduler_enabled', false);
            update_option('elko_import_stop_requested', true);
            
            // Clear all ELKO cron hooks
            $all_crons = _get_cron_array();
            if ($all_crons) {
                foreach ($all_crons as $timestamp => $cron_jobs) {
                    foreach ($cron_jobs as $hook => $jobs) {
                        if (strpos($hook, 'elko_') === 0) {
                            wp_clear_scheduled_hook($hook);
                        }
                    }
                }
            }
            
            // Force clear specific hooks
            for ($i = 0; $i < 5; $i++) {
                wp_clear_scheduled_hook('elko_sync_products');
                wp_clear_scheduled_hook('elko_sync_categories');
                wp_clear_scheduled_hook('elko_update_prices');
                wp_clear_scheduled_hook('elko_cleanup_logs');
            }
            
            delete_option('elko_import_running');
            update_option('elko_kill_all_processes', $current_time);
            
            ELKO_Logger::log_sync('nuclear', 'success', 'Nuclear stop executed');
            
            wp_send_json_success('☢️ NUCLEAR STOP completed! ALL ELKO processes terminated.');
            
        } catch (Exception $e) {
            wp_send_json_error('Nuclear stop failed: ' . $e->getMessage());
        }
    }

    /**
     * AJAX Toggle Scheduler
     */
    public function ajax_toggle_scheduler() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $force_action = isset($_POST['force_action']) ? sanitize_text_field($_POST['force_action']) : '';
        $scheduler_enabled = get_option('elko_scheduler_enabled', false);
        
        try {
            if ($scheduler_enabled || $force_action === 'disable') {
                update_option('elko_scheduler_enabled', false);
                
                for ($i = 0; $i < 3; $i++) {
                    wp_clear_scheduled_hook('elko_sync_products');
                    wp_clear_scheduled_hook('elko_sync_categories');
                    wp_clear_scheduled_hook('elko_update_prices');
                    wp_clear_scheduled_hook('elko_cleanup_logs');
                }
                
                update_option('elko_emergency_stop', time());
                
                wp_send_json_success('✅ Scheduler DISABLED. No automatic imports will run.');
                
            } else {
                update_option('elko_scheduler_enabled', true);
                delete_option('elko_emergency_stop');
                delete_option('elko_force_stop');
                
                if (class_exists('ELKO_Scheduler')) {
                    $scheduler = new ELKO_Scheduler();
                    $scheduler->schedule_events();
                }
                
                wp_send_json_success('🔄 Scheduler ENABLED. Automatic imports will run according to schedule.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Scheduler toggle failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Sync Categories
     */
    public function ajax_sync_categories() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(300);
        
        try {
            // Clear stop flag
            delete_option('elko_import_stop_requested');
            
            $importer = new ELKO_Category_Importer();
            $result = $importer->import_categories_with_tree();
            
            if ($result !== false && $result > 0) {
                update_option('elko_last_category_sync', current_time('mysql'));
                ELKO_Logger::log_sync('categories', 'success', "Imported {$result} categories");
                wp_send_json_success("✅ Successfully imported {$result} categories with proper hierarchy!");
            } else {
                wp_send_json_error('❌ Category import failed. Check API connection and credentials.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Category sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Sync Products with categories selection and progress
     */
    public function ajax_sync_products() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        try {
            // Clear stop flag
            delete_option('elko_import_stop_requested');
            
            // Get selected categories
            $selected_categories = array();
            if (!empty($_POST['categories']) && is_array($_POST['categories'])) {
                $selected_categories = array_map('sanitize_text_field', $_POST['categories']);
            }
            
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : wp_generate_uuid4();
            
            $importer = new ELKO_Product_Importer();
            $result = $importer->import_products($selected_categories, $session_id);
            
            if ($result !== false && $result > 0) {
                update_option('elko_last_product_sync', current_time('mysql'));
                ELKO_Logger::log_sync('products', 'success', "Imported {$result} products");
                wp_send_json_success("✅ Successfully imported {$result} products with enhanced data!");
            } elseif ($result === 0) {
                wp_send_json_success("✅ Import completed. No new products to import.");
            } else {
                wp_send_json_error('❌ Product import failed. Make sure categories are imported first.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Product sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Import Attributes separately
     */
    public function ajax_import_attributes() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        try {
            delete_option('elko_import_stop_requested');
            
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : wp_generate_uuid4();
            
            $importer = new ELKO_Product_Importer();
            $result = $importer->import_attributes_only($session_id);
            
            if ($result !== false && $result > 0) {
                ELKO_Logger::log_sync('attributes', 'success', "Updated attributes for {$result} products");
                wp_send_json_success("✅ Successfully updated attributes for {$result} products!");
            } elseif ($result === 0) {
                wp_send_json_success("✅ No products found to update attributes.");
            } else {
                wp_send_json_error('❌ Attribute import failed.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Attribute import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Update Prices
     */
    public function ajax_update_prices() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(0);
        
        try {
            delete_option('elko_import_stop_requested');
            
            $updater = new ELKO_Price_Updater();
            $result = $updater->update_all_prices();
            
            if ($result !== false && $result > 0) {
                update_option('elko_last_price_update', current_time('mysql'));
                ELKO_Logger::log_sync('prices', 'success', "Updated prices for {$result} products");
                wp_send_json_success("✅ Successfully updated prices and stock for {$result} products!");
            } elseif ($result === 0) {
                wp_send_json_success("✅ No products found to update.");
            } else {
                wp_send_json_error('❌ Price update failed.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Price update failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Fix Images
     */
    public function ajax_fix_images() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        try {
            delete_option('elko_import_stop_requested');
            
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : wp_generate_uuid4();
            
            $importer = new ELKO_Product_Importer();
            $result = $importer->fix_all_images($session_id);
            
            if ($result !== false && $result > 0) {
                ELKO_Logger::log_sync('images', 'success', "Fixed images for {$result} products");
                wp_send_json_success("✅ Successfully re-imported images for {$result} products!");
            } elseif ($result === 0) {
                wp_send_json_success("✅ No products found with missing images.");
            } else {
                wp_send_json_error('❌ Image fix failed.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Image fix failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Refresh Categories Cache
     */
    public function ajax_refresh_categories() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            $api_client = new ELKO_API_Client();
            
            // Clear the cache
            $api_client->clear_categories_cache();
            
            // Fetch fresh categories from API
            $categories = $api_client->get_allowed_categories();
            
            if (empty($categories)) {
                wp_send_json_error('❌ Failed to fetch categories from API. Using fallback list.');
                return;
            }
            
            $count = count($categories);
            
            ELKO_Logger::log_sync('categories', 'success', "Refreshed categories list. Found {$count} categories.");
            
            wp_send_json_success(array(
                'message' => "✅ Successfully refreshed categories. Found {$count} product categories!",
                'count' => $count,
                'categories' => $categories
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('❌ Failed to refresh categories: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Get Progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        if (empty($session_id)) {
            wp_send_json_error('No session ID provided.');
            return;
        }
        
        global $wpdb;
        $progress_table = $wpdb->prefix . 'elko_import_progress';
        
        $progress = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$progress_table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ));
        
        if (!$progress) {
            wp_send_json_success(array(
                'status' => 'not_started',
                'total' => 0,
                'processed' => 0,
                'current_item' => '',
                'percentage' => 0
            ));
            return;
        }
        
        $percentage = $progress->total_items > 0 
            ? round(($progress->processed_items / $progress->total_items) * 100, 1)
            : 0;
        
        wp_send_json_success(array(
            'status' => $progress->status,
            'total' => intval($progress->total_items),
            'processed' => intval($progress->processed_items),
            'current_item' => $progress->current_item,
            'percentage' => $percentage,
            'error_count' => intval($progress->error_count),
            'last_error' => $progress->last_error
        ));
    }
    
    /**
     * AJAX Clear All Data
     */
    public function ajax_clear_all_data() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        try {
            global $wpdb;
            
            $deleted_products = 0;
            $deleted_categories = 0;
            $batch_size = 50;
            
            do {
                $elko_products = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} 
                         WHERE meta_key = '_elko_product_id' 
                         LIMIT %d",
                        $batch_size
                    )
                );
                
                if (!empty($elko_products)) {
                    foreach ($elko_products as $product_id) {
                        if (wp_delete_post($product_id, true)) {
                            $deleted_products++;
                        }
                    }
                }
            } while (!empty($elko_products));
            
            $elko_categories = $wpdb->get_col(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_elko_category_id'"
            );
            
            foreach ($elko_categories as $term_id) {
                if (wp_delete_term($term_id, 'product_cat')) {
                    $deleted_categories++;
                }
            }
            
            $logs_table = $wpdb->prefix . 'elko_logs';
            if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table) {
                $wpdb->query("TRUNCATE TABLE $logs_table");
            }
            
            $progress_table = $wpdb->prefix . 'elko_import_progress';
            if ($wpdb->get_var("SHOW TABLES LIKE '$progress_table'") == $progress_table) {
                $wpdb->query("TRUNCATE TABLE $progress_table");
            }
            
            delete_option('elko_last_product_sync');
            delete_option('elko_last_category_sync');
            delete_option('elko_last_price_update');
            
            ELKO_Logger::log_sync('cleanup', 'success', "Deleted {$deleted_products} products and {$deleted_categories} categories");
            
            wp_send_json_success("🗑️ Successfully cleared {$deleted_products} products and {$deleted_categories} categories.");
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to clear data: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX Get Stats
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
     * AJAX Test Connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
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
     * AJAX Debug Endpoints
     */
    public function ajax_debug_endpoints() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
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
     * AJAX Get Recent Logs
     */
    public function ajax_get_recent_logs() {
        check_ajax_referer('elko_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $logs = ELKO_Logger::get_logs(50);
        wp_send_json_success($logs);
    }
    
    /**
     * Render sync tab with all new features
     */
    private function render_sync_tab() {
        $stats = $this->get_sync_stats();
        $scheduler_enabled = get_option('elko_scheduler_enabled', false);
        $api_client = new ELKO_API_Client();
        $allowed_categories = $api_client->get_allowed_categories();
        ?>
        
        <!-- EMERGENCY CONTROLS -->
        <div class="elko-emergency-alert" style="background: #ffebee; border: 3px solid #f44336; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2 style="color: #d32f2f; margin: 0 0 15px 0;">🚨 EMERGENCY CONTROLS</h2>
            
            <div style="display: flex; gap: 15px; margin: 15px 0; flex-wrap: wrap;">
                <button type="button" id="stop-import" class="button" style="background: #ff9800; color: white; border-color: #ff9800; font-size: 14px; padding: 10px 20px; height: auto;">
                    ⏹️ Stop Current Import
                </button>
                <button type="button" id="emergency-stop" class="button" style="background: #f44336; color: white; border-color: #f44336; font-size: 14px; padding: 10px 20px; height: auto;">
                    🛑 FORCE STOP ALL
                </button>
                
                <?php if ($scheduler_enabled): ?>
                    <button type="button" id="disable-scheduler" class="button" style="background: #388e3c; color: white; border-color: #388e3c; font-size: 14px; padding: 10px 20px; height: auto;">
                        ✅ Disable Scheduler
                    </button>
                <?php else: ?>
                    <button type="button" id="enable-scheduler" class="button" style="background: #2196f3; color: white; border-color: #2196f3; font-size: 14px; padding: 10px 20px; height: auto;">
                        🔄 Enable Scheduler
                    </button>
                <?php endif; ?>
            </div>
            
            <div id="emergency-result" style="margin-top: 15px; display: none; padding: 10px; border-radius: 4px;"></div>
            
            <p style="margin-top: 10px;">
                <strong>Scheduler:</strong> 
                <?php if ($scheduler_enabled): ?>
                    <span style="color: #d32f2f; font-weight: bold;">🔴 ENABLED</span>
                <?php else: ?>
                    <span style="color: #388e3c; font-weight: bold;">🟢 DISABLED</span>
                <?php endif; ?>
            </p>
        </div>
        
        <!-- CATEGORY SELECTION -->
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('📁 Category Selection for Import', 'woocommerce-elko-integration'); ?></h3>
            <p><?php esc_html_e('Select categories to import. Leave empty to import all allowed categories.', 'woocommerce-elko-integration'); ?></p>
            
            <div class="elko-category-select" style="margin: 15px 0;">
                <select id="import-categories" multiple style="width: 100%; min-height: 200px;">
                    <?php foreach ($allowed_categories as $name => $code): ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple categories.', 'woocommerce-elko-integration'); ?></p>
                <p class="description"><strong><?php echo count($allowed_categories); ?></strong> categories available.</p>
            </div>
            
            <div style="margin-top: 10px;">
                <button type="button" id="refresh-categories" class="button button-secondary">
                    🔄 Refresh Categories List from API
                </button>
                <span id="refresh-categories-result" style="margin-left: 10px;"></span>
            </div>
        </div>
        
        <!-- MANUAL SYNC CONTROLS -->
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('🔄 Manual Synchronization', 'woocommerce-elko-integration'); ?></h3>
            <p><?php esc_html_e('Recommended order: Categories → Products → Attributes → Prices', 'woocommerce-elko-integration'); ?></p>
            
            <div class="elko-sync-buttons" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 20px 0;">
                <button type="button" id="sync-categories" class="button button-primary">
                    📁 1. Sync Categories
                </button>
                
                <button type="button" id="sync-products" class="button button-primary">
                    📦 2. Sync Products
                </button>
                
                <button type="button" id="import-attributes" class="button button-primary">
                    🏷️ 3. Import Attributes
                </button>
                
                <button type="button" id="update-prices" class="button button-primary">
                    💰 4. Update Prices
                </button>
                
                <button type="button" id="fix-images" class="button button-secondary">
                    🖼️ Fix Images
                </button>
            </div>
            
            <!-- PROGRESS BAR -->
            <div id="sync-progress" class="elko-progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 0%;"></div>
                </div>
                <div class="progress-info" style="margin-top: 10px;">
                    <div class="progress-current" style="font-weight: bold;"></div>
                    <div class="progress-stats" style="font-size: 12px; color: #666;"></div>
                </div>
            </div>
            
            <div id="sync-result" style="margin-top: 15px; display: none; padding: 10px; border-radius: 4px;"></div>
        </div>
        
        <!-- DATA MANAGEMENT -->
        <div class="elko-sync-controls">
            <h3><?php esc_html_e('🧹 Data Management', 'woocommerce-elko-integration'); ?></h3>
            
            <div class="elko-alert elko-alert-danger" style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 4px;">
                <h4 style="margin: 0 0 10px 0;">⚠️ Danger Zone</h4>
                <p style="margin: 0 0 10px 0;">This will permanently delete ALL ELKO data!</p>
                <button type="button" id="clear-all-data" class="button" style="background: #dc3545; color: white; border-color: #dc3545;">
                    🗑️ Clear All ELKO Data
                </button>
            </div>
        </div>
        
        <!-- STATISTICS -->
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
     * Render settings tab
     */
    private function render_settings_tab() {
        $api_settings = get_option('elko_api_settings', array());
        $pricing_settings = get_option('elko_pricing_settings', array());
        $sync_settings = get_option('elko_sync_settings', array());
        ?>
        
        <div id="settings-result" style="display: none; padding: 12px; margin: 15px 0; border-radius: 4px;"></div>
        
        <div class="elko-sync-controls">
            <h3>🔑 API Settings</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">API URL</th>
                    <td>
                        <input type="text" name="elko_api_url" 
                               value="<?php echo esc_attr($api_settings['api_url'] ?? 'https://api.elko.cloud'); ?>" 
                               class="regular-text">
                        <p class="description">ELKO API base URL</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Key (JWT Token)</th>
                    <td>
                        <textarea name="elko_api_key" rows="4" class="large-text code"><?php echo esc_textarea($api_settings['api_key'] ?? ''); ?></textarea>
                        <p class="description">Enter your ELKO API JWT token. Get it from ELKO partner portal.</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="elko-sync-controls">
            <h3>💰 Pricing Settings</h3>
            <p>Configure how prices are calculated from ELKO wholesale prices.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Tax Percentage (%)</th>
                    <td>
                        <input type="number" name="elko_tax_percentage" 
                               value="<?php echo esc_attr($pricing_settings['tax_percentage'] ?? 21); ?>" 
                               min="0" max="100" step="0.1" class="small-text">
                        <p class="description">VAT/Tax percentage to add to base price (e.g., 21 for 21%)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Markup Percentage (%)</th>
                    <td>
                        <input type="number" name="elko_markup_percentage" 
                               value="<?php echo esc_attr($pricing_settings['markup_percentage'] ?? 15); ?>" 
                               min="0" max="500" step="0.1" class="small-text">
                        <p class="description">Your profit margin percentage (e.g., 15 for 15% markup)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Calculation Method</th>
                    <td>
                        <select name="elko_price_calculation_method">
                            <option value="simple" <?php selected($pricing_settings['price_calculation_method'] ?? 'simple', 'simple'); ?>>Simple: (Base + Tax) × (1 + Markup)</option>
                            <option value="compound" <?php selected($pricing_settings['price_calculation_method'] ?? 'simple', 'compound'); ?>>Compound: Base × (1 + Tax) × (1 + Markup)</option>
                        </select>
                        <p class="description">Method for calculating final price</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Round Prices</th>
                    <td>
                        <label>
                            <input type="checkbox" name="elko_round_prices" value="1" 
                                   <?php checked($pricing_settings['round_prices'] ?? true, true); ?>>
                            Round prices to 2 decimal places
                        </label>
                    </td>
                </tr>
            </table>
            
            <!-- Price Preview Calculator -->
            <div style="background: #f0f6fc; border: 1px solid #0073aa; padding: 20px; border-radius: 8px; margin-top: 20px;">
                <h4 style="margin-top: 0;">🧮 Price Preview Calculator</h4>
                <p>Enter a test price to see how your settings affect the final price:</p>
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <label><strong>ELKO Price (€):</strong></label><br>
                        <input type="number" id="price-preview-input" value="100" min="0" step="0.01" style="width: 120px;">
                    </div>
                    <div style="font-size: 24px;">→</div>
                    <div>
                        <label><strong>Final Price:</strong></label><br>
                        <span id="price-preview-result" style="font-size: 24px; font-weight: bold; color: #0073aa;">€0.00</span>
                    </div>
                </div>
                <p id="price-preview-breakdown" style="margin-top: 10px; color: #666; font-size: 12px;"></p>
            </div>
        </div>
        
        <div class="elko-sync-controls">
            <h3>⏰ Sync Settings</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Sync Frequency</th>
                    <td>
                        <select name="elko_sync_frequency">
                            <option value="hourly" <?php selected($sync_settings['sync_frequency'] ?? 'daily', 'hourly'); ?>>Hourly</option>
                            <option value="twicedaily" <?php selected($sync_settings['sync_frequency'] ?? 'daily', 'twicedaily'); ?>>Twice Daily</option>
                            <option value="daily" <?php selected($sync_settings['sync_frequency'] ?? 'daily', 'daily'); ?>>Daily</option>
                            <option value="weekly" <?php selected($sync_settings['sync_frequency'] ?? 'daily', 'weekly'); ?>>Weekly</option>
                        </select>
                        <p class="description">How often to run automatic synchronization (when scheduler is enabled)</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div style="margin-top: 20px;">
            <button type="button" id="save-elko-settings" class="button button-primary button-large">
                💾 Save Settings
            </button>
            <button type="button" id="test-connection" class="button button-secondary button-large" style="margin-left: 10px;">
                🔌 Test API Connection
            </button>
        </div>
        
        <div id="debug-result" style="display: none; padding: 12px; margin: 15px 0; border-radius: 4px;"></div>
        
        <?php
    }
    
    /**
     * Render cron jobs tab
     */
    private function render_cron_tab() {
        $scheduler_status = $this->get_scheduler_status();
        $cron_secret_key = get_option('elko_cron_secret_key', '');
        if (empty($cron_secret_key)) {
            $cron_secret_key = wp_generate_password(32, false);
            update_option('elko_cron_secret_key', $cron_secret_key);
        }
        $plugin_path = plugin_dir_path(dirname(__FILE__));
        ?>
        <div class="elko-sync-controls">
            <h3>⏰ Cron Jobs Configuration</h3>
            
            <!-- DIRECT CRON RUNNER - RECOMMENDED -->
            <div class="elko-alert" style="background: #e8f5e9; border: 2px solid #4caf50; padding: 20px; border-radius: 4px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 15px 0; color: #2e7d32;">🚀 ПРЯМОЙ ЗАПУСК CRON (Рекомендуется для Zone.ee)</h4>
                <p>Используйте специальный файл <code>cron-runner.php</code> для прямого запуска без WP-Cron:</p>
                
                <p><strong>📦 Импорт товаров:</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=sync_products"</pre>
                
                <p><strong>💰 Обновление цен:</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=update_prices"</pre>
                
                <p><strong>📁 Синхронизация категорий:</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=sync_categories"</pre>
                
                <p><strong>🖼️ Исправление изображений:</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=fix_images"</pre>
                
                <p><strong>🏷️ Импорт атрибутов:</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">
/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=import_attributes"</pre>
                
                <p style="margin-top: 15px;"><strong>⚠️ Ваш секретный ключ:</strong> <code style="background: #ffeb3b; padding: 2px 6px;"><?php echo esc_html($cron_secret_key); ?></code></p>
                <p style="font-size: 12px; color: #666;">Этот ключ защищает cron от несанкционированного доступа. НЕ делитесь им публично!</p>
            </div>
            
            <div class="elko-alert" style="background: #fff3e0; border: 1px solid #ff9800; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;">🌐 Zone.ee - Пример настройки Cron</h4>
                <p>В панели Zone.ee добавьте cron задание:</p>
                
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>Задача</th>
                            <th>Расписание</th>
                            <th>Команда</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Импорт товаров</td>
                            <td>Раз в день в 3:00</td>
                            <td style="font-size: 11px;"><code>/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=sync_products"</code></td>
                        </tr>
                        <tr>
                            <td>Обновление цен</td>
                            <td>Каждые 30 мин</td>
                            <td style="font-size: 11px;"><code>/usr/bin/curl -s "<?php echo plugins_url('cron-runner.php', dirname(__FILE__)); ?>?key=<?php echo esc_attr($cron_secret_key); ?>&action=update_prices"</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="elko-alert" style="background: #e3f2fd; border: 1px solid #2196f3; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;">📋 Альтернатива: WP-Cron (требует включения Scheduler)</h4>
                <p>Если хотите использовать WP-Cron, нужно:</p>
                <ol>
                    <li>Нажать кнопку "🔄 Enable Scheduler" на вкладке Synchronization</li>
                    <li>Добавить в cron: <code>/usr/bin/curl -s "<?php echo site_url('/wp-cron.php?doing_wp_cron'); ?>"</code></li>
                </ol>
                <p style="color: #666; font-size: 12px;">Примечание: WP-Cron менее надёжен, чем прямой запуск через cron-runner.php</p>
            </div>
            
            <h4>Current Scheduled Events:</h4>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Scheduled</th>
                        <th>Next Run</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Product Sync</td>
                        <td><?php echo $scheduler_status['products']['scheduled'] ? '✅ Yes' : '❌ No'; ?></td>
                        <td><?php echo esc_html($scheduler_status['products']['next_run']); ?></td>
                    </tr>
                    <tr>
                        <td>Category Sync</td>
                        <td><?php echo $scheduler_status['categories']['scheduled'] ? '✅ Yes' : '❌ No'; ?></td>
                        <td><?php echo esc_html($scheduler_status['categories']['next_run']); ?></td>
                    </tr>
                    <tr>
                        <td>Price Update</td>
                        <td><?php echo $scheduler_status['prices']['scheduled'] ? '✅ Yes' : '❌ No'; ?></td>
                        <td><?php echo esc_html($scheduler_status['prices']['next_run']); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top: 20px;">Environment Variables (for wp-config.php):</h4>
            <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">
// Отключить встроенный WP-Cron (рекомендуется при использовании server cron)
define('DISABLE_WP_CRON', true);

// ELKO Integration Settings (optional)
define('ELKO_API_URL', 'https://api.elko.cloud');
define('ELKO_SYNC_FREQUENCY', 'daily'); // hourly, twicedaily, daily, weekly
define('ELKO_BATCH_SIZE', 50);
            </pre>
        </div>
        <?php
    }
    
    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        global $wpdb;
        $logs_table = $wpdb->prefix . 'elko_logs';
        
        $logs = array();
        if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table) {
            $logs = $wpdb->get_results(
                "SELECT * FROM {$logs_table} ORDER BY created_at DESC LIMIT 100"
            );
        }
        ?>
        <div class="elko-sync-controls">
            <h3>📋 Recent Logs</h3>
            
            <?php if (empty($logs)): ?>
                <p>No logs available.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="log-<?php echo esc_attr($log->status); ?>">
                                <td><?php echo esc_html($log->created_at); ?></td>
                                <td><?php echo esc_html($log->sync_type); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render debug tab
     */
    private function render_debug_tab() {
        ?>
        <div class="elko-sync-controls">
            <h3>🔍 Debug Tools</h3>
            <p>Debug tools for API testing and troubleshooting.</p>
            
            <div style="display: flex; gap: 10px; margin: 20px 0;">
                <button type="button" id="test-connection" class="button">🔌 Test API Connection</button>
                <button type="button" id="debug-endpoints" class="button">🔍 Debug Endpoints</button>
            </div>
            
            <div id="debug-result" style="margin-top: 15px; display: none; padding: 15px; border-radius: 4px; background: #f5f5f5;"></div>
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
                'next_run' => wp_next_scheduled('elko_sync_products') 
                    ? date('Y-m-d H:i:s', wp_next_scheduled('elko_sync_products')) 
                    : 'Not scheduled'
            ),
            'categories' => array(
                'scheduled' => wp_next_scheduled('elko_sync_categories') !== false,
                'next_run' => wp_next_scheduled('elko_sync_categories') 
                    ? date('Y-m-d H:i:s', wp_next_scheduled('elko_sync_categories')) 
                    : 'Not scheduled'
            ),
            'prices' => array(
                'scheduled' => wp_next_scheduled('elko_update_prices') !== false,
                'next_run' => wp_next_scheduled('elko_update_prices') 
                    ? date('Y-m-d H:i:s', wp_next_scheduled('elko_update_prices')) 
                    : 'Not scheduled'
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
}
