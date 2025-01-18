<?php
/*
Plugin Name: WooCommerce Product Price Updater
Description: Automatically updates WooCommerce product prices to IRR based on USD exchange rate when the plugin is activated and ensures all products are up-to-date.
Version: 1.9
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
    echo '<p>Click the button below to manually update product prices to IRR based on the current USD exchange rate.</p>';
    echo '<button type="submit" name="update_prices" class="button button-primary">Update Prices</button>';
    echo '</form>';
    echo '<form method="post" style="margin-top: 20px;">';
    echo '<p>Click the button below to export the product list with updated prices in IRR to a text file.</p>';
    echo '<button type="submit" name="export_prices" class="button button-secondary">Export Prices</button>';
    echo '</form>';
    echo '</div>';
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

    foreach ($products as $product) {
        $product_id = $product->get_id();
        $original_usd_price = get_post_meta($product_id, '_original_usd_price', true);
        $stored_exchange_rate = get_post_meta($product_id, '_exchange_rate_used', true);

        if ($original_usd_price && $latest_exchange_rate != $stored_exchange_rate) {
            $price_irr = $original_usd_price * $latest_exchange_rate;
            $product->set_price($price_irr);
            $product->set_regular_price($price_irr);
            $product->save();
            update_post_meta($product_id, '_exchange_rate_used', $latest_exchange_rate);
        }
    }

    // Export the updated prices
    $file_path = wp_upload_dir()['basedir'] . '/product_prices_list.txt';
    $file = fopen($file_path, 'w');

    if (!$file) {
        error_log('Failed to create the file.');
        return;
    }

    foreach ($products as $product) {
        $title = $product->get_name();
        $price_irr = $product->get_regular_price();
        fwrite($file, mb_convert_encoding("$title: $price_irr IRR\n", 'UTF-8', 'auto'));
    }

    fclose($file);

    $file_url = wp_upload_dir()['baseurl'] . '/product_prices_list.txt';
    echo '<div class="notice notice-success"><p>Product prices exported successfully. <a href="' . esc_url($file_url) . '" target="_blank">Download the file here</a>.</p></div>';
}

// Handle USD price changes in admin
add_action('woocommerce_product_save', 'update_original_usd_price');

function update_original_usd_price($product_id) {
    $product = wc_get_product($product_id);
    $current_price = $product->get_regular_price();
    update_post_meta($product_id, '_original_usd_price', $current_price);
}

// Update product prices in the database
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
        $current_price = $product->get_regular_price();
        $original_usd_price = get_post_meta($product_id, '_original_usd_price', true);
        $stored_exchange_rate = get_post_meta($product_id, '_exchange_rate_used', true);

        if (empty($original_usd_price)) {
            // Set the original USD price if not set
            update_post_meta($product_id, '_original_usd_price', $current_price);
            $original_usd_price = $current_price;
        }

        if ($current_price != $original_usd_price) {
            // Update the original USD price if the product price has changed
            update_post_meta($product_id, '_original_usd_price', $current_price);
            $original_usd_price = $current_price;
        }

        if ($latest_exchange_rate != $stored_exchange_rate) {
            // Convert to IRR using the new exchange rate
            $price_irr = $original_usd_price * $latest_exchange_rate;
            $product->set_price($price_irr);
            $product->set_regular_price($price_irr);
            $product->save();
            update_post_meta($product_id, '_exchange_rate_used', $latest_exchange_rate);
        }
    }

    wc_delete_product_transients();
    error_log('Product prices updated successfully to IRR.');
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

// Clear cache after price update
function wc_clear_cache_after_price_update() {
    wc_delete_product_transients();
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

// Change currency symbol to "ریال"
add_filter('woocommerce_currency_symbol', 'change_currency_symbol', 10, 2);

function change_currency_symbol($currency_symbol, $currency) {
    if ($currency === 'IRR') {
        $currency_symbol = 'ریال';
    }
    return $currency_symbol;
}

// Format prices in IRR
add_filter('woocommerce_price_format', 'format_prices_in_irr');

function format_prices_in_irr($format) {
    $currency_pos = get_option('woocommerce_currency_pos');
    switch ($currency_pos) {
        case 'left':
            $format = '%1$s%2$s';
            break;
        case 'right':
            $format = '%2$s%1$s';
            break;
        case 'left_space':
            $format = '%1$s %2$s';
            break;
        case 'right_space':
            $format = '%2$s %1$s';
            break;
    }
    return $format;
}

// Set IRR as the default currency (optional)
add_filter('woocommerce_currency', 'set_default_currency_to_irr');

function set_default_currency_to_irr($currency) {
    return 'IRR';
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

// Scheduled event to ensure products are updated daily
if (!wp_next_scheduled('wc_daily_price_update_event')) {
    wp_schedule_event(time(), 'daily', 'wc_daily_price_update_event');
}

add_action('wc_daily_price_update_event', 'wc_update_product_prices');