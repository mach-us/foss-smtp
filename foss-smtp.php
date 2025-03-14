<?php
/**
 * Plugin Name: FOSS SMTP
 * Plugin URI: https://github.com/mach-us/foss-smtp
 * Description: A simple SMTP plugin for WordPress with testing capabilities and detailed logging
 * Version: 1.0.0
 * Author: Machus
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: foss-smtp
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('FOSS_SMTP_VERSION', '1.0.0');
define('FOSS_SMTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FOSS_SMTP_PLUGIN_URL', plugin_dir_url(__FILE__));

class Foss_SMTP {
    private static $instance = null;
    private $options;
    private $log_messages = [];
    private $default_options = [
        'host' => '',
        'port' => '587',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'encryption' => 'tls',
        'debug_mode' => '0'
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = wp_parse_args(
            get_option('foss_smtp_settings', []),
            $this->default_options
        );
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_foss_smtp_test', [$this, 'ajax_test_email']);
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // Add settings link on plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_foss-smtp' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'foss-smtp-admin',
            FOSS_SMTP_PLUGIN_URL . 'js/admin.js',
            ['jquery'],
            FOSS_SMTP_VERSION,
            true
        );

        wp_localize_script('foss-smtp-admin', 'fossSmtpAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('foss_smtp_test'),
            'sending' => __('Sending test email...', 'foss-smtp'),
            'success' => __('Test email sent successfully!', 'foss-smtp'),
            'error' => __('Failed to send test email. Check logs for details.', 'foss-smtp')
        ]);
    }

    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=foss-smtp'),
            __('Settings', 'foss-smtp')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'foss-smtp') {
            return;
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved successfully!', 'foss-smtp'); ?></p>
            </div>
            <?php
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('FOSS SMTP Settings', 'foss-smtp'),
            __('FOSS SMTP', 'foss-smtp'),
            'manage_options',
            'foss-smtp',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            'foss_smtp_settings',
            'foss_smtp_settings',
            [$this, 'sanitize_settings']
        );
        
        add_settings_section(
            'foss_smtp_main',
            __('SMTP Configuration', 'foss-smtp'),
            null,
            'foss-smtp'
        );

        $fields = [
            'host' => [
                'label' => __('SMTP Host', 'foss-smtp'),
                'type' => 'text'
            ],
            'port' => [
                'label' => __('SMTP Port', 'foss-smtp'),
                'type' => 'number'
            ],
            'encryption' => [
                'label' => __('Encryption Type', 'foss-smtp'),
                'type' => 'select',
                'options' => [
                    'none' => __('None', 'foss-smtp'),
                    'ssl' => 'SSL',
                    'tls' => 'TLS'
                ]
            ],
            'username' => [
                'label' => __('SMTP Username', 'foss-smtp'),
                'type' => 'text'
            ],
            'password' => [
                'label' => __('SMTP Password', 'foss-smtp'),
                'type' => 'password'
            ],
            'from_email' => [
                'label' => __('From Email', 'foss-smtp'),
                'type' => 'email'
            ],
            'from_name' => [
                'label' => __('From Name', 'foss-smtp'),
                'type' => 'text'
            ],
            'debug_mode' => [
                'label' => __('Debug Mode', 'foss-smtp'),
                'type' => 'select',
                'options' => [
                    '0' => __('Disabled', 'foss-smtp'),
                    '1' => __('Basic', 'foss-smtp'),
                    '2' => __('Advanced', 'foss-smtp')
                ]
            ]
        ];

        foreach ($fields as $field => $config) {
            add_settings_field(
                'foss_smtp_' . $field,
                $config['label'],
                [$this, 'render_field'],
                'foss-smtp',
                'foss_smtp_main',
                [
                    'field' => $field,
                    'type' => $config['type'],
                    'options' => isset($config['options']) ? $config['options'] : null
                ]
            );
        }
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Keep the old password if no new one is provided
        if (empty($input['password'])) {
            $sanitized['password'] = $this->options['password'];
        } else {
            $sanitized['password'] = $input['password'];
        }

        $sanitized['host'] = sanitize_text_field($input['host']);
        $sanitized['port'] = absint($input['port']);
        $sanitized['username'] = sanitize_text_field($input['username']);
        $sanitized['from_email'] = sanitize_email($input['from_email']);
        $sanitized['from_name'] = sanitize_text_field($input['from_name']);
        $sanitized['encryption'] = in_array($input['encryption'], ['none', 'ssl', 'tls']) ? $input['encryption'] : 'none';
        $sanitized['debug_mode'] = in_array($input['debug_mode'], ['0', '1', '2']) ? $input['debug_mode'] : '0';

        return $sanitized;
    }

    public function render_field($args) {
        $field = $args['field'];
        $type = $args['type'];
        $value = isset($this->options[$field]) ? $this->options[$field] : '';
        
        switch ($type) {
            case 'select':
                echo '<select name="foss_smtp_settings[' . esc_attr($field) . ']" class="regular-text">';
                foreach ($args['options'] as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
                break;
                
            case 'password':
                echo '<input type="password" name="foss_smtp_settings[' . esc_attr($field) . ']" value="" class="regular-text" placeholder="' . esc_attr__('Enter new password or leave blank to keep existing', 'foss-smtp') . '">';
                break;
                
            default:
                echo '<input type="' . esc_attr($type) . '" name="foss_smtp_settings[' . esc_attr($field) . ']" value="' . esc_attr($value) . '" class="regular-text">';
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('foss_smtp_settings');
                do_settings_sections('foss-smtp');
                submit_button(__('Save Settings', 'foss-smtp'));
                ?>
            </form>

            <hr>

            <h2><?php _e('Test Email Configuration', 'foss-smtp'); ?></h2>
            <div class="foss-smtp-test-email">
                <input type="email" id="test-email-to" placeholder="<?php esc_attr_e('Enter test email address', 'foss-smtp'); ?>" class="regular-text" required>
                <button type="button" id="send-test-email" class="button button-secondary">
                    <?php _e('Send Test Email', 'foss-smtp'); ?>
                </button>
                <span class="spinner" style="float: none; margin-top: 4px;"></span>
            </div>

            <div id="foss-smtp-log" style="margin-top: 20px;">
                <h2><?php _e('Log Messages', 'foss-smtp'); ?></h2>
                <textarea id="smtp-log" readonly style="width: 100%; height: 200px; font-family: monospace; margin-top: 10px;"><?php echo esc_textarea(implode("\n", $this->log_messages)); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function configure_smtp($phpmailer) {
        // Only configure if host is set
        if (empty($this->options['host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $this->options['host'];
        $phpmailer->Port = $this->options['port'];
        
        if (!empty($this->options['username']) && !empty($this->options['password'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $this->options['username'];
            $phpmailer->Password = $this->options['password'];
        }

        $encryption = $this->options['encryption'];
        if ($encryption !== 'none') {
            $phpmailer->SMTPSecure = $encryption;
        }

        if (!empty($this->options['from_email'])) {
            $phpmailer->From = $this->options['from_email'];
        }
        if (!empty($this->options['from_name'])) {
            $phpmailer->FromName = $this->options['from_name'];
        }

        // Set debug level based on settings
        $phpmailer->SMTPDebug = intval($this->options['debug_mode']);
        $phpmailer->Debugoutput = function($str, $level) {
            $this->log_messages[] = date('[Y-m-d H:i:s] ') . trim($str);
        };
    }

    public function ajax_test_email() {
        check_ajax_referer('foss_smtp_test');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $to = sanitize_email($_POST['to']);
        if (!is_email($to)) {
            wp_send_json_error(__('Invalid email address', 'foss-smtp'));
        }

        $subject = sprintf(__('[%s] FOSS SMTP Test Email', 'foss-smtp'), get_bloginfo('name'));
        $message = sprintf(
            __("This is a test email sent from your WordPress site (%s) using FOSS SMTP plugin.\n\nSMTP Configuration:\nHost: %s\nPort: %s\nEncryption: %s\nFrom: %s\n\nIf you received this email, your SMTP configuration is working correctly!", 'foss-smtp'),
            get_bloginfo('url'),
            $this->options['host'],
            $this->options['port'],
            $this->options['encryption'],
            $this->options['from_email']
        );
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $this->log_messages = []; // Clear previous logs
        
        add_action('wp_mail_failed', function($error) {
            $this->log_messages[] = '[ERROR] ' . $error->get_error_message();
        });

        $result = wp_mail($to, $subject, $message, $headers);

        if ($result) {
            wp_send_json_success([
                'message' => __('Test email sent successfully!', 'foss-smtp'),
                'logs' => $this->log_messages
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to send test email', 'foss-smtp'),
                'logs' => $this->log_messages
            ]);
        }
    }
}

// Initialize the plugin
Foss_SMTP::get_instance();
