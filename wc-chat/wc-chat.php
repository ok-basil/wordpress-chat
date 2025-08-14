<?php
/**
* Plugin Name: WC Chat
* Description: A user-friendly chat system that can be integrated into an eCommerce platform.
* Author: Basil Okache 
* Version: 1.0.0
*/ 

if (!defined('ABSPATH')) exit;

define('WCCHAT_VERSION', '1.0.0');
define('WCCHAT_DIR', plugin_dir_path(__FILE__));
define('WCCHAT_URL', plugin_dir_url(__FILE__));

require_once WCCHAT_DIR . 'includes/db-schema.php';
require_once WCCHAT_DIR . 'includes/class-wcchat-roles.php';
require_once WCCHAT_DIR . 'includes/class-wcchat-utils.php';
require_once WCCHAT_DIR . 'includes/class-wcchat-cpt.php';
require_once WCCHAT_DIR . 'includes/class-wcchat-rest.php';
require_once WCCHAT_DIR . 'includes/class-wcchat-woo.php';

register_activation_hook(__FILE__, function() {
    \WCChat\DB_Schema::install();
    \WCChat\Roles::install();
});

add_action('init', function() {
    \WCChat\CPT::register();
});

add_action('wp_enqueue_scripts', function() {
    wp_register_style('wcchat', WCCHAT_URL . 'assets/chat.css', [], WCCHAT_VERSION);
    wp_register_script('ecchat', WCCHAT_URL . 'assets/chat.js', [], WCCHAT_VERSION, true);

    // Expose REST details & nonces to JS
    wp_localize_script('wcchat', 'WCCHAT', [
        'rest'          => esc_url_raw(rest_url('wcchat/v1/')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'user_id'       => get_current_user_id(),
        'is_logged'     => is_user_logged_in(),
        'site_name'     => get_bloginfo('name'),
    ]);
});

// Shortcode
add_shortcode('wc_chat', function ($atts) {
     $atts = shortcode_atts([
        'product_id' => '',
        'session_id' => '',
     ], $atts);
    
    wp_enqueue_style('wcchat');
    wp_enqueue_script('wcchat');

    ob_start(); ?>
    <div id="wcchat-root"
        data-product-id="<?php echo esc_attr($atts['product_id']); ?>"
        data-session-id="<?php echo esc_attr($atts['session_id']); ?>"
    >
        <div class="wcchat-widget">
            <div class="wcchat-header">
                <div class="wcchat-title">Chat</div>
                <button class="wcchat-theme-toggle" aria-label="Toggle theme"></button>
            </div>
            <div class="wcchat-message" id="wcchat-messages" aria-live="polite"></div>
            <div class="wcchat-typing" id="wcchat-typing" hidden></div>
            <form class="wcchat-input" id="wcchat-form">
                <input type="text" id="wcchat-text" placehold="Type a message..." aria-autocomplete="off" required />
                <input type="file" id="wcchat-file" hidden />
                <button type="button" id="wcchat-attach" title="Attach file">📎</button>
                <button type="submit" id="wcchat-send">Send</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
});
