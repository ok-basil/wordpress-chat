<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class CPT {
    public static function register() {
        register_post_type('chat_session', [
            'label'             => 'Chat Sessions',
            'public'            => false,
            'show_ui'           => true,
            'supports'          => ['title'],
            'capability_type'   => 'post',
            'map_meta_cap'      => true,
            'menu_icon'         => 'dashicons-format-chat',
        ]);
    }
}
