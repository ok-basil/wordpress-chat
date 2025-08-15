<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class Utils {
    public static function ensure_user_in_session($session_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcchat_participants';
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE session_id=%d AND user_id=%d", $session_id, $user_id)
        );
        if ($exists) return true;

        // Allow auto-join creator or product owner when session is first created via REST
        return false;
    }

    public static function set_typing($session_id, $user_id) {
        // Transient expires quickly; used for typing indicator
        set_transient("wcchat_typing_{session_id}_{$user_id}", 1, 8);
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
