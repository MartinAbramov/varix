<?php
/**
 * ELKO Integration Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default plugin settings
 */
function elko_get_default_settings() {
    return array(
        'api' => array(
            'api_url' => 'https://api.elko.cloud',
            'username' => '',
            'password' => '',
            'token' => '',
            'token_expires' => 0,
            'timeout' => 60,
            'retry_attempts' => 3,
        ),
        'sync' => array(
            'allowed_categories' => array(
                '_4015', // PC Components
                '_6838', // Переферия
                '_7127', // Смартфоны
                '_4018', // Networking
                '_6341', // Телевизоры
                '_4021', // Сервера
            ),
            'sync_frequency' => 'daily',
            'import_images' => true,
            'update_existing' => true,
            'auto_publish' => false,
            'batch_size' => 100,
            'image_quality' => 80,
            'max_image_size' => 2048,
        ),
        'advanced' => array(
            'log_retention_days' => 30,
            'enable_debug' => false,
            'cache_duration' => 3600,
            'parallel_requests' => 1,
            'memory_limit' => '512M',
        ),
    );
}

/**
 * Get plugin setting
 */
function elko_get_setting($key, $default = null) {
    $parts = explode('.', $key);
    
    if (count($parts) === 2) {
        $group = $parts[0];
        $setting = $parts[1];
        
        $options = get_option('elko_' . $group . '_settings', array());
        return $options[$setting] ?? $default;
    }
    
    return $default;
}

/**
 * Update plugin setting
 */
function elko_update_setting($key, $value) {
    $parts = explode('.', $key);
    
    if (count($parts) === 2) {
        $group = $parts[0];
        $setting = $parts[1];
        
        $options = get_option('elko_' . $group . '_settings', array());
        $options[$setting] = $value;
        
        return update_option('elko_' . $group . '_settings', $options);
    }
    
    return false;
}

/**
 * Get ELKO category names
 */
function elko_get_category_names() {
    return array(
        '_4015' => __('PC Components', 'woocommerce-elko-integration'),
        '_6838' => __('Peripherals', 'woocommerce-elko-integration'),
        '_7127' => __('Smartphones', 'woocommerce-elko-integration'),
        '_4018' => __('Networking', 'woocommerce-elko-integration'),
        '_6341' => __('TVs', 'woocommerce-elko-integration'),
        '_4021' => __('Servers', 'woocommerce-elko-integration'),
    );
}

/**
 * Validate API credentials
 */
function elko_validate_api_credentials($username, $password, $api_url = null) {
    if (empty($username) || empty($password)) {
        return new WP_Error('missing_credentials', __('Username and password are required.', 'woocommerce-elko-integration'));
    }
    
    $api_url = $api_url ?: 'https://api.elko.cloud';
    
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', __('Invalid API URL.', 'woocommerce-elko-integration'));
    }
    
    return true;
}

/**
 * Get sync statistics
 */
function elko_get_sync_stats() {
    global $wpdb;
    
    $stats = array();
    
    // Count imported products
    $stats['products'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p 
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
         WHERE p.post_type = 'product' AND pm.meta_key = '_elko_product_id'"
    );
    
    // Count imported categories
    $stats['categories'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->termmeta} 
         WHERE meta_key = 'elko_category_id'"
    );
    
    // Get last sync times
    $stats['last_category_sync'] = get_option('elko_last_category_sync');
    $stats['last_product_sync'] = get_option('elko_last_product_sync');
    $stats['last_price_update'] = get_option('elko_last_price_update');
    
    // Get recent log counts
    $log_table = $wpdb->prefix . 'elko_sync_logs';
    $stats['recent_errors'] = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} 
             WHERE status = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )
    );
    
    return $stats;
}

/**
 * Clean up plugin data
 */
function elko_cleanup_data() {
    global $wpdb;
    
    // Remove product meta
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_elko_product_id'));
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_elko_last_price_update'));
    
    // Remove category meta
    $wpdb->delete($wpdb->termmeta, array('meta_key' => 'elko_category_id'));
    
    // Remove image meta
    $wpdb->delete($wpdb->postmeta, array('meta_key' => '_elko_image_url'));
    
    // Clear logs
    if (class_exists('ELKO_Logger')) {
        ELKO_Logger::clear_logs();
    }
    
    // Remove options
    delete_option('elko_api_settings');
    delete_option('elko_sync_settings');
    delete_option('elko_advanced_settings');
    delete_option('elko_category_mapping');
    delete_option('elko_last_category_sync');
    delete_option('elko_last_product_sync');
    delete_option('elko_last_price_update');
}