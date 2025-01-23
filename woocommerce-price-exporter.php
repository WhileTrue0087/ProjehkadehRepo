<?php
/*
Plugin Name: WooCommerce Product Price Updater
Description: Automatically updates WooCommerce product prices to IRR based on the admin-defined USD price and the latest USD exchange rate.
Version: 2.0
Author: Taha
*/

// Hook into plugin activation
register_activation_hook(__FILE__, 'wc_price_updater_on_activation');

function wc_price_updater_on_activation() {
    wc_update_product_prices();
}

// Add a manual admin menu for testing
add_action('admin_menu', 'wc_price_updater_menu');

function wc_price_updater_menu() {
    add_menu_page(
        'Price Updater',
        'Price Updater',
        'manage_options',
        'wc-price-updater',
        'wc_price_updater_page',
        'dashicons-update',
        20
    );
}

function wc_price_updater_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['update_prices'])) {
        wc_update_product_prices();
    }

    if (isset($_POST['export_prices'])) {
        wc_export_product_prices();
    }

    echo '<div class="wrap">';
    echo '<h1>WooCommerce Product Price Updater</h1>';
    echo '<form method="post">';
    echo '<p>Click the button below to manually update product prices to IRR based on the admin-defined USD price and current exchange rate.</p>';
    echo '<button type="submit" name="update_prices" class="button button-primary">Update Prices</button>';
    echo '</form>';
    echo '<form method="post" style="margin-top: 20px;">';
    echo '<p>Click the button below to export the product list with updated prices in IRR to a text file.</p>';
    echo '<button type="submit" name="export_prices" class="button button-secondary">Export Prices</button>';
    echo '</form>';
    echo '</div>';
}

// Add custom field for _original_usd_price
add_action('woocommerce_product_options_pricing', 'add_original_usd_price_field');
function add_original_usd_price_field() {
    woocommerce_wp_text_input(array(
        'id' => '_original_usd_price',
        'label' => __('Original USD Price', 'woocommerce'),
        'desc_tip' => 'true',
        'description' => __('Enter the original price in USD.', 'woocommerce'),
    ));
}

// Save custom field value
add_action('woocommerce_admin_process_product_object', 'save_original_usd_price_field');
function save_original_usd_price_field($product) {
    if (isset($_POST['_original_usd_price'])) {
        $product->update_meta_data('_original_usd_price', sanitize_text_field($_POST['_original_usd_price']));
    }
}

// Update product prices in the database based on admin-defined USD price
function wc_update_product_prices() {
    $latest_exchange_rate = wc_get_usd_exchange_rate();

    if (!$latest_exchange_rate) {
        error_log('Failed to retrieve the USD exchange rate.');
        return;
    }

    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
    ));

    foreach ($products as $product) {
        $product_id = $product->get_id();
        $original_usd_price = get_post_meta($product_id, '_original_usd_price', true); // USD price set by admin

        if ($original_usd_price) {
            // Calculate IRR price using admin-defined USD price
            $price_irr = $original_usd_price * $latest_exchange_rate;

            // Update product price in IRR
            $product->set_price($price_irr);
            $product->set_regular_price($price_irr);
            $product->save();

            // Store the latest exchange rate used
            update_post_meta($product_id, '_exchange_rate_used', $latest_exchange_rate);
        }
    }

    wc_delete_product_transients();
    error_log('Product prices updated successfully to IRR.');
}

// Export product prices to a text file
function wc_export_product_prices() {
    $latest_exchange_rate = wc_get_usd_exchange_rate();

    if (!$latest_exchange_rate) {
        error_log('Failed to retrieve the USD exchange rate.');
        return;
    }

    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
    ));

    $file_path = wp_upload_dir()['basedir'] . '/product_prices_list.txt';
    $file = fopen($file_path, 'w');

    if (!$file) {
        error_log('Failed to create the file.');
        return;
    }

    // Write the latest exchange rate at the top of the file
    fwrite($file, mb_convert_encoding("آخرین نرخ ارز: " . $latest_exchange_rate . " ریال\n\n", 'UTF-8', 'auto'));

    // Write product prices in IRR
    foreach ($products as $product) {
        $title = $product->get_name();
        $price_irr = $product->get_regular_price();
        fwrite($file, mb_convert_encoding("$title: $price_irr IRR\n", 'UTF-8', 'auto'));
    }

    // Add a separator
    fwrite($file, "\n");

    // Write original USD prices
    fwrite($file, mb_convert_encoding("قیمت‌های اصلی به دلار:\n", 'UTF-8', 'auto'));
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $title = $product->get_name();
        $original_usd_price = get_post_meta($product_id, '_original_usd_price', true);
        if ($original_usd_price) {
            fwrite($file, mb_convert_encoding("$title: $original_usd_price USD\n", 'UTF-8', 'auto'));
        }
    }

    fclose($file);

    $file_url = wp_upload_dir()['baseurl'] . '/product_prices_list.txt';
    echo '<div class="notice notice-success"><p>Product prices exported successfully. <a href="' . esc_url($file_url) . '" target="_blank">Download the file here</a>.</p></div>';
}

// Get the current USD exchange rate via an API
function wc_get_usd_exchange_rate() {
    $api_url = 'https://v6.exchangerate-api.com/v6/744a67eac9b267d8f4d15815/latest/USD';

    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['conversion_rates']['IRR'])) { // Assuming IRR is the target currency
        return floatval($data['conversion_rates']['IRR']);
    }

    return false;
}

// Display IRR price on the frontend
add_filter('woocommerce_get_price_html', 'wc_display_irr_price', 10, 2);

function wc_display_irr_price($price, $product) {
    $price_irr = $product->get_price();
    if ($price_irr) {
        return number_format($price_irr, 0, '.', ',') . ' ریال';
    }
    return $price;
}

// تغییر قیمت‌ها در مینی‌کارت
add_filter('woocommerce_cart_item_price', 'wc_display_irr_price_mini_cart', 10, 3);

function wc_display_irr_price_mini_cart($price, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $price_irr = $product->get_price();
    if ($price_irr) {
        return number_format($price_irr, 0, '.', ',') . ' ریال';
    }
    return $price;
}

// تغییر جمع کل در مینی‌کارت
add_filter('woocommerce_cart_item_subtotal', 'wc_display_irr_subtotal_mini_cart', 10, 3);

function wc_display_irr_subtotal_mini_cart($subtotal, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $price_irr = $product->get_price() * $cart_item['quantity'];
    if ($price_irr) {
        return number_format($price_irr, 0, '.', ',') . ' ریال';
    }
    return $subtotal;
}
