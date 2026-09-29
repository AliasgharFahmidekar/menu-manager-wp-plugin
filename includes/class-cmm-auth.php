<?php
if (!defined('ABSPATH')) { exit; }

class CMM_Auth {
    public static function boot(): void {
        // Keep the manager role/capability valid even when the plugin is updated
        // without a deactivate/activate cycle.
        add_action('init', [__CLASS__, 'ensure_manager_capability'], 4);
        add_action('login_init', [__CLASS__, 'block_manager_wordpress_login'], 1);
        add_action('init', [__CLASS__, 'handle_login'], 30);
        add_action('admin_init', [__CLASS__, 'redirect_manager_from_admin'], 1);
    }

    public static function ensure_manager_capability(): void {
        $role = get_role('cafe_manager');
        if (!$role) {
            $role = add_role('cafe_manager', 'Cafe Manager', [
                'read' => true,
                'upload_files' => true,
                'manage_cafe_menu' => true,
            ]);
        }
        if ($role) {
            $role->add_cap('read');
            $role->add_cap('upload_files');
            $role->add_cap('manage_cafe_menu');
        }

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('manage_cafe_menu');
        }
    }

    private static function is_manager_login_request(): bool {
        if (get_query_var('cmm_page') === 'login') {
            return true;
        }

        // Be tolerant of installations where the rewrite rule has not been
        // refreshed yet. This also makes login POST handling reliable after
        // uploading/replacing the plugin files.
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $base = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }

        return $path === 'manage/login';
    }

    public static function handle_login(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !self::is_manager_login_request()) {
            return;
        }

        if (
            !isset($_POST['cmm_login_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['cmm_login_nonce'])),
                'cmm_login'
            )
        ) {
            self::fail('درخواست نامعتبر است. صفحه را دوباره باز کنید.');
        }

        $identifier = sanitize_text_field(wp_unslash($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        if ($identifier === '' || $password === '') {
            self::fail('ایمیل/نام کاربری و رمز عبور را وارد کنید.');
        }

        $username = $identifier;
        if (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            $username = $user ? $user->user_login : $identifier;
        }

        $result = wp_signon(
            [
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => $remember,
            ],
            is_ssl()
        );

        if (is_wp_error($result)) {
            self::fail('اطلاعات ورود صحیح نیست.');
        }

        if (!CMM_Core::can_manage($result)) {
            wp_logout();
            self::fail('این حساب اجازه ورود به پنل کافه را ندارد.');
        }

        wp_safe_redirect(CMM_Core::manager_url());
        exit;
    }

    private static function fail(string $message): void {
        // Use a request-specific key. For anonymous users WordPress may return
        // an empty session token, so a fixed key is more reliable here.
        $key = wp_generate_uuid4();
        set_transient('cmm_login_error_' . $key, $message, MINUTE_IN_SECONDS * 5);

        // Pass the error key through the redirect instead of relying on an
        // anonymous session token that may change between requests.
        wp_safe_redirect(add_query_arg('cmm_error', rawurlencode($key), CMM_Core::login_url()));
        exit;
    }

    public static function consume_login_error(): string {
        $key = sanitize_text_field(wp_unslash($_GET['cmm_error'] ?? ''));
        if ($key === '') {
            return '';
        }

        $error = get_transient('cmm_login_error_' . $key);
        delete_transient('cmm_login_error_' . $key);

        return is_string($error) ? $error : '';
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
