<?php
/**
 * GHN Checkout - Province/District/Ward dropdown selectors
 *
 * Cascading dropdowns that sync to WooCommerce standard shipping fields.
 * Works with both classic shortcode checkout and block-based checkout.
 */

defined('ABSPATH') || exit;

class GHN_Checkout {

    public function __construct() {
        /* ── Classic checkout (shortcode [woocommerce_checkout]) ── */
        add_filter('woocommerce_checkout_fields', [$this, 'add_ghn_checkout_fields']);
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'render_ghn_selects']);

        /* ── Save to order meta ── */
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_ghn_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_ghn_to_order'], 10, 2);

        /* ── Validate: require GHN fields ── */
        add_action('woocommerce_checkout_process', [$this, 'validate_ghn_fields']);

        /* ── Display in admin order detail ── */
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_in_admin']);

        /* ── Enqueue JS on checkout ── */
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        /* ── Block-based checkout (WooCommerce Blocks) ── */
        add_action('woocommerce_blocks_loaded', [$this, 'register_block_checkout_hooks']);

        /* ── AJAX endpoints (logged-in + nopriv) ── */
        add_action('wp_ajax_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_nopriv_ghn_get_provinces', [$this, 'ajax_get_provinces']);
        add_action('wp_ajax_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_nopriv_ghn_get_districts', [$this, 'ajax_get_districts']);
        add_action('wp_ajax_ghn_get_wards', [$this, 'ajax_get_wards']);
        add_action('wp_ajax_nopriv_ghn_get_wards', [$this, 'ajax_get_wards']);
    }

    /* ------------------------------------------------------------------
     *  Classic Checkout — Add hidden fields for GHN meta
     * ----------------------------------------------------------------*/

    public function add_ghn_checkout_fields(array $fields): array {
        /* Hidden inputs to carry GHN IDs (not visible, just data carriers) */
        $fields['shipping']['ghn_province_id'] = [
            'type'     => 'hidden',
            'label'    => false,
            'required' => false,
            'class'    => ['ghn-field-hidden'],
            'priority' => 100,
        ];
        $fields['shipping']['ghn_district_id'] = [
            'type'     => 'hidden',
            'label'    => false,
            'required' => false,
            'class'    => ['ghn-field-hidden'],
            'priority' => 101,
        ];
        $fields['shipping']['ghn_ward_code'] = [
            'type'     => 'hidden',
            'label'    => false,
            'required' => false,
            'class'    => ['ghn-field-hidden'],
            'priority' => 102,
        ];
        return $fields;
    }

    /* ------------------------------------------------------------------
     *  Render the GHN Select Dropdowns
     * ----------------------------------------------------------------*/

    public function render_ghn_selects(WC_Checkout $checkout): void {
        $token   = get_option('ghn_token', '');
        $shop_id = get_option('ghn_shop_id', 0);
        if (empty($token) || !$shop_id) return;

        /* Get saved values for pre-selection */
        $saved_province = $checkout->get_value('ghn_province_id');
        $saved_district = $checkout->get_value('ghn_district_id');
        $saved_ward     = $checkout->get_value('ghn_ward_code');

        /* Also try to get from current user's last order if no session data */
        if (!$saved_province && is_user_logged_in()) {
            $last_order = $this->get_user_last_order();
            if ($last_order) {
                $saved_province = $saved_province ?: $last_order->get_meta('_ghn_province_id');
                $saved_district = $saved_district ?: $last_order->get_meta('_ghn_district_id');
                $saved_ward     = $saved_ward ?: $last_order->get_meta('_ghn_ward_code');
            }
        }

        echo '<div id="ghn-address-fields" class="ghn-checkout-wrapper">';
        echo '<h3>📦 Khu vực giao hàng</h3>';

        /* Province select */
        $province_options = ['' => '— Chọn tỉnh/thành —'];
        woocommerce_form_field('ghn_province_id', [
            'type'        => 'select',
            'required'    => true,
            'class'       => ['form-row-first'],
            'input_class' => ['ghn-select'],
            'input_id'    => 'ghn_province_id',
            'options'     => $province_options,
            'label'       => 'Tỉnh / Thành phố',
        ], $saved_province);

        /* District select */
        $district_options = ['' => '— Chọn quận/huyện —'];
        woocommerce_form_field('ghn_district_id', [
            'type'        => 'select',
            'required'    => true,
            'class'       => ['form-row-last'],
            'input_class' => ['ghn-select'],
            'input_id'    => 'ghn_district_id',
            'options'     => $district_options,
            'label'       => 'Quận / Huyện',
        ], $saved_district);

        /* Ward select */
        $ward_options = ['' => '— Chọn phường/xã —'];
        woocommerce_form_field('ghn_ward_code', [
            'type'        => 'select',
            'required'    => true,
            'class'       => ['form-row-wide'],
            'input_class' => ['ghn-select'],
            'input_id'    => 'ghn_ward_code',
            'options'     => $ward_options,
            'label'       => 'Phường / Xã',
        ], $saved_ward);

        echo '<div id="ghn-address-status" style="margin-top:8px; font-size:13px; color:#555; min-height:20px;"></div>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Validate GHN Fields
     * ----------------------------------------------------------------*/

    public function validate_ghn_fields(): void {
        /* Only validate if GHN is configured */
        $token   = get_option('ghn_token', '');
        $shop_id = get_option('ghn_shop_id', 0);
        if (empty($token) || !$shop_id) return;

        $province = sanitize_text_field($_POST['ghn_province_id'] ?? '');
        $district = sanitize_text_field($_POST['ghn_district_id'] ?? '');
        $ward     = sanitize_text_field($_POST['ghn_ward_code'] ?? '');

        if (empty($province)) {
            wc_add_notice('<strong>Tỉnh/Thành phố</strong>: Vui lòng chọn tỉnh/thành từ dropdown.', 'error');
        }
        if (empty($district)) {
            wc_add_notice('<strong>Quận/Huyện</strong>: Vui lòng chọn quận/huyện từ dropdown.', 'error');
        }
        if (empty($ward)) {
            wc_add_notice('<strong>Phường/Xã</strong>: Vui lòng chọn phường/xã từ dropdown.', 'error');
        }
    }

    /* ------------------------------------------------------------------
     *  Save to Order
     * ----------------------------------------------------------------*/

    /**
     * Save via checkout_create_order (runs before save, works with HPOS).
     * Also syncs GHN names to WooCommerce standard shipping fields.
     */
    public function save_ghn_to_order(WC_Order $order, array $data): void {
        $province_id = sanitize_text_field($_POST['ghn_province_id'] ?? '');
        $district_id = sanitize_text_field($_POST['ghn_district_id'] ?? '');
        $ward_code   = sanitize_text_field($_POST['ghn_ward_code'] ?? '');

        /* Save GHN meta (IDs/codes) */
        if ($province_id) $order->update_meta_data('_ghn_province_id', $province_id);
        if ($district_id) $order->update_meta_data('_ghn_district_id', $district_id);
        if ($ward_code)   $order->update_meta_data('_ghn_ward_code', $ward_code);

        /* Save human-readable names for admin display */
        $province_name = sanitize_text_field($_POST['ghn_province_name'] ?? '');
        $district_name = sanitize_text_field($_POST['ghn_district_name'] ?? '');
        $ward_name     = sanitize_text_field($_POST['ghn_ward_name'] ?? '');

        if ($province_name) $order->update_meta_data('_ghn_province_name', $province_name);
        if ($district_name) $order->update_meta_data('_ghn_district_name', $district_name);
        if ($ward_name)     $order->update_meta_data('_ghn_ward_name', $ward_name);

        /*
         * Sync to WooCommerce standard shipping fields if they're empty.
         * This ensures the address shows properly in admin & shipping plugins.
         */
        if ($province_name && !$order->get_shipping_state()) {
            $order->set_shipping_state($province_name);
        }
        if ($district_name && !$order->get_shipping_city()) {
            $order->set_shipping_city($district_name);
        }
        if ($ward_name && $district_name && !$order->get_shipping_address_1()) {
            $order->set_shipping_address_1($ward_name . ', ' . $district_name);
        }
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
        if (!empty($_POST['ghn_province_name'])) {
            update_post_meta($order_id, '_ghn_province_name', sanitize_text_field($_POST['ghn_province_name']));
        }
        if (!empty($_POST['ghn_district_name'])) {
            update_post_meta($order_id, '_ghn_district_name', sanitize_text_field($_POST['ghn_district_name']));
        }
        if (!empty($_POST['ghn_ward_name'])) {
            update_post_meta($order_id, '_ghn_ward_name', sanitize_text_field($_POST['ghn_ward_name']));
        }
    }

    /* ------------------------------------------------------------------
     *  Admin Display
     * ----------------------------------------------------------------*/

    public function display_in_admin(WC_Order $order): void {
        $province_id   = $order->get_meta('_ghn_province_id');
        $district_id   = $order->get_meta('_ghn_district_id');
        $ward_code     = $order->get_meta('_ghn_ward_code');
        $province_name = $order->get_meta('_ghn_province_name');
        $district_name = $order->get_meta('_ghn_district_name');
        $ward_name     = $order->get_meta('_ghn_ward_name');

        if (!$province_id && !$district_id && !$ward_code) return;

        echo '<div style="margin-top:10px; padding:10px 12px; background:#f0f6fc; border-left:4px solid #2271b1; font-size:12px; line-height:1.6;">';
        echo '<strong>📦 GHN Address Info:</strong><br>';

        if ($province_name) {
            echo 'Tỉnh/TP: <strong>' . esc_html($province_name) . '</strong>';
            echo ' <code>' . esc_html($province_id) . '</code><br>';
        } elseif ($province_id) {
            echo 'Province ID: <code>' . esc_html($province_id) . '</code><br>';
        }

        if ($district_name) {
            echo 'Quận/Huyện: <strong>' . esc_html($district_name) . '</strong>';
            echo ' <code>' . esc_html($district_id) . '</code><br>';
        } elseif ($district_id) {
            echo 'District ID: <code>' . esc_html($district_id) . '</code><br>';
        }

        if ($ward_name) {
            echo 'Phường/Xã: <strong>' . esc_html($ward_name) . '</strong>';
            echo ' <code>' . esc_html($ward_code) . '</code>';
        } elseif ($ward_code) {
            echo 'Ward Code: <code>' . esc_html($ward_code) . '</code>';
        }

        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Block Checkout Support (WooCommerce Blocks)
     * ----------------------------------------------------------------*/

    public function register_block_checkout_hooks(): void {
        if (!class_exists('Automattic\WooCommerce\Blocks\Package')) return;

        add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order, $request) {
            $province = $request->get_meta('ghn_province_id') ?? '';
            $district = $request->get_meta('ghn_district_id') ?? '';
            $ward     = $request->get_meta('ghn_ward_code') ?? '';

            if ($province) $order->update_meta_data('_ghn_province_id', sanitize_text_field($province));
            if ($district) $order->update_meta_data('_ghn_district_id', sanitize_text_field($district));
            if ($ward)     $order->update_meta_data('_ghn_ward_code', sanitize_text_field($ward));

            /* Also save names */
            $province_name = $request->get_meta('ghn_province_name') ?? '';
            $district_name = $request->get_meta('ghn_district_name') ?? '';
            $ward_name     = $request->get_meta('ghn_ward_name') ?? '';

            if ($province_name) $order->update_meta_data('_ghn_province_name', sanitize_text_field($province_name));
            if ($district_name) $order->update_meta_data('_ghn_district_name', sanitize_text_field($district_name));
            if ($ward_name)     $order->update_meta_data('_ghn_ward_name', sanitize_text_field($ward_name));

            /* Sync to standard fields */
            if ($province_name && !$order->get_shipping_state()) {
                $order->set_shipping_state($province_name);
            }
            if ($district_name && !$order->get_shipping_city()) {
                $order->set_shipping_city($district_name);
            }
            if ($ward_name && $district_name && !$order->get_shipping_address_1()) {
                $order->set_shipping_address_1($ward_name . ', ' . $district_name);
            }
        }, 10, 2);

        add_action('woocommerce_after_shipping_address', function () {
            if (!is_checkout()) return;
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

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the last completed order for the current user (for pre-filling).
     */
    private function get_user_last_order(): ?WC_Order {
        $customer_id = get_current_user_id();
        if (!$customer_id) return null;

        $orders = wc_get_orders([
            'customer_id' => $customer_id,
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => ['wc-completed', 'wc-processing'],
        ]);

        return $orders ? $orders[0] : null;
    }
}
