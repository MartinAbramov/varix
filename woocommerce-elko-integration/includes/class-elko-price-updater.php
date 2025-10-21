<?php
/**
 * ELKO Price Updater
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Price_Updater {
    
    private $api_client;
    private $price_calculator;
    
    public function __construct() {
        $this->api_client = new ELKO_API_Client();
        $this->price_calculator = new ELKO_Price_Calculator();
    }
    
    /**
     * Update prices for all ELKO products
     */
    public function update_all_prices() {
        ELKO_Logger::log_sync('prices', 'started', 'Starting price update for all products');
        
        try {
            // Get all products with ELKO IDs
            $elko_products = $this->get_elko_products();
            
            if (empty($elko_products)) {
                ELKO_Logger::log_sync('prices', 'error', 'No ELKO products found to update');
                return false;
            }
            
            ELKO_Logger::log("Found " . count($elko_products) . " ELKO products to update", 'info');
            
            $updated_count = 0;
            $allowed_categories = $this->api_client->get_allowed_categories();
            
            // Update prices for each category
            foreach ($allowed_categories as $category_name => $category_code_id) {
                $products = $this->api_client->get_products_by_category($category_code_id);
                
                if (is_wp_error($products)) {
                    ELKO_Logger::log("Failed to get products for category {$category_name}: " . $products->get_error_message(), 'error');
                    continue;
                }
                
                if (!is_array($products) || empty($products)) {
                    continue;
                }
                
                foreach ($products as $product_data) {
                    if ($this->update_product_price($product_data)) {
                        $updated_count++;
                    }
                }
            }
            
            ELKO_Logger::log_sync('prices', 'success', "Successfully updated prices for {$updated_count} products");
            return $updated_count;
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('prices', 'error', 'Price update failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update single product price
     */
    private function update_product_price($product_data) {
        if (!isset($product_data['id']) || !isset($product_data['price'])) {
            return false;
        }
        
        $elko_id = $product_data['id'];
        $elko_price = $product_data['price'];
        
        // Find WooCommerce product
        $wc_product_id = $this->get_wc_product_by_elko_id($elko_id);
        
        if (!$wc_product_id) {
            return false;
        }
        
        try {
            $wc_product = wc_get_product($wc_product_id);
            
            if (!$wc_product) {
                ELKO_Logger::log("Failed to load WooCommerce product ID: {$wc_product_id}", 'error');
                return false;
            }
            
            // Calculate final price
            $final_price = $this->price_calculator->calculate_price($elko_price);
            
            if ($final_price <= 0) {
                ELKO_Logger::log("Invalid calculated price for product {$elko_id}: {$final_price}", 'warning');
                return false;
            }
            
            // Update prices
            $wc_product->set_regular_price($final_price);
            $wc_product->set_price($final_price);
            
            // Update stock
            if (isset($product_data['quantity'])) {
                $stock_quantity = $this->parse_stock_quantity($product_data['quantity']);
                $wc_product->set_stock_quantity($stock_quantity);
                $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            }
            
            $wc_product->save();
            
            // Update meta data
            update_post_meta($wc_product_id, '_elko_original_price', $elko_price);
            update_post_meta($wc_product_id, '_elko_final_price', $final_price);
            update_post_meta($wc_product_id, '_elko_price_updated', current_time('mysql'));
            
            ELKO_Logger::log("Updated price for product {$elko_id}: {$elko_price} EUR -> {$final_price} EUR", 'info');
            return true;
            
        } catch (Exception $e) {
            ELKO_Logger::log("Error updating price for product {$elko_id}: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Get all WooCommerce products with ELKO IDs
     */
    private function get_elko_products() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT post_id, meta_value as elko_id 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_elko_product_id'"
        );
    }
    
    /**
     * Get WooCommerce product ID by ELKO ID
     */
    private function get_wc_product_by_elko_id($elko_id) {
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_elko_product_id' AND meta_value = %s",
                $elko_id
            )
        );
    }
    
    /**
     * Parse stock quantity from ELKO format
     */
    private function parse_stock_quantity($quantity_string) {
        $quantity_string = trim($quantity_string);
        
        if ($quantity_string === '0' || empty($quantity_string)) {
            return 0;
        }
        
        if (strpos($quantity_string, '>') === 0) {
            // "> 50" -> return number after >
            return intval(trim(str_replace('>', '', $quantity_string)));
        }
        
        if (is_numeric($quantity_string)) {
            return intval($quantity_string);
        }
        
        return 0;
    }
}