<?php
/**
 * ELKO Category Importer - With 7 main parent categories
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Category_Importer {
    
    private $api_client;
    
    public function __construct() {
        $this->api_client = new ELKO_API_Client();
    }
    
    /**
     * Import categories with proper 7-category structure
     */
    public function import_categories_with_tree() {
        try {
            // Get category tree from API  
            $tree = $this->api_client->get_category_tree();
            
            if (is_wp_error($tree)) {
                error_log("ELKO: Failed to get category tree: " . $tree->get_error_message());
                return false;
            }
            
            if (!is_array($tree) || empty($tree)) {
                error_log("ELKO: No tree data received from API");
                return false;
            }
            
            $imported_count = 0;
            
            // Define the 7 main categories we want
            $main_categories = $this->get_main_categories();
            
            // Import each main category and its children
            foreach ($main_categories as $main_id => $main_name) {
                $main_tree = $this->find_tree_node($tree, $main_id);
                
                if ($main_tree) {
                    $imported_count += $this->import_tree_recursive($main_tree, 0);
                } else {
                    error_log("ELKO: Main category {$main_name} (ID: {$main_id}) not found in tree");
                }
            }
            
            return $imported_count;
            
        } catch (Exception $e) {
            error_log("ELKO Category Import Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the 7 main categories we want to import
     */
    private function get_main_categories() {
        return array(
            4015 => 'PC Components',
            6838 => 'Peripherals & Office Products', 
            6341 => 'TV, Audio & Video',
            7127 => 'Smartphones & Tablets',
            4018 => 'Networking',
            6404 => 'Security Solutions',
            4021 => 'Servers & Components'
        );
    }
    
    /**
     * Find specific node in tree by ID
     */
    private function find_tree_node($tree, $target_id) {
        foreach ($tree as $node) {
            if ($node['id'] == $target_id) {
                return $node;
            }
            
            if (isset($node['childs']) && !empty($node['childs'])) {
                $found = $this->find_tree_node($node['childs'], $target_id);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }
    
    /**
     * Import tree structure recursively
     */
    private function import_tree_recursive($node, $parent_wp_id = 0) {
        $imported_count = 0;
        
        // Import current node
        $wp_term_id = $this->import_single_category_from_tree($node, $parent_wp_id);
        
        if ($wp_term_id) {
            $imported_count++;
            
            // Import children (but filter them based on our needs)
            if (isset($node['childs']) && !empty($node['childs'])) {
                foreach ($node['childs'] as $child) {
                    // For PC Components, import specific subcategories only
                    if ($node['id'] == 4015) { // PC Components
                        $wanted_pc_subcats = array(7104, 7109, 7110, 7111, 7107, 7108, 7106, 7105); // Processors, Mainboards, RAM, Video&Sound, SSD, HDD, Cases&PSU, Cooling
                        if (in_array($child['id'], $wanted_pc_subcats)) {
                            $imported_count += $this->import_tree_recursive($child, $wp_term_id);
                        }
                    } else {
                        // For other categories, import all children
                        $imported_count += $this->import_tree_recursive($child, $wp_term_id);
                    }
                }
            }
        }
        
        return $imported_count;
    }
    
    /**
     * Import single category from tree node
     */
    private function import_single_category_from_tree($node, $parent_wp_id = 0) {
        try {
            $category_name = $node['name'];
            $category_code = $node['code'] ?? '';
            $category_id = $node['id'];
            
            // Skip empty names
            if (empty($category_name)) {
                return false;
            }
            
            // Check if category already exists
            $existing_category = $this->find_existing_category($category_name, $parent_wp_id);
            
            if ($existing_category) {
                // Update existing category meta
                update_term_meta($existing_category->term_id, '_elko_category_id', $category_id);
                if (!empty($category_code)) {
                    update_term_meta($existing_category->term_id, '_elko_category_code', $category_code);
                }
                update_term_meta($existing_category->term_id, '_elko_last_update', '2025-10-21 13:37:25');
                update_term_meta($existing_category->term_id, '_elko_updated_by', 'MartinAbramov');
                
                return $existing_category->term_id;
            }
            
            // Prepare category args
            $args = array(
                'description' => "ELKO category: {$category_name}" . (!empty($category_code) ? " (Code: {$category_code})" : ""),
                'slug' => sanitize_title($category_name)
            );
            
            if ($parent_wp_id > 0) {
                $args['parent'] = $parent_wp_id;
            }
            
            // Create new category
            $result = wp_insert_term($category_name, 'product_cat', $args);
            
            if (is_wp_error($result)) {
                error_log("ELKO: Failed to create category {$category_name}: " . $result->get_error_message());
                return false;
            }
            
            $term_id = $result['term_id'];
            
            // Add ELKO meta data
            update_term_meta($term_id, '_elko_category_id', $category_id);
            if (!empty($category_code)) {
                update_term_meta($term_id, '_elko_category_code', $category_code);
            }
            update_term_meta($term_id, '_elko_last_update', '2025-10-21 13:37:25');
            update_term_meta($term_id, '_elko_imported_by', 'MartinAbramov');
            
            error_log("ELKO: Created category: {$category_name} (ID: {$term_id}, Parent: {$parent_wp_id})");
            
            return $term_id;
            
        } catch (Exception $e) {
            error_log("ELKO: Error importing category {$node['name']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Find existing category with specific parent
     */
    private function find_existing_category($category_name, $parent_id) {
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'name' => $category_name,
            'parent' => $parent_id,
            'hide_empty' => false,
        ));
        
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }
        
        return false;
    }
}