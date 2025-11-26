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

// Check if running from CLI or with valid secret key
$is_cli = (php_sapi_name() === 'cli');
$is_valid_key = (!empty($secret_key) && $secret_key === $stored_key);

if (!$is_cli && !$is_valid_key) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. Use CLI or provide valid secret key.');
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

try {
    switch ($action) {
        case 'sync_products':
            echo "Starting product sync...\n";
            if (class_exists('ELKO_Product_Importer')) {
                $importer = new ELKO_Product_Importer();
                $result = $importer->import_products();
                
                if ($result !== false) {
                    update_option('elko_last_product_sync', current_time('mysql'));
                    echo "✅ Product sync completed. Imported: {$result} products\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Product sync completed. Imported: {$result}");
                    }
                } else {
                    echo "❌ Product sync failed\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'error', 'Product sync failed');
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
                $result = $importer->fix_all_images();
                
                if ($result !== false) {
                    echo "✅ Image fix completed. Fixed: {$result} products\n";
                    if (class_exists('ELKO_Logger')) {
                        ELKO_Logger::log_sync('cron-runner', 'success', "Image fix completed. Fixed: {$result}");
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
                $result = $importer->import_attributes_only();
                
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
