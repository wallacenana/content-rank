<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content_Rank_License_Client
{
    const OPTION_KEY = 'content_rank_license';
    const CRON_HOOK = 'content_rank_license_daily_check';
    // The hosting rewrite does not expose /wp-json reliably; rest_route still reaches WordPress.
    const DEFAULT_API_URL = 'https://content-rank.com/?rest_route=/content-rank-license/v1';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 1001);
        add_action('admin_post_content_rank_save_license', array(__CLASS__, 'save_license'));
        add_action('admin_post_content_rank_deactivate_license', array(__CLASS__, 'deactivate_license'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_check'));
        self::schedule_cron();
    }

    public static function settings()
    {
        $settings = wp_parse_args((array) get_option(self::OPTION_KEY, array()), array(
            'license_key' => '',
            'email' => '',
            'api_url' => self::DEFAULT_API_URL,
            'valid' => false,
            'status' => '',
            'plan' => '',
            'max_domains' => 0,
            'active_domains' => array(),
            'message' => '',
            'last_checked' => 0,
        ));
        $settings['api_url'] = self::DEFAULT_API_URL;
        return $settings;
    }

    public static function admin_menu()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        add_submenu_page(
            'content-rank',
            'Licenca',
            'Licenca',
            'manage_options',
            'content-rank-license',
            array(__CLASS__, 'render_page'),
            998
        );
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        $settings = self::settings();
        $domain = self::current_domain();
        echo '<div class="wrap"><h1>Licenca Content Rank</h1>';
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Licenca ativada neste dominio.</p></div>';
        }
        if (!empty($_GET['deactivated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Dominio removido da licenca.</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['error'])) . '</p></div>';
        }

        echo '<div class="card" style="max-width:760px;padding:20px;margin-top:20px;">';
        if (self::is_valid()) {
            echo '<p><strong>Status:</strong> Ativa</p>';
            echo '<p><strong>Plano:</strong> ' . esc_html($settings['plan']) . '</p>';
            echo '<p><strong>Dominio atual:</strong> ' . esc_html($domain) . '</p>';
            echo '<p><strong>Dominios:</strong> ' . esc_html(count((array) $settings['active_domains']) . '/' . intval($settings['max_domains'])) . '</p>';
            if (!empty($settings['active_domains'])) {
                echo '<p><strong>Ativos:</strong> ' . esc_html(implode(', ', $settings['active_domains'])) . '</p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="content_rank_deactivate_license">';
            wp_nonce_field('content_rank_deactivate_license');
            submit_button('Desativar este dominio', 'secondary', 'submit', false);
            echo '</form>';
        } else {
            echo '<p>Ainda nao existe uma licenca ativa neste site.</p>';
        }

        echo '<hr><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="content_rank_save_license">';
        wp_nonce_field('content_rank_save_license');
        echo '<table class="form-table">';
        echo '<tr><th><label for="content-rank-license-email">E-mail da compra</label></th><td><input class="regular-text" type="email" id="content-rank-license-email" name="email" value="' . esc_attr($settings['email']) . '" placeholder="cliente@exemplo.com" required></td></tr>';
        echo '<tr><th><label for="content-rank-license-key">Chave da licenca</label></th><td><input class="regular-text" type="text" id="content-rank-license-key" name="license_key" value="' . esc_attr($settings['license_key']) . '" placeholder="CR-START-XXXX-XXXX" required></td></tr>';
        echo '</table>';
        submit_button(self::is_valid() ? 'Validar novamente' : 'Ativar neste dominio');
        echo '</form></div></div>';
    }

    public static function save_license()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer('content_rank_save_license');

        $settings = self::settings();
        $settings['email'] = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : (string) $settings['email'];
        $settings['license_key'] = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $settings['api_url'] = self::DEFAULT_API_URL;

        $response = self::request($settings, 'activate');
        if (is_wp_error($response)) {
            self::save_error($settings, $response->get_error_message());
            self::redirect_error($response->get_error_message());
        }

        self::save_response($settings, $response);
        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=content-rank-license')));
        exit;
    }

    public static function deactivate_license()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer('content_rank_deactivate_license');
        $settings = self::settings();
        self::request($settings, 'deactivate');
        $settings['valid'] = false;
        $settings['status'] = '';
        $settings['active_domains'] = array();
        $settings['last_checked'] = time();
        update_option(self::OPTION_KEY, $settings, false);
        wp_safe_redirect(add_query_arg('deactivated', '1', admin_url('admin.php?page=content-rank-license')));
        exit;
    }

    public static function cron_check()
    {
        $settings = self::settings();
        if (empty($settings['license_key'])) {
            return;
        }
        $response = self::request($settings, 'validate');
        if (is_wp_error($response)) {
            self::save_error($settings, $response->get_error_message());
            return;
        }
        self::save_response($settings, $response);
    }

    public static function is_valid()
    {
        $settings = self::settings();
        return !empty($settings['valid']) && $settings['status'] === 'active';
    }

    public static function current_domain()
    {
        $domain = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return preg_replace('/^www\./', '', $domain);
    }

    private static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    private static function request($settings, $action)
    {
        if (empty($settings['license_key']) || empty($settings['email']) || empty($settings['api_url'])) {
            return new WP_Error('content_rank_license_missing', 'Informe o e-mail e a chave da licenca.');
        }

        $email_log = (string) $settings['email'];
        $email_log = $email_log !== '' ? substr($email_log, 0, 2) . '***' : '';

        $response = wp_remote_post(trailingslashit($settings['api_url']) . ltrim($action, '/'), array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'license_key' => $settings['license_key'],
                'email' => $settings['email'],
                'domain' => self::current_domain(),
                'site_url' => home_url('/'),
                'site_name' => get_bloginfo('name'),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plugin' => 'content-rank',
                'plugin_version' => defined('Content_Rank_Generator::VERSION') ? Content_Rank_Generator::VERSION : '',
            )),
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['valid'])) {
            $message = !empty($data['message']) ? $data['message'] : 'Nao foi possivel validar a licenca.';
            return new WP_Error('content_rank_license_invalid', sanitize_text_field($message), array('status' => $code, 'body' => $raw));
        }
        return $data;
    }

    private static function save_response($settings, $response)
    {
        $settings = array_merge($settings, array(
            'valid' => !empty($response['valid']),
            'status' => !empty($response['status']) ? sanitize_key($response['status']) : '',
            'plan' => !empty($response['plan']) ? sanitize_key($response['plan']) : '',
            'max_domains' => isset($response['max_domains']) ? absint($response['max_domains']) : 0,
            'active_domains' => !empty($response['active_domains']) && is_array($response['active_domains']) ? array_map('sanitize_text_field', $response['active_domains']) : array(),
            'message' => '',
            'last_checked' => time(),
        ));
        update_option(self::OPTION_KEY, $settings, false);
    }

    private static function save_error($settings, $message)
    {
        $settings['valid'] = false;
        $settings['message'] = sanitize_text_field($message);
        $settings['last_checked'] = time();
        update_option(self::OPTION_KEY, $settings, false);
    }

    private static function redirect_error($message)
    {
        wp_safe_redirect(add_query_arg('error', rawurlencode($message), admin_url('admin.php?page=content-rank-license')));
        exit;
    }
}
