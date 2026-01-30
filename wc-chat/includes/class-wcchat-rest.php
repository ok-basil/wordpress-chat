<?php
namespace WCChat;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class REST {
    /**
     * Register REST endpoints for chat sessions.
     */
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
                'callback'              => [__CLASS__, 'typing_set'],
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
            [
                'methods'               => 'GET',
                'callback'              => [__CLASS__, 'typing_peek'],
                'permission_callback'   => function() { return is_user_logged_in(); },
            ]
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
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
        ]);

        register_rest_route('wcchat/v1', '/upload', [
            [
                'methods'               => 'POST',
                'callback'              => [__CLASS__, 'upload_file'],
                'permission_callback'   => [__CLASS__, 'can_send'],
            ],
        ]);

        register_rest_route('wcchat/v1', '/participants', [
            [
                'methods'               => 'GET',
                'callback'              => [__CLASS__, 'participants'],
                'permission_callback'   => [__CLASS__, 'can_view'],
            ],
        ]);

        register_rest_route('wcchat/v1', '/sessions/claim', [
            'methods'                   => 'POST',
            'callback'                  => [__CLASS__, 'claim_session'],
            'permission_callback'       => [__CLASS__, 'can_view'],
        ]);
    }

    public static function can_view(WP_REST_Request $req) {
        $session_id = (int) ($req['session_id'] ?? $req->get_param('session_id'));
        if (!is_user_logged_in()) {
            return false;
        }

        if (current_user_can('wcchat_manage')) {
            return true;
        }

        return Utils::ensure_user_in_session($session_id, get_current_user_id());
    }

    public static function can_send(WP_REST_Request $req) {
        return self::can_view($req) && current_user_can('wcchat_send');
    }

    /**
     * Restrict upload types for chat attachments.
     */
    private static function allowed_upload_mimes() : array {
        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        return apply_filters('wcchat_allowed_upload_mimes', $mimes);
    }

    public static function create_session(\WP_REST_Request $req) {
        $opts = function_exists('\WCChat\Settings::defaults')
            ? wp_parse_args(get_option(\WCChat\Settings::OPTION_KEY, []), \WCChat\Settings::defaults())
            : ['assign_agent_mode'=>'round_robin','auto_assign_merchant'=>1,'auto_assign_designer'=>0,'designer_meta_key'=>'_wcchat_designer_user_id'];

        $product_id = (int) $req->get_param('product_id');
        $current_id = get_current_user_id();

        global $wpdb;

        // Find the existing session for this user on this product
        if ($product_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = '_wcchat_product_id' AND pm.meta_value = %d
             INNER JOIN {$wpdb->prefix}wcchat_participants part
                     ON part.session_id = p.ID AND part.user_id = %d
             WHERE p.post_type = 'chat_session' AND p.post_status = 'publish'
             ORDER BY p.ID DESC
             LIMIT 1",
                $product_id, $current_id
            ));
            if ($existing) {
                return new \WP_REST_Response(['session_id' => (int) $existing], 200);
            }
        }

        // Create a new session
        $title = 'Chat: ' . ($product_id ? get_the_title($product_id) : 'General');
        $session_id = wp_insert_post([
            'post_type'   => 'chat_session',
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);
        if (is_wp_error($session_id)) return $session_id;

        if ($product_id) {
            update_post_meta($session_id, '_wcchat_product_id', $product_id);
        }

        // Add current user
        \WCChat\Utils::add_participant($session_id, $current_id, self::current_role_slug());

        // Auto-assign merchant (product author)
        if ($product_id && !empty($opts['auto_assign_merchant'])) {
            $author_id = (int) get_post_field('post_author', $product_id);
            if ($author_id && $author_id !== $current_id) {
                \WCChat\Utils::add_participant($session_id, $author_id, 'merchant');
            }
        }

        // Auto-assign designer (if configured)
        if (!empty($opts['auto_assign_designer'])) {
            $meta_key = is_string($opts['designer_meta_key']) ? $opts['designer_meta_key'] : '_wcchat_designer_user_id';
            $designer_id = $product_id ? (int) get_post_meta($product_id, $meta_key, true) : 0;
            if ($designer_id && $designer_id !== $current_id) {
                \WCChat\Utils::add_participant($session_id, $designer_id, 'designer');
            }
        }

        // Agent assignment (round-robin/random/none)
        if (($opts['assign_agent_mode'] ?? 'round_robin') !== 'none') {
            $agent_ids = get_users(['role' => 'agent', 'fields' => 'ID']);
            if (!empty($agent_ids)) {
                $agent_id = 0;
                if ($opts['assign_agent_mode'] === 'random') {
                    shuffle($agent_ids);
                    $agent_id = (int) $agent_ids[0];
                } else {
                    $idx = (int) get_option('wcchat_agent_rr', 0);
                    $agent_id = (int) $agent_ids[$idx % count($agent_ids)];
                    update_option('wcchat_agent_rr', $idx + 1);
                }
                if ($agent_id && $agent_id !== $current_id) {
                    \WCChat\Utils::add_participant($session_id, $agent_id, 'agent');
                }
            }
        }

        return new \WP_REST_Response(['session_id' => (int) $session_id], 201);
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

        if ($rows) {
            $attachment_ids = [];
            foreach ($rows as $row) {
                $att_id = (int) ($row['attachment_id'] ?? 0);
                if ($att_id) {
                    $attachment_ids[] = $att_id;
                }
            }

            $attachments = [];
            $attachment_ids = array_values(array_unique($attachment_ids));
            foreach ($attachment_ids as $att_id) {
                $mime = get_post_mime_type($att_id);
                $attachments[$att_id] = [
                    'url' => wp_get_attachment_url($att_id) ?: '',
                    'name' => basename(get_attached_file($att_id)),
                    'mime' => $mime,
                    'thumb' => '',
                ];

                if ($mime && str_starts_with((string) $mime, 'image/')) {
                    $thumb = image_downsize($att_id, 'thumbnail');
                    if ($thumb && is_array($thumb)) {
                        $attachments[$att_id]['thumb'] = $thumb[0];
                    }
                }
            }

            foreach ($rows as &$row) {
                $att_id = (int) ($row['attachment_id'] ?? 0);
                if ($att_id && isset($attachments[$att_id])) {
                    $row['attachment_url'] = $attachments[$att_id]['url'];
                    $row['attachment_name'] = $attachments[$att_id]['name'];
                    $row['attachment_mime'] = $attachments[$att_id]['mime'];
                    if ($attachments[$att_id]['thumb']) {
                        $row['attachment_thumb'] = $attachments[$att_id]['thumb'];
                    }
                }
            }
            unset($row);
        }

        return $rows ?: [];
    }

    public static function send_message(WP_REST_Request $req) {
        global $wpdb;
        $messages = $wpdb->prefix . 'wcchat_messages';
        $session_id = (int) $req->get_param('session_id');
        $text = wp_kses_post($req->get_param('message'));
        $attachment_id = (int) $req->get_param('attachment_id');

        if ($attachment_id) {
            $attachment = get_post($attachment_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return new WP_Error('wcchat_attachment_invalid', 'Attachment not found', ['status' => 404]);
            }

            $attachment_session_id = (int) get_post_meta($attachment_id, '_wcchat_session_id', true);
            if (!$attachment_session_id || $attachment_session_id !== $session_id) {
                return new WP_Error('wcchat_attachment_mismatch', 'Attachment does not belong to this session', ['status' => 403]);
            }

            $uploader_id = (int) get_post_meta($attachment_id, '_wcchat_uploader_id', true);
            if ($uploader_id && $uploader_id !== get_current_user_id() && !current_user_can('wcchat_manage')) {
                return new WP_Error('wcchat_attachment_forbidden', 'Attachment not owned by user', ['status' => 403]);
            }
        }

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

    public static function typing_set(WP_REST_Request $req) {
        $session_id = (int) $req->get_param('session_id');
        Utils::set_typing($session_id, get_current_user_id());
        return ['ok' => true];
    }

    public static function typing_peek(\WP_REST_Request $req) {
        $session_id = (int) $req->get_param('session_id');
        return ['others_typing' => Utils::others_typing($session_id, get_current_user_id())];
    }

    public static function presence_ping(WP_REST_Request $req) {
        Utils::touch_presence(get_current_user_id());
        return ['online' => true];
    }

    public static function presence_lookup(WP_REST_Request $req) {
        global $wpdb;
        $session_id = (int) $req->get_param('session_id');
        if (!$session_id) {
            return new WP_Error('wcchat_missing_session', 'Session required', ['status' => 400]);
        }

        $requested_ids = array_map('intval', (array) $req->get_param('user_ids'));
        $parts = $wpdb->prefix . 'wcchat_participants';
        $participant_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $parts WHERE session_id=%d",
            $session_id
        ));

        $lookup_ids = $participant_ids;
        if (!empty($requested_ids)) {
            $lookup_ids = array_values(array_intersect($participant_ids, $requested_ids));
        }

        $out = [];
        foreach ($lookup_ids as $uid) {
            $out[(int) $uid] = Utils::is_online((int) $uid);
        }

        return $out;
    }

    public static function upload_file(WP_REST_Request $req) {
        if (empty($_FILES['file'])) {
            return new WP_Error('wcchat_no_file', 'No file uploaded', ['status' => 400]);
        }

        $session_id = (int) $req->get_param('session_id');
        if (!$session_id) {
            return new WP_Error('wcchat_missing_session', 'Session required', ['status' => 400]);
        }

        // Get file size limit from settings
        $settings = wp_parse_args(get_option(\WCChat\Settings::OPTION_KEY, []), \WCChat\Settings::defaults());
        $max_file_size_mb = (int) ($settings['max_file_size'] ?? 5);
        $max_file_size = apply_filters('wcchat_max_file_size', $max_file_size_mb * MB_IN_BYTES);

        // Check file size
        $file_size = $_FILES['file']['size'];
        if ($file_size > $max_file_size) {
            return new WP_Error(
                'wcchat_file_too_large',
                sprintf(__('File is too large. Maximum size is %s.', 'wc-chat'), size_format($max_file_size,)),
                ['status' => 413]
            );
        }

        // Check for upload errors
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('File exceeds upload_max_filesize', 'wc-chat'),
                UPLOAD_ERR_FORM_SIZE => __('File exceeds MAX_FILE_SIZE', 'wc-chat'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded', 'wc-chat'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded', 'wc-chat'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder', 'wc-chat'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk', 'wc-chat'),
                UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload', 'wc-chat'),
            ];

            $error_message = $error_messages[$_FILES['file']['error']] ?? __('Unknown upload error', 'wc-chat');
            return new WP_Error('wcchat_upload_error', $error_message, ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form' => false, 'mimes' => self::allowed_upload_mimes()];

        $file = wp_handle_upload($_FILES['file'], $overrides);
        if (isset($file['error'])) {
            return new WP_Error('wcchat_upload_error', $file['error'], ['status' => 400]);
        }
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $file['type'],
            'post_title' => sanitize_file_name($_FILES['file']['name']),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file['file']);

        update_post_meta($attachment_id, '_wcchat_session_id', $session_id);
        update_post_meta($attachment_id, '_wcchat_uploader_id', get_current_user_id());

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
    }

    public static function participants(\WP_REST_Request $req) {
        global $wpdb;
        $session_id = (int) $req->get_param('session_id');
        $parts = $wpdb->prefix . 'wcchat_participants';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, role_slug FROM $parts WHERE session_id=%d", $session_id), ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        $user_ids = array_map('intval', array_column($rows, 'user_id'));
        $users = get_users(['include' => $user_ids]);
        $user_map = [];
        foreach ($users as $user) {
            $user_map[(int) $user->ID] = $user;
        }

        $out = [];
        foreach ($rows as $row) {
            $user_id = (int) $row['user_id'];
            $u = $user_map[$user_id] ?? null;
            if (!$u) {
                continue;
            }
            $out[] = [
                'user_id'       => $user_id,
                'role_slug'     => $row['role_slug'],
                'display_name'  => $u->display_name,
                'avatar'        => get_avatar_url($u->ID, ['size' => 48]),
            ];
        }
        return $out;
    }

    public static function claim_session(WP_REST_Request $req) {
        $session_id = (int) $req->get_param('session_id');
        $uid = get_current_user_id();

        // Only participants can claim; managers can override
        if (!\WCChat\Utils::ensure_user_in_session($session_id, $uid) && !current_user_can('wcchat_manage')) {
            return new WP_Error('wcchat_forbidden', 'Not allowed', ['status' => 403]);
        }

        // Set owner
        $owner = (int) get_post_meta($session_id, '_wcchat_owner', true);
        if ($owner && $owner !== $uid && !current_user_can('wcchat_manage')) {
            return new \WP_Error('wcchat_claimed', 'Already claimed', ['status' => 409, 'owner'=>$owner]);
        }
        update_post_meta($session_id, '_wcchat_owner', $uid);
        return ['owner' => $uid];
    }
}
