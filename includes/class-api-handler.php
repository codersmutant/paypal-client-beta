<?php
/**
 * API Handler for communication with Website B
 * Updated to support multiple proxy servers with fixed usage tracking
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy API Handler Class
 */
class WPPPC_API_Handler {
    
    /**
     * Current proxy server
     */
    private $server;
    
    /**
     * Server Manager instance
     */
    private $server_manager;
    
    /**
     * Constructor
     */
    public function __construct($server_id = null) {
        // Initialize server manager using singleton pattern
        $this->server_manager = WPPPC_Server_Manager::get_instance();
        
        // Get server based on provided ID or use the selected server
        if ($server_id) {
            $this->server = $this->server_manager->get_server($server_id);
        } else {
            // Use the selected server or get next available one
            $this->server = $this->server_manager->get_selected_server();
            
            if (!$this->server) {
                $this->server = $this->server_manager->get_next_available_server();
            }
        }
        
        // Fallback to legacy options if no server is available
        if (!$this->server) {
            $this->legacy_init();
        }
    }
    
    /**
     * Legacy initialization using old global options
     
    private function legacy_init() {
        $this->server = new stdClass();
        $this->server->url = get_option('wpppc_proxy_url', '');
        $this->server->api_key = get_option('wpppc_api_key', '');
        $this->server->api_secret = get_option('wpppc_api_secret', '');
    }
    */
    
    private function legacy_init() {
        $this->server = new stdClass();
        $this->server->url = '';
        $this->server->api_key = '';
        $this->server->api_secret = '';
   }
    
    /**
     * Send order details to Website B
     * This is where we actually use the server, so track usage here
     */
    public function send_order_details($order) {
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order object', 'woo-paypal-proxy-client'));
        }
        

        
        // Store the server ID used for this order
        if (isset($this->server->id)) {
            update_post_meta($order->get_id(), '_wpppc_server_id', $this->server->id);
        }
        
        // Prepare order data
        $order_data = array(
            'order_id'       => $order->get_id(),
            'order_key'      => $order->get_order_key(),
            'order_total'    => $order->get_total(),
            'currency'       => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'items'          => $this->get_order_items($order),
            'site_url'       => get_site_url(),
            'server_id'      => isset($this->server->id) ? $this->server->id : 0,
        );
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order->get_total() . $this->server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $this->server->api_secret);
        
        // Encode order data
        $encoded_data = base64_encode(json_encode($order_data));
        
        // Prepare request parameters
        $params = array(
            'rest_route'  => '/wppps/v1/register-order',
            'api_key'     => $this->server->api_key,
            'timestamp'   => $timestamp,
            'hash'        => $hash,
            'order_data'  => $encoded_data,
        );
        
        // Send request to Website B
        $response = $this->make_request($params);
        
        return $response;
    }
    
    /**
     * Verify payment with Website B
     * This is where we actually use the server, so track usage here
     */
    public function verify_payment($paypal_order_id, $order) {
        if (!$paypal_order_id || !$order) {
            return new WP_Error('invalid_data', __('Invalid payment data', 'woo-paypal-proxy-client'));
        }
        
        // Get the server ID used for this order
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        
        // If a specific server was used for this order, use it again
        if ($server_id) {
            $this->server = $this->server_manager->get_server($server_id);
            
            // Fallback to the current server if the original server is not found
            if (!$this->server) {
                $this->server = $this->server_manager->get_selected_server();
                if (!$this->server) {
                    $this->server = $this->server_manager->get_next_available_server();
                }
            }
        }
        
        
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $paypal_order_id . $order->get_id() . $this->server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $this->server->api_secret);
        
        // Prepare request parameters
        $params = array(
            'rest_route'     => '/wppps/v1/verify-payment',
            'api_key'        => $this->server->api_key,
            'timestamp'      => $timestamp,
            'hash'           => $hash,
            'paypal_order_id' => $paypal_order_id,
            'order_id'       => $order->get_id(),
            'order_total'    => $order->get_total(),
            'currency'       => $order->get_currency(),
            'server_id'      => isset($this->server->id) ? $this->server->id : 0,
        );
        
        // Send request to Website B
        $response = $this->make_request($params);
        
        return $response;
    }
    
    /**
     * Make API request to Website B
     */
    private function make_request($params) {
        // Build request URL
        $url = $this->server->url . '?' . http_build_query($params);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WooCommerce PayPal Proxy Client/' . WPPPC_VERSION,
            ),
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return new WP_Error(
                'api_error',
                sprintf(__('API Error: %s', 'woo-paypal-proxy-client'), wp_remote_retrieve_response_message($response))
            );
        }
        
        // Get response body
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API', 'woo-paypal-proxy-client'));
        }
        
        // Check for API error
        if (isset($data['success']) && $data['success'] === false) {
            return new WP_Error(
                'api_response_error',
                isset($data['message']) ? $data['message'] : __('Unknown API error', 'woo-paypal-proxy-client')
            );
        }
        
        return $data;
    }
    
    /**
     * Get order items in a format suitable for API transmission
     */
    private function get_order_items($order) {
        $items = array();
        
        // Get Product Mapping instance if available
        $product_mapping = null;
        if (class_exists('WPPPC_Product_Mapping')) {
            $product_mapping = new WPPPC_Product_Mapping();
        }
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $item_data = array(
                'product_id'   => $product ? $product->get_id() : 0,
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'price'        => $order->get_item_total($item, false, false),
                'line_total'   => $item->get_total(),
                'sku'          => $product ? $product->get_sku() : '',
            );
            
            // Add mapped product ID if available
            if ($product_mapping && $product) {
                $mapped_id = $product_mapping->get_product_mapping($product->get_id());
                if ($mapped_id) {
                    $item_data['mapped_product_id'] = $mapped_id;
                }
            }
            
            $items[] = $item_data;
        }
        
        return $items;
    }
    
    /**
     * Get current server details
     */
    public function get_server() {
        return $this->server;
    }
    
    /**
     * Get iframe URL with parameters for the current server
     */
    public function generate_iframe_url() {
        // Get cart total and currency
        $total = WC()->cart->get_total('');
        $currency = get_woocommerce_currency();
        
        // Get callback URL
        $callback_url = WC()->api_request_url('wpppc_callback');
        
        // Generate a hash for security
        $timestamp = time();
        //$hash_data = $timestamp . $total . $currency . $this->server->api_key;
        $hash_data = $timestamp . $this->server->api_key;
        $hash = hash_hmac('sha256', $timestamp, $this->server->api_secret);
        
        // Build the iframe URL
        $params = array(
            'rest_route'    => '/wppps/v1/paypal-buttons',
            'amount'        => $total,
            'currency'      => $currency,
            'api_key'       => $this->server->api_key,
            'timestamp'     => $timestamp,
            'hash'          => $hash,
            'callback_url'  => base64_encode($callback_url),
            'site_url'      => base64_encode(get_site_url()),
            'server_id'     => isset($this->server->id) ? $this->server->id : 0,
        );
        
        return $this->server->url . '?' . http_build_query($params);
    }
}