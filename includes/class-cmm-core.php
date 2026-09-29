<?php
if (!defined('ABSPATH')) { exit; }

class CMM_Core {
    public static function boot(): void {
        add_action('init', [__CLASS__, 'register_content_model'], 5);
        add_action('init', [__CLASS__, 'register_routes'], 20);
        add_action('init', [__CLASS__, 'maybe_flush_rewrites'], 99);
        add_action('template_redirect', [__CLASS__, 'render_manager'], 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_filter('show_admin_bar', [__CLASS__, 'hide_admin_bar_for_manager']);
    }

    public static function activate(): void {
        self::register_content_model();
        self::register_routes();
        self::seed_manager_role();
        self::normalize_existing_orders();
        self::ensure_menu_page();
        update_option('cmm_rewrite_version', CMM_VERSION);
        if (get_option('cmm_cafe_name', '') === '') {
            update_option('cmm_cafe_name', get_bloginfo('name'));
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    public static function maybe_flush_rewrites(): void {
        if (get_option('cmm_rewrite_version') !== CMM_VERSION) {
            self::ensure_menu_page();
            flush_rewrite_rules(false);
            update_option('cmm_rewrite_version', CMM_VERSION);
        }
    }

    public static function ensure_menu_page(): int {
        $existing = get_page_by_path('menu', OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            return (int) $existing->ID;
        }
        $page_id = wp_insert_post([
            'post_title'   => 'Cafe Menu',
            'post_name'    => 'menu',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- Cafe Menu: build this page with Elementor. -->',
        ], true);
        return is_wp_error($page_id) ? 0 : (int) $page_id;
    }

    public static function seed_manager_role(): void {
        $role = get_role('cafe_manager');
        if (!$role) {
            add_role('cafe_manager', 'Cafe Manager', [
                'read' => true,
                'upload_files' => true,
                'manage_cafe_menu' => true,
            ]);
        } else {
            $role->add_cap('manage_cafe_menu');
            $role->add_cap('upload_files');
        }
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap('manage_cafe_menu'); }
    }

    /** Resolve the single product taxonomy shared with CafeFlo/ACF. */
    public static function product_taxonomy(): string {
        $preferred = sanitize_key((string) get_option('cafeflo_product_taxonomy', ''));
        foreach ([$preferred, 'product_category', 'product_cat'] as $taxonomy) {
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $object = get_taxonomy($taxonomy);
            if ($object && in_array('product', (array) $object->object_type, true)) {
                return $taxonomy;
            }
        }
        return 'product_category';
    }

    public static function register_content_model(): void {
        if (!post_type_exists('product')) {
            register_post_type('product', [
                'labels' => [
                    'name' => 'Products', 'singular_name' => 'Product',
                ],
                'public' => true,
                'show_ui' => true,
                'show_in_rest' => true,
                'supports' => ['title'],
                'has_archive' => false,
                'rewrite' => ['slug' => 'product'],
                'menu_icon' => 'dashicons-carrot',
            ]);
        }
        $taxonomy = self::product_taxonomy();
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'labels' => [
                    'name' => 'Product Categories', 'singular_name' => 'Product Category',
                ],
                'public' => true,
                'show_ui' => true,
                'show_in_rest' => true,
                'hierarchical' => true,
                'rewrite' => ['slug' => 'product-category'],
            ]);
        }
        register_taxonomy_for_object_type($taxonomy, 'product');
        if (!taxonomy_exists('menu_tag')) {
            register_taxonomy('menu_tag', ['product'], [
                'labels' => [
                    'name' => 'Menu Tags', 'singular_name' => 'Menu Tag',
                ],
                'public' => false,
                'publicly_queryable' => false,
                'show_ui' => true,
                'show_in_rest' => true,
                'hierarchical' => false,
                'rewrite' => false,
            ]);
        } else {
            register_taxonomy_for_object_type('menu_tag', 'product');
        }
    }

    public const MAX_TAGS = 2;

    public static function tag_colors(): array {
        return ['gray', 'peach', 'dark', 'sage'];
    }

    public static function sanitize_tag_color($value): string {
        $value = sanitize_key((string) $value);
        return in_array($value, self::tag_colors(), true) ? $value : 'gray';
    }

    public static function tag_payload(WP_Term $term): array {
        return [
            'id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'color' => self::sanitize_tag_color(get_term_meta($term->term_id, 'cmm_color', true)),
            'count' => (int) $term->count,
        ];
    }

    public static function product_tags(int $post_id): array {
        $terms = wp_get_object_terms($post_id, 'menu_tag', ['orderby' => 'term_id', 'order' => 'ASC']);
        if (is_wp_error($terms)) { return []; }
        return array_values(array_map([__CLASS__, 'tag_payload'], $terms));
    }

    public static function ensure_product_tags(int $post_id, array $term_ids): void {
        $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
        $term_ids = array_slice($term_ids, 0, self::MAX_TAGS);
        wp_set_object_terms($post_id, $term_ids, 'menu_tag', false);
    }

    /** Accepts Persian/Arabic digits and thousands separators: "۷۲,۰۰۰" => "72000". */
    public static function normalize_number($value): string {
        if ($value === null || is_array($value)) { return ''; }
        $value = strtr((string) $value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٬'=>'','،'=>'',','=>'',' '=>'','٫'=>'.',
        ]);
        return trim($value);
    }

    public static function register_routes(): void {
        add_rewrite_rule('^manage/?$', 'index.php?cmm_page=manage', 'top');
        add_rewrite_rule('^manage/login/?$', 'index.php?cmm_page=login', 'top');
        add_rewrite_rule('^manage/logout/?$', 'index.php?cmm_page=logout', 'top');
    }

    public static function query_vars(array $vars): array {
        $vars[] = 'cmm_page';
        return $vars;
    }

    public static function is_manager_request(): bool {
        return in_array(get_query_var('cmm_page'), ['manage', 'login', 'logout'], true);
    }

    public static function can_manage(?WP_User $user = null): bool {
        $user = $user ?: wp_get_current_user();
        return $user instanceof WP_User && $user->exists() && ($user->has_cap('manage_cafe_menu') || $user->has_cap('manage_options'));
    }

    public static function manager_url(string $path = ''): string {
        return home_url('/manage' . ($path ? '/' . ltrim($path, '/') : '/'));
    }

    public static function login_url(): string { return self::manager_url('login'); }
    public static function logout_url(): string { return self::manager_url('logout'); }

    public static function render_manager(): void {
        $page = get_query_var('cmm_page');
        if (!$page) {
            $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            $base = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
            if ($base !== '' && str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base) + 1);
            }
            if ($path === 'manage') $page = 'manage';
            elseif ($path === 'manage/login') $page = 'login';
            elseif ($path === 'manage/logout') $page = 'logout';
        }
        if (!$page) { return; }

        if ($page === 'logout') {
            wp_logout();
            wp_safe_redirect(self::login_url());
            exit;
        }

        if ($page === 'login') {
            if (self::can_manage()) {
                wp_safe_redirect(self::manager_url());
                exit;
            }
            self::render_template('login.php', ['error' => CMM_Auth::consume_login_error()]);
            exit;
        }

        if ($page === 'manage') {
            if (!is_user_logged_in() || !self::can_manage()) {
                wp_safe_redirect(self::login_url());
                exit;
            }
            self::render_template('manager.php');
            exit;
        }
    }

    private static function consume_login_error(): string {
        $error = get_transient('cmm_login_error_' . wp_get_session_token());
        if ($error) { delete_transient('cmm_login_error_' . wp_get_session_token()); }
        return is_string($error) ? $error : '';
    }

    public static function render_template(string $file, array $vars = []): void {
        extract($vars, EXTR_SKIP);
        $template = CMM_DIR . 'templates/' . $file;
        if (file_exists($template)) { require $template; }
    }

    public static function enqueue_assets(): void {
        if (get_query_var('cmm_page') !== 'manage') { return; }
        wp_enqueue_style('cmm-dashboard', CMM_URL . 'assets/dashboard.css', [], CMM_VERSION);
        wp_enqueue_script('cmm-dashboard', CMM_URL . 'assets/dashboard.js', [], CMM_VERSION, true);
        wp_localize_script('cmm-dashboard', 'CMM_DATA', [
            'apiBase' => esc_url_raw(rest_url('cmm/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'manageUrl' => self::manager_url(),
            'loginUrl' => self::login_url(),
            'menuUrl' => esc_url_raw(home_url('/menu/')),
            'cafeName' => (string) get_option('cmm_cafe_name', get_bloginfo('name')),
            'ajaxFailure' => 'ارتباط با سرور انجام نشد. دوباره تلاش کنید.',
        ]);
    }

    public static function hide_admin_bar_for_manager(bool $show): bool {
        return self::can_manage() ? false : $show;
    }

    public static function acf_get(string $field, int $post_id, $fallback = null) {
        if (function_exists('get_field')) {
            $value = get_field($field, $post_id);
            return $value !== null && $value !== '' ? $value : ($fallback !== null ? $fallback : $value);
        }
        $value = get_post_meta($post_id, $field, true);
        return $value !== '' ? $value : $fallback;
    }

    public static function acf_update(string $field, $value, int $post_id): void {
        // false must never reach update_field()/update_post_meta() as-is: WordPress stores it as an
        // empty string, which the public menu then reads as "not set" (= available / visible).
        if (is_bool($value)) { $value = $value ? 1 : 0; }
        if (function_exists('update_field')) {
            update_field($field, $value, $post_id);
        } else {
            update_post_meta($post_id, $field, is_bool($value) ? ($value ? '1' : '0') : $value);
        }
    }

    public static function product_image(int $post_id): array {
        $value = self::acf_get('product_image', $post_id, 0);
        $id = 0;
        if (is_array($value)) { $id = absint($value['ID'] ?? $value['id'] ?? 0); }
        elseif (is_numeric($value)) { $id = absint($value); }
        if (!$id) {
            $id = absint(get_post_meta($post_id, 'product_image', true));
        }
        return [
            'id' => $id,
            'url' => $id ? (string) wp_get_attachment_image_url($id, 'medium') : '',
        ];
    }

    /**
     * Reads a True/False field straight from post meta. A missing meta row means "use the default";
     * a row that exists but is empty is "off" (older versions saved false as an empty string).
     */
    public static function bool_meta(string $field, int $post_id, bool $default): bool {
        if (!metadata_exists('post', $post_id, $field)) { return $default; }
        $raw = get_post_meta($post_id, $field, true);
        if ($raw === '' || $raw === null) { return false; }
        return self::normalize_bool($raw, $default);
    }

    public static function normalize_bool($value, bool $default = false): bool {
        if ($value === null || $value === '') return $default;
        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true) || $value === true || $value === 1;
    }

    public static function product_payload(int $post_id): array {
        $terms = wp_get_post_terms($post_id, self::product_taxonomy(), ['fields' => 'all']);
        $price = self::acf_get('price', $post_id, '');
        $old = self::acf_get('old_price', $post_id, '');
        $description = self::acf_get('description', $post_id, '');
        return [
            'id' => $post_id,
            'name' => get_the_title($post_id),
            'description' => is_string($description) ? $description : '',
            'price' => $price === '' ? '' : (float) $price,
            'old_price' => $old === '' ? '' : (float) $old,
            'available' => self::bool_meta('available', $post_id, true),
            'visible' => self::bool_meta('visible', $post_id, true),
            'featured' => self::bool_meta('featured', $post_id, false),
            'sort_order' => (int) self::acf_get('sort_order', $post_id, 0),
            'categories' => array_values(array_map(static fn($t) => ['id'=>(int)$t->term_id,'name'=>$t->name,'slug'=>$t->slug], is_wp_error($terms) ? [] : $terms)),
            'tags' => self::product_tags($post_id),
            'image' => self::product_image($post_id),
            'editUrl' => self::manager_url('products/' . $post_id),
            'updatedAt' => get_post_modified_time('c', true, $post_id),
        ];
    }

    public static function category_payload(WP_Term $term): array {
        $visible = get_term_meta($term->term_id, 'cmm_visible', true);
        if ($visible === '') { $visible = '1'; }
        $sort = get_term_meta($term->term_id, 'cmm_sort_order', true);
        $image_id = absint(get_term_meta($term->term_id, 'cmm_image_id', true));
        return [
            'id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'visible' => self::normalize_bool($visible, true),
            'sort_order' => (int) $sort,
            'image' => ['id'=>$image_id, 'url'=>$image_id ? (string)wp_get_attachment_image_url($image_id, 'medium') : ''],
            'count' => (int) $term->count,
        ];
    }

    public static function normalize_existing_orders(): void {
        $products = get_posts(['post_type'=>'product','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'date','order'=>'ASC','fields'=>'ids','no_found_rows'=>true]);
        $rank = 10;
        foreach ($products as $id) {
            $raw = self::acf_get('sort_order', (int)$id, '');
            if ($raw === '' || $raw === null || !is_numeric($raw)) {
                self::acf_update('sort_order', $rank, (int)$id);
                wp_update_post(['ID'=>(int)$id,'menu_order'=>$rank]);
            }
            $rank += 10;
        }
        $terms = get_terms(['taxonomy'=>self::product_taxonomy(),'hide_empty'=>false,'fields'=>'ids','orderby'=>'name','order'=>'ASC']);
        if (!is_wp_error($terms)) {
            $rank = 10;
            foreach ($terms as $term_id) {
                $raw = get_term_meta((int)$term_id, 'cmm_sort_order', true);
                if ($raw === '' || $raw === null || !is_numeric($raw)) update_term_meta((int)$term_id, 'cmm_sort_order', $rank);
                $rank += 10;
            }
        }
    }

    public static function find_clear_sort_order(): int {
        $posts = get_posts(['post_type'=>'product','post_status'=>'any','posts_per_page'=>1,'orderby'=>'menu_order','order'=>'DESC','fields'=>'ids']);
        if (!$posts) return 10;
        $max = (int) get_post_field('menu_order', (int)$posts[0]);
        if ($max <= 0) $max = (int) self::acf_get('sort_order', (int)$posts[0], 0);
        return max(10, $max + 10);
    }

    public static function ensure_product_terms(int $post_id, array $term_ids): void {
        $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
        wp_set_object_terms($post_id, $term_ids, self::product_taxonomy(), false);
    }

    public static function upload_image(string $field = 'image'): int|WP_Error {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return 0;

        // An optional file input is still posted by the browser even when no
        // file was selected. Treat UPLOAD_ERR_NO_FILE as "no new image", not
        // as an upload error. This keeps image changes optional on edits and
        // also allows products/categories to exist without an image.
        $error = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        $name  = (string) ($_FILES[$field]['name'] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') return 0;

        if (!current_user_can('upload_files')) return new WP_Error('forbidden_upload', 'اجازه آپلود تصویر ندارید.', ['status'=>403]);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload($field, 0);
        return $attachment_id;
    }
}
