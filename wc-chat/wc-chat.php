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


$includes = [
    'includes/class-wcchat-roles.php',
    'includes/class-wcchat-utils.php',
    'includes/class-wcchat-cpt.php',
    'includes/class-wcchat-rest.php',
    'includes/class-wcchat-woo.php',
    'includes/class-wcchat-settings.php',
    'includes/class-wcchat-escalation.php',
    'includes/class-wcchat-email.php',
    'includes/db-schema.php',
];

foreach ($includes as $include) {
    $file = WCCHAT_DIR . $include;
    if (file_exists($file)) {
        require_once $file;
    }
}

// Activation hook
register_activation_hook(__FILE__, function() {
    if (class_exists('\WCChat\DB_Schema')) {
        \WCChat\DB_Schema::install();
    }
    if (class_exists('\WCChat\Roles')) {
        \WCChat\Roles::install();
    }
    if (!get_option(\WCChat\Settings::OPTION_KEY)) {
        add_option(\WCChat\Settings::OPTION_KEY, \WCChat\Settings::defaults());
    }
});

// Initialize plugin
add_action('init', function() {
   if (class_exists('\WCChat\CPT')) {
       \WCChat\CPT::register();
   }

   if (class_exists('\WCChat\Settings')) {
       \WCChat\Settings::init();
   }
});

add_action('rest_api_init', function() {
    \WCChat\REST::register_routes();
});


// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function() {
    wp_register_style('wcchat', WCCHAT_URL . 'assets/chat.css', [], WCCHAT_VERSION);
    wp_register_script('wcchat', WCCHAT_URL . 'assets/chat.js', [], WCCHAT_VERSION, true);

    // Always localize when registered
    wp_localize_script('wcchat', 'WCCHAT', [
        'rest'          => esc_url_raw(rest_url('wcchat/v1/')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'user_id'       => get_current_user_id(),
        'is_logged'     => is_user_logged_in(),
        'site_name'     => get_bloginfo('name'),
        'max_file_size' => apply_filters('wcchat_max_file_size', 5 * MB_IN_BYTES),
    ]);
});

// Mail notification
add_action('phpmailer_init', function($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host        = 'smtp.gmail.com';
    $phpmailer->Port        = 587;
    $phpmailer->SMTPAuth    = TRUE;
    $phpmailer->SMTPSecure  = 'tls';
    $phpmailer->Username    = 'okachebasil@gmail.com';
    $phpmailer->Password    = 'etpg kwog fncs opbj';
    $phpmailer->From        = 'okachebasil@gmail.com';
    $phpmailer->FromName    = get_bloginfo('name');
});

// Shortcode
add_shortcode('wc_chat', function ($atts) {
     $atts = shortcode_atts([
        'product_id' => '',
        'session_id' => '',
     ], $atts);

    wp_enqueue_style('wcchat');
    wp_enqueue_script('wcchat');

    ob_start();
    ?>
    <div id="wcchat-root"
        data-product-id="<?php echo esc_attr($atts['product_id']); ?>"
        data-session-id="<?php echo esc_attr($atts['session_id']); ?>"
    >
        <div class="wcchat-widget">
            <div class="wcchat-header">
                <div class="wcchat-title">Chat</div>
                <div class="wcchat-presence" id="wcchat-presence" hidden>
                    <span class="dot" aria-hidden="true"></span>
                    <span class="label">Offline</span>
                </div>
                <button class="wcchat-theme-toggle" aria-label="Toggle theme"></button>
            </div>
            <div class="wcchat-messages" id="wcchat-messages" aria-live="polite" tabindex="0"></div>
            <div class="wcchat-typing" id="wcchat-typing" hidden></div>
            <form class="wcchat-input" id="wcchat-form">
                <input type="text" id="wcchat-text" placeholder="Type a message..." aria-autocomplete="both" required />
                <input type="file" id="wcchat-file" accept="image/*,.pdf,.doc,.docx,.txt" hidden />
                <button type="button" id="wcchat-attach" title="Attach file">📎</button>
                <button type="submit" id="wcchat-send">Send</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
});
