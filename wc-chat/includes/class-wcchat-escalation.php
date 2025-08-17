<?php

use WCChat\Utils;

add_action('wcchat_new_message', function($session_id, $message_id) {
    // Only escalate on buyer messages
    global $wpdb;
    $parts = $wpdb->prefix . 'wcchat_participants';
    $sender_id = get_current_user_id();
    $sender_role = $wpdb->get_var($wpdb->prepare(
        "SELECT role_slug FROM $parts WHERE session_id=%d AND user_id=%d", $session_id, $sender_id
    ));
    if ($sender_role !== 'buyer') return;

    // Who's in this chat?
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id FROM $parts WHERE session_id=%d", $session_id
    ), ARRAY_A);

    // Is any merchant/agent online?
    $any_online = false;
    $has_agent = false;
    foreach ($rows as $row) {
        if (in_array($row['role_slug'], ['merchant', 'agent'], true)) {
            if (Utils::is_online((int) $row['user_id'])) $any_online = true;
            if ($row['role_slug'] === 'agent') $has_agent = true;
        }
    }
    if ($any_online) return; // Someone can reply

    // Assign an agent if none
    $agent_ids = get_users(['role' => 'agent', 'fields' => 'ID']);
    if (empty($agent_ids)) return;

    // Round-robin pick
    $idx = (int) get_option('wcchat_agent_rr', 0);
    $agent_id = (int) $agent_ids[$idx % count($agent_ids)];
    update_option('wcchat_agent_rr', $idx + 1);

    // If this agent isn't already in the chat, add them
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $parts WHERE session_id=%d AND user_id=%d", $session_id, $agent_id
    ));

    if (!$exists) {
        Utils::add_participant($session_id, $agent_id, 'agent');
        // Nudge the agent to reply
        $user = get_userdata($agent_id);
        if ($user && $user->user_email) {
            wp_mail($user->user_email, 'New chat needs attention', 'A customer is waiting in chat');
        }
    }
}, 10, 2);
