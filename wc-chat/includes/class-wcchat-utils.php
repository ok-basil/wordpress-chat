<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class Utils {
    private static function auto_join_roles_enabled() : bool {
        $options = wp_parse_args(get_option(\WCChat\Settings::OPTION_KEY, []), \WCChat\Settings::defaults());
        return !empty($options['auto_join_roles']);
    }

    /**
     * Ensure a user is a participant in a session.
     * Returns true if already present or can be auto-joined based on context.
     */
    public static function ensure_user_in_session($session_id, $user_id) {
        global $wpdb;
        $parts = $wpdb->prefix . 'wcchat_participants';

        // Already in?
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE session_id=%d AND user_id=%d", $session_id, $user_id)
        );
        if ($exists) return true;

        // Try auto-join based on product context
        $user = get_userdata($user_id);
        if (!$user) return false;

        // Product context
        $product_id = (int) get_post_meta($session_id, '_wcchat_product_id', true);
        $product_author = $product_id ? (int) get_post_field('post_author', $product_id) : 0;
        $designer_id = (int) get_post_meta($product_id, '_wcchat_designer_user_id', true);

        // Role-based auto-join (optional)
        if (self::auto_join_roles_enabled()) {
            if (in_array('agent', (array) $user->roles, true) || in_array('administrator', (array) $user->roles, true)) {
                return self::add_participant($session_id, $user_id, 'agent');
            }
            if (in_array('shop_manager', (array) $user->roles, true)) {
                return self::add_participant($session_id, $user_id, 'merchant');
            }
        }

        // Product-author auto-join as merchant
        if ($product_author && $user_id === $product_author) {
            return self::add_participant($session_id, $user_id, 'merchant');
        }

        // Optional: designer meta auto-join
        if ($designer_id && $user_id === $designer_id) {
            return self::add_participant($session_id, $user_id, 'designer');
        }

        // Otherwise not allowed to auto-join
        return false;
    }

    public static function add_participant($session_id, $user_id, $role_slug) {
        global $wpdb;
        $parts = $wpdb->prefix . 'wcchat_participants';

        // Avoid unique constraint error
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE session_id=%d AND user_id=%d", $session_id, $user_id)
        );
        if ($exists) return true;

        $wpdb->insert($parts, [
            'session_id'        => (int) $session_id,
            'user_id'           => (int) $user_id,
            'role_slug'         => sanitize_key($role_slug),
            'last_seen'         => current_time('mysql'),
        ]);
        return true;
    }

    public static function set_typing($session_id, $user_id) {
        set_transient("wcchat_typing_{$session_id}_{$user_id}", 1, 8);
    }

    public static function others_typing($session_id, $exclude_user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcchat_participants';
        $user_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $table WHERE session_id=%d", $session_id));
        $typing = [];
        foreach ($user_ids as $uid) {
            if ((int)$uid === (int)$exclude_user_id) continue;
            if (get_transient("wcchat_typing_{$session_id}_{$uid}")) $typing[] = (int)$uid;
        }
        return $typing;
    }

    public static function touch_presence($user_id) {
        set_transient("wcchat_seen_{$user_id}", current_time('timestamp'), 60);
    }

    public static function is_online($user_id) {
        $ts = (int) get_transient("wcchat_seen_{$user_id}");
        return $ts && (current_time('timestamp') - $ts) <= 60;
    }
}
