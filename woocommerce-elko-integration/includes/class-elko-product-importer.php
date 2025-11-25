<?php
/**
 * ELKO Product Importer - Enhanced with progress tracking, stop support, and manufacturerCode as SKU
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Product_Importer {
    
    private $api_client;
    private $price_calculator;
    private $session_id;
    private $progress_table;
    
    public function __construct() {
        global $wpdb;
        $this->api_client = new ELKO_API_Client();
        $this->price_calculator = new ELKO_Price_Calculator();
        $this->progress_table = $wpdb->prefix . 'elko_import_progress';
    }
    
    /**
     * Check if import should stop
     */
    private function should_stop() {
        // Check emergency stop
        if (get_option('elko_emergency_stop', 0) > (time() - 300)) {
            return true;
        }
        
        // Check force stop
        if (get_option('elko_force_stop', 0) > (time() - 60)) {
            return true;
        }
        
        // Check graceful stop request
        if (get_option('elko_import_stop_requested', false)) {
            return true;
        }
        
        // Check session-specific stop
        if (!empty($this->session_id) && get_option('elko_stop_session_' . $this->session_id, false)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Initialize progress tracking
     */
    private function init_progress($import_type, $total_items, $session_id) {
        global $wpdb;
        
        $this->session_id = $session_id;
        
        // Ensure table exists
        $this->ensure_progress_table();
        
        $wpdb->insert(
            $this->progress_table,
            array(
                'import_type' => $import_type,
                'session_id' => $session_id,
                'total_items' => $total_items,
                'processed_items' => 0,
                'current_item' => '',
                'status' => 'running',
                'started_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Ensure progress table exists
     */
    private function ensure_progress_table() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->progress_table}'") == $this->progress_table;
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$this->progress_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                import_type varchar(50) NOT NULL,
                session_id varchar(64) NOT NULL,
                total_items int(11) NOT NULL DEFAULT 0,
                processed_items int(11) NOT NULL DEFAULT 0,
                current_item varchar(255) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'running',
                started_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at datetime DEFAULT NULL,
                error_count int(11) NOT NULL DEFAULT 0,
                last_error text,
                PRIMARY KEY (id),
                KEY session_id (session_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Update progress
     */
    private function update_progress($current_item, $processed = null, $error = null) {
        global $wpdb;
        
        if (empty($this->session_id)) {
            return;
        }
        
        $update_data = array(
            'current_item' => $current_item,
            'updated_at' => current_time('mysql')
        );
        
        if ($processed !== null) {
            $update_data['processed_items'] = $processed;
        }
        
        if ($error !== null) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->progress_table} SET error_count = error_count + 1, last_error = %s WHERE session_id = %s",
                $error,
                $this->session_id
            ));
        }
        
        $wpdb->update(
            $this->progress_table,
            $update_data,
            array('session_id' => $this->session_id)
        );
    }
    
    /**
     * Complete progress
     */
    private function complete_progress($status = 'completed') {
        global $wpdb;
        
        if (empty($this->session_id)) {
            return;
        }
        
        $wpdb->update(
            $this->progress_table,
            array(
                'status' => $status,
                'completed_at' => current_time('mysql')
            ),
            array('session_id' => $this->session_id)
        );
        
        // Clean up session-specific stop flag
        delete_option('elko_stop_session_' . $this->session_id);
    }
    
    /**
     * Import products with category selection and progress tracking
     */
    public function import_products($selected_categories = array(), $session_id = '') {
        if ($this->should_stop()) {
            ELKO_Logger::log_sync('products', 'stopped', 'Import stopped due to stop flag');
            return false;
        }
        
        try {
            $categories = $this->get_third_level_categories();
            
            // Filter categories if selection provided
            if (!empty($selected_categories)) {
                $categories = array_filter($categories, function($code) use ($selected_categories) {
                    return in_array($code, $selected_categories);
                });
            }
            
            if (empty($categories)) {
                ELKO_Logger::log_sync('products', 'info', 'No categories selected for import');
                return 0;
            }
            
            // Count total products first
            $total_products = $this->count_products_in_categories($categories);
            
            if (!empty($session_id)) {
                $this->init_progress('products', $total_products, $session_id);
            }
            
            $imported_count = 0;
            $processed = 0;
            
            ELKO_Logger::log_sync('products', 'started', "Processing " . count($categories) . " categories with {$total_products} total products");
            
            foreach ($categories as $category_name => $category_code_id) {
                if ($this->should_stop()) {
                    ELKO_Logger::log_sync('products', 'stopped', "Import stopped at category: {$category_name}");
                    $this->complete_progress('stopped');
                    return $imported_count;
                }
                
                $this->update_progress("Loading category: {$category_name}");
                
                // Get products one by one with attributes
                $products = $this->api_client->get_products_by_category($category_code_id);
                
                if (is_wp_error($products)) {
                    $this->update_progress("Error: " . $products->get_error_message(), null, $products->get_error_message());
                    continue;
                }
                
                if (!is_array($products) || empty($products)) {
                    continue;
                }
                
                foreach ($products as $product_data) {
                    if ($this->should_stop()) {
                        ELKO_Logger::log_sync('products', 'stopped', "Import stopped during processing");
                        $this->complete_progress('stopped');
                        return $imported_count;
                    }
                    
                    $product_name = $product_data['name'] ?? 'Unknown Product';
                    $processed++;
                    
                    $this->update_progress($product_name, $processed);
                    
                    // Get product attributes separately
                    if (isset($product_data['id'])) {
                        $descriptions = $this->api_client->get_product_descriptions($product_data['id'], 'EE');
                        if (!is_wp_error($descriptions) && is_array($descriptions)) {
                            foreach ($descriptions as $desc) {
                                if (isset($desc['productId']) && $desc['productId'] == $product_data['id']) {
                                    $product_data['detailed_description'] = $desc;
                                    $product_data['attributes'] = $this->parse_product_attributes($desc);
                                    break;
                                }
                            }
                        }
                        
                        // Get product media
                        $media = $this->api_client->get_product_media($product_data['id']);
                        if (!is_wp_error($media) && is_array($media)) {
                            foreach ($media as $media_item) {
                                if (isset($media_item['id']) && $media_item['id'] == $product_data['id']) {
                                    $product_data['gallery'] = $media_item['mediaFiles'] ?? array();
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Clean product name
                    $product_data['name'] = $this->clean_product_name($product_data['name']);
                    
                    if ($this->import_single_product($product_data, $category_name)) {
                        $imported_count++;
                    }
                    
                    usleep(100000); // 0.1 second delay
                }
            }
            
            $this->complete_progress('completed');
            ELKO_Logger::log_sync('products', 'success', "Successfully imported {$imported_count} products");
            return $imported_count;
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('products', 'error', 'Product import failed: ' . $e->getMessage());
            $this->complete_progress('error');
            return false;
        }
    }
    
    /**
     * Import attributes only for existing products
     */
    public function import_attributes_only($session_id = '') {
        if ($this->should_stop()) {
            return false;
        }
        
        try {
            global $wpdb;
            
            // Get all products with ELKO IDs
            $elko_products = $wpdb->get_results(
                "SELECT post_id, meta_value as elko_id FROM {$wpdb->postmeta} WHERE meta_key = '_elko_product_id'"
            );
            
            if (empty($elko_products)) {
                return 0;
            }
            
            $total = count($elko_products);
            
            if (!empty($session_id)) {
                $this->init_progress('attributes', $total, $session_id);
            }
            
            $updated_count = 0;
            $processed = 0;
            
            ELKO_Logger::log_sync('attributes', 'started', "Updating attributes for {$total} products");
            
            // Process in batches of 20
            $batches = array_chunk($elko_products, 20);
            
            foreach ($batches as $batch) {
                if ($this->should_stop()) {
                    $this->complete_progress('stopped');
                    return $updated_count;
                }
                
                $elko_ids = array_column($batch, 'elko_id');
                
                // Get descriptions for batch
                $descriptions = $this->api_client->get_product_descriptions($elko_ids, 'EE');
                
                if (is_wp_error($descriptions)) {
                    continue;
                }
                
                // Index descriptions by product ID
                $desc_by_id = array();
                if (is_array($descriptions)) {
                    foreach ($descriptions as $desc) {
                        if (isset($desc['productId'])) {
                            $desc_by_id[$desc['productId']] = $desc;
                        }
                    }
                }
                
                // Update each product
                foreach ($batch as $product) {
                    if ($this->should_stop()) {
                        $this->complete_progress('stopped');
                        return $updated_count;
                    }
                    
                    $processed++;
                    $product_title = get_the_title($product->post_id);
                    $this->update_progress($product_title, $processed);
                    
                    if (isset($desc_by_id[$product->elko_id])) {
                        $attributes = $this->parse_product_attributes($desc_by_id[$product->elko_id]);
                        
                        // Get existing attributes and merge
                        $existing_attrs = get_post_meta($product->post_id, '_product_attributes', true);
                        if (!is_array($existing_attrs)) {
                            $existing_attrs = array();
                        }
                        
                        $merged_attrs = array_merge($existing_attrs, $attributes);
                        update_post_meta($product->post_id, '_product_attributes', $merged_attrs);
                        
                        // Also update manufacturer code as SKU if found
                        $manufacturer_code = $this->extract_manufacturer_code($desc_by_id[$product->elko_id], array());
                        if (!empty($manufacturer_code)) {
                            $wc_product = wc_get_product($product->post_id);
                            if ($wc_product) {
                                $current_sku = $wc_product->get_sku();
                                if (empty($current_sku) || strpos($current_sku, 'ELKO-') === 0) {
                                    $unique_sku = $this->get_unique_sku($manufacturer_code, $product->elko_id);
                                    $wc_product->set_sku($unique_sku);
                                    $wc_product->save();
                                }
                            }
                        }
                        
                        $updated_count++;
                    }
                }
                
                usleep(200000); // 0.2 second delay between batches
            }
            
            $this->complete_progress('completed');
            ELKO_Logger::log_sync('attributes', 'success', "Updated attributes for {$updated_count} products");
            return $updated_count;
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('attributes', 'error', 'Attribute import failed: ' . $e->getMessage());
            $this->complete_progress('error');
            return false;
        }
    }
    
    /**
     * Fix all images - re-import gallery for existing products
     */
    public function fix_all_images($session_id = '') {
        if ($this->should_stop()) {
            return false;
        }
        
        try {
            global $wpdb;
            
            // Get all products with ELKO IDs
            $elko_products = $wpdb->get_results(
                "SELECT post_id, meta_value as elko_id FROM {$wpdb->postmeta} WHERE meta_key = '_elko_product_id'"
            );
            
            if (empty($elko_products)) {
                return 0;
            }
            
            $total = count($elko_products);
            
            if (!empty($session_id)) {
                $this->init_progress('images', $total, $session_id);
            }
            
            $fixed_count = 0;
            $processed = 0;
            
            ELKO_Logger::log_sync('images', 'started', "Fixing images for {$total} products");
            
            // Process in batches of 20
            $batches = array_chunk($elko_products, 20);
            
            foreach ($batches as $batch) {
                if ($this->should_stop()) {
                    $this->complete_progress('stopped');
                    return $fixed_count;
                }
                
                $elko_ids = array_column($batch, 'elko_id');
                
                // Get media for batch
                $media_response = $this->api_client->get_product_media($elko_ids);
                
                if (is_wp_error($media_response)) {
                    continue;
                }
                
                // Index media by product ID
                $media_by_id = array();
                if (is_array($media_response)) {
                    foreach ($media_response as $media_item) {
                        if (isset($media_item['id'])) {
                            $media_by_id[$media_item['id']] = $media_item['mediaFiles'] ?? array();
                        }
                    }
                }
                
                // Update each product's images
                foreach ($batch as $product) {
                    if ($this->should_stop()) {
                        $this->complete_progress('stopped');
                        return $fixed_count;
                    }
                    
                    $processed++;
                    $product_title = get_the_title($product->post_id);
                    $this->update_progress($product_title, $processed);
                    
                    if (isset($media_by_id[$product->elko_id]) && !empty($media_by_id[$product->elko_id])) {
                        // Delete existing attachments
                        $this->delete_product_attachments($product->post_id);
                        
                        // Import new images
                        $this->import_enhanced_gallery($product->post_id, $media_by_id[$product->elko_id]);
                        $fixed_count++;
                    }
                }
                
                usleep(300000); // 0.3 second delay between batches
            }
            
            $this->complete_progress('completed');
            ELKO_Logger::log_sync('images', 'success', "Fixed images for {$fixed_count} products");
            return $fixed_count;
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('images', 'error', 'Image fix failed: ' . $e->getMessage());
            $this->complete_progress('error');
            return false;
        }
    }
    
    /**
     * Delete product attachments
     */
    private function delete_product_attachments($product_id) {
        $thumbnail_id = get_post_thumbnail_id($product_id);
        if ($thumbnail_id) {
            wp_delete_attachment($thumbnail_id, true);
        }
        
        $gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
        if (!empty($gallery_ids)) {
            $ids = explode(',', $gallery_ids);
            foreach ($ids as $id) {
                wp_delete_attachment(intval($id), true);
            }
        }
        
        delete_post_thumbnail($product_id);
        delete_post_meta($product_id, '_product_image_gallery');
    }
    
    /**
     * Count products in categories
     */
    private function count_products_in_categories($categories) {
        $total = 0;
        
        foreach ($categories as $category_code_id) {
            $products = $this->api_client->get_products_by_category($category_code_id);
            if (!is_wp_error($products) && is_array($products)) {
                $total += count($products);
            }
        }
        
        return $total;
    }
    
    /**
     * Clean product name
     */
    private function clean_product_name($name) {
        if (empty($name)) {
            return $name;
        }
        
        $removeWords = array(
            'Notebook', 'Graphics Card', 'SSD', 'Power Supply', 'Case', 'CPU', 'Mainboard',
            'Desktop', 'Laptop', 'Gaming', 'Professional', 'Business', 'Home', 'Office'
        );
        
        $originalName = $name;
        
        // Replace "|" with spaces
        $name = preg_replace('/\s*\|\s*/', ' ', $name);
        
        // Remove unwanted words
        $name = str_ireplace($removeWords, '', $name);
        
        // Insert spaces between stuck words
        $name = preg_replace('/((?<=[A-Z])(?=[A-Z][a-z])|(?<=[a-z])(?=[A-Z]))/', ' ', $name);
        
        // Normalize spaces
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);
        $name = trim($name, ' ,-/()[]');
        
        // If name became too short, use original
        if (strlen($name) < 5) {
            $name = $originalName;
        }
        
        return $name;
    }
    
    /**
     * Parse product attributes from description data
     */
    private function parse_product_attributes($description_data) {
        $attributes = array();
        
        if (!isset($description_data['description']) || !is_array($description_data['description'])) {
            return $attributes;
        }
        
        $skip_criteria = array('Description', 'Vendor Homepage', 'Category Code', 'Unit Box Height', 'Unit Box Width', 'Unit Box Length');
        
        foreach ($description_data['description'] as $criteria) {
            if (!isset($criteria['criteria']) || !isset($criteria['value'])) {
                continue;
            }
            
            $name = $criteria['criteria'];
            $value = $criteria['value'];
            $measurement = $criteria['measurement'] ?? '';
            
            if (in_array($name, $skip_criteria)) {
                continue;
            }
            
            if (!empty($measurement) && !empty($value)) {
                $value = $value . ' ' . $measurement;
            }
            
            $value = strip_tags($value);
            $value = html_entity_decode($value);
            
            if (!empty($value) && $value !== 'none' && $value !== '0') {
                $attr_key = sanitize_key($name);
                $attributes[$attr_key] = array(
                    'name' => $name,
                    'value' => $value,
                    'is_visible' => true,
                    'is_taxonomy' => false,
                );
            }
        }
        
        return $attributes;
    }
    
    /**
     * Get third-level categories
     */
    private function get_third_level_categories() {
        return array(
            'CPU' => 'CPU_4028',
            'Mainboards for AMD CPUs' => 'MBA_4106', 
            'Mainboards for Intel CPUs' => 'MBI_4040',
            'Memory DIMM' => 'MEM_4041',
            'Memory SODIMM' => 'MEB_5876',
            'Video Cards' => 'VGP_4047',
            'Sound Cards' => 'SOU_6327',
            'SSD SATA' => 'SSM_4891',
            'SSD M.2' => 'SSU_5151',
            'SSD MSATA' => 'SST_6189',
            'HDD Desktop SATA' => 'HDS_4413',
            'HDD Mobile SATA' => 'HMS_4414',
            'Cases' => 'CAS_4816',
            'Desktop Computer PSU' => 'PSU_4817',
            'CPU Coolers' => 'COC_4481',
            'System & VGA Coolers' => 'COS_4482',
            'Keyboards' => 'KEY_4039',
            'Mouse Devices' => 'MOU_4045',
            'Mouse Pads' => 'MOP_6307',
            'Numeric Keypads' => 'KPA_8124',
            'Monitors' => 'LC3_4815',
            'LFD Monitors' => 'LCD_6342',
            'Headphones' => 'HPH_6313',
            'Speakers' => 'SPE_6315',
            'Microphones' => 'MIC_6471',
            'Web Cameras' => 'WCA_4052',
            'Laser Printers' => 'LAS_4067',
            'All In One' => 'AIO_4065'
        );
    }
    
    /**
     * Import single product with add/update logic
     */
    private function import_single_product($product_data, $category_name) {
        try {
            if (!isset($product_data['id']) || !isset($product_data['name']) || !isset($product_data['price'])) {
                return false;
            }
            
            $elko_id = $product_data['id'];
            
            // Check if product already exists
            $existing_product_id = $this->get_product_by_elko_id($elko_id);
            
            if ($existing_product_id) {
                return $this->update_existing_product($existing_product_id, $product_data, $category_name);
            } else {
                return $this->create_new_product($product_data, $category_name);
            }
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('products', 'error', "Error importing product {$product_data['id']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new product
     */
    private function create_new_product($product_data, $category_name) {
        $elko_id = $product_data['id'];
        $product_name = $product_data['name'];
        $elko_price = $product_data['price'];
        $product_code = $product_data['code'] ?? '';
        $description = $product_data['description'] ?? '';
        $short_description = $product_data['shortDescription'] ?? '';
        $manufacturer = $product_data['manufacturer'] ?? '';
        $warranty = $product_data['warranty'] ?? '';
        $gallery = $product_data['gallery'] ?? array();
        $attributes = $product_data['attributes'] ?? array();
        $detailed_description = $product_data['detailed_description'] ?? array();
        
        $final_price = $this->price_calculator->calculate_price($elko_price);
        
        if ($final_price <= 0) {
            return false;
        }
        
        $product = new WC_Product_Simple();
        
        $product->set_name($product_name);
        
        $enhanced_description = $this->build_enhanced_description($detailed_description, $description);
        $product->set_description($enhanced_description);
        $product->set_short_description($short_description);
        
        // Use manufacturerCode as SKU
        $manufacturer_code = $this->extract_manufacturer_code($detailed_description, $product_data);
        $unique_sku = $this->get_unique_sku($manufacturer_code ?: $product_code, $elko_id);
        $product->set_sku($unique_sku);
        
        $product->set_regular_price($final_price);
        $product->set_price($final_price);
        
        // Stock management
        if (isset($product_data['quantity'])) {
            $stock_quantity = $this->parse_stock_quantity($product_data['quantity']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_quantity);
            $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
        }
        
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        
        $this->set_product_dimensions($product, $detailed_description);
        
        $product_id = $product->save();
        
        if (!$product_id) {
            return false;
        }
        
        $this->assign_product_to_category($product_id, $category_name);
        
        if (!empty($manufacturer)) {
            $this->assign_product_brand($product_id, $manufacturer);
        }
        
        // Meta data
        update_post_meta($product_id, '_elko_product_id', $elko_id);
        update_post_meta($product_id, '_elko_original_price', $elko_price);
        update_post_meta($product_id, '_elko_final_price', $final_price);
        update_post_meta($product_id, '_elko_last_update', current_time('mysql'));
        update_post_meta($product_id, '_elko_manufacturer', $manufacturer);
        update_post_meta($product_id, '_elko_warranty', $warranty);
        update_post_meta($product_id, '_elko_original_code', $product_code);
        update_post_meta($product_id, '_elko_manufacturer_code', $manufacturer_code);
        
        // Import gallery
        if (!empty($gallery)) {
            $this->import_enhanced_gallery($product_id, $gallery);
        }
        
        // Add attributes
        $this->add_enhanced_attributes($product_id, $attributes, $product_data, $detailed_description);
        
        return true;
    }
    
    /**
     * Update existing product with prices and stock
     */
    private function update_existing_product($product_id, $product_data, $category_name) {
        try {
            $wc_product = wc_get_product($product_id);
            
            if (!$wc_product) {
                return false;
            }
            
            $elko_price = $product_data['price'];
            $final_price = $this->price_calculator->calculate_price($elko_price);
            
            if ($final_price > 0) {
                $wc_product->set_regular_price($final_price);
                $wc_product->set_price($final_price);
            }
            
            // Update stock
            if (isset($product_data['quantity'])) {
                $stock_quantity = $this->parse_stock_quantity($product_data['quantity']);
                $wc_product->set_manage_stock(true);
                $wc_product->set_stock_quantity($stock_quantity);
                $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            }
            
            $wc_product->save();
            
            // Update meta
            update_post_meta($product_id, '_elko_original_price', $elko_price);
            update_post_meta($product_id, '_elko_final_price', $final_price);
            update_post_meta($product_id, '_elko_last_update', current_time('mysql'));
            
            // Check if images exist, if not import them
            $thumbnail_id = get_post_thumbnail_id($product_id);
            if (!$thumbnail_id && !empty($product_data['gallery'])) {
                $this->import_enhanced_gallery($product_id, $product_data['gallery']);
            }
            
            // Update attributes if provided
            if (!empty($product_data['attributes'])) {
                $existing_attrs = get_post_meta($product_id, '_product_attributes', true);
                if (!is_array($existing_attrs)) {
                    $existing_attrs = array();
                }
                $merged_attrs = array_merge($existing_attrs, $product_data['attributes']);
                update_post_meta($product_id, '_product_attributes', $merged_attrs);
            }
            
            return true;
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('products', 'error', "Error updating product {$product_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extract manufacturer code from detailed description
     */
    private function extract_manufacturer_code($detailed_description, $product_data) {
        if (isset($detailed_description['description']) && is_array($detailed_description['description'])) {
            foreach ($detailed_description['description'] as $criteria) {
                if (!isset($criteria['criteria']) || !isset($criteria['value'])) {
                    continue;
                }
                
                $criteria_name = strtolower($criteria['criteria']);
                $value = trim($criteria['value']);
                
                if (in_array($criteria_name, ['product model code', 'model code', 'part number', 'manufacturer part number', 'manufacturercode']) && !empty($value)) {
                    return $value;
                }
            }
        }
        
        return $product_data['code'] ?? '';
    }
    
    /**
     * Get unique SKU
     */
    private function get_unique_sku($product_code, $elko_id) {
        if (empty($product_code)) {
            return 'ELKO-' . $elko_id;
        }
        
        $existing_product_id = wc_get_product_id_by_sku($product_code);
        if (!$existing_product_id) {
            return $product_code;
        }
        
        $existing_elko_id = get_post_meta($existing_product_id, '_elko_product_id', true);
        if ($existing_elko_id == $elko_id) {
            return $product_code;
        }
        
        $counter = 1;
        $new_sku = $product_code . '-' . $counter;
        while (wc_get_product_id_by_sku($new_sku)) {
            $counter++;
            $new_sku = $product_code . '-' . $counter;
        }
        return $new_sku;
    }
    
    /**
     * Assign product brand using Perfect Brands
     */
    private function assign_product_brand($product_id, $manufacturer) {
        if (empty($manufacturer)) {
            return;
        }
        
        if (!taxonomy_exists('pwb-brand')) {
            return;
        }
        
        $brand_term = get_term_by('name', $manufacturer, 'pwb-brand');
        
        if (!$brand_term) {
            $brand_result = wp_insert_term($manufacturer, 'pwb-brand', array(
                'slug' => sanitize_title($manufacturer)
            ));
            
            if (!is_wp_error($brand_result)) {
                $brand_term_id = $brand_result['term_id'];
            } else {
                return;
            }
        } else {
            $brand_term_id = $brand_term->term_id;
        }
        
        wp_set_post_terms($product_id, array($brand_term_id), 'pwb-brand');
    }
    
    /**
     * Add enhanced attributes
     */
    private function add_enhanced_attributes($product_id, $attributes, $product_data, $detailed_description) {
        $final_attributes = array();
        
        if (!empty($product_data['manufacturer'])) {
            $final_attributes['manufacturer'] = array(
                'name' => 'Manufacturer',
                'value' => $product_data['manufacturer'],
                'is_visible' => true,
                'is_taxonomy' => false,
            );
        }
        
        if (!empty($product_data['warranty'])) {
            $final_attributes['warranty'] = array(
                'name' => 'Warranty',
                'value' => $product_data['warranty'],
                'is_visible' => true,
                'is_taxonomy' => false,
            );
        }
        
        if (!empty($product_data['code'])) {
            $final_attributes['elko_code'] = array(
                'name' => 'ELKO Code',
                'value' => $product_data['code'],
                'is_visible' => true,
                'is_taxonomy' => false,
            );
        }
        
        if (!empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $attr_key => $attr_data) {
                if (isset($attr_data['name']) && isset($attr_data['value'])) {
                    $final_attributes[$attr_key] = $attr_data;
                }
            }
        }
        
        if (!empty($final_attributes)) {
            update_post_meta($product_id, '_product_attributes', $final_attributes);
        }
    }
    
    /**
     * Import enhanced gallery
     */
    private function import_enhanced_gallery($product_id, $gallery) {
        if (empty($gallery) || !is_array($gallery)) {
            return;
        }
        
        $attachment_ids = array();
        
        foreach ($gallery as $index => $media_item) {
            if (!isset($media_item['link'])) {
                continue;
            }
            
            $image_url = $media_item['link'];
            $sequence = $media_item['sequence'] ?? $index;
            
            $attachment_id = $this->import_image_from_url($image_url, $product_id);
            
            if ($attachment_id) {
                $attachment_ids[] = array(
                    'id' => $attachment_id,
                    'sequence' => $sequence
                );
            }
        }
        
        if (!empty($attachment_ids)) {
            usort($attachment_ids, function($a, $b) {
                return $a['sequence'] <=> $b['sequence'];
            });
            
            $sorted_ids = array_column($attachment_ids, 'id');
            
            set_post_thumbnail($product_id, $sorted_ids[0]);
            
            if (count($sorted_ids) > 1) {
                $gallery_ids = array_slice($sorted_ids, 1);
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            }
        }
    }
    
    /**
     * Import image from URL
     */
    private function import_image_from_url($image_url, $product_id) {
        try {
            $existing_attachment = $this->get_attachment_by_url($image_url);
            if ($existing_attachment) {
                return $existing_attachment;
            }
            
            $upload_dir = wp_upload_dir();
            $image_data = wp_remote_get($image_url, array(
                'timeout' => 30,
                'user-agent' => 'WooCommerce-ELKO-Integration/' . ELKO_PLUGIN_VERSION
            ));
            
            if (is_wp_error($image_data)) {
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($image_data);
            if ($response_code !== 200) {
                return false;
            }
            
            $image_content = wp_remote_retrieve_body($image_data);
            if (empty($image_content)) {
                return false;
            }
            
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            if (empty($filename) || strpos($filename, '.') === false) {
                $filename = 'elko-image-' . time() . '.jpg';
            }
            
            $filename = wp_unique_filename($upload_dir['path'], $filename);
            
            if (wp_mkdir_p($upload_dir['path'])) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }
            
            $file_written = file_put_contents($file, $image_content);
            if ($file_written === false) {
                return false;
            }
            
            $wp_filetype = wp_check_filetype($filename, null);
            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            $attachment_id = wp_insert_attachment($attachment, $file, $product_id);
            
            if (!is_wp_error($attachment_id)) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attachment_data = wp_generate_attachment_metadata($attachment_id, $file);
                wp_update_attachment_metadata($attachment_id, $attachment_data);
                
                update_post_meta($attachment_id, '_elko_image_url', $image_url);
                
                return $attachment_id;
            } else {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
        } catch (Exception $e) {
            ELKO_Logger::log_sync('images', 'error', "Error importing image {$image_url}: " . $e->getMessage());
        }
        
        return false;
    }
    
    // Helper methods
    private function get_product_by_elko_id($elko_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elko_product_id' AND meta_value = %s", $elko_id));
    }
    
    private function assign_product_to_category($product_id, $category_name) {
        $category = get_term_by('name', $category_name, 'product_cat');
        if ($category) {
            wp_set_post_terms($product_id, array($category->term_id), 'product_cat');
        }
    }
    
    private function parse_stock_quantity($quantity_string) {
        $quantity_string = trim($quantity_string);
        if ($quantity_string === '0' || empty($quantity_string)) {
            return 0;
        }
        if (strpos($quantity_string, '>') === 0) {
            return intval(trim(str_replace('>', '', $quantity_string)));
        }
        if (is_numeric($quantity_string)) {
            return intval($quantity_string);
        }
        return 0;
    }
    
    private function get_attachment_by_url($image_url) {
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elko_image_url' AND meta_value = %s", $image_url));
        return $attachment_id ? intval($attachment_id) : false;
    }
    
    private function build_enhanced_description($detailed_description, $fallback_description = '') {
        $description = '';
        
        if (isset($detailed_description['description']) && is_array($detailed_description['description'])) {
            foreach ($detailed_description['description'] as $criteria) {
                if (isset($criteria['criteria']) && $criteria['criteria'] === 'Description' && !empty($criteria['value'])) {
                    $description = $criteria['value'];
                    break;
                }
            }
        }
        
        if (empty($description)) {
            $description = $fallback_description;
        }
        
        if (!empty($description)) {
            $allowed_tags = '<p><br><strong><em><ul><li><h3><h4><span><div>';
            $description = strip_tags($description, $allowed_tags);
            
            if (strip_tags($description) === $description) {
                $description = wpautop($description);
            }
        }
        
        return $description;
    }
    
    private function set_product_dimensions($product, $detailed_description) {
        $dimensions = array();
        
        if (!isset($detailed_description['description']) || !is_array($detailed_description['description'])) {
            return;
        }
        
        foreach ($detailed_description['description'] as $criteria) {
            if (!isset($criteria['criteria']) || !isset($criteria['value'])) {
                continue;
            }
            
            $name = strtolower($criteria['criteria']);
            $value = $criteria['value'];
            
            if (empty($value) || $value === '0') {
                continue;
            }
            
            if (isset($criteria['measurement']) && $criteria['measurement'] === 'mm' && is_numeric($value)) {
                $value = round($value / 10, 2);
            }
            
            if ($name === 'product net weight' && isset($criteria['measurement']) && $criteria['measurement'] === 'kg') {
                $product->set_weight($value);
            }
            
            switch ($name) {
                case 'width':
                    $dimensions['width'] = $value;
                    break;
                case 'height':
                    $dimensions['height'] = $value;
                    break;
                case 'depth':
                    $dimensions['length'] = $value;
                    break;
            }
        }
        
        if (!empty($dimensions)) {
            if (isset($dimensions['length'])) $product->set_length($dimensions['length']);
            if (isset($dimensions['width'])) $product->set_width($dimensions['width']);
            if (isset($dimensions['height'])) $product->set_height($dimensions['height']);
        }
    }
}
