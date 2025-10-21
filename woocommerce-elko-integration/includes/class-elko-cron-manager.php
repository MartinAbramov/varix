<?php
/**
 * ELKO Cron Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Cron_Manager {
    
    public function __construct() {
        add_action('elko_daily_sync', array($this, 'run_daily_sync'));
        add_action('elko_import_products_page', array($this, 'import_products_page'), 10, 2);
        add_filter('cron_schedules', array($this, 'add_custom_schedules'));
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_custom_schedules($schedules) {
        $schedules['every_30_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'woocommerce-elko-integration'),
        );
        
        return $schedules;
    }
    
    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        $settings = get_option('elko_sync_settings', array());
        $frequency = $settings['sync_frequency'] ?? 'daily';
        
        // Clear existing events
        self::clear_scheduled_events();
        
        // Schedule daily sync
        if (!wp_next_scheduled('elko_daily_sync')) {
            wp_schedule_event(time(), $frequency, 'elko_daily_sync');
        }
    }
    
    /**
     * Clear scheduled events
     */
    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook('elko_daily_sync');
        wp_clear_scheduled_hook('elko_import_products_page');
    }
    
    /**
     * Run daily sync
     */
    public function run_daily_sync() {
        ELKO_Logger::log_sync('cron', 'started', 'Starting scheduled sync');
        
        try {
            // Update prices first (faster operation)
            $price_updater = new ELKO_Price_Updater();
            $price_updater->update_all_prices();
            
            // Import/update categories
            $category_importer = new ELKO_Category_Importer();
            $category_importer->import_categories();
            
            // Import/update products (first page)
            $product_importer = new ELKO_Product_Importer();
            $product_importer->import_products(1, 100);
            
            ELKO_Logger::log_sync('cron', 'success', 'Scheduled sync completed successfully');
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('cron', 'error', 'Scheduled sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Import products page (for paginated import)
     */
    public function import_products_page($page, $limit) {
        $product_importer = new ELKO_Product_Importer();
        $product_importer->import_products($page, $limit);
    }
    
    /**
     * Get next scheduled sync time
     */
    public static function get_next_sync_time() {
        $timestamp = wp_next_scheduled('elko_daily_sync');
        return $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : null;
    }
    
    /**
     * Manually trigger sync
     */
    public static function trigger_manual_sync() {
        if (!wp_next_scheduled('elko_daily_sync')) {
            wp_schedule_single_event(time() + 10, 'elko_daily_sync');
            return true;
        }
        return false;
    }
}