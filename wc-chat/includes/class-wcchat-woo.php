<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class Woo {
    public static function init() {
        // Button on product page: "Chat about this product"
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_chat_button'], 35);
    }

    public static function render_chat_button() {
        global $product;
        if (!is_user_logged_in()) return;
        $pid = $product ? $product->get_id() : 0;
        echo do_shortcode('[wc_chat product_id="'.intval($pid).'"]');
    }
}
Woo::init();