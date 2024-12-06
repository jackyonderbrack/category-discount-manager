<?php
/**
 * Plugin Name: Category Discount Manager
 * Description: Zarządzanie promocjami dla wybranych kategorii w WooCommerce.
 * Version: 1.0.5
 * Author: Michał Łuczak
 * Text Domain: category-discount
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'cdm_register_discount_post_type');

function cdm_register_discount_post_type() {
    register_post_type('category_discount', [
        'labels' => [
            'name' => 'Promocje',
            'singular_name' => 'Promocja',
            'add_new' => 'Dodaj nową promocję',
            'add_new_item' => 'Dodaj nową promocję',
            'edit_item' => 'Edytuj promocję',
            'new_item' => 'Nowa promocja',
            'view_item' => 'Zobacz promocję',
            'search_items' => 'Szukaj promocji',
        ],
        'public' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'has_archive' => false,
    ]);
}

add_action('add_meta_boxes', 'cdm_add_discount_meta_boxes');

function cdm_add_discount_meta_boxes() {
    add_meta_box('cdm_discount_meta', 'Szczegóły zniżki', 'cdm_discount_meta_box_callback', 'category_discount', 'normal', 'high');
}

function cdm_discount_meta_box_callback($post) {
    $category = get_post_meta($post->ID, 'cdm_category', true);
    $discount = get_post_meta($post->ID, 'cdm_discount', true);
    $start_date = get_post_meta($post->ID, 'cdm_start_date', true);
    $end_date = get_post_meta($post->ID, 'cdm_end_date', true);
    $unlimited = get_post_meta($post->ID, 'cdm_unlimited', true);

    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    ?>
    <p>
        <label for="cdm_category">Kategoria:</label>
        <select name="cdm_category" id="cdm_category" required>
            <option value="">Wybierz kategorię</option>
            <?php foreach ($categories as $cat) : ?>
                <option value="<?php echo $cat->term_id; ?>" <?php selected($category, $cat->term_id); ?>>
                    <?php echo $cat->name; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="cdm_discount">Procentowa Zniżka (%):</label>
        <input type="number" name="cdm_discount" id="cdm_discount" value="<?php echo esc_attr($discount); ?>" min="1" max="100" required>
    </p>
    <p>
        <label for="cdm_start_date">Data Początku:</label>
        <input type="date" name="cdm_start_date" id="cdm_start_date" value="<?php echo esc_attr($start_date); ?>" required>
    </p>
    <p>
        <label for="cdm_end_date">Data Końca:</label>
        <input type="date" name="cdm_end_date" id="cdm_end_date" value="<?php echo esc_attr($end_date); ?>" <?php if ($unlimited) echo 'disabled'; ?>>
    </p>
    <p>
        <label for="cdm_unlimited">
            <input type="checkbox" name="cdm_unlimited" id="cdm_unlimited" value="1" <?php checked($unlimited, '1'); ?>>
            Czas nieograniczony
        </label>
    </p>
    <script>
        (function($){
            $('#cdm_unlimited').change(function(){
                if($(this).is(':checked')){
                    $('#cdm_end_date').attr('disabled', true);
                } else {
                    $('#cdm_end_date').attr('disabled', false);
                }
            });
        })(jQuery);
    </script>
    <?php
}

add_action('save_post', 'cdm_save_discount_meta');

function cdm_save_discount_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['cdm_category']) || !isset($_POST['cdm_discount'])) return;

    update_post_meta($post_id, 'cdm_category', sanitize_text_field($_POST['cdm_category']));
    update_post_meta($post_id, 'cdm_discount', floatval($_POST['cdm_discount']));
    update_post_meta($post_id, 'cdm_start_date', sanitize_text_field($_POST['cdm_start_date']));

    $unlimited = isset($_POST['cdm_unlimited']) ? '1' : '';
    update_post_meta($post_id, 'cdm_unlimited', $unlimited);

    if ($unlimited !== '1') {
        update_post_meta($post_id, 'cdm_end_date', sanitize_text_field($_POST['cdm_end_date']));
    } else {
        delete_post_meta($post_id, 'cdm_end_date');
    }

    cdm_update_sale_prices();
}

function cdm_update_sale_prices($deleted_category = null) {
    $args = [
        'post_type' => 'category_discount',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ];
    $promotions = get_posts($args);

    $active_promotions = [];
    foreach ($promotions as $promo) {
        $cat_id = get_post_meta($promo->ID, 'cdm_category', true);
        $discount = (float) get_post_meta($promo->ID, 'cdm_discount', true);
        $start_date = get_post_meta($promo->ID, 'cdm_start_date', true);
        $unlimited = get_post_meta($promo->ID, 'cdm_unlimited', true);
        $end_date = $unlimited === '1' ? '' : get_post_meta($promo->ID, 'cdm_end_date', true);

        $active_promotions[$cat_id] = [
            'discount' => $discount,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'unlimited' => ($unlimited === '1')
        ];
    }

    $categories_to_update = $deleted_category ? [$deleted_category] : (empty($active_promotions) ? [] : array_keys($active_promotions));

    if ($deleted_category && !isset($active_promotions[$deleted_category])) {
        $categories_to_update[] = $deleted_category;
    }

    $categories_to_update = array_unique($categories_to_update);

    foreach ($categories_to_update as $cat_id) {
        $product_args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $cat_id,
                ],
            ],
        ];

        $products = get_posts($product_args);

        foreach ($products as $product_post) {
            $product_id = $product_post->ID;
            $product = wc_get_product($product_id);

            if (!$product) continue;

            $regular_price = $product->get_regular_price();
            if (!$regular_price) continue;

            if (isset($active_promotions[$cat_id])) {
                $promo = $active_promotions[$cat_id];
                $discounted_price = $regular_price - ($regular_price * ($promo['discount'] / 100));
                $discounted_price = round($discounted_price, 2);

                update_post_meta($product_id, '_sale_price', $discounted_price);
                update_post_meta($product_id, '_price', $discounted_price);

                $start_timestamp = $promo['start_date'] ? strtotime($promo['start_date']) : '';
                $end_timestamp = $promo['end_date'] ? strtotime($promo['end_date']) : '';

                if ($start_timestamp) {
                    update_post_meta($product_id, '_sale_price_dates_from', $start_timestamp);
                } else {
                    delete_post_meta($product_id, '_sale_price_dates_from');
                }

                if (!$promo['unlimited'] && $end_timestamp) {
                    update_post_meta($product_id, '_sale_price_dates_to', $end_timestamp);
                } else {
                    delete_post_meta($product_id, '_sale_price_dates_to');
                }
            } else {
                delete_post_meta($product_id, '_sale_price');
                update_post_meta($product_id, '_price', $regular_price);
                delete_post_meta($product_id, '_sale_price_dates_from');
                delete_post_meta($product_id, '_sale_price_dates_to');
            }

            wc_delete_product_transients($product_id);
        }
    }
}

add_action('wp_trash_post', 'cdm_update_sale_prices_after_trash');

function cdm_update_sale_prices_after_trash($post_id) {
    if (get_post_type($post_id) === 'category_discount') {
        $trashed_category = get_post_meta($post_id, 'cdm_category', true);
        cdm_update_sale_prices($trashed_category);
    }
}

add_filter('redirect_post_location', 'cdm_redirect_to_promotions_list', 10, 2);

function cdm_redirect_to_promotions_list($location, $post_id) {
    $post_type = get_post_type($post_id);

    if ($post_type === 'category_discount' && isset($_POST['save'])) {
        $location = admin_url('edit.php?post_type=category_discount');
    }

    return $location;
}
