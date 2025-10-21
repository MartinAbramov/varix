<?php
/**
 * ELKO Price Calculator
 */

if (!defined('ABSPATH')) {
    exit;
}

class ELKO_Price_Calculator {
    
    private $pricing_settings;
    
    public function __construct() {
        $this->pricing_settings = get_option('elko_pricing_settings', array());
    }
    
    /**
     * Calculate final price from ELKO base price
     */
    public function calculate_price($elko_price) {
        if (!is_numeric($elko_price) || $elko_price <= 0) {
            return 0;
        }
        
        $tax_percentage = floatval($this->pricing_settings['tax_percentage'] ?? 21);
        $markup_percentage = floatval($this->pricing_settings['markup_percentage'] ?? 15);
        $method = $this->pricing_settings['price_calculation_method'] ?? 'simple';
        $round_prices = $this->pricing_settings['round_prices'] ?? true;
        
        $base_price = floatval($elko_price);
        
        if ($method === 'compound') {
            // Compound: ELKO Price × (1 + Tax%) × (1 + Markup%)
            $final_price = $base_price * (1 + $tax_percentage / 100) * (1 + $markup_percentage / 100);
        } else {
            // Simple: (ELKO Price + Tax) × (1 + Markup%)
            $tax_amount = $base_price * ($tax_percentage / 100);
            $final_price = ($base_price + $tax_amount) * (1 + $markup_percentage / 100);
        }
        
        if ($round_prices) {
            $final_price = round($final_price, 2);
        }
        
        return $final_price;
    }
    
    /**
     * Get price breakdown for display
     */
    public function get_price_breakdown($elko_price) {
        if (!is_numeric($elko_price) || $elko_price <= 0) {
            return array();
        }
        
        $tax_percentage = floatval($this->pricing_settings['tax_percentage'] ?? 21);
        $markup_percentage = floatval($this->pricing_settings['markup_percentage'] ?? 15);
        $method = $this->pricing_settings['price_calculation_method'] ?? 'simple';
        
        $base_price = floatval($elko_price);
        $breakdown = array();
        
        $breakdown['base_price'] = $base_price;
        $breakdown['tax_percentage'] = $tax_percentage;
        $breakdown['markup_percentage'] = $markup_percentage;
        $breakdown['method'] = $method;
        
        if ($method === 'compound') {
            $breakdown['price_after_tax'] = $base_price * (1 + $tax_percentage / 100);
            $breakdown['final_price'] = $breakdown['price_after_tax'] * (1 + $markup_percentage / 100);
        } else {
            $breakdown['tax_amount'] = $base_price * ($tax_percentage / 100);
            $breakdown['price_after_tax'] = $base_price + $breakdown['tax_amount'];
            $breakdown['final_price'] = $breakdown['price_after_tax'] * (1 + $markup_percentage / 100);
        }
        
        return $breakdown;
    }
}