<?php
/**
 * GHN Checkout - Province/District/Ward dropdown selectors
 *
 * Works with both classic shortcode checkout and block-based checkout.
 */

defined('ABSPATH') || exit;

class GHN_Checkout {

    public function __construct() {
        // ── Classic checkout (shortcode [woocommerce_checkout]) ──
        // Hook into woocommerce_checkout_fields — the standard, works with ALL themes
        add_filter('woocommerce_checkout_fields', [$this, 'add_ghn_checkout_fields']);

        // Render the select fields (WooCommerce only renders text inputs from checkout_fields,
        // so we render selects manually after the shipping fields)
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'render_ghn_selects']);

        // Save to order meta
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_ghn_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_ghn_to_order'], 10, 2);

        // Display in admin order detail
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_in_admin']);

        // Enqueue JS on checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // ── Block-based checkout (WooCommerce Blocks) ──
        add_action('woocommerce_blocks_loaded', [$this, 'register_block_checkout_hooks']);

        // ── AJAX endpoints (logged-in + nopriv) ──
        add_action('wp_ajax_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_nopriv_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_nopriv_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_ghn_get_wards', [$this, 'ajax_get_wards']);
        add_action('wp_ajax_nopriv_ghn_get_wards', [$this, 'ajax_get_wards']);
    }

    /* ------------------------------------------------------------------
     *  Classic Checkout — Add fields
     * ----------------------------------------------------------------*/

    /**
     * Register GHN fields in WooCommerce checkout fields array.
     * This ensures they are recognized during validation & processing.
     */
    public function add_ghn_checkout_fields(array $fields): array {
        $fields['shipping']['ghn_province_id'] = [
            'type'     => 'select',
            'label'    => 'Tỉnh / Thành phố',
            'required' => true,
            'class'    => ['form-row-first ghn-field'],
            'priority' => 25,
            'options'  => ['' => '— Chọn tỉnh/thành —'],
        ];

        $fields['shipping']['ghn_district_id'] = [
            'type'     => 'select',
            'label'    => 'Quận / Huyện',
            'required' => true,
            'class'    => ['form-row-last ghn-field'],
            'priority' => 26,
            'options'  => ['' => '— Chọn quận/huyện —'],
        ];

        $fields['shipping']['ghn_ward_code'] = [
            'type'     => 'select',
            'label'    => 'Phường / Xã',
            'required' => true,
            'class'    => ['form-row-wide ghn-field'],
            'priority' => 27,
            'options'  => ['' => '— Chọn phường/xã —'],
        ];

        return $fields;
    }

    /**
     * Render the select dropdowns manually after the shipping form.
     * WooCommerce doesn't render <select> from checkout_fields options automatically
     * for custom fields, so we do it ourselves.
     */
    public function render_ghn_selects(WC_Checkout $checkout): void {
        // Check if GHN is configured
        $token   = get_option('ghn_token', '');
        $shop_id = get_option('ghn_shop_id', 0);
        if (empty($token) || !$shop_id) return;

        echo '<div id="ghn-address-fields" class="ghn-checkout-wrapper" style="margin-top:20px;">';
        echo '<h3 style="margin:0 0 12px; font-size:15px; font-weight:600;">📦 Khu vực giao hàng</h3>';

        // Province
        woocommerce_form_field('ghn_province_id', [
            'type'        => 'select',
            'label'       => 'Tỉnh / Thành phố',
            'required'    => true,
            'class'       => ['form-row-first'],
            'input_class' => ['ghn-select'],
            'options'     => ['' => '— Chọn tỉnh/thành —'],
        ], $checkout->get_value('ghn_province_id'));

        // District
        woocommerce_form_field('ghn_district_id', [
            'type'        => 'select',
            'label'       => 'Quận / Huyện',
            'required'    => true,
            'class'       => ['form-row-last'],
            'input_class' => ['ghn-select'],
            'options'     => ['' => '— Chọn quận/huyện —'],
        ], $checkout->get_value('ghn_district_id'));

        // Ward
        woocommerce_form_field('ghn_ward_code', [
            'type'        => 'select',
            'label'       => 'Phường / Xã',
            'required'    => true,
            'class'       => ['form-row-wide'],
            'input_class' => ['ghn-select'],
            'options'     => ['' => '— Chọn phường/xã —'],
        ], $checkout->get_value('ghn_ward_code'));

        echo '<div id="ghn-address-status" style="margin-top:6px; font-size:12px; color:#666;"></div>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Save to Order
     * ----------------------------------------------------------------*/

    /**
     * Save via checkout_create_order (runs before save, works with HPOS).
     */
    public function save_ghn_to_order(WC_Order $order, array $data): void {
        $province = sanitize_text_field($_POST['ghn_province_id'] ?? '');
        $district = sanitize_text_field($_POST['ghn_district_id'] ?? '');
        $ward     = sanitize_text_field($_POST['ghn_ward_code'] ?? '');

        if ($province) $order->update_meta_data('_ghn_province_id', $province);
        if ($district) $order->update_meta_data('_ghn_district_id', $district);
        if ($ward)     $order->update_meta_data('_ghn_ward_code', $ward);
    }

    /**
     * Fallback save via update_order_meta (legacy).
     */
    public function save_ghn_fields(int $order_id): void {
        if (!empty($_POST['ghn_province_id'])) {
            update_post_meta($order_id, '_ghn_province_id', sanitize_text_field($_POST['ghn_province_id']));
        }
        if (!empty($_POST['ghn_district_id'])) {
            update_post_meta($order_id, '_ghn_district_id', sanitize_text_field($_POST['ghn_district_id']));
        }
        if (!empty($_POST['ghn_ward_code'])) {
            update_post_meta($order_id, '_ghn_ward_code', sanitize_text_field($_POST['ghn_ward_code']));
        }
    }

    /* ------------------------------------------------------------------
     *  Admin Display
     * ----------------------------------------------------------------*/

    public function display_in_admin(WC_Order $order): void {
        $province = $order->get_meta('_ghn_province_id');
        $district = $order->get_meta('_ghn_district_id');
        $ward     = $order->get_meta('_ghn_ward_code');

        if (!$province && !$district && !$ward) return;

        echo '<div style="margin-top:8px; padding:8px; background:#f0f6fc; border-left:3px solid #2271b1; font-size:12px;">';
        echo '<strong>📦 GHN Area Codes:</strong><br>';
        if ($province) echo 'Province: <code>' . esc_html($province) . '</code><br>';
        if ($district) echo 'District: <code>' . esc_html($district) . '</code><br>';
        if ($ward)     echo 'Ward: <code>' . esc_html($ward) . '</code>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Block Checkout Support (WooCommerce Blocks)
     * ----------------------------------------------------------------*/

    public function register_block_checkout_hooks(): void {
        if (!class_exists('Automattic\WooCommerce\Blocks\Package')) return;

        $store_api = function_exists('woocommerce_store_api_register_endpoint_data')
            ? 'woocommerce_store_api_register_endpoint_data'
            : null;

        if ($store_api) {
            // Register data in Store API
            add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order, $request) {
                $province = $request->get_meta('ghn_province_id') ?? '';
                $district = $request->get_meta('ghn_district_id') ?? '';
                $ward     = $request->get_meta('ghn_ward_code') ?? '';

                if ($province) $order->update_meta_data('_ghn_province_id', sanitize_text_field($province));
                if ($district) $order->update_meta_data('_ghn_district_id', sanitize_text_field($district));
                if ($ward)     $order->update_meta_data('_ghn_ward_code', sanitize_text_field($ward));
            }, 10, 2);
        }

        // Also render via woocommerce_after_shipping_address as block fallback
        add_action('woocommerce_after_shipping_address', function () {
            if (!is_checkout()) return;
            // Only render if our select fields weren't already rendered (block checkout)
            if (did_action('woocommerce_after_checkout_shipping_form')) return;
            $this->render_ghn_selects(WC()->checkout());
        });
    }

    /* ------------------------------------------------------------------
     *  AJAX Handlers
     * ----------------------------------------------------------------*/

    public function ajax_get_provinces(): void {
        check_ajax_referer('ghn_checkout_nonce', 'nonce');

        $api    = new GHN_API();
        $result = $api->get_districts();

        if ($result['success'] && is_array($result['data'])) {
            $provinces = [];
            foreach ($result['data'] as $d) {
                $pid = $d['ProvinceID'];
                if (!isset($provinces[$pid])) {
                    $provinces[$pid] = [
                        'id'   => $pid,
                        'name' => $d['ProvinceName'] ?? "Province $pid",
                    ];
                }
            }
            usort($provinces, fn($a, $b) => strcmp($a['name'], $b['name']));
            wp_send_json_success($provinces);
        } else {
            wp_send_json_error($result['message'] ?? 'Không lấy được danh sách tỉnh/thành.');
        }
    }

    public function ajax_get_districts(): void {
        check_ajax_referer('ghn_checkout_nonce', 'nonce');

        $province_id = absint($_GET['province_id'] ?? 0);
        if (!$province_id) wp_send_json_error('Thiếu province_id.');

        $api    = new GHN_API();
        $result = $api->get_districts();

        if ($result['success'] && is_array($result['data'])) {
            $districts = [];
            foreach ($result['data'] as $d) {
                if ((int) $d['ProvinceID'] === $province_id) {
                    $districts[] = [
                        'id'          => $d['DistrictID'],
                        'name'        => $d['DistrictName'],
                        'supportType' => $d['SupportType'] ?? 0,
                    ];
                }
            }
            usort($districts, fn($a, $b) => strcmp($a['name'], $b['name']));
            wp_send_json_success($districts);
        } else {
            wp_send_json_error($result['message'] ?? 'Không lấy được danh sách quận/huyện.');
        }
    }

    public function ajax_get_wards(): void {
        check_ajax_referer('ghn_checkout_nonce', 'nonce');

        $district_id = absint($_GET['district_id'] ?? 0);
        if (!$district_id) wp_send_json_error('Thiếu district_id.');

        $api    = new GHN_API();
        $result = $api->get_wards($district_id);

        if ($result['success'] && is_array($result['data'])) {
            $wards = [];
            foreach ($result['data'] as $w) {
                $wards[] = [
                    'code' => $w['WardCode'],
                    'name' => $w['WardName'],
                ];
            }
            usort($wards, fn($a, $b) => strcmp($a['name'], $b['name']));
            wp_send_json_success($wards);
        } else {
            wp_send_json_error($result['message'] ?? 'Không lấy được danh sách phường/xã.');
        }
    }

    /* ------------------------------------------------------------------
     *  Scripts
     * ----------------------------------------------------------------*/

    public function enqueue_scripts(): void {
        if (!is_checkout()) return;

        $token   = get_option('ghn_token', '');
        $shop_id = get_option('ghn_shop_id', 0);
        if (empty($token) || !$shop_id) return;

        wp_enqueue_style('ghn-checkout', GHN_PLUGIN_URL . 'assets/checkout.css', [], GHN_VERSION);
        wp_enqueue_script('ghn-checkout', GHN_PLUGIN_URL . 'assets/checkout.js', ['jquery'], GHN_VERSION, true);
        wp_localize_script('ghn-checkout', 'ghnCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ghn_checkout_nonce'),
        ]);
    }
}
