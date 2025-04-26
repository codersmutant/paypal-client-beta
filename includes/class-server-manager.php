<?php
/**
 * PayPal Proxy Server Manager Class
 * 
 * Manages multiple proxy servers with load balancing and capacity limits
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WPPPC_Server_Manager {
    
    /**
     * Table name
     */
    private $table_name;
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * Constructor
     */
     
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpppc_proxy_servers';
        
        // Add admin menu - Use a higher priority to ensure it only runs once
        add_action('admin_menu', array($this, 'add_admin_menu'), 30);
        
        // Ajax handlers
        add_action('wp_ajax_wpppc_add_server', array($this, 'ajax_add_server'));
        add_action('wp_ajax_wpppc_update_server', array($this, 'ajax_update_server'));
        add_action('wp_ajax_wpppc_delete_server', array($this, 'ajax_delete_server'));
        add_action('wp_ajax_wpppc_reset_server_usage', array($this, 'ajax_reset_server_usage'));
        add_action('wp_ajax_wpppc_get_server', array($this, 'ajax_get_server'));
        add_action('wp_ajax_wpppc_use_server', array($this, 'ajax_use_server'));
    }
    /**
     * Add admin menu
     */
     public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('PayPal Proxy Servers', 'woo-paypal-proxy-client'),
            __('PayPal Proxy Servers', 'woo-paypal-proxy-client'),
            'manage_woocommerce',
            'wpppc-servers',
            array($this, 'render_servers_page')
        );
    }
    
    /**
     * Create the server table during plugin activation
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            url varchar(255) NOT NULL,
            api_key varchar(255) NOT NULL,
            api_secret varchar(255) NOT NULL,
            capacity_limit int(11) NOT NULL DEFAULT 1000,
            current_usage int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            is_selected tinyint(1) NOT NULL DEFAULT 0,
            priority int(11) NOT NULL DEFAULT 0,
            last_used timestamp NULL DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add default server if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if ($count == 0) {
            $default_url = get_option('wpppc_proxy_url', '');
            $default_api_key = get_option('wpppc_api_key', '');
            $default_api_secret = get_option('wpppc_api_secret', '');
            
            if (!empty($default_url) && !empty($default_api_key)) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'name' => 'Default Server',
                        'url' => $default_url,
                        'api_key' => $default_api_key,
                        'api_secret' => $default_api_secret,
                        'capacity_limit' => 1000,
                        'current_usage' => 0,
                        'is_active' => 1,
                        'is_selected' => 1,
                        'priority' => 0,
                    )
                );
            }
        }
    }
    
     /**
     * Render servers page
     */
    public function render_servers_page() {
        $servers = $this->get_all_servers();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('PayPal Proxy Servers', 'woo-paypal-proxy-client'); ?></h1>
            <a href="#" class="page-title-action add-server"><?php _e('Add New Server', 'woo-paypal-proxy-client'); ?></a>
            <hr class="wp-header-end">
            
            <div class="notice notice-info inline">
                <p><?php _e('Configure multiple proxy servers with capacity limits. The system will automatically switch to the next available server when a capacity limit is reached.', 'woo-paypal-proxy-client'); ?></p>
                <p><?php _e('You can force the use of a specific server by clicking the "Use This Server" button.', 'woo-paypal-proxy-client'); ?></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped servers-table">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('URL', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Usage', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Capacity', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Status', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Priority', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Actions', 'woo-paypal-proxy-client'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($servers)) : ?>
                    <tr>
                        <td colspan="7"><?php _e('No servers configured yet.', 'woo-paypal-proxy-client'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($servers as $server) : ?>
                        <tr data-id="<?php echo esc_attr($server->id); ?>" class="<?php echo $server->is_selected ? 'selected-server' : ''; ?>">
                            <td>
                                <?php echo esc_html($server->name); ?>
                                <?php if ($server->is_selected) : ?>
                                    <span class="selected-badge"><?php _e('(Selected)', 'woo-paypal-proxy-client'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_url($server->url); ?></td>
                            <td>
                                <div class="usage-bar">
                                    <div class="usage-progress" style="width: <?php echo min(100, ($server->current_usage / max(1, $server->capacity_limit)) * 100); ?>%"></div>
                                </div>
                                <span class="usage-text"><?php echo esc_html($server->current_usage); ?> / <?php echo esc_html($server->capacity_limit); ?></span>
                            </td>
                            <td><?php echo esc_html($server->capacity_limit); ?></td>
                            <td>
                                <?php if ($server->is_active) : ?>
                                    <span class="status-badge active"><?php _e('Active', 'woo-paypal-proxy-client'); ?></span>
                                <?php else : ?>
                                    <span class="status-badge inactive"><?php _e('Inactive', 'woo-paypal-proxy-client'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($server->priority); ?></td>
                            <td>
                                <?php if (!$server->is_selected) : ?>
                                    <a href="#" class="use-server" data-id="<?php echo esc_attr($server->id); ?>"><?php _e('Use This Server', 'woo-paypal-proxy-client'); ?></a> | 
                                <?php endif; ?>
                                <a href="#" class="edit-server" data-id="<?php echo esc_attr($server->id); ?>"><?php _e('Edit', 'woo-paypal-proxy-client'); ?></a> | 
                                <a href="#" class="reset-usage" data-id="<?php echo esc_attr($server->id); ?>"><?php _e('Reset Usage', 'woo-paypal-proxy-client'); ?></a> | 
                                <a href="#" class="delete-server" data-id="<?php echo esc_attr($server->id); ?>"><?php _e('Delete', 'woo-paypal-proxy-client'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            
            <div id="server-form-modal" class="wpppc-modal">
                <div class="wpppc-modal-content">
                    <span class="wpppc-modal-close">&times;</span>
                    <h2 id="modal-title"><?php _e('Add New Server', 'woo-paypal-proxy-client'); ?></h2>
                    
                    <form id="server-form">
                        <input type="hidden" id="server_id" name="server_id" value="0">
                        
                        <div class="form-field">
                            <label for="server_name"><?php _e('Server Name', 'woo-paypal-proxy-client'); ?></label>
                            <input type="text" id="server_name" name="server_name" required>
                        </div>
                        
                        <div class="form-field">
                            <label for="server_url"><?php _e('Server URL', 'woo-paypal-proxy-client'); ?></label>
                            <input type="url" id="server_url" name="server_url" required>
                        </div>
                        
                        <div class="form-field">
                            <label for="api_key"><?php _e('API Key', 'woo-paypal-proxy-client'); ?></label>
                            <input type="text" id="api_key" name="api_key" required>
                        </div>
                        
                        <div class="form-field">
                            <label for="api_secret"><?php _e('API Secret', 'woo-paypal-proxy-client'); ?></label>
                            <input type="text" id="api_secret" name="api_secret" required>
                            <button type="button" id="generate-secret" class="button button-secondary">
                                <?php _e('Generate Secret', 'woo-paypal-proxy-client'); ?>
                            </button>
                        </div>
                        
                        <div class="form-field">
                            <label for="capacity_limit"><?php _e('Capacity Limit', 'woo-paypal-proxy-client'); ?></label>
                            <input type="number" id="capacity_limit" name="capacity_limit" min="1" value="1000" required>
                            <p class="description"><?php _e('Maximum number of transactions this server can handle before switching to the next server.', 'woo-paypal-proxy-client'); ?></p>
                        </div>
                        
                        <div class="form-field">
                            <label for="is_active"><?php _e('Status', 'woo-paypal-proxy-client'); ?></label>
                            <select id="is_active" name="is_active">
                                <option value="1"><?php _e('Active', 'woo-paypal-proxy-client'); ?></option>
                                <option value="0"><?php _e('Inactive', 'woo-paypal-proxy-client'); ?></option>
                            </select>
                        </div>
                        
                        <div class="form-field">
                            <label for="priority"><?php _e('Priority', 'woo-paypal-proxy-client'); ?></label>
                            <input type="number" id="priority" name="priority" min="0" value="0" required>
                            <p class="description"><?php _e('Lower numbers have higher priority. Servers with the same priority will be used in a round-robin fashion.', 'woo-paypal-proxy-client'); ?></p>
                        </div>
                        
                        <div class="form-field submit-field">
                            <button type="submit" class="button button-primary"><?php _e('Save Server', 'woo-paypal-proxy-client'); ?></button>
                            <div class="form-status"></div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="server-delete-modal" class="wpppc-modal">
                <div class="wpppc-modal-content">
                    <span class="wpppc-modal-close">&times;</span>
                    <h2><?php _e('Confirm Deletion', 'woo-paypal-proxy-client'); ?></h2>
                    <p><?php _e('Are you sure you want to delete this server?', 'woo-paypal-proxy-client'); ?></p>
                    <div class="modal-actions">
                        <button id="confirm-delete" class="button button-primary" data-id="0"><?php _e('Yes, Delete', 'woo-paypal-proxy-client'); ?></button>
                        <button class="button wpppc-modal-close"><?php _e('Cancel', 'woo-paypal-proxy-client'); ?></button>
                    </div>
                </div>
            </div>
            
            <style>
                .servers-table {
                    margin-top: 20px;
                }
                .usage-bar {
                    width: 100%;
                    height: 20px;
                    background-color: #f0f0f0;
                    border-radius: 3px;
                    overflow: hidden;
                    margin-bottom: 5px;
                }
                .usage-progress {
                    height: 100%;
                    background-color: #2271b1;
                }
                .usage-text {
                    font-size: 12px;
                    color: #666;
                }
                .status-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                }
                .status-badge.active {
                    background-color: #d4edda;
                    color: #155724;
                }
                .status-badge.inactive {
                    background-color: #f8d7da;
                    color: #721c24;
                }
                
                /* Selected server styling */
                .selected-server {
                    background-color: #f0f7ff !important;
                }
                .selected-badge {
                    display: inline-block;
                    margin-left: 8px;
                    padding: 2px 6px;
                    background-color: #2271b1;
                    color: white;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: normal;
                }
                
                /* Modal styles */
                .wpppc-modal {
                    display: none;
                    position: fixed;
                    z-index: 99999;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    overflow: auto;
                    background-color: rgba(0,0,0,0.4);
                }
                
                .wpppc-modal-content {
                    position: relative;
                    background-color: #fefefe;
                    margin: 10% auto;
                    padding: 20px;
                    border: 1px solid #888;
                    width: 50%;
                    box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
                    border-radius: 4px;
                }
                
                .wpppc-modal-close {
                    color: #aaa;
                    float: right;
                    font-size: 28px;
                    font-weight: bold;
                    cursor: pointer;
                }
                
                .wpppc-modal-close:hover,
                .wpppc-modal-close:focus {
                    color: black;
                    text-decoration: none;
                    cursor: pointer;
                }
                
                .form-field {
                    margin-bottom: 15px;
                }
                
                .form-field label {
                    display: block;
                    margin-bottom: 5px;
                    font-weight: 500;
                }
                
                .form-field input,
                .form-field select {
                    width: 100%;
                    padding: 8px;
                }
                
                #generate-secret {
                    margin-top: 5px;
                }
                
                .submit-field {
                    margin-top: 20px;
                }
                
                .form-status {
                    margin-top: 10px;
                    padding: 8px;
                    border-radius: 3px;
                    display: none;
                }
                
                .form-status.success {
                    background-color: #d4edda;
                    color: #155724;
                }
                
                .form-status.error {
                    background-color: #f8d7da;
                    color: #721c24;
                }
                
                .modal-actions {
                    margin-top: 20px;
                    text-align: right;
                }
            </style>
            
            <script>
                jQuery(document).ready(function($) {
                    // Show add server modal
                    $('.add-server').on('click', function(e) {
                        e.preventDefault();
                        $('#server-form')[0].reset();
                        $('#server_id').val(0);
                        $('#modal-title').text('<?php _e('Add New Server', 'woo-paypal-proxy-client'); ?>');
                        $('#server-form-modal').show();
                    });
                    
                    // Generate secret
                    $('#generate-secret').on('click', function() {
                        var length = 32;
                        var chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                        var result = '';
                        
                        for (var i = length; i > 0; --i) {
                            result += chars[Math.floor(Math.random() * chars.length)];
                        }
                        
                        $('#api_secret').val(result);
                    });
                    
                    // Edit server
                    $('.edit-server').on('click', function(e) {
                        e.preventDefault();
                        var serverId = $(this).data('id');
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'wpppc_get_server',
                                nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                                server_id: serverId
                            },
                            success: function(response) {
                                if (response.success) {
                                    var server = response.data;
                                    
                                    $('#server_id').val(server.id);
                                    $('#server_name').val(server.name);
                                    $('#server_url').val(server.url);
                                    $('#api_key').val(server.api_key);
                                    $('#api_secret').val(server.api_secret);
                                    $('#capacity_limit').val(server.capacity_limit);
                                    $('#is_active').val(server.is_active);
                                    $('#priority').val(server.priority);
                                    
                                    $('#modal-title').text('<?php _e('Edit Server', 'woo-paypal-proxy-client'); ?>');
                                    $('#server-form-modal').show();
                                } else {
                                    alert(response.data.message || 'Error loading server data');
                                }
                            },
                            error: function() {
                                alert('Error loading server data');
                            }
                        });
                    });
                    
                    // Save server
                    $('#server-form').on('submit', function(e) {
                        e.preventDefault();
                        
                        var formData = $(this).serialize();
                        var serverId = $('#server_id').val();
                        var action = serverId == 0 ? 'wpppc_add_server' : 'wpppc_update_server';
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: action,
                                nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                                server_id: serverId,
                                server_data: formData
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('.form-status')
                                        .removeClass('error')
                                        .addClass('success')
                                        .text(response.data.message)
                                        .show();
                                    
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    $('.form-status')
                                        .removeClass('success')
                                        .addClass('error')
                                        .text(response.data.message || 'Error saving server')
                                        .show();
                                }
                            },
                            error: function() {
                                $('.form-status')
                                    .removeClass('success')
                                    .addClass('error')
                                    .text('Error saving server')
                                    .show();
                            }
                        });
                    });
                    
                    // Use specific server
                    $('.use-server').on('click', function(e) {
                        e.preventDefault();
                        var serverId = $(this).data('id');
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'wpppc_use_server',
                                nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                                server_id: serverId
                            },
                            success: function(response) {
                                if (response.success) {
                                    window.location.reload();
                                } else {
                                    alert(response.data.message || 'Error selecting server');
                                }
                            },
                            error: function() {
                                alert('Error selecting server');
                            }
                        });
                    });
                    
                    // Reset usage
                    $('.reset-usage').on('click', function(e) {
                        e.preventDefault();
                        var serverId = $(this).data('id');
                        
                        if (confirm('<?php _e('Are you sure you want to reset the usage counter for this server?', 'woo-paypal-proxy-client'); ?>')) {
                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'wpppc_reset_server_usage',
                                    nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                                    server_id: serverId
                                },
                                success: function(response) {
                                    if (response.success) {
                                        window.location.reload();
                                    } else {
                                        alert(response.data.message || 'Error resetting usage');
                                    }
                                },
                                error: function() {
                                    alert('Error resetting usage');
                                }
                            });
                        }
                    });
                    
                    // Delete server (show confirmation)
                    $('.delete-server').on('click', function(e) {
                        e.preventDefault();
                        var serverId = $(this).data('id');
                        $('#confirm-delete').data('id', serverId);
                        $('#server-delete-modal').show();
                    });
                    
                    // Confirm delete
                    $('#confirm-delete').on('click', function() {
                        var serverId = $(this).data('id');
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'wpppc_delete_server',
                                nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                                server_id: serverId
                            },
                            success: function(response) {
                                if (response.success) {
                                    window.location.reload();
                                } else {
                                    alert(response.data.message || 'Error deleting server');
                                    $('#server-delete-modal').hide();
                                }
                            },
                            error: function() {
                                alert('Error deleting server');
                                $('#server-delete-modal').hide();
                            }
                        });
                    });
                    
                    // Close modals
                    $('.wpppc-modal-close').on('click', function() {
                        $('.wpppc-modal').hide();
                    });
                    
                    // Close modal if clicked outside
                    $(window).on('click', function(e) {
                        if ($(e.target).hasClass('wpppc-modal')) {
                            $('.wpppc-modal').hide();
                        }
                    });
                });
            </script>
        </div>
        <?php
    }
    
      
    /**
     * Get all servers
     */
    public function get_all_servers() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY priority ASC, id ASC");
    }
    
    /**
     * Get server by ID
     */
    public function get_server($server_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $server_id));
    }
    
    
/**
 * Add to server usage by amount
 * This allows tracking total order amounts instead of just incrementing by 1
 * 
 * @param int $server_id Server ID
 * @param float $amount Amount to add to usage (typically order total)
 * @return bool Success/failure
 */
public function add_server_usage($server_id, $amount) {
    global $wpdb;
    
    // Validate inputs
    $server_id = intval($server_id);
    $amount = floatval($amount);
    
    if (!$server_id || $amount <= 0) {
        return false;
    }
    
    // Get current usage
    $current_usage = $wpdb->get_var(
        $wpdb->prepare("SELECT current_usage FROM {$this->table_name} WHERE id = %d", $server_id)
    );
    
    if (null === $current_usage) {
        return false; // Server not found
    }
    
    // Add amount to current usage
    $new_usage = floatval($current_usage) + $amount;
    
    // Update server usage and last_used timestamp
    $result = $wpdb->update(
        $this->table_name,
        array(
            'current_usage' => $new_usage,
            'last_used' => current_time('mysql')
        ),
        array('id' => $server_id)
    );
    
    return $result !== false;
}
    
    /**
     * Get the currently selected server
     * FIXED: Removed automatic usage tracking from this method
     */
    public function get_selected_server() {
        global $wpdb;
        $server = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE is_selected = 1 LIMIT 1");
        
        if (!$server) {
            // If no server is selected, select the first active one
            $server = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE is_active = 1 ORDER BY priority ASC, id ASC LIMIT 1");
            
            // If there's still no server, get any server
            if (!$server) {
                $server = $wpdb->get_row("SELECT * FROM {$this->table_name} ORDER BY id ASC LIMIT 1");
            }
            
            // Mark this server as selected
            if ($server) {
                $this->set_selected_server($server->id);
            }
        }
        
        return $server;
    }
    
    /**
     * Get server for payment processing
     * NEW METHOD: Use this when actually processing a payment to track usage
     */
    public function get_server_for_payment() {
        $server = $this->get_selected_server();
        
        if ($server) {
            // Only increment usage when actually processing a payment
            $this->increment_server_usage($server->id);
        }
        
        return $server;
    }
    
    /**
     * Set a server as the selected one
     */
    public function set_selected_server($server_id) {
        global $wpdb;
        
        // First, unselect all servers
        $wpdb->query("UPDATE {$this->table_name} SET is_selected = 0");
        
        // Then, select the specified one
        $wpdb->update(
            $this->table_name,
            array('is_selected' => 1),
            array('id' => $server_id)
        );
    }
    /**
     * AJAX handler for getting server details
     */
    public function ajax_get_server() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID'));
            wp_die();
        }
        
        $server = $this->get_server($server_id);
        
        if (!$server) {
            wp_send_json_error(array('message' => 'Server not found'));
            wp_die();
        }
        
        wp_send_json_success($server);
        wp_die();
    }
    
    /**
     * AJAX handler for setting a server as the active one
     */
    public function ajax_use_server() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID'));
            wp_die();
        }
        
        $server = $this->get_server($server_id);
        
        if (!$server) {
            wp_send_json_error(array('message' => 'Server not found'));
            wp_die();
        }
        
        // Set this server as the selected one
        $this->set_selected_server($server_id);
        
        wp_send_json_success(array('message' => 'Server set as active'));
        wp_die();
    }
    
    /**
     * AJAX handler for adding a server
     */
    public function ajax_add_server() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        parse_str($_POST['server_data'], $server_data);
        
        // Validate required fields
        if (empty($server_data['server_name']) || empty($server_data['server_url']) || 
            empty($server_data['api_key']) || empty($server_data['api_secret'])) {
            wp_send_json_error(array('message' => 'All fields are required'));
            wp_die();
        }
        
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name' => sanitize_text_field($server_data['server_name']),
                'url' => esc_url_raw($server_data['server_url']),
                'api_key' => sanitize_text_field($server_data['api_key']),
                'api_secret' => sanitize_text_field($server_data['api_secret']),
                'capacity_limit' => intval($server_data['capacity_limit']),
                'is_active' => intval($server_data['is_active']),
                'is_selected' => 0, // New servers are not selected by default
                'priority' => intval($server_data['priority']),
            )
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to add server'));
            wp_die();
        }
        
        wp_send_json_success(array('message' => 'Server added successfully'));
        wp_die();
    }
    
    /**
     * AJAX handler for updating a server
     */
    public function ajax_update_server() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID'));
            wp_die();
        }
        
        parse_str($_POST['server_data'], $server_data);
        
        // Validate required fields
        if (empty($server_data['server_name']) || empty($server_data['server_url']) || 
            empty($server_data['api_key']) || empty($server_data['api_secret'])) {
            wp_send_json_error(array('message' => 'All fields are required'));
            wp_die();
        }
        
        global $wpdb;
        
        // Check if this is the selected server
        $is_selected = $wpdb->get_var($wpdb->prepare("SELECT is_selected FROM {$this->table_name} WHERE id = %d", $server_id));
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'name' => sanitize_text_field($server_data['server_name']),
                'url' => esc_url_raw($server_data['server_url']),
                'api_key' => sanitize_text_field($server_data['api_key']),
                'api_secret' => sanitize_text_field($server_data['api_secret']),
                'capacity_limit' => intval($server_data['capacity_limit']),
                'is_active' => intval($server_data['is_active']),
                'priority' => intval($server_data['priority']),
                // Keep the is_selected value unchanged
            ),
            array('id' => $server_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to update server'));
            wp_die();
        }
        
        wp_send_json_success(array('message' => 'Server updated successfully'));
        wp_die();
    }
    
    /**
     * AJAX handler for deleting a server
     */
    public function ajax_delete_server() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID'));
            wp_die();
        }
        
        // Count total servers
        global $wpdb;
        $total_servers = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // Don't allow deleting the last server
        if ($total_servers <= 1) {
            wp_send_json_error(array('message' => 'Cannot delete the last server. At least one server must exist.'));
            wp_die();
        }
        
        // Check if this is the selected server
        $is_selected = $wpdb->get_var($wpdb->prepare("SELECT is_selected FROM {$this->table_name} WHERE id = %d", $server_id));
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $server_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete server'));
            wp_die();
        }
        
        // If we deleted the selected server, select another one
        if ($is_selected) {
            $new_server = $wpdb->get_row("SELECT id FROM {$this->table_name} WHERE is_active = 1 ORDER BY priority ASC, id ASC LIMIT 1");
            if ($new_server) {
                $this->set_selected_server($new_server->id);
            } else {
                // If no active server, select any server
                $any_server = $wpdb->get_row("SELECT id FROM {$this->table_name} ORDER BY id ASC LIMIT 1");
                if ($any_server) {
                    $this->set_selected_server($any_server->id);
                }
            }
        }
        
        wp_send_json_success(array('message' => 'Server deleted successfully'));
        wp_die();
    }
    
    /**
     * AJAX handler for resetting server usage
     */
    public function ajax_reset_server_usage() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            wp_die();
        }
        
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID'));
            wp_die();
        }
        
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array('current_usage' => 0),
            array('id' => $server_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to reset server usage'));
            wp_die();
        }
        
        wp_send_json_success(array('message' => 'Server usage reset successfully'));
        wp_die();
    }
    
    /**
     * Get next available server
     * FIXED: Only increment usage when actually using a server for payment
     */
    public function get_next_available_server() {
        // First, check if there's a manually selected server
        $selected_server = $this->get_selected_server();
        
        if ($selected_server) {
            // If there's a selected server, always use it but don't increment usage here
            return $selected_server;
        }
        
        global $wpdb;
        
        // Step 1: Try to find a server that has not reached capacity limit
        $server = $wpdb->get_row(
            "SELECT * FROM {$this->table_name} 
            WHERE is_active = 1 
            AND current_usage < capacity_limit 
            ORDER BY priority ASC, last_used ASC, id ASC 
            LIMIT 1"
        );
        
        // Step 2: If no server is available, get the one with lowest usage percentage
        if (!$server) {
            $server = $wpdb->get_row(
                "SELECT *, (current_usage / capacity_limit) as usage_ratio 
                FROM {$this->table_name} 
                WHERE is_active = 1 
                ORDER BY usage_ratio ASC, priority ASC, id ASC 
                LIMIT 1"
            );
        }
        
        // Step 3: If still no server, get any active server
        if (!$server) {
            $server = $wpdb->get_row(
                "SELECT * FROM {$this->table_name} 
                WHERE is_active = 1 
                ORDER BY priority ASC, id ASC 
                LIMIT 1"
            );
        }
        
        // Step 4: Last resort, get any server regardless of active status
        if (!$server) {
            $server = $wpdb->get_row(
                "SELECT * FROM {$this->table_name} 
                ORDER BY priority ASC, id ASC 
                LIMIT 1"
            );
        }
        
        return $server;
    }
    
    /**
     * Increment server usage
     * Kept as a separate method to be called when actually processing a payment
     */
    public function increment_server_usage($server_id) {
        global $wpdb;
        
        // Update last_used timestamp and increment usage
        $wpdb->update(
            $this->table_name,
            array(
                'last_used' => current_time('mysql'),
                'current_usage' => $wpdb->get_var(
                    $wpdb->prepare("SELECT current_usage + 1 FROM {$this->table_name} WHERE id = %d", $server_id)
                )
            ),
            array('id' => $server_id)
        );
    }
    
    /**
     * Get server by ID with usage tracking
     * FIXED: We'll only use this when explicitly tracking usage for a payment
     */
    public function get_server_with_tracking($server_id) {
        $server = $this->get_server($server_id);
        
        if ($server) {
            $this->increment_server_usage($server_id);
        }
        
        return $server;
    }
}