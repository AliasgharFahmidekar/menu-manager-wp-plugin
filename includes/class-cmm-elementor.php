<?php
if (!defined('ABSPATH')) { exit; }

class CMM_Elementor {
    public static function boot(): void {
        add_action('elementor/query/cmm_products', [__CLASS__, 'products_query']);
        add_action('pre_get_posts', [__CLASS__, 'public_product_query_fallback'], 20);
    }

    public static function products_query(WP_Query $query): void {
        $query->set('post_type', 'product');
        $query->set('post_status', 'publish');
        $query->set('posts_per_page', -1);
        if (get_option('cmm_menu_visible', '1') !== '1') { $query->set('post__in', [0]); return; }
        $query->set('orderby', ['menu_order'=>'ASC','title'=>'ASC']);
        $query->set('order', 'ASC');
        $existing = $query->get('meta_query');
        $meta = is_array($existing) ? $existing : [];
        if (isset($meta['relation'])) {
            $relation = $meta['relation']; unset($meta['relation']);
        } else { $relation = 'AND'; }
        $meta[] = ['relation'=>'OR', ['key'=>'visible','value'=>'1','compare'=>'='], ['key'=>'visible','compare'=>'NOT EXISTS']];
        $meta['relation'] = $relation;
        $query->set('meta_query', $meta);
    }

    public static function public_product_query_fallback(WP_Query $query): void {
        if (is_admin() || !$query->is_main_query()) return;
        if ((bool)$query->get('cmm_menu_query')) {
            $query->set('post_type','product');
            $query->set('posts_per_page',-1);
            $query->set('post_status','publish');
            $query->set('orderby',['menu_order'=>'ASC','title'=>'ASC']);
        }
    }
}
