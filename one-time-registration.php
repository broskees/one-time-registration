<?php
/**
 * Plugin Name: One Time Registration
 * Description: Easily generate one time registration URLs
 * Plugin URI: https://github.com/broskees/one-time-registration/
 * Version: 1.0.0
 * Author: Digital Joe
 * Author URI: https://digitaljoe.agency
 * Text Domain: onetimeregistration
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    die();
}

$config = [
    'plugin_slug' => 'one_time_registration',
    'db_version' => '1.0.0',
    'textdomain' => 'onetimeregistration'
];

new OneTimeRegistration($config);

class OneTimeRegistration
{
    private $plugin_slug;
    private $filterHookPrefix;
    private $textdomain;
    private $errors;

    public function __construct($config)
    {
        $this->plugin_slug = $config['plugin_slug'];
        $this->db_version = $config['db_version'];
        $this->textdomain = $config['textdomain'];
        $this->errors = null;
        $this->initialize();
    }

    public function initialize()
    {
        // Core functionality
        register_activation_hook(__FILE__, [$this, 'install']);
        add_action('plugins_loaded', [$this, 'db_check']);
        add_action('init', [$this, 'handle_token']);
        add_action('admin_footer', [$this, 'admin_script']);
        add_action('wp_ajax_generate_token', [$this, 'generate_and_save_token']);
        add_action('admin_menu', [$this, 'create_admin_page']);

        // Error handling
        add_action($this->plugin_slug . '_not_valid', [$this, 'do_error']);
        add_action($this->plugin_slug . '_no_token', [$this, 'do_error']);
        add_action($this->plugin_slug . '_unable_to_delete_token', [$this, 'do_error']);
    }

    public function install()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->plugin_slug;
        $result = $wpdb->get_var("show tables like '$table_name'");

        // check if table already exists
        if ($result == $table_name
            && get_site_option($this->plugin_slug . '_db_version') == $this->db_version
        ) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            token varchar(40) NOT NULL
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option($this->plugin_slug . '_db_version', $this->db_version);
    }

    public function db_check()
    {
        $this->install();
    }

    public function generate_and_save_token()
    {
        global $wpdb;     
        $table_name = $wpdb->prefix . $this->plugin_slug;
        $token = sha1(wp_generate_password());
        $wpdb->insert(
            $table_name, 
            [
                'time' => current_time('mysql'),
                'token' => $token
            ]
        );

        $registration_url = $this->build_registration_url($token);

        echo $registration_url;
        wp_die();
    }

    public function admin_script()
    {
        ?>
            <script type="text/javascript" >
                jQuery(document).ready(function($) {
                    const
                        data = {'action': 'generate_token'},
                        genButton = document.querySelector('#generate-url') || false,
                        input = document.querySelector('#generated-url') || false,
                        errorSpace = document.querySelector('#error-space') || false,
                        copyButton = document.querySelector('#copy-url') || false;

                    if (!copyButton || !input || !errorSpace || !genButton) {
                        return;
                    }

                    genButton.addEventListener('click', event => {
                        jQuery.post(
                            ajaxurl,
                            data,
                            function(response) {
                                if (response.includes('error')) {
                                    errorSpace.innerHTML = response;
                                }

                                input.value = response;
                            }
                        );
                    });

                    copyButton.addEventListener('click', event => {
                        input.select();
                        document.execCommand('copy');
                    });
                });
            </script>
        <?php
    }

    public function create_admin_page()
    {
        add_management_page(
            'Generate One-Time Registration Link',
            'Registration Link Generator',
            'create_users',
            'registration-link-generator',
            [$this, 'admin_page_contents']
        );
    }

    public function admin_page_contents()
    {
        $td = $this->textdomain;

        ?>
            <h1>
                <?php esc_html_e('Generate One-Time Registration Link', $td); ?>
            </h1>
            <div id="error-space"></div>
            <input id="generated-url" class="regular-text ltr" type="text" value="" disabled />
            <button id="copy-url" class="button">
                <?php esc_html_e('Copy Link', $td); ?>
            </button>
            <button id="generate-url" class="button">
                <?php esc_html_e('Generate Link', $td); ?>
            </button>
        <?php
    }

    public function handle_token()
    {
        global $pagenow;
        global $wpdb;     

        // check that we're on the registration page and that "Anyone can Register" isn't turned on
        if ($pagenow !== 'wp-login.php'
            || !in_array('action', array_keys($_GET))
            || $_GET['action'] !== 'register'
        ) {
            return;
        }

        // handle submissions
        if (!empty($_POST['token']) && $post_token = $_POST['token']) {
            if ($this->handle_submission($post_token)) {
                return;
            }
        }

        // check the token
        if ($this->check_token($_GET)) {
            return;
        }

        // jump ship if errors
        if (is_wp_error($this->errors)) {
            $this->error_and_quit();
        }

        // allow to proceed with registration
        do_action($this->plugin_slug . '_valid');

        // include the token in a hidden field
        add_action('register_form', function () use ($token) {
            echo sprintf(
                '<input type="hidden" name="token" value="%s" />',
                $token
            );
        });
    }

    public function build_registration_url($token)
    {
        $url = "/wp-login.php?" . http_build_query([
            'action' => 'register',
            'token' => "$token"
        ]);

        return site_url($url);
    }

    public function check_if_token_is_valid($token)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->plugin_slug;
        $isTokenValid = $wpdb->get_var($wpdb->prepare(
            "SELECT token 
            FROM $table_name
            WHERE token = '%s'",
            [$token]
        ));

        return $isTokenValid;
    }

    private function handle_submission($token)
    {
        if (!$this->check_if_token_is_valid($post_token)) {
            do_action(
                $this->plugin_slug . '_invalid_post_token', 
                apply_filters(
                    $this->plugin_slug . '_invalid_post_token_error', 
                    __('Nice Try.', $this->textdomain)
                )
            );
            return false;
        }

        $table_name = $wpdb->prefix . $this->plugin_slug;
        $result = $wpdb->delete($table_name, ['token' => $post_token], ['%s']);

        if (!$result) {
            do_action(
                $this->plugin_slug . '_unable_to_delete_token', 
                apply_filters(
                    $this->plugin_slug . '_unable_to_delete_token_error', 
                    __('Unable to delete token', $this->textdomain)
                )
            );

            return false;
        }

        return true;
    }

    private function check_token($get_params)
    {
        do_action($this->plugin_slug . '_before_token_check');

        if (empty($get_params['token'])) {
            do_action(
                $this->plugin_slug . '_no_token', 
                apply_filters(
                    $this->plugin_slug . '_no_token_error', 
                    __('No token was provided', $this->textdomain)
                )
            );
            return false;
        }

        do_action($this->plugin_slug . '_token_present');

        // is token valid
        $token = $_GET['token'];
        $isTokenValid = $this->check_if_token_is_valid($token);

        if (!$isTokenValid) {
            do_action(
                $this->plugin_slug . '_not_valid',
                apply_filters(
                    $this->plugin_slug . '_no_token_error', 
                    __('Token is not valid', $this->textdomain)
                )
            );
            return false;
        }

        return true;
    }

    public function do_error($error_message, $code = 503)
    {
        is_wp_error($this->errors)
            ? $this->errors->add($code, $error_message) 
            : $this->errors = new WP_Error($code, $error_message);
    }

    private function error_and_quit()
    {
        if (!is_wp_error($this->errors)) {
            return;
        }

        $error_messages = $this->errors->get_error_messages();
        $output = implode('\n', array_map(
            function($error_message) {
                return sprintf('<p>%s</p>', $error_message);
            },
            $error_messages
        ));

        wp_die($output);
    }
}
