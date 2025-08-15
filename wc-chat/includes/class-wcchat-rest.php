<?php
namespace WCChat;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class REST {
    public static function register_routes() {
        register_rest_route('wcchat/v1', '/sessions', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'create_session'],
                'permission_callback'   => function() { return is_user_logged_in(); }
            ],
        ]);

        register_rest_route('wcchat/v1', '/messages', [
            [
                'methods'               => 'GET',
                'callback'              => [__CLASS__, 'get_messages'],
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'send_message'],
                'permission_callback'   => [__CLASS__, 'can_send'],
            ],
        ]);

        register_rest_route('wcchat/v1', '/messages/read', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'mark_read'],
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
        ]);
    
        register_rest_route('wcchat/v1', '/typing', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'typing'],
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
        ]);

        register_rest_route('wcchat/v1', '/presence', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'presence_ping'],
                'permission_callback'   => function() { return is_user_logged_in(); },
            ],
            [
                'methods'               => 'GET',
                'callback'              => [__CLASS__, 'presence_lookup'],
                'permission_callback'   => function() { return current_user_can('wcchat_view'); },
            ],
        ]);

        register_rest_route('wcchat/v1', '/upload', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'upload_file'],
                'permission_callback'   => [__CLASS__, 'can_send'],
            ],
        ]);
    }

    public static function can_view(WP_REST_Request $req) {
        $session_id = (int) ($req['session_id'] ?? $req->get_param('session_id'));
        return is_user_logged_in() && Utils::ensure_user_in_session($session_id, get_current_user_id());
    }

    public static function can_send(WP_REST_Request $req) {
        return self::can_view($req) && current_user_can('wcchat_send');
    }

    public static function create_session(WP_REST_Request $req) {
        $product_id = (int) $req->get_param('product_id');
        $title = 'Chat: ' . ($product_id ? get_the_title($product_id) : 'General');
        $session_id = wp_insert_post([
            'post_type'     => 'chat_session',
            'post_title'    => $title,
            'post_status'   => 'publish',
        ]);

        if (is_wp_error($session_id)) return $session_id;

        // Participants: current user and couterpart
        global $wpdb;
        $table = $wpdb->prefix . 'wcchat_participants';
        $wpdb->insert($table, [
            'session_id'        => $session_id,
            'user_id'           => get_current_user_id(),
            'role_slug'         => self::current_role_slug(),
            'last_seen'         => current_time('mysql'),
        ]);

        if ($product_id) {
            update_post_meta($session_id, '_wcchat_product_id', $product_id);
        }

        return new WP_REST_Response(['session_id' => $session_id], 201);
    }

    private static function current_role_slug() {
        $user = wp_get_current_user();
        $map = ['administrator'=>'agent', 'shop_manager'=>'merchant', 'agent'=>'agent', 'designer'=>'designer', 'customer'=>'buyer'];
        foreach ($user->roles as $role) {
          if (isset($map[$role])) return $map[$role];
        }
        return 'buyer';
    }

    public static function get_messages(WP_REST_Request $req) {
        global $wpdb;
        $messages = $wpdb->prefix . 'wcchat_messages';
        $session_id = (int) $req->get_param('session_id');
        $after_id = (int) $req->get_param('after_id');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $messages WHERE session_id=%d AND id > %d ORDER BY id ASC LIMIT 200", $session_id, $after_id
        ), ARRAY_A);

        return $rows ?: [];
    }

    public static function send_message(WP_REST_Request $req) {
        global $wpdb;
        $messages = $wpdb->prefix . 'wcchat_messages';
        $session_id = (int) $req->get_param('session_id');
        $text = wp_kses_post($req->get_param('message'));
        $attachment_id = (int) $req->get_param('attachment_id');

        if (!$text && !$attachment_id) {
            return new WP_Error('wcchat_empty', 'Message or attachment required', ['status' => 400]);
        }

        $wpdb->insert($messages, [
            'session_id'        => $session_id,
            'sender_id'         => get_current_user_id(),
            'message'           => $text ?: null,
            'attachment_id'     => $attachment_id ?: null,
            'is_read'           => 0,
            'created_at'        => current_time('mysql'),
        ]);

        $id = (int) $wpdb->insert_id;

        // Notify other participants
        do_action('wcchat_new_message', $session_id, $id);

        return ['id' => $id];
    }

    public static function mark_read(WP_REST_Request $req) {
        global $wpdb;
        $messages = $wpdb->prefix . 'wcchat_messages';
        $session_id = (int) $req->get_param('session_id');

        // Mark other people's messages as read
        $wpdb->query($wpdb->prepare(
            "UPDATE $messages SET is_read=1, read_at=%s WHERE session_id=%d AND sender_id<>%d AND is_read=0",
            current_time('mysql'), $session_id, get_current_user_id()
        ));

        // Update participant last_read_message_id
        $last_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM $messages WHERE session_id=%d", $session_id
        ));

        $parts = $wpdb->prefix . 'wcchat_participants';
        $wpdb->update($parts,
            ['last_read_message_id' => $last_id, 'last_seen' => current_time('mysql')],
            ['session_id' => $session_id, 'user_id' => get_current_user_id()]
        );

        return ['ok' => true, 'last_read_id' => $last_id];
    }

    public static function typing(WP_REST_Request $req) {
        $session_id = (int) $req->get_param('session_id');
        Utils::set_typing($session_id, get_current_user_id());
        return ['others_typing' => Utils::others_typing($session_id, get_current_user_id())];
    }

    public static function presence_ping(WP_REST_Request $req) {
        Utils::touch_presence(get_current_user_id());
        return ['online' => true];
    }

    public static function presence_lookup(WP_REST_Request $req) {
        $user_ids = array_map('intval', (array) $req->get_param('user_ids'));
        $out = [];
        foreach ($user_ids as $uid) {
            $out[$uid] = Utils::is_online($uid);
        }

        return $out;
    }

    public static function upload_file(WP_REST_Request $req) {
        if (empty($_FILES['file'])) {
            return new WP_Error('wcchat_no_file', 'No file uploaded', ['status' => 400]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form' => false, 'mimes' => null];
        $file = wp_handle_upload($_FILES['file'], $overrides);
        if (isset($file['error'])) {
            return new WP_Error('wcchat_upload_error', $file['error'], ['status' => 400]);
        }
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $file['type'],
            'post_title' => sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file['file']);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
    }
}

add_action('rest_api_init', [REST::class, 'register_routes']);

// Email notification
add_action('wcchat_new_message', function($session_id, $message_id) {
    // Find other participants & email them
    global $wpdb;
    $parts = $wpdb->prefix . 'wcchat_participants';
    $others = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $parts WHERE session_id=%d AND user_id<>%d", $session_id, get_current_user_id()));
    foreach ($others as $uid) {
        $user = get_userdata($uid);
        if ($user && $user->user_email) {
            wp_mail($user->user_email, 'New chat message', 'You have a new chat message.');
        }
    }
}, 10, 2);
