<?php
/**
 * Plugin Name: Category Discount Manager
 * Description: Zarządzanie promocjami dla wybranych kategorii w WooCommerce.
 * Version: 1.0
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
            'singular_name' => 'Category Discount',
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

// Dodawanie metaboxów do edycji promocji
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

// Zapisywanie danych promocji
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

    // Wywołanie aktualizacji cen promocyjnych
    cdm_update_sale_price();
}

// Funkcja do aktualizacji cen promocyjnych produktów
function cdm_update_sale_price() {
    $discounts = get_posts([
        'post_type' => 'category_discount',
        'post_status' => 'publish',
        'numberposts' => -1,
    ]);

    $now = current_time('Y-m-d');

    foreach ($discounts as $discount) {
        $category = get_post_meta($discount->ID, 'cdm_category', true);
        $percent = (float) get_post_meta($discount->ID, 'cdm_discount', true);
        $start_date = get_post_meta($discount->ID, 'cdm_start_date', true);
        $end_date = get_post_meta($discount->ID, 'cdm_end_date', true);
        $unlimited = get_post_meta($discount->ID, 'cdm_unlimited', true);

        if (!$start_date) continue;

        $current_date = strtotime($now);
        $start_timestamp = strtotime($start_date);
        $end_timestamp = $unlimited === '1' ? PHP_INT_MAX : strtotime($end_date . ' 23:59:59');

        if ($current_date >= $start_timestamp && $current_date <= $end_timestamp) {
            $args = [
                'post_type' => 'product',
                'posts_per_page' => -1,
                'tax_query' => [
                    [
                        'taxonomy' => 'product_cat',
                        'field' => 'id',
                        'terms' => $category,
                    ],
                ],
            ];

            $products = get_posts($args);

            foreach ($products as $product) {
                $product_id = $product->ID;
                $product_obj = wc_get_product($product_id);
                $regular_price = $product_obj->get_regular_price();

                if ($regular_price) {
                    $sale_price = $regular_price - ($regular_price * ($percent / 100));
                    $product_obj->set_sale_price($sale_price);
                    $product_obj->save();
                }
            }
        }
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
?>
