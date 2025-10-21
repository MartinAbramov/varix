<?php
/**
 * ELKO Logger
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Logger {
    
    /**
     * Log message
     */
    public static function log($message, $level = 'info', $data = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'elko_sync_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'sync_type' => 'general',
                'status' => $level,
                'message' => $message,
                'data' => $data ? json_encode($data) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ELKO Integration] ' . $level . ': ' . $message);
        }
    }
    
    /**
     * Log sync operation
     */
    public static function log_sync($sync_type, $status, $message, $data = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'elko_sync_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'sync_type' => $sync_type,
                'status' => $status,
                'message' => $message,
                'data' => $data ? json_encode($data) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get logs
     */
    public static function get_logs($limit = 100, $offset = 0, $sync_type = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'elko_sync_logs';
        
        $where = '';
        if ($sync_type) {
            $where = $wpdb->prepare(' WHERE sync_type = %s', $sync_type);
        }
        
        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Clear old logs
     */
    public static function clear_logs($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'elko_sync_logs';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}