<?php
/**
 * ELKO Direct Cron Runner
 * 
 * Этот файл можно вызывать напрямую через server-side cron
 * 
 * Использование для Zone.ee:
 * /usr/bin/php /path/to/wordpress/wp-content/plugins/woocommerce-elko-integration/cron-runner.php
 * 
 * Или через curl:
 * /usr/bin/curl -s "https://your-site.ee/wp-content/plugins/woocommerce-elko-integration/cron-runner.php?key=YOUR_SECRET_KEY"
 */

// Set unlimited execution time for long imports
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
if (function_exists('ignore_user_abort')) {
    @ignore_user_abort(true);
}
if (function_exists('ini_set')) {
    @ini_set('memory_limit', '512M');
    @ini_set('max_execution_time', '0');
}

// Prevent direct web access without secret key
$secret_key = isset($_GET['key']) ? $_GET['key'] : '';
$stored_key = '';

// Load WordPress
$wp_load_paths = array(
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../../../wp-load.php',
    dirname(__FILE__) . '/../../../../../wp-load.php',
);

$wp_loaded = false;
foreach ($wp_load_paths as $wp_load_path) {
    if (file_exists($wp_load_path)) {
        require_once($wp_load_path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('WordPress not found. Please check plugin installation path.');
}

// Get stored secret key from options
$stored_key = get_option('elko_cron_secret_key', '');

// Generate secret key if not exists
if (empty($stored_key)) {
    $stored_key = wp_generate_password(32, false);
    update_option('elko_cron_secret_key', $stored_key);
}

// Check for internal background job token
$internal_token = isset($_GET['internal_token']) ? $_GET['internal_token'] : '';
$stored_internal_token = get_transient('elko_internal_job_token');

// Check if running from CLI or with valid secret key or valid internal token
$is_cli = (php_sapi_name() === 'cli');
$is_valid_key = (!empty($secret_key) && $secret_key === $stored_key);
$is_internal_job = (!empty($internal_token) && !empty($stored_internal_token) && $internal_token === $stored_internal_token);

if (!$is_cli && !$is_valid_key && !$is_internal_job) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. Use CLI or provide valid secret key.');
}

// If this is an internal job, delete the token after use (one-time use)
if ($is_internal_job) {
    delete_transient('elko_internal_job_token');
}

// Log start
if (class_exists('ELKO_Logger')) {
    ELKO_Logger::log_sync('cron-runner', 'started', 'Direct cron runner started');
}

echo "ELKO Cron Runner Started: " . date('Y-m-d H:i:s') . "\n";

// Determine action
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'sync_products';

// For CLI, check arguments
if ($is_cli && isset($argv[1])) {
    $action = $argv[1];
}

// Clear any previous stop flags before starting
delete_option('elko_import_stop_requested');
delete_option('elko_emergency_stop');
delete_option('elko_force_stop');

// Get session_id from URL parameter for progress tracking
$session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
if (empty($session_id)) {
    $session_id = 'cron_' . time() . '_' . wp_generate_password(8, false, false);
}

try {
    switch ($action) {
        case 'sync_products':
            echo "Starting product sync...\n";
            
            // Clear categories cache to ensure we have the latest from API
            if (class_exists('ELKO_API_Client')) {
                $api_client = new ELKO_API_Client();
                $api_client->clear_categories_cache();
                echo "Categories cache cleared.\n";
            }
            
            echo "Categories to process: all defined categories\n";
            if (class_exists('ELKO_Product_Importer')) {
                $importer = new ELKO_Product_Importer();
                
                // Get selected categories from URL parameter (optional)
                $selected_categories = array();
                if (isset($_GET['categories']) && !empty($_GET['categories'])) {
                    $selected_categories = explode(',', sanitize_text_field($_GET['categories']));
                    echo "Filtering by categories: " . implode(', ', $selected_categories) . "\n";
                }
                
                $result = $importer->import_products($selected_categories, $session_id);
                
                if ($result !== false) {
                    update_option('elko_last_product_sync', current_time('mysql'));
                    echo "✅ Product sync completed. Imported/Updated: {$result} products\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Product sync completed. Imported: {$result}");
                    }
                } else {
                    echo "❌ Product sync failed or was stopped\n";
                    // Check why it stopped
                    if (get_option('elko_import_stop_requested', false)) {
                        echo "⚠️ Reason: Stop was requested\n";
                    }
                    if (get_option('elko_emergency_stop', 0) > (time() - 300)) {
                        echo "⚠️ Reason: Emergency stop was triggered\n";
                    }
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'error', 'Product sync failed or stopped');
                    }
                }
            } else {
                echo "❌ ELKO_Product_Importer class not found\n";
            }
            break;
            
        case 'sync_categories':
            echo "Starting category sync...\n";
            if (class_exists('ELKO_Category_Importer')) {
                $importer = new ELKO_Category_Importer();
                $result = $importer->import_categories_with_tree();
                
                if ($result !== false) {
                    update_option('elko_last_category_sync', current_time('mysql'));
                    echo "✅ Category sync completed. Imported: {$result} categories\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Category sync completed. Imported: {$result}");
                    }
                } else {
                    echo "❌ Category sync failed\n";
                }
            } else {
                echo "❌ ELKO_Category_Importer class not found\n";
            }
            break;
            
        case 'update_prices':
            echo "Starting price update...\n";
            if (class_exists('ELKO_Price_Updater')) {
                $updater = new ELKO_Price_Updater();
                $result = $updater->update_all_prices();
                
                if ($result !== false) {
                    update_option('elko_last_price_update', current_time('mysql'));
                    echo "✅ Price update completed. Updated: {$result} products\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Price update completed. Updated: {$result}");
                    }
                } else {
                    echo "❌ Price update failed\n";
                }
            } else {
                echo "❌ ELKO_Price_Updater class not found\n";
            }
            break;
            
        case 'fix_images':
            echo "Starting image fix...\n";
            if (class_exists('ELKO_Product_Importer')) {
                $importer = new ELKO_Product_Importer();
                $category_msg = !empty($categories) ? "from " . count($categories) . " selected categories" : "from all categories";
                echo "Processing products {$category_msg}...\n";
                $result = $importer->fix_all_images($session_id, $categories);
                
                if ($result !== false) {
                    echo "✅ Image fix completed. Fixed: {$result} products {$category_msg}\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Image fix completed. Fixed: {$result} {$category_msg}");
                    }
                } else {
                    echo "❌ Image fix failed\n";
                }
            } else {
                echo "❌ ELKO_Product_Importer class not found\n";
            }
            break;
            
        case 'import_attributes':
            echo "Starting attributes import...\n";
            if (class_exists('ELKO_Product_Importer')) {
                $importer = new ELKO_Product_Importer();
                $result = $importer->import_attributes_only($session_id);
                
                if ($result !== false) {
                    echo "✅ Attributes import completed. Updated: {$result} products\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Attributes import completed. Updated: {$result}");
                    }
                } else {
                    echo "❌ Attributes import failed\n";
                }
            } else {
                echo "❌ ELKO_Product_Importer class not found\n";
            }
            break;
            
        case 'show_key':
            // Only for CLI - show the secret key
            if ($is_cli) {
                echo "Your secret key for web access: {$stored_key}\n";
                echo "Use URL: " . site_url('/wp-content/plugins/woocommerce-elko-integration/cron-runner.php?key=' . $stored_key . '&action=sync_products') . "\n";
            }
            break;
            
        default:
            echo "Unknown action: {$action}\n";
            echo "Available actions: sync_products, sync_categories, update_prices, fix_images, import_attributes\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    if (class_exists('ELKO_Logger')) {
        ELKO_Logger::log_sync('cron-runner', 'error', 'Cron runner error: ' . $e->getMessage());
    }
}

echo "ELKO Cron Runner Finished: " . date('Y-m-d H:i:s') . "\n";
