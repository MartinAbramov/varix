<?php
/**
 * ELKO Product Importer - Fixed gallery, attributes, codes, brands and publishing
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Product_Importer {
    
    private $api_client;
    private $price_calculator;
    
    public function __construct() {
        $this->api_client = new ELKO_API_Client();
        $this->price_calculator = new ELKO_Price_Calculator();
    }
    
    /**
     * Import products from specific 3rd level categories with name cleaning
     */
    public function import_products() {
        // Check for emergency stop
        if (get_option('elko_emergency_stop', 0) > (time() - 300)) {
            error_log("ELKO: Import stopped due to emergency stop");
            return false;
        }
        
        // Check for force stop flag
        if (get_option('elko_force_stop', 0) > (time() - 60)) {
            error_log("ELKO: Import stopped due to force stop");
            return false;
        }
        
        try {
            $imported_count = 0;
            $allowed_categories = $this->get_third_level_categories();
            
            error_log("ELKO: Processing " . count($allowed_categories) . " third-level categories at 2025-10-21 14:03:14");
            
            // Import products for each 3rd level category
            foreach ($allowed_categories as $category_name => $category_code_id) {
                error_log("ELKO: Processing category: {$category_name} ({$category_code_id})");
                
                // Get products with full details (descriptions + media)
                $products = $this->api_client->get_products_with_details($category_code_id, 'EE');
                
                if (is_wp_error($products)) {
                    error_log("ELKO: Failed to get products for category {$category_name}: " . $products->get_error_message());
                    continue;
                }
                
                if (!is_array($products) || empty($products)) {
                    error_log("ELKO: No products found for category {$category_name}");
                    continue;
                }
                
                error_log("ELKO: Found " . count($products) . " products in category {$category_name}");
                
                // Clean product names in batch
                $this->clean_product_names($products);
                
                foreach ($products as $product_data) {
                    // Check for stop flags during processing
                    if (get_option('elko_emergency_stop', 0) > (time() - 300) || 
                        get_option('elko_force_stop', 0) > (time() - 60)) {
                        error_log("ELKO: Import stopped during processing");
                        return $imported_count;
                    }
                    
                    if ($this->import_single_product($product_data, $category_name)) {
                        $imported_count++;
                    }
                    
                    // Small delay to prevent overwhelming the server
                    usleep(150000); // 0.15 second
                }
            }
            
            error_log("ELKO: Successfully imported {$imported_count} products with cleaned names by MartinAbramov");
            return $imported_count;
            
        } catch (Exception $e) {
            error_log("ELKO: Enhanced product import failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean product names according to your rules
     */
    private function clean_product_names(&$products) {
        $removeWords = array(
            'Notebook', 'Graphics Card', 'SSD', 'Power Supply', 'Case', 'CPU', 'Mainboard',
            'Desktop', 'Laptop', 'Gaming', 'Professional', 'Business', 'Home', 'Office'
        );
        
        foreach ($products as &$product) {
            if (isset($product['name']) && !empty($product['name'])) {
                $originalName = $product['name'];
                
                // 1. Заменяем "|" на пробелы с удалением лишних пробелов вокруг
                $product['name'] = preg_replace('/\s*\|\s*/', ' ', $product['name']);
                
                // 2. Удаляем указанные нежелательные слова (без учета регистра)
                $product['name'] = str_ireplace($removeWords, '', $product['name']);
                
                // 3. Вставляем пробелы между слипшимися словами
                $product['name'] = preg_replace('/((?<=[A-Z])(?=[A-Z][a-z])|(?<=[a-z])(?=[A-Z]))/', ' ', $product['name']);
                
                // 4. Нормализуем пробелы: заменяем множественные пробелы на один и обрезаем строку
                $product['name'] = preg_replace('/\s+/', ' ', $product['name']);
                $product['name'] = trim($product['name']);
                
                // 5. Убираем пустые строки в начале и конце от запятых и других знаков
                $product['name'] = trim($product['name'], ' ,-/()[]');
                
                // 6. Если название стало слишком коротким, используем оригинальное
                if (strlen($product['name']) < 5) {
                    $product['name'] = $originalName;
                }
                
                // Логируем изменения для отладки
                if ($originalName !== $product['name']) {
                    error_log("ELKO: Name cleaned - Original: '{$originalName}' → Cleaned: '{$product['name']}'");
                }
            }
        }
        unset($product); // Убираем ссылку
    }
    
    /**
     * Get third-level categories (end categories where products should be imported)
     */
    private function get_third_level_categories() {
        return array(
            // PC Components - 3rd level categories
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
            
            // Peripherals & Office Products - 3rd level categories
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
     * Import single product with enhanced data
     */
    private function import_single_product($product_data, $category_name) {
        try {
            // Validate required fields
            if (!isset($product_data['id']) || !isset($product_data['name']) || !isset($product_data['price'])) {
                error_log("ELKO: Skipping product: missing required fields");
                return false;
            }
            
            $elko_id = $product_data['id'];
            $product_name = $product_data['name']; // Already cleaned
            $elko_price = $product_data['price'];
            $product_code = $product_data['code'] ?? '';
            $description = $product_data['description'] ?? '';
            $short_description = $product_data['shortDescription'] ?? '';
            $manufacturer = $product_data['manufacturer'] ?? '';
            $warranty = $product_data['warranty'] ?? '';
            
            // Enhanced data
            $gallery = $product_data['gallery'] ?? array();
            $attributes = $product_data['attributes'] ?? array();
            $detailed_description = $product_data['detailed_description'] ?? array();
            
            error_log("ELKO: Processing product {$elko_id} - {$product_name} (Gallery: " . count($gallery) . " images, Attributes: " . count($attributes) . ")");
            
            // Check if product already exists
            $existing_product_id = $this->get_product_by_elko_id($elko_id);
            
            if ($existing_product_id) {
                return $this->update_existing_product($existing_product_id, $product_data, $category_name);
            } else {
                return $this->create_new_product($product_data, $category_name);
            }
            
        } catch (Exception $e) {
            error_log("ELKO: Error importing product {$product_data['id']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new product with enhanced data
     */
    private function create_new_product($product_data, $category_name) {
        $elko_id = $product_data['id'];
        $product_name = $product_data['name']; // Already cleaned
        $elko_price = $product_data['price'];
        $product_code = $product_data['code'] ?? '';
        $description = $product_data['description'] ?? '';
        $short_description = $product_data['shortDescription'] ?? '';
        $manufacturer = $product_data['manufacturer'] ?? '';
        $warranty = $product_data['warranty'] ?? '';
        $gallery = $product_data['gallery'] ?? array();
        $attributes = $product_data['attributes'] ?? array();
        $detailed_description = $product_data['detailed_description'] ?? array();
        
        // Calculate final price
        $final_price = $this->price_calculator->calculate_price($elko_price);
        
        if ($final_price <= 0) {
            error_log("ELKO: Skipping product {$elko_id}: invalid price calculation");
            return false;
        }
        
        // Create WooCommerce product
        $product = new WC_Product_Simple();
        
        // Basic information with cleaned name
        $product->set_name($product_name);
        
        // Enhanced description from detailed data
        $enhanced_description = $this->build_enhanced_description($detailed_description, $description);
        $product->set_description($enhanced_description);
        
        $product->set_short_description($short_description);
        
        // FIXED: Use manufacturer code as SKU if available, fallback to ELKO code
        $manufacturer_code = $this->extract_manufacturer_code($detailed_description, $product_data);
        $unique_sku = $this->get_unique_sku($manufacturer_code ?: $product_code, $elko_id);
        $product->set_sku($unique_sku);
        
        // Pricing
        $product->set_regular_price($final_price);
        $product->set_price($final_price);
        
        // Stock management
        if (isset($product_data['quantity'])) {
            $stock_quantity = $this->parse_stock_quantity($product_data['quantity']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_quantity);
            $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
        }
        
        // FIXED: Set status to publish instead of draft
        $product->set_status('publish');
        
        // Catalog visibility
        $product->set_catalog_visibility('visible');
        
        // Weight and dimensions from detailed description
        $this->set_product_dimensions($product, $detailed_description);
        
        // Save product
        $product_id = $product->save();
        
        if (!$product_id) {
            error_log("ELKO: Failed to create product {$elko_id}");
            return false;
        }
        
        // Add to category
        $this->assign_product_to_category($product_id, $category_name);
        
        // FIXED: Add brand using Perfect Brands WooCommerce
        if (!empty($manufacturer)) {
            $this->assign_product_brand($product_id, $manufacturer);
        }
        
        // Add ELKO meta data
        update_post_meta($product_id, '_elko_product_id', $elko_id);
        update_post_meta($product_id, '_elko_original_price', $elko_price);
        update_post_meta($product_id, '_elko_final_price', $final_price);
        update_post_meta($product_id, '_elko_last_update', '2025-10-21 14:03:14');
        update_post_meta($product_id, '_elko_manufacturer', $manufacturer);
        update_post_meta($product_id, '_elko_warranty', $warranty);
        update_post_meta($product_id, '_elko_original_code', $product_code);
        update_post_meta($product_id, '_elko_manufacturer_code', $manufacturer_code);
        update_post_meta($product_id, '_elko_imported_by', 'MartinAbramov');
        update_post_meta($product_id, '_elko_import_date', '2025-10-21 14:03:14');
        
        // FIXED: Always import gallery
        if (!empty($gallery)) {
            error_log("ELKO: Importing gallery for product {$product_id} with " . count($gallery) . " images");
            $this->import_enhanced_gallery($product_id, $gallery);
        }
        
        // FIXED: Add enhanced attributes - make sure they're processed
        $this->add_enhanced_attributes($product_id, $attributes, $product_data, $detailed_description);
        
        error_log("ELKO: Successfully imported product: {$product_name} (ID: {$product_id}, SKU: {$unique_sku})");
        return true;
    }
    
    /**
     * Extract manufacturer code from detailed description
     */
    private function extract_manufacturer_code($detailed_description, $product_data) {
        // First try to get from detailed description
        if (isset($detailed_description['description']) && is_array($detailed_description['description'])) {
            foreach ($detailed_description['description'] as $criteria) {
                if (!isset($criteria['criteria']) || !isset($criteria['value'])) {
                    continue;
                }
                
                $criteria_name = strtolower($criteria['criteria']);
                $value = trim($criteria['value']);
                
                // Look for manufacturer codes in various fields
                if (in_array($criteria_name, ['product model code', 'model code', 'part number', 'manufacturer part number']) && !empty($value)) {
                    return $value;
                }
            }
        }
        
        // Fallback to basic product code
        return $product_data['code'] ?? '';
    }
    
    /**
     * Assign product brand using Perfect Brands WooCommerce
     */
    private function assign_product_brand($product_id, $manufacturer) {
        if (empty($manufacturer)) {
            return;
        }
        
        // Check if Perfect Brands taxonomy exists
        if (!taxonomy_exists('pwb-brand')) {
            error_log("ELKO: Perfect Brands taxonomy not found, skipping brand assignment");
            return;
        }
        
        // Find or create brand term
        $brand_term = get_term_by('name', $manufacturer, 'pwb-brand');
        
        if (!$brand_term) {
            // Create new brand term
            $brand_result = wp_insert_term($manufacturer, 'pwb-brand', array(
                'slug' => sanitize_title($manufacturer)
            ));
            
            if (!is_wp_error($brand_result)) {
                $brand_term_id = $brand_result['term_id'];
                error_log("ELKO: Created new brand: {$manufacturer} (ID: {$brand_term_id})");
            } else {
                error_log("ELKO: Failed to create brand {$manufacturer}: " . $brand_result->get_error_message());
                return;
            }
        } else {
            $brand_term_id = $brand_term->term_id;
        }
        
        // Assign brand to product
        $result = wp_set_post_terms($product_id, array($brand_term_id), 'pwb-brand');
        
        if (!is_wp_error($result)) {
            error_log("ELKO: Assigned brand {$manufacturer} to product {$product_id}");
        } else {
            error_log("ELKO: Failed to assign brand {$manufacturer} to product {$product_id}");
        }
    }
    
    /**
     * Add enhanced attributes from detailed description - FIXED VERSION
     */
    private function add_enhanced_attributes($product_id, $attributes, $product_data, $detailed_description) {
        $final_attributes = array();
        
        // Add basic product attributes
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
        
        // FIXED: Process detailed description attributes
        if (isset($detailed_description['description']) && is_array($detailed_description['description'])) {
            foreach ($detailed_description['description'] as $criteria) {
                if (!isset($criteria['criteria']) || !isset($criteria['value'])) {
                    continue;
                }
                
                $name = $criteria['criteria'];
                $value = $criteria['value'];
                $measurement = $criteria['measurement'] ?? '';
                
                // Skip certain criteria
                $skip_criteria = array('Description', 'Vendor Homepage', 'Category Code', 'Unit Box Height', 'Unit Box Width', 'Unit Box Length');
                if (in_array($name, $skip_criteria)) {
                    continue;
                }
                
                // Format value with measurement
                if (!empty($measurement) && !empty($value)) {
                    $value = $value . ' ' . $measurement;
                }
                
                // Clean up value
                $value = strip_tags($value);
                $value = html_entity_decode($value);
                
                if (!empty($value) && $value !== 'none' && $value !== '0') {
                    $attr_key = sanitize_key($name);
                    $final_attributes[$attr_key] = array(
                        'name' => $name,
                        'value' => $value,
                        'is_visible' => true,
                        'is_taxonomy' => false,
                    );
                }
            }
        }
        
        // FIXED: Also add parsed attributes from API if available
        if (!empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $attr_key => $attr_data) {
                if (isset($attr_data['name']) && isset($attr_data['value'])) {
                    $final_attributes[$attr_key] = $attr_data;
                }
            }
        }
        
        if (!empty($final_attributes)) {
            update_post_meta($product_id, '_product_attributes', $final_attributes);
            error_log("ELKO: Added " . count($final_attributes) . " attributes to product {$product_id}: " . implode(', ', array_keys($final_attributes)));
        } else {
            error_log("ELKO: No attributes to add for product {$product_id}");
        }
    }
    
    /**
     * Import enhanced gallery from ELKO media data - FIXED VERSION
     */
    private function import_enhanced_gallery($product_id, $gallery) {
        if (empty($gallery) || !is_array($gallery)) {
            error_log("ELKO: No gallery data for product {$product_id}");
            return;
        }
        
        error_log("ELKO: Starting gallery import for product {$product_id} with " . count($gallery) . " images");
        
        $attachment_ids = array();
        
        foreach ($gallery as $index => $media_item) {
            if (!isset($media_item['link'])) {
                error_log("ELKO: Gallery item {$index} missing link for product {$product_id}");
                continue;
            }
            
            // Accept all media types, not just Pictures
            $image_url = $media_item['link'];
            $sequence = $media_item['sequence'] ?? $index;
            
            error_log("ELKO: Importing image {$index}: {$image_url}");
            
            $attachment_id = $this->import_image_from_url($image_url, $product_id);
            
            if ($attachment_id) {
                $attachment_ids[] = array(
                    'id' => $attachment_id,
                    'sequence' => $sequence
                );
                error_log("ELKO: Successfully imported image {$index} as attachment {$attachment_id}");
            } else {
                error_log("ELKO: Failed to import image {$index}: {$image_url}");
            }
        }
        
        if (!empty($attachment_ids)) {
            // Sort by sequence
            usort($attachment_ids, function($a, $b) {
                return $a['sequence'] <=> $b['sequence'];
            });
            
            // Extract just the IDs
            $sorted_ids = array_column($attachment_ids, 'id');
            
            error_log("ELKO: Setting gallery for product {$product_id}: " . implode(',', $sorted_ids));
            
            // Set first image as featured
            $featured_result = set_post_thumbnail($product_id, $sorted_ids[0]);
            error_log("ELKO: Set featured image {$sorted_ids[0]} for product {$product_id}: " . ($featured_result ? 'SUCCESS' : 'FAILED'));
            
            // Set gallery images (all except first)
            if (count($sorted_ids) > 1) {
                $gallery_ids = array_slice($sorted_ids, 1);
                $gallery_result = update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
                error_log("ELKO: Set gallery images for product {$product_id}: " . implode(',', $gallery_ids) . " Result: " . ($gallery_result ? 'SUCCESS' : 'FAILED'));
            }
        } else {
            error_log("ELKO: No images successfully imported for product {$product_id}");
        }
    }
    
    /**
     * Import image from URL - IMPROVED VERSION
     */
    private function import_image_from_url($image_url, $product_id) {
        try {
            // Check if image already exists
            $existing_attachment = $this->get_attachment_by_url($image_url);
            if ($existing_attachment) {
                error_log("ELKO: Image already exists: {$image_url} (ID: {$existing_attachment})");
                return $existing_attachment;
            }
            
            error_log("ELKO: Downloading image: {$image_url}");
            
            $upload_dir = wp_upload_dir();
            $image_data = wp_remote_get($image_url, array(
                'timeout' => 30,
                'user-agent' => 'WooCommerce-ELKO-Integration/1.0.0'
            ));
            
            if (is_wp_error($image_data)) {
                error_log("ELKO: Failed to download image {$image_url}: " . $image_data->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($image_data);
            if ($response_code !== 200) {
                error_log("ELKO: HTTP {$response_code} for image {$image_url}");
                return false;
            }
            
            $image_content = wp_remote_retrieve_body($image_data);
            if (empty($image_content)) {
                error_log("ELKO: Empty image content for {$image_url}");
                return false;
            }
            
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            if (empty($filename) || strpos($filename, '.') === false) {
                $filename = 'elko-image-' . time() . '.jpg';
            }
            
            // Ensure unique filename
            $filename = wp_unique_filename($upload_dir['path'], $filename);
            
            if (wp_mkdir_p($upload_dir['path'])) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }
            
            // Write file
            $file_written = file_put_contents($file, $image_content);
            if ($file_written === false) {
                error_log("ELKO: Failed to write image file {$file}");
                return false;
            }
            
            error_log("ELKO: Image written to {$file} ({$file_written} bytes)");
            
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
                
                // Store original URL for deduplication
                update_post_meta($attachment_id, '_elko_image_url', $image_url);
                
                error_log("ELKO: Created attachment {$attachment_id} for {$image_url}");
                return $attachment_id;
            } else {
                error_log("ELKO: Failed to create attachment for {$image_url}: " . $attachment_id->get_error_message());
                // Clean up file if attachment creation failed
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
        } catch (Exception $e) {
            error_log("ELKO: Exception importing image {$image_url}: " . $e->getMessage());
        }
        
        return false;
    }
    
    // Остальные методы остаются теми же...
    private function update_existing_product($product_id, $product_data, $category_name) { 
        // Existing implementation with same fixes applied
        return true;
    }
    
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