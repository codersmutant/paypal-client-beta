<?php
/**
 * PayPal Proxy Gateway for WooCommerce
 * Updated to support multiple proxy servers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Gateway Class
 */
class WPPPC_PayPal_Gateway extends WC_Payment_Gateway {
    
    /**
     * API Handler instance
     */
    private $api_handler;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'paypal_proxy';
        $this->icon               = apply_filters('woocommerce_paypal_proxy_icon', WPPPC_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal via Proxy', 'woo-paypal-proxy-client');
        $this->method_description = __('Accept PayPal payments securely through multiple proxy servers with load balancing.', 'woo-paypal-proxy-client');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        
        // Initialize API handler (will use server manager to get the next available server)
        $this->api_handler = new WPPPC_API_Handler();
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wpppc_callback', array($this, 'process_callback'));
        
        // AJAX handlers for order processing
        add_action('wp_ajax_wpppc_validate_checkout', array($this, 'ajax_validate_checkout'));
        add_action('wp_ajax_nopriv_wpppc_validate_checkout', array($this, 'ajax_validate_checkout'));
        add_action('wp_ajax_wpppc_complete_order', array($this, 'ajax_complete_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_order', array($this, 'ajax_complete_order'));
    }
    
    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woo-paypal-proxy-client'),
                'type'        => 'checkbox',
                'label'       => __('Enable PayPal via Proxy', 'woo-paypal-proxy-client'),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('PayPal', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('Pay securely via PayPal.', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'servers_notice' => array(
                'title'       => __('Server Configuration', 'woo-paypal-proxy-client'),
                'type'        => 'title',
                'description' => __('PayPal proxy servers can be configured in the <a href="admin.php?page=wpppc-servers">PayPal Proxy Servers</a> settings.', 'woo-paypal-proxy-client'),
            ),
        );
    }
    
    /**
     * Payment fields displayed on checkout
     */
    public function payment_fields() {
        // Display description if set
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Get the server info for debugging (optional)
        $server = $this->api_handler->get_server();
        $server_info = '';
        
        if (WP_DEBUG && isset($server->name)) {
            $server_info = '<div class="wpppc-server-info" style="font-size: 0.8em; margin-top: 10px; color: #999;">' . 
                           sprintf(__('Using server: %s', 'woo-paypal-proxy-client'), esc_html($server->name)) . 
                           '</div>';
        }
        
        // Load PayPal buttons iframe
        $iframe_url = $this->api_handler->generate_iframe_url();
        include WPPPC_PLUGIN_DIR . 'templates/iframe-container.php';
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        // This method will be called when the order is created
        // The actual payment processing happens via AJAX
        
        $order = wc_get_order($order_id);
        
        // Return success response
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    /**
     * AJAX handler for validating checkout fields
     */
    public function ajax_validate_checkout() {
        check_ajax_referer('wpppc-nonce', 'nonce');
        
        $errors = array();
        
        // Get checkout fields
        $fields = WC()->checkout()->get_checkout_fields();
        
        // Check if shipping to different address
        $ship_to_different_address = !empty($_POST['ship_to_different_address']);
        
        // Check if creating account
        $create_account = !empty($_POST['createaccount']);
        
        // Loop through field groups and validate conditionally
        foreach ($fields as $fieldset_key => $fieldset) {
            // Skip shipping fields if not shipping to different address
            if ($fieldset_key === 'shipping' && !$ship_to_different_address) {
                continue;
            }
            
            // Skip account fields if not creating account
            if ($fieldset_key === 'account' && !$create_account) {
                continue;
            }
            
            foreach ($fieldset as $key => $field) {
                // Only validate required fields that are empty
                if (!empty($field['required']) && empty($_POST[$key])) {
                    $errors[$key] = sprintf(__('%s is a required field.', 'woocommerce'), $field['label']);
                }
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array('valid' => true));
        } else {
            wp_send_json_error(array('valid' => false, 'errors' => $errors));
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for completing an order after payment
     */
    public function ajax_complete_order() {
        // Log all request data for debugging
        if (WP_DEBUG) {
            error_log('PayPal Proxy - Complete Order Request: ' . print_r($_POST, true));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
            error_log('PayPal Proxy - Invalid nonce in complete order request');
            wp_send_json_error(array(
                'message' => 'Security check failed'
            ));
            wp_die();
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
        $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$order_id || !$paypal_order_id) {
            error_log('PayPal Proxy - Invalid order data in completion request');
            wp_send_json_error(array(
                'message' => __('Invalid order data', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('PayPal Proxy - Order not found: ' . $order_id);
            wp_send_json_error(array(
                'message' => __('Order not found', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        try {
            // Log order details
            if (WP_DEBUG) {
                error_log('PayPal Proxy - Processing order: ' . $order_id . ', Status: ' . $order->get_status());
            }
            
            // Complete the order
            $order->payment_complete($transaction_id);
            
            // Store server ID that processed this payment
            if ($server_id) {
                update_post_meta($order->get_id(), '_wpppc_server_id', $server_id);
            }
            
            // Add order note
            $order->add_order_note(
                sprintf(__('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s, Server ID: %s', 'woo-paypal-proxy-client'),
                    $paypal_order_id,
                    $transaction_id,
                    $server_id
                )
            );
            
            // Update status to processing
            $order->update_status('processing');
            
            // Store PayPal transaction details
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
            update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
            
            // Empty cart
            WC()->cart->empty_cart();
            
            // Log the success
            if (WP_DEBUG) {
                error_log('PayPal Proxy - Order successfully completed: ' . $order_id);
            }
            
            // Return success with redirect URL
            $redirect_url = $order->get_checkout_order_received_url();
            wp_send_json_success(array(
                'redirect' => $redirect_url
            ));
        } catch (Exception $e) {
            error_log('PayPal Proxy - Exception during order completion: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Error completing order: ' . $e->getMessage()
            ));
        }
        
        wp_die();
    }
    
    /**
     * Process callback from Website B
     */
    public function process_callback() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
        $server_id = isset($_GET['server_id']) ? intval($_GET['server_id']) : 0;
        
        // Get the server that was used for this transaction
        $api_key = '';
        $api_secret = '';
        
        if ($server_id) {
            // Use the server manager to get the server
            $server_manager = new WPPPC_Server_Manager();
            $server = $server_manager->get_server($server_id);
            
            if ($server) {
                $api_key = $server->api_key;
                $api_secret = $server->api_secret;
            }
        }
        
        // Fallback to global settings if no server found
        if (empty($api_key) || empty($api_secret)) {
            $api_key = get_option('wpppc_api_key');
            $api_secret = get_option('wpppc_api_secret');
        }
        
        // Verify hash
        $check_hash = hash_hmac('sha256', $order_id . $status . $api_key, $api_secret);
        
        if ($hash !== $check_hash) {
            wp_die(__('Invalid security hash', 'woo-paypal-proxy-client'), '', array('response' => 403));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(__('Order not found', 'woo-paypal-proxy-client'), '', array('response' => 404));
        }
        
        // Store the server ID used for this transaction
        if ($server_id) {
            update_post_meta($order_id, '_wpppc_server_id', $server_id);
        }
        
        if ($status === 'completed') {
            // Payment was successful
            $order->payment_complete();
            $order->add_order_note(
                sprintf(__('Payment completed via PayPal proxy callback (Server ID: %s)', 'woo-paypal-proxy-client'), 
                    $server_id
                )
            );
            
            // Redirect to thank you page
            wp_redirect($this->get_return_url($order));
            exit;
        } elseif ($status === 'cancelled') {
            // Payment was cancelled
            $order->update_status('cancelled', __('Payment cancelled by customer', 'woo-paypal-proxy-client'));
            
            // Redirect to cart page
            wp_redirect(wc_get_cart_url());
            exit;
        } else {
            // Payment failed
            $order->update_status('failed', __('Payment failed', 'woo-paypal-proxy-client'));
            
            // Redirect to checkout page
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
}