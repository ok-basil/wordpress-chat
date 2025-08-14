<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class DB_Schema {
    public static function install() {
        global $wpdb;
        $chatset_collate = $wpdb->get_charset_collate();

        // Messages table
        $messages = $wpdb->prefix . 'wcchat_messages';
        $participants = $wpdb->prefix . 'wcchat_participants';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE $messages (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
            session_id      BIGINT UNSIGNED NOT NULL,
            sender_id       BIGINT UNSIGNED NOT NULL,
            message         LONGTEXT NULL,
            attachment_id   BIGINT UNSIGNED NULL,
            is_read         TINYINT(1) NOT NULL DEFAULT 0,
            read_at         DATETIME NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY     (id),
            KEY session_id  (session_id),
            KEY sender_id   (sender_id)
        ) $charset_collate;";
    
        $sql2 = "CREATE TABLE $participants (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id              BIGINT UNSIGNED NOT NULL,
            user_id                 BIGINT UNSIGNED NOT NULL,
            role_slug               VARCHAR(50) NOT NULL,
            last_seen               DATETIME NULL,
            last_read_message_id    BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
            UNIQUE KEY unique_participant (session_id, user_id),
            KEY session_id (session_id),
            KEY user_id (user_id)
        ) $charset_collate;";
   
        dbDelta($sql1);
        dbDelta($sql2);
    } 
}
