<?php
add_action( 'wcchat_new_message', 'wcchat_notify_participants', 10, 2 );

/**
 * Email everyone in the session except the sender.
 * Includes a 60s per-participant cooldown to avoid spam while someone is typing
 */
function wcchat_notify_participants($session_id, $message_id) {
    global $wpdb;

    $messages = $wpdb->prefix . "wcchat_messages";
    $parts    = $wpdb->prefix . "wcchat_participants";

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $messages WHERE id=%d AND session_id=%d",
        $message_id, $session_id
    ));

    if (!$row) return;

    $sender_id = (int) $row->sender_id;

    // Build context (product + title)
    $product_id     = (int) get_post_meta($session_id, '_wcchat_product_id', true);
    $product_title  = $product_id ? get_the_title($product_id) : 'General';
    $subject        = apply_filters(
        'wcchat_email_subject',
        sprintf('[%s] New chat message: %s', get_bloginfo('name'), $product_title),
        $session_id, $message_id
    );

    // Link to where they'd reply
    $chat_link  = $product_id ? get_permalink($product_id) : home_url('/');
    $sender     = get_userdata($sender_id);
    $preview    = wp_trim_words(wp_strip_all_tags((string)$row->message), 25, '...');

    $html = sprintf(
        '<p>You have a message from <strong>%s</strong> in <em>%s</em>.</p>
        %s
        <p><a href="%s" target="_blank">Open chat</a></p>
        <hr><small>This is an automated message from %s.</small>',
        esc_html($sender ? $sender->display_name : 'User #'.$sender_id),
        esc_html($product_title),
        $row->message ? '<blockquote style="margin:0;padding:0.5rem 1rem;border-left:3px solid #ddd;">'.wp_kses_post($row->message).'</blockquote>' : '',
        esc_url($chat_link),
        esc_html(get_bloginfo('name'))
    );

    $html = apply_filters('wcchat_email_body', $html, $session_id, $message_id);

    // Get recipients
    $recipients = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id FROM $parts WHERE session_id=%d AND user_id<>%d",
        $session_id, $sender_id
    ));
    if (!$recipients) return;

    // Send individually
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    foreach ($recipients as $recipient) {
        $uid = (int) $recipient->user_id;

        // Per recipient cooldown (60 seconds)
        $cool_key = "wcchat_notif_{$session_id}_{$uid}";
        if (get_transient($cool_key)) continue;
        set_transient($cool_key, 1, 60);

        $user = get_userdata($uid);
        if (!$user || !is_email($user->user_email)) continue;

        wp_mail($user->user_email, $subject, $html, $headers);
    }
}