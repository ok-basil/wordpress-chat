<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class Roles {
    public static function install() {
        // Ensure that the roles exist. WooCommerce adds 'customer'
        add_role('designer', 'Designer', ['read' => true]);
        add_role('agent', 'Support Agent', ['read' => true]);

        // Capabilites
        $caps = ['wcchat_view', 'wcchat_send', 'wcchat_manage'];
        foreach (['administrator', 'shop_manager', 'agent', 'designer', 'customer'] as $role_name) {
            if ($role = get_role($role_name)) {
                $role->add_cap('wcchat_view');
                $role->add_cap('wcchat_send');
                if (in_array($role_name, ['administrator', 'shop_manager', 'agent'])) {
                    $role->add_cap('wcchat_manage');
                }
            }
        }
    }
}
