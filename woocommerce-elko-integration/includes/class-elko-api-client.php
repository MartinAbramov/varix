<?php
/**
 * ELKO API Client - Enhanced with media and descriptions
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_API_Client {
    
    private $api_url;
    private $api_key;
    
    public function __construct() {
        $settings = get_option('elko_api_settings', array());
        $this->api_url = $settings['api_url'] ?? 'https://api.elko.cloud';
        $this->api_key = $settings['api_key'] ?? '';
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $start_time = microtime(true);
        
        try {
            $response = $this->make_request('/v3.0/api/Catalog/Categories');
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Connection failed: ' . $response->get_error_message(),
                    'debug_info' => $this->get_debug_info()
                );
            }
            
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            $categories_count = is_array($response) ? count($response) : 0;
            
            return array(
                'success' => true,
                'message' => "Connection successful! Found {$categories_count} categories. API key is valid.",
                'debug_info' => $this->get_debug_info()
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'debug_info' => $this->get_debug_info()
            );
        }
    }
    
    /**
     * Get categories
     */
    public function get_categories() {
        return $this->make_request('/v3.0/api/Catalog/Categories');
    }
    
    /**
     * Get category tree
     */
public function get_category_tree() {
    // Сначала попробуем реальный Tree endpoint
    $tree_endpoint = '/v3.0/api/Catalog/Categories/Tree';
    $tree_response = $this->make_request($tree_endpoint);
    
    // Если Tree endpoint работает, используем его
    if (!is_wp_error($tree_response) && is_array($tree_response)) {
        return $tree_response;
    }
    
    // Иначе используем данные из вашего файла (hardcoded для надежности)
    return $this->get_hardcoded_tree();
}

/**
 * Get hardcoded tree structure from your file data
 */
private function get_hardcoded_tree() {
    return array(
        // PC Components
        array(
            'parentId' => 0,
            'id' => 4015,
            'name' => 'PC Components',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 4015,
                    'id' => 7104,
                    'name' => 'Processors',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7104, 'id' => 4028, 'name' => 'CPU', 'code' => 'CPU', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7109,
                    'name' => 'Mainboards',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7109, 'id' => 4106, 'name' => 'Mainboards for AMD CPUs', 'code' => 'MBA', 'childs' => array()),
                        array('parentId' => 7109, 'id' => 4040, 'name' => 'Mainboards for Intel CPUs', 'code' => 'MBI', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7110,
                    'name' => 'RAM',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7110, 'id' => 4041, 'name' => 'Memory DIMM', 'code' => 'MEM', 'childs' => array()),
                        array('parentId' => 7110, 'id' => 5876, 'name' => 'Memory SODIMM', 'code' => 'MEB', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7111,
                    'name' => 'Video & Sound Cards',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7111, 'id' => 4047, 'name' => 'Video Cards', 'code' => 'VGP', 'childs' => array()),
                        array('parentId' => 7111, 'id' => 6327, 'name' => 'Sound Cards', 'code' => 'SOU', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7107,
                    'name' => 'SSD',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7107, 'id' => 4891, 'name' => 'SSD SATA', 'code' => 'SSM', 'childs' => array()),
                        array('parentId' => 7107, 'id' => 5151, 'name' => 'SSD M.2', 'code' => 'SSU', 'childs' => array()),
                        array('parentId' => 7107, 'id' => 6189, 'name' => 'SSD MSATA', 'code' => 'SST', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7108,
                    'name' => 'HDD',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7108, 'id' => 4413, 'name' => 'HDD Desktop SATA', 'code' => 'HDS', 'childs' => array()),
                        array('parentId' => 7108, 'id' => 4414, 'name' => 'HDD Mobile SATA', 'code' => 'HMS', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7106,
                    'name' => 'Cases & PSU',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7106, 'id' => 4816, 'name' => 'Cases', 'code' => 'CAS', 'childs' => array()),
                        array('parentId' => 7106, 'id' => 4817, 'name' => 'Desktop Computer PSU', 'code' => 'PSU', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4015,
                    'id' => 7105,
                    'name' => 'Cooling',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7105, 'id' => 4481, 'name' => 'CPU Coolers', 'code' => 'COC', 'childs' => array()),
                        array('parentId' => 7105, 'id' => 4482, 'name' => 'System & VGA Coolers', 'code' => 'COS', 'childs' => array())
                    )
                )
            )
        ),
        
        // Peripherals & Office Products  
        array(
            'parentId' => 0,
            'id' => 6838,
            'name' => 'Peripherals & Office Products',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 6838,
                    'id' => 7121,
                    'name' => 'Keyboards & Mouse',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7121, 'id' => 4039, 'name' => 'Keyboards', 'code' => 'KEY', 'childs' => array()),
                        array('parentId' => 7121, 'id' => 4045, 'name' => 'Mouse Devices', 'code' => 'MOU', 'childs' => array()),
                        array('parentId' => 7121, 'id' => 6307, 'name' => 'Mouse Pads', 'code' => 'MOP', 'childs' => array()),
                        array('parentId' => 7121, 'id' => 8124, 'name' => 'Numeric Keypads', 'code' => 'KPA', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6838,
                    'id' => 7119,
                    'name' => 'Monitors',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7119, 'id' => 4815, 'name' => 'Monitors', 'code' => 'LC3', 'childs' => array()),
                        array('parentId' => 7119, 'id' => 6342, 'name' => 'LFD Monitors', 'code' => 'LCD', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6838,
                    'id' => 7122,
                    'name' => 'Multimedia',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7122, 'id' => 6313, 'name' => 'Headphones', 'code' => 'HPH', 'childs' => array()),
                        array('parentId' => 7122, 'id' => 6315, 'name' => 'Speakers', 'code' => 'SPE', 'childs' => array()),
                        array('parentId' => 7122, 'id' => 6471, 'name' => 'Microphones', 'code' => 'MIC', 'childs' => array()),
                        array('parentId' => 7122, 'id' => 4052, 'name' => 'Web Cameras', 'code' => 'WCA', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6838,
                    'id' => 4020,
                    'name' => 'Printers, Scanners & Supplies',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 4020, 'id' => 4067, 'name' => 'Laser Printers', 'code' => 'LAS', 'childs' => array()),
                        array('parentId' => 4020, 'id' => 4065, 'name' => 'All In One', 'code' => 'AIO', 'childs' => array())
                    )
                )
            )
        ),
        
        // TV, Audio & Video
        array(
            'parentId' => 0,
            'id' => 6341,
            'name' => 'TV, Audio & Video',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 6341,
                    'id' => 6312,
                    'name' => 'Audio',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 6312, 'id' => 6313, 'name' => 'Headphones', 'code' => 'HPH', 'childs' => array()),
                        array('parentId' => 6312, 'id' => 6315, 'name' => 'Speakers', 'code' => 'SPE', 'childs' => array()),
                        array('parentId' => 6312, 'id' => 6471, 'name' => 'Microphones', 'code' => 'MIC', 'childs' => array()),
                        array('parentId' => 6312, 'id' => 6316, 'name' => 'MP3 Players', 'code' => 'MMD', 'childs' => array()),
                        array('parentId' => 6312, 'id' => 6883, 'name' => 'Home Audio', 'code' => 'HAV', 'childs' => array()),
                        array('parentId' => 6312, 'id' => 6395, 'name' => 'Soundbar Speakers', 'code' => 'SBR', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6341,
                    'id' => 7124,
                    'name' => 'TV',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7124, 'id' => 4372, 'name' => 'TV Sets', 'code' => 'TVP', 'childs' => array()),
                        array('parentId' => 7124, 'id' => 4848, 'name' => 'Media Players', 'code' => 'TMP', 'childs' => array()),
                        array('parentId' => 7124, 'id' => 8087, 'name' => 'Portable TVs & Monitors', 'code' => 'PTV', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6341,
                    'id' => 4467,
                    'name' => 'Camera & Photo',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 4467, 'id' => 4356, 'name' => 'Video Cameras', 'code' => 'VCA', 'childs' => array()),
                        array('parentId' => 4467, 'id' => 6248, 'name' => 'Instant Cameras', 'code' => 'INS', 'childs' => array()),
                        array('parentId' => 4467, 'id' => 7010, 'name' => 'Drones', 'code' => 'DRO', 'childs' => array()),
                        array('parentId' => 4467, 'id' => 7039, 'name' => 'Gimbals', 'code' => 'GIM', 'childs' => array())
                    )
                )
            )
        ),
        
        // Smartphones & Tablets  
        array(
            'parentId' => 0,
            'id' => 7127,
            'name' => 'Smartphones & Tablets',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 7127,
                    'id' => 5875,
                    'name' => 'Smartphones',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 5875, 'id' => 4997, 'name' => 'Smartphones', 'code' => 'MPH', 'childs' => array()),
                        array('parentId' => 5875, 'id' => 7064, 'name' => 'Feature Phones', 'code' => 'MPF', 'childs' => array()),
                        array('parentId' => 5875, 'id' => 6747, 'name' => 'Smartphone Covers & Cases', 'code' => 'MPC', 'childs' => array()),
                        array('parentId' => 5875, 'id' => 6756, 'name' => 'Phone Screen Protectors', 'code' => 'MSP', 'childs' => array()),
                        array('parentId' => 5875, 'id' => 6792, 'name' => 'Phone Car Mounts', 'code' => 'MHC', 'childs' => array()),
                        array('parentId' => 5875, 'id' => 5039, 'name' => 'Phone Accessories', 'code' => 'MPA', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 7127,
                    'id' => 6382,
                    'name' => 'Tablets & E-Readers',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 6382, 'id' => 6383, 'name' => 'Tablets', 'code' => 'TPC', 'childs' => array()),
                        array('parentId' => 6382, 'id' => 8120, 'name' => 'Children\'s tablets', 'code' => 'CHT', 'childs' => array()),
                        array('parentId' => 6382, 'id' => 6385, 'name' => 'E-Readers & Accessories', 'code' => 'ERD', 'childs' => array()),
                        array('parentId' => 6382, 'id' => 6384, 'name' => 'Tablet Accessories', 'code' => 'TPA', 'childs' => array()),
                        array('parentId' => 6382, 'id' => 6748, 'name' => 'Tablet Sleeves', 'code' => 'TSL', 'childs' => array())
                    )
                )
            )
        ),
        
        // Networking
        array(
            'parentId' => 0,
            'id' => 4018,
            'name' => 'Networking',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 4018,
                    'id' => 7130,
                    'name' => 'Wired devices',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7130, 'id' => 4060, 'name' => 'Switches', 'code' => 'SWI', 'childs' => array()),
                        array('parentId' => 7130, 'id' => 5161, 'name' => 'Routers', 'code' => 'ROU', 'childs' => array()),
                        array('parentId' => 7130, 'id' => 4056, 'name' => 'Wired Network Adapters', 'code' => 'NIC', 'childs' => array()),
                        array('parentId' => 7130, 'id' => 5158, 'name' => 'POE Devices', 'code' => 'POE', 'childs' => array()),
                        array('parentId' => 7130, 'id' => 4055, 'name' => 'Media Convertors &Modules', 'code' => 'MCO', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4018,
                    'id' => 7129,
                    'name' => 'Wireless equipment',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7129, 'id' => 5204, 'name' => 'Wireless Routers', 'code' => 'WRO', 'childs' => array()),
                        array('parentId' => 7129, 'id' => 5189, 'name' => 'Wireless Access Points', 'code' => 'WAP', 'childs' => array()),
                        array('parentId' => 7129, 'id' => 4401, 'name' => 'Wireless Network Adapters', 'code' => 'WRA', 'childs' => array()),
                        array('parentId' => 7129, 'id' => 5203, 'name' => 'Wireless Range Extenders', 'code' => 'WRE', 'childs' => array()),
                        array('parentId' => 7129, 'id' => 5198, 'name' => '3G/4G Routers', 'code' => 'WR3', 'childs' => array())
                    )
                )
            )
        ),
        
        // Security Solutions
        array(
            'parentId' => 0,
            'id' => 6404,
            'name' => 'Security Solutions', 
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 6404,
                    'id' => 7166,
                    'name' => 'Video Surveillance',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7166, 'id' => 4949, 'name' => 'IP Cameras', 'code' => 'FNC', 'childs' => array()),
                        array('parentId' => 7166, 'id' => 4960, 'name' => 'NVR', 'code' => 'NVR', 'childs' => array()),
                        array('parentId' => 7166, 'id' => 4935, 'name' => 'DVR', 'code' => 'STD', 'childs' => array()),
                        array('parentId' => 7166, 'id' => 5065, 'name' => 'HDCVI Cameras', 'code' => 'HSC', 'childs' => array()),
                        array('parentId' => 7166, 'id' => 4962, 'name' => 'AccessoriesCCTV IP', 'code' => 'IPC', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6404,
                    'id' => 4918,
                    'name' => 'Intruder Alarm Systems',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 4918, 'id' => 4928, 'name' => 'Control Panels', 'code' => 'CNP', 'childs' => array()),
                        array('parentId' => 4918, 'id' => 4919, 'name' => 'Detectors', 'code' => 'MDE', 'childs' => array()),
                        array('parentId' => 4918, 'id' => 4942, 'name' => 'Keypads', 'code' => 'SKP', 'childs' => array()),
                        array('parentId' => 4918, 'id' => 6209, 'name' => 'Sirens', 'code' => 'SIR', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6404,
                    'id' => 4916,
                    'name' => 'Door Entry Systems',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 4916, 'id' => 6648, 'name' => 'Doorphone Systems', 'code' => 'DEV', 'childs' => array()),
                        array('parentId' => 4916, 'id' => 6646, 'name' => 'Network Doorphones', 'code' => 'NDP', 'childs' => array()),
                        array('parentId' => 4916, 'id' => 4955, 'name' => 'Standalone Locks', 'code' => 'DCL', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 6404,
                    'id' => 4975,
                    'name' => 'Access Control Systems',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 4975, 'id' => 4982, 'name' => 'Door Controllers', 'code' => 'CRL', 'childs' => array())
                    )
                )
            )
        ),
        
        // Servers & Components
        array(
            'parentId' => 0,
            'id' => 4021,
            'name' => 'Servers & Components',
            'code' => '',
            'childs' => array(
                array(
                    'parentId' => 4021,
                    'id' => 7168,
                    'name' => 'Servers & Chassis',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7168, 'id' => 5809, 'name' => 'Servers', 'code' => 'SER', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4021,
                    'id' => 7169,
                    'name' => 'Server Components',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7169, 'id' => 4119, 'name' => 'Mainboards', 'code' => 'MBS', 'childs' => array()),
                        array('parentId' => 7169, 'id' => 4363, 'name' => 'Memory', 'code' => 'MES', 'childs' => array()),
                        array('parentId' => 7169, 'id' => 5173, 'name' => 'Server PSU', 'code' => 'SPU', 'childs' => array()),
                        array('parentId' => 7169, 'id' => 4073, 'name' => 'Server Parts', 'code' => 'SCO', 'childs' => array())
                    )
                ),
                array(
                    'parentId' => 4021,
                    'id' => 7170,
                    'name' => 'SSD & HDD Enterprise',
                    'code' => '',
                    'childs' => array(
                        array('parentId' => 7170, 'id' => 4142, 'name' => 'HDD Enterprise SAS', 'code' => 'HDC', 'childs' => array()),
                        array('parentId' => 7170, 'id' => 5111, 'name' => 'HDD Enterprise SATA', 'code' => 'HES', 'childs' => array()),
                        array('parentId' => 7170, 'id' => 5183, 'name' => 'SSD Enterprise PCI-E', 'code' => 'SSP', 'childs' => array()),
                        array('parentId' => 7170, 'id' => 5137, 'name' => 'SSD Enterprise SAS', 'code' => 'SSS', 'childs' => array()),
                        array('parentId' => 7170, 'id' => 5025, 'name' => 'SSD Enterprise SATA', 'code' => 'SFM', 'childs' => array())
                    )
                )
            )
        )
    );
}
    
    /**
     * Get products by category
     */
    public function get_products_by_category($category_code_id) {
        $endpoint = "/v3.0/api/Catalog/Products?catalog={$category_code_id}";
        return $this->make_request($endpoint);
    }
    
    /**
     * Get product descriptions/attributes
     */
    public function get_product_descriptions($product_ids, $language = 'EE') {
        if (is_array($product_ids)) {
            $product_ids = implode(',', $product_ids);
        }
        
        $endpoint = "/v3.0/api/Catalog/Products/{$product_ids}/Description?lang={$language}";
        return $this->make_request($endpoint);
    }
    
    /**
     * Get product media items (gallery)
     */
    public function get_product_media($product_ids) {
        if (is_array($product_ids)) {
            $product_ids = implode(',', $product_ids);
        }
        
        $endpoint = "/v3.0/api/Catalog/MediaItems/{$product_ids}";
        return $this->make_request($endpoint);
    }
    
    /**
     * Get product details with descriptions and media
     */
    public function get_products_with_details($category_code_id, $language = 'EE') {
        try {
            // 1. Get base products
            $products = $this->get_products_by_category($category_code_id);
            
            if (is_wp_error($products) || empty($products)) {
                return $products;
            }
            
            ELKO_Logger::log("Retrieved " . count($products) . " products for category {$category_code_id}", 'info');
            
            // 2. Extract product IDs
            $product_ids = array();
            foreach ($products as $product) {
                if (isset($product['id'])) {
                    $product_ids[] = $product['id'];
                }
            }
            
            if (empty($product_ids)) {
                return $products; // Return basic products if no IDs found
            }
            
            // Limit to batches of 50 to avoid URL length issues
            $batch_size = 50;
            $product_batches = array_chunk($product_ids, $batch_size);
            
            $all_descriptions = array();
            $all_media = array();
            
            // 3. Get descriptions in batches
            foreach ($product_batches as $batch) {
                try {
                    $descriptions = $this->get_product_descriptions($batch, $language);
                    if (is_array($descriptions)) {
                        foreach ($descriptions as $desc) {
                            if (isset($desc['productId'])) {
                                $all_descriptions[$desc['productId']] = $desc;
                            }
                        }
                    }
                    
                    // Small delay between requests
                    usleep(200000); // 0.2 seconds
                    
                } catch (Exception $e) {
                    ELKO_Logger::log("Failed to get descriptions for batch: " . $e->getMessage(), 'warning');
                }
            }
            
            // 4. Get media in batches
            foreach ($product_batches as $batch) {
                try {
                    $media = $this->get_product_media($batch);
                    if (is_array($media)) {
                        foreach ($media as $media_item) {
                            if (isset($media_item['id'])) {
                                $all_media[$media_item['id']] = $media_item;
                            }
                        }
                    }
                    
                    // Small delay between requests
                    usleep(200000); // 0.2 seconds
                    
                } catch (Exception $e) {
                    ELKO_Logger::log("Failed to get media for batch: " . $e->getMessage(), 'warning');
                }
            }
            
            // 5. Merge data into products
            foreach ($products as &$product) {
                $product_id = $product['id'];
                
                // Add descriptions/attributes
                if (isset($all_descriptions[$product_id])) {
                    $product['detailed_description'] = $all_descriptions[$product_id];
                    $product['attributes'] = $this->parse_product_attributes($all_descriptions[$product_id]);
                }
                
                // Add media/gallery
                if (isset($all_media[$product_id])) {
                    $product['gallery'] = $all_media[$product_id]['mediaFiles'] ?? array();
                }
            }
            
            ELKO_Logger::log("Enhanced " . count($products) . " products with descriptions and media", 'info');
            
            return $products;
            
        } catch (Exception $e) {
            ELKO_Logger::log("Error getting products with details: " . $e->getMessage(), 'error');
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * Parse product attributes from description data
     */
    private function parse_product_attributes($description_data) {
        $attributes = array();
        
        if (!isset($description_data['description']) || !is_array($description_data['description'])) {
            return $attributes;
        }
        
        foreach ($description_data['description'] as $criteria) {
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
                $attributes[sanitize_key($name)] = array(
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
     * Get allowed categories for import - dynamically from API
     */
    public function get_allowed_categories() {
        // Try to get cached categories first
        $cached = get_transient('elko_allowed_categories');
        if ($cached !== false && !empty($cached)) {
            return $cached;
        }
        
        // Fetch all third-level categories from API
        $categories = $this->fetch_all_third_level_categories();
        
        if (!empty($categories)) {
            // Cache for 1 hour
            set_transient('elko_allowed_categories', $categories, HOUR_IN_SECONDS);
            return $categories;
        }
        
        // Fallback to hardcoded if API fails
        return $this->get_fallback_categories();
    }
    
    /**
     * Fetch all third-level categories from API tree
     */
    public function fetch_all_third_level_categories() {
        $tree = $this->get_category_tree();
        
        if (is_wp_error($tree) || !is_array($tree)) {
            return array();
        }
        
        $categories = array();
        
        // Recursively extract all leaf categories (those with code)
        $this->extract_categories_recursive($tree, $categories);
        
        // Sort alphabetically by name
        ksort($categories);
        
        return $categories;
    }
    
    /**
     * Recursively extract categories with codes (leaf categories)
     */
    private function extract_categories_recursive($nodes, &$categories) {
        foreach ($nodes as $node) {
            // If this node has a code, it's a product category
            if (!empty($node['code']) && !empty($node['name'])) {
                $code = $node['code'];
                $id = $node['id'];
                $name = $node['name'];
                
                // Format: CODE_ID for API calls
                $categories[$name] = $code . '_' . $id;
            }
            
            // Process children
            if (isset($node['childs']) && is_array($node['childs']) && !empty($node['childs'])) {
                $this->extract_categories_recursive($node['childs'], $categories);
            }
        }
    }
    
    /**
     * Clear categories cache (call this when categories are updated)
     */
    public function clear_categories_cache() {
        delete_transient('elko_allowed_categories');
    }
    
    /**
     * Get fallback hardcoded categories if API fails
     */
    private function get_fallback_categories() {
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
            'All In One' => 'AIO_4065',
            'Smartphones' => 'MPH_4997',
            'Feature Phones' => 'MPF_7064',
            'Smartphone Covers & Cases' => 'MPC_6747',
            'Phone Screen Protectors' => 'MSP_6756',
            'Phone Car Mounts' => 'MHC_6792',
            'Phone Accessories' => 'MPA_5039',
            'Tablets' => 'TPC_6383',
            'Children\'s tablets' => 'CHT_8120',
            'E-Readers & Accessories' => 'ERD_6385',
            'Tablet Accessories' => 'TPA_6384',
            'Tablet Sleeves' => 'TSL_6748',
            'Switches' => 'SWI_4060',
            'Routers' => 'ROU_5161',
            'Wired Network Adapters' => 'NIC_4056',
            'POE Devices' => 'POE_5158',
            'Media Convertors & Modules' => 'MCO_4055',
            'Wireless Routers' => 'WRO_5204',
            'Wireless Access Points' => 'WAP_5189',
            'Wireless Network Adapters' => 'WRA_4401',
            'Wireless Range Extenders' => 'WRE_5203',
            '3G/4G Routers' => 'WR3_5198',
            'IP Cameras' => 'FNC_4949',
            'NVR' => 'NVR_4960',
            'DVR' => 'STD_4935',
            'HDCVI Cameras' => 'HSC_5065',
            'Accessories CCTV IP' => 'IPC_4962',
            'Control Panels' => 'CNP_4928',
            'Detectors' => 'MDE_4919',
            'Keypads' => 'SKP_4942',
            'Sirens' => 'SIR_6209',
            'Doorphone Systems' => 'DEV_6648',
            'Network Doorphones' => 'NDP_6646',
            'Standalone Locks' => 'DCL_4955',
            'Door Controllers' => 'CRL_4982',
            'Servers' => 'SER_5809',
            'Server Mainboards' => 'MBS_4119',
            'Server Memory' => 'MES_4363',
            'Server PSU' => 'SPU_5173',
            'Server Parts' => 'SCO_4073',
            'HDD Enterprise SAS' => 'HDC_4142',
            'HDD Enterprise SATA' => 'HES_5111',
            'SSD Enterprise PCI-E' => 'SSP_5183',
            'SSD Enterprise SAS' => 'SSS_5137',
            'SSD Enterprise SATA' => 'SFM_5025',
            'TV Sets' => 'TVP_4372',
            'Media Players' => 'TMP_4848',
            'Portable TVs & Monitors' => 'PTV_8087',
            'MP3 Players' => 'MMD_6316',
            'Home Audio' => 'HAV_6883',
            'Soundbar Speakers' => 'SBR_6395',
            'Video Cameras' => 'VCA_4356',
            'Instant Cameras' => 'INS_6248',
            'Drones' => 'DRO_7010',
            'Gimbals' => 'GIM_7039'
        );
    }
    
    /**
     * Debug endpoints
     */
    public function debug_endpoints() {
        $endpoints = array(
            'Categories' => '/v3.0/api/Catalog/Categories',
            'Category Tree' => '/v3.0/api/Catalog/Categories/Tree',
            'Sample Products' => '/v3.0/api/Catalog/Products?catalog=CPU_4028',
            'Sample Descriptions' => '/v3.0/api/Catalog/Products/1435774,1437263/Description?lang=EE',
            'Sample Media' => '/v3.0/api/Catalog/MediaItems/1435774,1437263'
        );
        
        $results = array();
        
        foreach ($endpoints as $name => $endpoint) {
            try {
                $start_time = microtime(true);
                $response = $this->make_request($endpoint);
                $end_time = microtime(true);
                
                $time_taken = round(($end_time - $start_time) * 1000, 2);
                
                if (is_wp_error($response)) {
                    $results[$name] = "❌ Error: " . $response->get_error_message();
                } else {
                    $count = is_array($response) ? count($response) : 1;
                    $results[$name] = "✅ HTTP 200 OK - {$count} items ({$time_taken}ms)";
                }
                
            } catch (Exception $e) {
                $results[$name] = "❌ Exception: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Make HTTP request to ELKO API
     */
    private function make_request($endpoint) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'API key is not configured');
        }
        
        $url = rtrim($this->api_url, '/') . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept' => 'text/plain',
            'Content-Type' => 'application/json',
            'User-Agent' => 'WooCommerce-ELKO-Integration/' . ELKO_PLUGIN_VERSION
        );
        
        $start_time = microtime(true);
        
        // Log request details
        ELKO_Logger::log('=== API REQUEST DEBUG ===', 'info');
        ELKO_Logger::log("Request URL: {$url}", 'info');
        ELKO_Logger::log("Method: GET", 'info');
        ELKO_Logger::log("Client IP: " . $this->get_client_ip(), 'info');
        ELKO_Logger::log("Server IP: " . $this->get_server_ip(), 'info');
        ELKO_Logger::log("API Key (first 20 chars): " . substr($this->api_key, 0, 20) . '...', 'info');
        ELKO_Logger::log("Full Headers: " . json_encode($headers), 'info');
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        $end_time = microtime(true);
        $request_time = round(($end_time - $start_time) * 1000, 1);
        
        ELKO_Logger::log("Request took: {$request_time}ms", 'info');
        
        if (is_wp_error($response)) {
            ELKO_Logger::log("Request failed: " . $response->get_error_message(), 'error');
            ELKO_Logger::log('=== END API REQUEST DEBUG ===', 'info');
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $body = wp_remote_retrieve_body($response);
        
        ELKO_Logger::log("Response Status: {$status_code}", 'info');
        ELKO_Logger::log("Response Headers: " . json_encode($response_headers->getAll()), 'info');
        ELKO_Logger::log("Response Body Length: " . strlen($body) . ' bytes', 'info');
        ELKO_Logger::log("Response Body (first 500 chars): " . substr($body, 0, 500), 'info');
        
        if ($status_code !== 200) {
            ELKO_Logger::log("HTTP Error {$status_code}: {$body}", 'error');
            ELKO_Logger::log('=== END API REQUEST DEBUG ===', 'info');
            return new WP_Error('http_error', "HTTP {$status_code}: {$body}");
        }
        
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ELKO_Logger::log("JSON decode error: " . json_last_error_msg(), 'error');
            ELKO_Logger::log('=== END API REQUEST DEBUG ===', 'info');
            return new WP_Error('json_error', 'Invalid JSON response');
        }
        
        ELKO_Logger::log("Successfully parsed JSON response with " . (is_array($decoded) ? count($decoded) : 1) . " items", 'info');
        ELKO_Logger::log('=== END API REQUEST DEBUG ===', 'info');
        
        return $decoded;
    }
    
    /**
     * Get debug information
     */
    private function get_debug_info() {
        $debug_info = array(
            'environment' => array(
                'client_ip' => $this->get_client_ip(),
                'server_ip' => $this->get_server_ip(),
                'user_agent' => 'WooCommerce-ELKO-Integration/' . ELKO_PLUGIN_VERSION,
                'api_url' => $this->api_url,
                'api_key_length' => strlen($this->api_key),
                'wp_version' => get_bloginfo('version'),
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A'
            )
        );
        
        // JWT token info
        if (!empty($this->api_key)) {
            $debug_info['jwt_info'] = $this->parse_jwt_info($this->api_key);
        }
        
        return $debug_info;
    }
    
    /**
     * Parse JWT token info
     */
    private function parse_jwt_info($jwt) {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return array('error' => 'Invalid JWT format');
            }
            
            $header = json_decode(base64_decode($parts[0]), true);
            $payload = json_decode(base64_decode($parts[1]), true);
            
            $info = array(
                'algorithm' => $header['alg'] ?? 'Unknown',
                'type' => $header['typ'] ?? 'Unknown',
                'issued_at' => isset($payload['iat']) ? date('Y-m-d H:i:s', $payload['iat']) : 'Unknown',
                'expires_at' => isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : 'Unknown',
                'is_expired' => isset($payload['exp']) ? ($payload['exp'] < time()) : false,
                'issuer' => $payload['iss'] ?? 'Unknown'
            );
            
            return $info;
            
        } catch (Exception $e) {
            return array('error' => 'Could not parse JWT: ' . $e->getMessage());
        }
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Get server IP
     */
    private function get_server_ip() {
        return $_SERVER['SERVER_ADDR'] ?? 'Unknown';
    }
}