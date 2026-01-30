<?php
namespace WCChat;

if (!defined('ABSPATH')) exit;

class Settings {
    const OPTION_KEY = 'wcchat_options';

    public static function init() {
        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'add_menu']);
            add_action('admin_init', [__CLASS__, 'register']);
        }
    }

    public static function defaults() : array {
        return [
            'assign_agent_mode'     => 'round_robin', // round_robin, random, none
            'auto_assign_merchant'  => 1,
            'auto_assign_designer'  => 0,
            'designer_meta_key'     => '_wcchat_designer_user_id',
            'max_file_size'         => 5, // 5MB default
            'auto_join_roles'       => 0,
        ];
    }

    public static function add_menu() {
        add_options_page(
            'WC Chat Settings',
            'WC Chat',
            'manage_options',
            'wcchat-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function register() {
        register_setting(
            'wcchat_options_group',
            self::OPTION_KEY,
            ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize']]
        );

        add_settings_section(
            'wcchat_assign_section',
            'Assignment Rules',
            function() {
                echo '<p>Control who gets auto-added to new chat sessions.</p>';
            },
            'wcchat-settings'
        );

        add_settings_field(
            'assign_agent_mode',
            'Agent assignment',
            [__CLASS__, 'field_assign_agent_mode'],
            'wcchat-settings',
            'wcchat_assign_section'
        );

        add_settings_field(
            'auto_assign_merchant',
            'Auto-assign merchant (product author)',
            [__CLASS__, 'field_auto_assign_merchant'],
            'wcchat-settings',
            'wcchat_assign_section'
        );

        add_settings_field(
            'auto_assign_designer',
            'Auto-assign designer (product designer)',
            [__CLASS__, 'field_auto_assign_designer'],
            'wcchat-settings',
            'wcchat_assign_section'
        );

        add_settings_field(
            'designer_meta_key',
            'Designer meta key on product',
            [__CLASS__, 'field_designer_meta_key'],
            'wcchat-settings',
            'wcchat_assign_section'
        );

        add_settings_field(
            'max_file_size',
            'Max upload size (MB)',
            [__CLASS__, 'field_max_file_size'],
            'wcchat-settings',
            'wcchat_assign_section'
        );

        add_settings_field(
            'auto_join_roles',
            'Auto-join agents/admins',
            [__CLASS__, 'field_auto_join_roles'],
            'wcchat-settings',
            'wcchat_assign_section'
        );
    }

    public static function sanitize($input) : array {
        $defaults = self::defaults();
        $out = is_array($input) ? $input : [];

        // assign_agent_mode
        $allowed = ['round_robin', 'random', 'none'];
        $mode = isset($out['assign_agent_mode']) ? sanitize_key($out['assign_agent_mode']) : $defaults['assign_agent_mode'];
        if (!in_array($mode, $allowed, true)) $mode = $defaults['assign_agent_mode'];

        // checkboxes
        $merchant = !empty($out['auto_assign_merchant']) ? 1 : 0;
        $designer = !empty($out['auto_assign_designer']) ? 1 : 0;

        // meta-key
        $meta = isset($out['designer_meta_key']) ? sanitize_key($out['designer_meta_key']) : $defaults['designer_meta_key'];
        if ($meta === '') $meta = $defaults['designer_meta_key'];

        $max_file_size = isset($out['max_file_size']) ? absint($out['max_file_size']) : $defaults['max_file_size'];
        if ($max_file_size < 1) $max_file_size = $defaults['max_file_size'];

        $auto_join_roles = !empty($out['auto_join_roles']) ? 1 : 0;

        return [
            'assign_agent_mode'     => $mode,
            'auto_assign_merchant'  => $merchant,
            'auto_assign_designer'  => $designer,
            'designer_meta_key'     => $meta,
            'max_file_size'         => $max_file_size,
            'auto_join_roles'       => $auto_join_roles,
        ];
    }

    private static function get_option($key) {
        $opts = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());
        return $opts[$key] ?? self::defaults()[$key];
    }


    // Field renderers
    public static function field_assign_agent_mode() {
        $val = self::get_option('assign_agent_mode');
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[assign_agent_mode]">
            <option value="round_robin" <?php selected($val, 'round_robin'); ?>>Round-robin</option>
            <option value="random" <?php selected($val, 'random'); ?>>Random</option>
            <option value="none" <?php selected($val, 'none'); ?>>None</option>
        </select>
        <p class="description">How to pick an agent for each new session.</p>
        <?php
    }

    public static function field_auto_assign_merchant() {
        $val = (int) self::get_option('auto_assign_merchant');
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_assign_merchant]" value="1" <?php checked($val, 1); ?>>
            Add the product author as the <em>merchant</em> when a product context exists.
        </label>
        <?php
    }

    public static function field_auto_assign_designer() {
        $val = (int) self::get_option('auto_assign_designer');
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_assign_designer]" value="1" <?php checked($val, 1); ?>>
            Add the product designer as the <em>designer</em> when a product context exists.
        </label>
        <?php
    }

    public static function field_designer_meta_key() {
        $val = esc_attr(self::get_option('designer_meta_key'));
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[designer_meta_key]" value="<?php echo $val; ?>" class="regular-text" />
        <p class="description">Meta key on the product that stores the <code>user_id</code> of the designer.</p>
        <?php
    }

    public static function field_max_file_size() {
        $val = (int) self::get_option('max_file_size');
        ?>
        <input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_file_size]" value="<?php echo esc_attr($val); ?>" class="small-text" />
        <p class="description">Maximum upload size in megabytes for chat attachments.</p>
        <?php
    }

    public static function field_auto_join_roles() {
        $val = (int) self::get_option('auto_join_roles');
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_join_roles]" value="1" <?php checked($val, 1); ?>>
            Allow agents and administrators to auto-join sessions they access.
        </label>
        <?php
    }

    public static function render_page() {
        ?>
        <div class="wrap">
            <h1>WC Chat Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wcchat_options_group');
                do_settings_sections('wcchat-settings');
                submit_button('Save Changes');
                ?>
            </form>
        </div>
        <?php
    }
}
