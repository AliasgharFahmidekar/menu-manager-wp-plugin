<?php
if (!defined('ABSPATH')) { exit; }

class CMM_REST {
    private const NS = 'cmm/v1';

    public static function boot(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function permission(): bool {
        return CMM_Core::can_manage();
    }

    public static function register_routes(): void {
        register_rest_route(self::NS, '/products', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'products'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'create_product'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/products/(?P<id>\d+)', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'get_product'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::EDITABLE,'callback'=>[__CLASS__,'update_product'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::DELETABLE,'callback'=>[__CLASS__,'delete_product'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/products/(?P<id>\d+)/duplicate', [
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'duplicate_product'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/products/reorder', [
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'reorder_products'],'permission_callback'=>[__CLASS__,'permission']],
        ]);

        register_rest_route(self::NS, '/categories', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'categories'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'create_category'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/categories/(?P<id>\d+)', [
            ['methods'=>WP_REST_Server::EDITABLE,'callback'=>[__CLASS__,'update_category'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::DELETABLE,'callback'=>[__CLASS__,'delete_category'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/categories/(?P<id>\d+)/duplicate', [
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'duplicate_category'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/categories/reorder', [
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'reorder_categories'],'permission_callback'=>[__CLASS__,'permission']],
        ]);

        register_rest_route(self::NS, '/tags', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'tags'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'create_tag'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
        register_rest_route(self::NS, '/tags/(?P<id>\d+)', [
            ['methods'=>WP_REST_Server::EDITABLE,'callback'=>[__CLASS__,'update_tag'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::DELETABLE,'callback'=>[__CLASS__,'delete_tag'],'permission_callback'=>[__CLASS__,'permission']],
        ]);

        register_rest_route(self::NS, '/settings', [
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'get_settings'],'permission_callback'=>[__CLASS__,'permission']],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'save_settings'],'permission_callback'=>[__CLASS__,'permission']],
        ]);
    }

    public static function products(WP_REST_Request $request): WP_REST_Response {
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $category = absint($request->get_param('category') ?? 0);
        $availability = sanitize_key($request->get_param('availability') ?? 'all');
        $visibility = sanitize_key($request->get_param('visibility') ?? 'all');
        $args = ['post_type'=>'product','post_status'=>['publish','draft','private'],'posts_per_page'=>-1,'orderby'=>['menu_order'=>'ASC','title'=>'ASC'],'order'=>'ASC','s'=> $search,'no_found_rows'=>true];
        if ($category) $args['tax_query'] = [['taxonomy'=>CMM_Core::product_taxonomy(),'field'=>'term_id','terms'=>$category]];
        $meta = [];
        if ($availability === 'available') $meta[]=['key'=>'available','value'=>'1','compare'=>'='];
        if ($availability === 'unavailable') $meta[]=['key'=>'available','value'=>'0','compare'=>'='];
        if ($visibility === 'visible') $meta[]=['key'=>'visible','value'=>'1','compare'=>'='];
        if ($visibility === 'hidden') $meta[]=['key'=>'visible','value'=>'0','compare'=>'='];
        if ($meta) { $meta['relation']='AND'; $args['meta_query']=$meta; }
        $q = new WP_Query($args);
        $items=[]; foreach($q->posts as $p) $items[]=CMM_Core::product_payload((int)$p->ID);
        usort($items, static fn($a,$b)=>($a['sort_order']<=>$b['sort_order']) ?: strcasecmp($a['name'],$b['name']));
        return rest_ensure_response($items);
    }

    public static function get_product(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);
        $p=get_post($id);
        if(!$p || $p->post_type!=='product') return new WP_Error('not_found','محصول پیدا نشد.',['status'=>404]);
        return rest_ensure_response(CMM_Core::product_payload($id));
    }

    private static function product_fields_from_request(WP_REST_Request $r): array {
        return [
            'name'=>sanitize_text_field($r->get_param('name') ?? ''),
            'description'=>sanitize_textarea_field($r->get_param('description') ?? ''),
            'price'=>CMM_Core::normalize_number($r->get_param('price')),
            'old_price'=>CMM_Core::normalize_number($r->get_param('old_price')),
            'available'=>filter_var($r->get_param('available'),FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'visible'=>filter_var($r->get_param('visible'),FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'featured'=>filter_var($r->get_param('featured'),FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'sort_order'=>$r->get_param('sort_order'),
            'tags'=>self::tags_from_request($r),
            'categories'=>is_array($r->get_param('categories')) ? array_map('absint',$r->get_param('categories')) : (is_string($r->get_param('categories')) ? array_map('absint',(json_decode($r->get_param('categories'), true) ?: [])) : []),
        ];
    }

    /** null = "tags" not sent, so the product keeps its current tags. */
    private static function tags_from_request(WP_REST_Request $r): ?array {
        $raw = $r->get_param('tags');
        if ($raw === null) { return null; }
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        return is_array($raw) ? array_map('absint', $raw) : [];
    }

    public static function create_product(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $f=self::product_fields_from_request($request);
        if($f['name']==='') return new WP_Error('invalid_name','نام محصول الزامی است.',['status'=>400]);
        if($f['price']==='' || !is_numeric($f['price']) || (float)$f['price']<0) return new WP_Error('invalid_price','قیمت معتبر وارد کنید.',['status'=>400]);
        $post_id=wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>$f['name']],true);
        if(is_wp_error($post_id)) return $post_id;
        self::save_product_fields($post_id,$f);
        $image=CMM_Core::upload_image();
        if(is_wp_error($image)){wp_delete_post($post_id,true);return $image;}
        if($image) CMM_Core::acf_update('product_image',$image,$post_id);
        return rest_ensure_response(CMM_Core::product_payload($post_id));
    }

    public static function update_product(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']); $p=get_post($id);
        if(!$p || $p->post_type!=='product') return new WP_Error('not_found','محصول پیدا نشد.',['status'=>404]);
        $f=self::product_fields_from_request($request);
        if($f['name']==='') return new WP_Error('invalid_name','نام محصول الزامی است.',['status'=>400]);
        if($f['price']!=='' && (!is_numeric($f['price']) || (float)$f['price']<0)) return new WP_Error('invalid_price','قیمت معتبر وارد کنید.',['status'=>400]);
        $updated=wp_update_post(['ID'=>$id,'post_title'=>$f['name']],true); if(is_wp_error($updated)) return $updated;
        self::save_product_fields($id,$f);
        $image=CMM_Core::upload_image();
        if(is_wp_error($image)) return $image;
        if($image) CMM_Core::acf_update('product_image',$image,$id);
        if($request->get_param('remove_image')) CMM_Core::acf_update('product_image',0,$id);
        return rest_ensure_response(CMM_Core::product_payload($id));
    }

    private static function save_product_fields(int $id,array $f):void{
        CMM_Core::acf_update('description',$f['description'],$id);
        if($f['price']!=='') CMM_Core::acf_update('price',(float)$f['price'],$id);
        CMM_Core::acf_update('available',$f['available']===null?true:$f['available'],$id);
        CMM_Core::acf_update('visible',$f['visible']===null?true:$f['visible'],$id);
        CMM_Core::acf_update('featured',$f['featured']===null?false:$f['featured'],$id);
        $order=$f['sort_order']===''||$f['sort_order']===null?CMM_Core::find_clear_sort_order():max(0,(int)$f['sort_order']);
        CMM_Core::acf_update('sort_order',$order,$id);
        wp_update_post(['ID'=>$id,'menu_order'=>$order]);
        if($f['old_price']===''||$f['old_price']===null) CMM_Core::acf_update('old_price','',$id); else CMM_Core::acf_update('old_price',max(0,(float)$f['old_price']),$id);
        CMM_Core::ensure_product_terms($id,$f['categories']);
        if($f['tags']!==null) CMM_Core::ensure_product_tags($id,$f['tags']);
    }

    public static function delete_product(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']); $p=get_post($id); if(!$p||$p->post_type!=='product') return new WP_Error('not_found','محصول پیدا نشد.',['status'=>404]);
        if(!wp_delete_post($id,true)) return new WP_Error('delete_failed','حذف انجام نشد.',['status'=>500]);
        return rest_ensure_response(['success'=>true,'id'=>$id]);
    }

    public static function duplicate_product(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);$p=get_post($id);if(!$p||$p->post_type!=='product')return new WP_Error('not_found','محصول پیدا نشد.',['status'=>404]);
        $new=wp_insert_post(['post_type'=>'product','post_status'=>$p->post_status,'post_title'=>$p->post_title.' — Copy'],true);if(is_wp_error($new))return $new;
        foreach(['description','price','old_price'] as $field) CMM_Core::acf_update($field,CMM_Core::acf_get($field,$id,''),$new);
        foreach(['available'=>true,'visible'=>true,'featured'=>false] as $field=>$default) CMM_Core::acf_update($field,CMM_Core::bool_meta($field,$id,$default),$new);
        $new_order=CMM_Core::find_clear_sort_order();
        CMM_Core::acf_update('sort_order',$new_order,$new);
        wp_update_post(['ID'=>$new,'menu_order'=>$new_order]);
        CMM_Core::ensure_product_terms($new,wp_get_post_terms($id,CMM_Core::product_taxonomy(),['fields'=>'ids']));
        CMM_Core::ensure_product_tags($new,wp_get_object_terms($id,'menu_tag',['fields'=>'ids']));
        $img=CMM_Core::product_image($id); if($img['id']) CMM_Core::acf_update('product_image',$img['id'],$new);
        return rest_ensure_response(CMM_Core::product_payload($new));
    }

    public static function reorder_products(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $ids=$request->get_param('ids');if(!is_array($ids))return new WP_Error('invalid_order','ترتیب نامعتبر است.',['status'=>400]);
        $rank=10; $updated=[]; foreach($ids as $id){$id=absint($id);$p=get_post($id);if($p&&$p->post_type==='product'){CMM_Core::acf_update('sort_order',$rank,$id);wp_update_post(['ID'=>$id,'menu_order'=>$rank]);$updated[]=$id;$rank+=10;}}
        return rest_ensure_response(['success'=>true,'updated'=>$updated]);
    }

    public static function categories(WP_REST_Request $request): WP_REST_Response {
        $terms=get_terms(['taxonomy'=>CMM_Core::product_taxonomy(),'hide_empty'=>false,'orderby'=>'meta_value_num','meta_key'=>'cmm_sort_order','order'=>'ASC']);
        if(is_wp_error($terms))return rest_ensure_response([]);
        usort($terms,static fn($a,$b)=>(intval(get_term_meta($a->term_id,'cmm_sort_order',true))<=>intval(get_term_meta($b->term_id,'cmm_sort_order',true))) ?: strcasecmp($a->name,$b->name));
        return rest_ensure_response(array_map([CMM_Core::class,'category_payload'],$terms));
    }

    public static function create_category(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $name=sanitize_text_field($request->get_param('name')??'');if($name==='')return new WP_Error('invalid_name','نام دسته الزامی است.',['status'=>400]);
        $r=wp_insert_term($name,CMM_Core::product_taxonomy(),['description'=>sanitize_textarea_field($request->get_param('description')??'')]);if(is_wp_error($r))return $r;
        $id=(int)$r['term_id'];self::save_category_fields($id,$request);return rest_ensure_response(CMM_Core::category_payload(get_term($id,CMM_Core::product_taxonomy())));
    }
    public static function update_category(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);if(!term_exists($id,CMM_Core::product_taxonomy()))return new WP_Error('not_found','دسته پیدا نشد.',['status'=>404]);
        $name=sanitize_text_field($request->get_param('name')??'');if($name==='')return new WP_Error('invalid_name','نام دسته الزامی است.',['status'=>400]);
        $r=wp_update_term($id,CMM_Core::product_taxonomy(),['name'=>$name,'description'=>sanitize_textarea_field($request->get_param('description')??'')]);if(is_wp_error($r))return $r;
        self::save_category_fields($id,$request);return rest_ensure_response(CMM_Core::category_payload(get_term($id,CMM_Core::product_taxonomy())));
    }
    private static function save_category_fields(int $id,WP_REST_Request $r):void{
        update_term_meta($id,'cmm_visible',$r->get_param('visible')===null?'1':($r->get_param('visible')?'1':'0'));
        update_term_meta($id,'cmm_sort_order',max(0,(int)($r->get_param('sort_order')??0)));
        $image=CMM_Core::upload_image();if(!is_wp_error($image)&&$image)update_term_meta($id,'cmm_image_id',$image);
        if($r->get_param('remove_image'))delete_term_meta($id,'cmm_image_id');
    }
    public static function delete_category(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);if(!term_exists($id,CMM_Core::product_taxonomy()))return new WP_Error('not_found','دسته پیدا نشد.',['status'=>404]);
        $term_count=(int)(get_term($id,CMM_Core::product_taxonomy())->count??0);
        if($term_count>0)return new WP_Error('category_not_empty','این دسته هنوز محصول دارد. ابتدا محصولات آن را جابه‌جا کنید.',['status'=>409]);
        $r=wp_delete_term($id,CMM_Core::product_taxonomy());if(is_wp_error($r)||!$r)return new WP_Error('delete_failed','حذف دسته انجام نشد.',['status'=>500]);
        return rest_ensure_response(['success'=>true,'id'=>$id]);
    }
    public static function duplicate_category(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);$t=get_term($id,CMM_Core::product_taxonomy());if(!$t||is_wp_error($t))return new WP_Error('not_found','دسته پیدا نشد.',['status'=>404]);
        $name=$t->name.' — Copy';$r=wp_insert_term($name,CMM_Core::product_taxonomy(),['description'=>$t->description]);if(is_wp_error($r))return $r;$new=(int)$r['term_id'];update_term_meta($new,'cmm_visible',get_term_meta($id,'cmm_visible',true)?:'1');update_term_meta($new,'cmm_sort_order',0);$img=absint(get_term_meta($id,'cmm_image_id',true));if($img)update_term_meta($new,'cmm_image_id',$img);return rest_ensure_response(CMM_Core::category_payload(get_term($new,CMM_Core::product_taxonomy())));
    }
    public static function reorder_categories(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $ids=$request->get_param('ids');if(!is_array($ids))return new WP_Error('invalid_order','ترتیب نامعتبر است.',['status'=>400]);$rank=10;$updated=[];foreach($ids as $id){$id=absint($id);if(term_exists($id,CMM_Core::product_taxonomy())){update_term_meta($id,'cmm_sort_order',$rank);$updated[]=$id;$rank+=10;}}return rest_ensure_response(['success'=>true,'updated'=>$updated]);
    }

    public static function tags(WP_REST_Request $request): WP_REST_Response {
        $terms=get_terms(['taxonomy'=>'menu_tag','hide_empty'=>false,'orderby'=>'term_id','order'=>'ASC']);
        if(is_wp_error($terms)) return rest_ensure_response([]);
        return rest_ensure_response(array_values(array_map([CMM_Core::class,'tag_payload'],$terms)));
    }

    public static function create_tag(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $name=sanitize_text_field($request->get_param('name')??'');
        if($name==='') return new WP_Error('invalid_name','نام تگ را وارد کنید.',['status'=>400]);
        $r=wp_insert_term($name,'menu_tag');
        if(is_wp_error($r)) return $r;
        $id=(int)$r['term_id'];
        update_term_meta($id,'cmm_color',CMM_Core::sanitize_tag_color($request->get_param('color')));
        return rest_ensure_response(CMM_Core::tag_payload(get_term($id,'menu_tag')));
    }

    public static function update_tag(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);
        if(!term_exists($id,'menu_tag')) return new WP_Error('not_found','تگ پیدا نشد.',['status'=>404]);
        $name=sanitize_text_field($request->get_param('name')??'');
        if($name==='') return new WP_Error('invalid_name','نام تگ را وارد کنید.',['status'=>400]);
        $r=wp_update_term($id,'menu_tag',['name'=>$name]);
        if(is_wp_error($r)) return $r;
        update_term_meta($id,'cmm_color',CMM_Core::sanitize_tag_color($request->get_param('color')));
        return rest_ensure_response(CMM_Core::tag_payload(get_term($id,'menu_tag')));
    }

    public static function delete_tag(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=absint($request['id']);
        if(!term_exists($id,'menu_tag')) return new WP_Error('not_found','تگ پیدا نشد.',['status'=>404]);
        $r=wp_delete_term($id,'menu_tag');
        if(is_wp_error($r)||!$r) return new WP_Error('delete_failed','حذف تگ انجام نشد.',['status'=>500]);
        return rest_ensure_response(['success'=>true,'id'=>$id]);
    }

    public static function get_settings(): WP_REST_Response { return rest_ensure_response(self::settings_payload()); }
    private static function settings_payload(): array { $logo=absint(get_option('cmm_logo_id',0));return ['cafe_name'=>(string)get_option('cmm_cafe_name',get_bloginfo('name')),'short_description'=>(string)get_option('cmm_short_description',''),'menu_url'=>CMM_Core::manager_url()===''?home_url('/menu/'):home_url('/menu/'),'menu_visible'=>get_option('cmm_menu_visible','1')==='1','logo'=>['id'=>$logo,'url'=>$logo?(string)wp_get_attachment_image_url($logo,'medium'):'']]; }
    public static function save_settings(WP_REST_Request $request): WP_REST_Response|WP_Error {
        update_option('cmm_cafe_name',sanitize_text_field($request->get_param('cafe_name')??''));update_option('cmm_short_description',sanitize_textarea_field($request->get_param('short_description')??''));update_option('cmm_menu_visible',$request->get_param('menu_visible')?'1':'0');
        $image=CMM_Core::upload_image('logo');if(is_wp_error($image))return $image;if($image)update_option('cmm_logo_id',$image);if($request->get_param('remove_logo'))delete_option('cmm_logo_id');
        return rest_ensure_response(self::settings_payload());
    }
}
