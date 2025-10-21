<?php
/**
 * ELKO Scheduler - With enable/disable control
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Scheduler {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('elko_sync_products', array($this, 'scheduled_sync_products'));
        add_action('elko_sync_categories', array($this, 'scheduled_sync_categories'));
        add_action('elko_update_prices', array($this, 'scheduled_update_prices'));
        add_action('elko_cleanup_logs', array($this, 'scheduled_cleanup_logs'));
    }
    
    /**
     * Initialize scheduler
     */
    public function init() {
        // Only schedule if scheduler is enabled
        if (get_option('elko_scheduler_enabled', false)) {
            $this->schedule_events();
        } else {
            // If disabled, make sure no events are scheduled
            $this->clear_events();
        }
    }
    
    /**
     * Schedule all events
     */
    public function schedule_events() {
        // Only schedule if enabled
        if (!get_option('elko_scheduler_enabled', false)) {
            return;
        }
        
        $sync_settings = get_option('elko_sync_settings', array());
        $frequency = $sync_settings['sync_frequency'] ?? 'daily';
        
        // Schedule product sync
        if (!wp_next_scheduled('elko_sync_products')) {
            wp_schedule_event(time() + 300, $frequency, 'elko_sync_products'); // 5 minutes from now
        }
        
        // Schedule category sync (less frequent)
        if (!wp_next_scheduled('elko_sync_categories')) {
            wp_schedule_event(time() + 600, 'weekly', 'elko_sync_categories'); // 10 minutes from now
        }
        
        // Schedule price updates (more frequent)
        if (!wp_next_scheduled('elko_update_prices')) {
            wp_schedule_event(time() + 900, 'hourly', 'elko_update_prices'); // 15 minutes from now
        }
        
        // Schedule log cleanup (weekly)
        if (!wp_next_scheduled('elko_cleanup_logs')) {
            wp_schedule_event(time() + 1200, 'weekly', 'elko_cleanup_logs'); // 20 minutes from now
        }
        
        ELKO_Logger::log_sync('scheduler', 'success', 'Scheduled events created');
    }
    
    /**
     * Clear all scheduled events
     */
    public function clear_events() {
        wp_clear_scheduled_hook('elko_sync_products');
        wp_clear_scheduled_hook('elko_sync_categories');
        wp_clear_scheduled_hook('elko_update_prices');
        wp_clear_scheduled_hook('elko_cleanup_logs');
        
        ELKO_Logger::log_sync('scheduler', 'success', 'All scheduled events cleared');
    }
    
    /**
     * Scheduled product sync
     */
    public function scheduled_sync_products() {
        // Check if scheduler is still enabled
        if (!get_option('elko_scheduler_enabled', false)) {
            ELKO_Logger::log_sync('products', 'skipped', 'Scheduler disabled - skipping scheduled product sync');
            return;
        }
        
        try {
            ELKO_Logger::log_sync('products', 'started', 'Starting scheduled product sync');
            
            $importer = new ELKO_Product_Importer();
            $result = $importer->import_products();
            
            if ($result !== false) {
                update_option('elko_last_product_sync', current_time('mysql'));
                ELKO_Logger::log_sync('products', 'success', "Scheduled sync imported {$result} products");
            } else {
                ELKO_Logger::log_sync('products', 'error', 'Scheduled product sync failed');
            }
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('products', 'error', 'Scheduled product sync error: ' . $e->getMessage());
        }
    }
    
    /**
     * Scheduled category sync
     */
    public function scheduled_sync_categories() {
        // Check if scheduler is still enabled
        if (!get_option('elko_scheduler_enabled', false)) {
            ELKO_Logger::log_sync('categories', 'skipped', 'Scheduler disabled - skipping scheduled category sync');
            return;
        }
        
        try {
            ELKO_Logger::log_sync('categories', 'started', 'Starting scheduled category sync');
            
            $importer = new ELKO_Category_Importer();
            $result = $importer->import_categories_with_tree();
            
            if ($result !== false) {
                update_option('elko_last_category_sync', current_time('mysql'));
                ELKO_Logger::log_sync('categories', 'success', "Scheduled sync imported {$result} categories");
            } else {
                ELKO_Logger::log_sync('categories', 'error', 'Scheduled category sync failed');
            }
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('categories', 'error', 'Scheduled category sync error: ' . $e->getMessage());
        }
    }
    
    /**
     * Scheduled price update
     */
    public function scheduled_update_prices() {
        // Check if scheduler is still enabled
        if (!get_option('elko_scheduler_enabled', false)) {
            ELKO_Logger::log_sync('prices', 'skipped', 'Scheduler disabled - skipping scheduled price update');
            return;
        }
        
        try {
            ELKO_Logger::log_sync('prices', 'started', 'Starting scheduled price update');
            
            $updater = new ELKO_Price_Updater();
            $result = $updater->update_all_prices();
            
            if ($result !== false) {
                update_option('elko_last_price_update', current_time('mysql'));
                ELKO_Logger::log_sync('prices', 'success', "Scheduled update processed {$result} products");
            } else {
                ELKO_Logger::log_sync('prices', 'error', 'Scheduled price update failed');
            }
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('prices', 'error', 'Scheduled price update error: ' . $e->getMessage());
        }
    }
    
    /**
     * Scheduled log cleanup
     */
    public function scheduled_cleanup_logs() {
        // Check if scheduler is still enabled
        if (!get_option('elko_scheduler_enabled', false)) {
            return;
        }
        
        try {
            $deleted = ELKO_Logger::cleanup_old_logs(30); // Keep logs for 30 days
            ELKO_Logger::log_sync('cleanup', 'success', "Cleaned up {$deleted} old log entries");
        } catch (Exception $e) {
            ELKO_Logger::log_sync('cleanup', 'error', 'Log cleanup error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get schedule status
     */
    public function get_schedule_status() {
        $next_times = array(
            'products' => wp_next_scheduled('elko_sync_products'),
            'categories' => wp_next_scheduled('elko_sync_categories'),
            'prices' => wp_next_scheduled('elko_update_prices'),
            'cleanup' => wp_next_scheduled('elko_cleanup_logs'),
        );
        
        $status = array();
        
        foreach ($next_times as $type => $timestamp) {
            if ($timestamp) {
                $status[$type] = array(
                    'scheduled' => true,
                    'next_run' => date('Y-m-d H:i:s', $timestamp),
                    'time_until' => human_time_diff(time(), $timestamp)
                );
            } else {
                $status[$type] = array(
                    'scheduled' => false,
                    'next_run' => 'Not scheduled',
                    'time_until' => 'N/A'
                );
            }
        }
        
        return $status;
    }
}