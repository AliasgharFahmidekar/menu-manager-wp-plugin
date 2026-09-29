<?php
if (!defined('ABSPATH')) { exit; }

class CMM_Auth {
    public static function boot(): void {
        add_action('login_init', [__CLASS__, 'block_manager_wordpress_login'], 1);
        add_action('init', [__CLASS__, 'handle_login'], 30);
        add_action('admin_init', [__CLASS__, 'redirect_manager_from_admin'], 1);
    }

    public static function handle_login(): void {
        if (get_query_var('cmm_page') !== 'login' || $_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (!isset($_POST['cmm_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cmm_login_nonce'])), 'cmm_login')) {
            self::fail('درخواست نامعتبر است. صفحه را دوباره باز کنید.');
        }
        $identifier = sanitize_text_field(wp_unslash($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        if ($identifier === '' || $password === '') self::fail('ایمیل/نام کاربری و رمز عبور را وارد کنید.');
        $username = $identifier;
        if (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            $username = $user ? $user->user_login : $identifier;
        }
        $result = wp_signon(['user_login'=>$username,'user_password'=>$password,'remember'=>$remember], is_ssl());
        if (is_wp_error($result)) self::fail('اطلاعات ورود صحیح نیست.');
        if (!CMM_Core::can_manage($result)) {
            wp_logout();
            self::fail('این حساب اجازه ورود به پنل کافه را ندارد.');
        }
        wp_safe_redirect(CMM_Core::manager_url());
        exit;
    }

    private static function fail(string $message): void {
        set_transient('cmm_login_error_' . wp_get_session_token(), $message, MINUTE_IN_SECONDS * 5);
        wp_safe_redirect(CMM_Core::login_url());
        exit;
    }

    public static function redirect_manager_from_admin(): void {
        if (!is_user_logged_in()) return;
        $user = wp_get_current_user();
        if (!$user || !in_array('cafe_manager', (array)$user->roles, true)) return;
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        wp_safe_redirect(CMM_Core::manager_url());
        exit;
    }

    public static function block_manager_wordpress_login(): void {
        if (CMM_Core::can_manage()) {
            wp_safe_redirect(CMM_Core::manager_url());
            exit;
        }
    }
}
