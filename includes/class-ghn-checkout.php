<?php
/**
 * GHN Checkout - Province/District/Ward dropdown selectors
 *
 * Enqueues AJAX-powered dropdowns on WooCommerce checkout
 * so customers select valid GHN administrative codes.
 */

defined('ABSPATH') || exit;

class GHN_Checkout {

    public function __construct() {
        // Add fields after shipping address on checkout
        add_action('woocommerce_after_shipping_address', [$this, 'render_ghn_fields']);

        // Save to order meta
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_ghn_fields']);

        // Display in admin order detail
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_in_admin']);

        // Enqueue JS on checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX endpoints (logged-in + nopriv)
        add_action('wp_ajax_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_nopriv_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_nopriv_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_ghn_get_wards', [$this, 'ajax_get_wards']);
        add_action('wp_ajax_nopriv_ghn_get_wards', [$this, 'ajax_get_wards']);
    }

    /* ------------------------------------------------------------------
     *  Checkout Fields
     * ----------------------------------------------------------------*/

    public function render_ghn_fields(): void {
        echo '<div id="ghn-address-fields" style="margin-top:16px; padding:12px; background:#f8f9fa; border:1px solid #e0e0e0; border-radius:6px;">';
        echo '<h4 style="margin:0 0 12px; font-size:14px;">📦 Thông tin khu vực (GHN)</h4>';

        // Province
        woocommerce_form_field('ghn_province_id', [
            'type'     => 'select',
            'label'    => 'Tỉnh / Thành phố',
            'required' => true,
            'class'    => ['form-row-first'],
            'options'  => ['' => '— Chọn tỉnh/thành —'],
        ]);

        // District
        woocommerce_form_field('ghn_district_id', [
            'type'     => 'select',
            'label'    => 'Quận / Huyện',
            'required' => true,
            'class'    => ['form-row-last'],
            'options'  => ['' => '— Chọn quận/huyện —'],
        ]);

        // Ward
        woocommerce_form_field('ghn_ward_code', [
            'type'     => 'select',
            'label'    => 'Phường / Xã',
            'required' => true,
            'class'    => ['form-row-wide'],
            'options'  => ['' => '— Chọn phường/xã —'],
        ]);

        echo '<div id="ghn-address-status" style="margin-top:8px; font-size:12px; color:#666;"></div>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  AJAX Handlers (proxy to GHN API — token stays server-side)
     * ----------------------------------------------------------------*/

    public function ajax_get_provinces(): void {
        $api    = new GHN_API();
        $result = $api->get_districts(); // GHN combines province+district

        if ($result['success'] && is_array($result['data'])) {
            // Group by Province
            $provinces = [];
            foreach ($result['data'] as $d) {
                $pid  = $d['ProvinceID'];
                $pname = $d['ProvinceName'] ?? ("Province $pid");
                if (!isset($provinces[$pid])) {
                    $provinces[$pid] = [
                        'id'   => $pid,
                        'name' => $pname,
                    ];
                }
            }
            // Sort by name
            usort($provinces, fn($a, $b) => strcmp($a['name'], $b['name']));
            wp_send_json_success($provinces);
        } else {
            wp_send_json_error($result['message'] ?? 'Không lấy được danh sách tỉnh/thành.');
        }
    }

    public function ajax_get_districts(): void {
        $province_id = absint($_GET['province_id'] ?? 0);
        if (!$province_id) {
            wp_send_json_error('Thiếu province_id.');
        }

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
        $district_id = absint($_GET['district_id'] ?? 0);
        if (!$district_id) {
            wp_send_json_error('Thiếu district_id.');
        }

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
     *  Save & Display
     * ----------------------------------------------------------------*/

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

    public function display_in_admin(WC_Order $order): void {
        $province = $order->get_meta('_ghn_province_id');
        $district = $order->get_meta('_ghn_district_id');
        $ward     = $order->get_meta('_ghn_ward_code');

        if (!$province && !$district && !$ward) return;

        echo '<div style="margin-top:8px; padding:8px; background:#f0f6fc; border-left:3px solid #2271b1; font-size:12px;">';
        echo '<strong>📦 GHN Codes:</strong><br>';
        if ($province) echo 'Province: <code>' . esc_html($province) . '</code><br>';
        if ($district) echo 'District: <code>' . esc_html($district) . '</code><br>';
        if ($ward)     echo 'Ward: <code>' . esc_html($ward) . '</code>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Scripts
     * ----------------------------------------------------------------*/

    public function enqueue_scripts(): void {
        if (!is_checkout()) return;

        $token   = get_option('ghn_token', '');
        $shop_id = get_option('ghn_shop_id', 0);
        if (empty($token) || !$shop_id) return;

        wp_enqueue_script('ghn-checkout', GHN_PLUGIN_URL . 'assets/checkout.js', ['jquery'], GHN_VERSION, true);
        wp_localize_script('ghn-checkout', 'ghnCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ghn_checkout_nonce'),
        ]);
    }
}
