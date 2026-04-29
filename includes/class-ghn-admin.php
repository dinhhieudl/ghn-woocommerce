<?php
/**
 * GHN Admin - WooCommerce integration
 *
 * - Adds "Đăng đơn GHN" button on order list
 * - Adds tracking status column
 * - AJAX handlers for create/track actions
 * - Order detail meta box for tracking info
 */

defined('ABSPATH') || exit;

class GHN_Admin {

    private GHN_API $api;

    public function __construct() {
        $this->api = new GHN_API();

        // Order list: custom column + button
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_columns']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_column'], 10, 2);

        // Order list: add bulk action "Đăng đơn GHN"
        add_filter('bulk_actions-edit-shop_order', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notices']);

        // Order detail: tracking meta box
        add_action('add_meta_boxes', [$this, 'add_tracking_meta_box']);

        // AJAX handlers
        add_action('wp_ajax_ghn_create_order', [$this, 'ajax_create_order']);
        add_action('wp_ajax_ghn_track_order', [$this, 'ajax_track_order']);
        add_action('wp_ajax_ghn_check_status', [$this, 'ajax_check_status']);

        // Enqueue assets on order list and order detail pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* ------------------------------------------------------------------
     *  Order List Columns
     * ----------------------------------------------------------------*/

    public function add_order_columns(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('order_number' === $key) {
                $new['ghn_status'] = 'GHN';
                $new['ghn_actions'] = 'Thao tác';
            }
        }
        return $new;
    }

    public function render_order_column(string $column, int $post_id): void {
        $order = wc_get_order($post_id);
        if (!$order) return;

        $ghn_code = $order->get_meta('_ghn_order_code');

        switch ($column) {
            case 'ghn_status':
                if ($ghn_code) {
                    $status  = $order->get_meta('_ghn_status');
                    $badge   = $this->status_badge($status);
                    echo "<div class='ghn-status-cell'>";
                    echo "<code>{$ghn_code}</code><br>";
                    echo $badge;
                    echo "</div>";
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'ghn_actions':
                echo '<div class="ghn-actions-cell" data-order-id="' . esc_attr($post_id) . '">';

                if (!$ghn_code) {
                    // Chưa đăng đơn → hiện nút đăng đơn
                    printf(
                        '<button class="button button-primary ghn-btn-create" data-order-id="%d" title="Tạo vận đơn GHN">📦 Đăng đơn</button>',
                        $post_id
                    );
                } else {
                    // Đã đăng đơn → hiện nút check trạng thái + link tracking
                    printf(
                        '<button class="button ghn-btn-track" data-order-id="%d" data-ghn-code="%s" title="Kiểm tra trạng thái">🔍 Tracking</button>',
                        $post_id,
                        esc_attr($ghn_code)
                    );
                    printf(
                        ' <a href="https://donhang.ghn.vn/?order_code=%s" target="_blank" class="button" title="Xem trên GHN">↗</a>',
                        esc_attr($ghn_code)
                    );
                }

                echo '</div>';
                break;
        }
    }

    /* ------------------------------------------------------------------
     *  Bulk Actions
     * ----------------------------------------------------------------*/

    public function register_bulk_actions(array $actions): array {
        $actions['ghn_create_bulk'] = 'Đăng đơn GHN hàng loạt';
        return $actions;
    }

    public function handle_bulk_action(string $redirect, string $action, array $order_ids): string {
        if ('ghn_create_bulk' !== $action) return $redirect;

        $success = 0;
        $fail    = 0;

        foreach ($order_ids as $order_id) {
            $result = $this->do_create_order($order_id);
            if ($result['success']) {
                $success++;
            } else {
                $fail++;
            }
        }

        // Store result for admin notice
        set_transient('ghn_bulk_result', [
            'success' => $success,
            'fail'    => $fail,
            'total'   => count($order_ids),
        ], 30);

        return $redirect;
    }

    public function bulk_action_notices(): void {
        $result = get_transient('ghn_bulk_result');
        if (!$result) return;

        delete_transient('ghn_bulk_result');

        if ($result['success'] > 0) {
            echo '<div class="updated notice"><p>';
            echo sprintf('✅ Đăng đơn thành công: <strong>%d/%d</strong> đơn.', $result['success'], $result['total']);
            if ($result['fail'] > 0) {
                echo sprintf(' ❌ Thất bại: <strong>%d</strong> đơn.', $result['fail']);
            }
            echo '</p></div>';
        } else {
            echo '<div class="error notice"><p>❌ Không đăng được đơn nào. Kiểm tra lại cấu hình GHN và thông tin đơn hàng.</p></div>';
        }
    }

    /* ------------------------------------------------------------------
     *  Order Detail Meta Box
     * ----------------------------------------------------------------*/

    public function add_tracking_meta_box(): void {
        add_meta_box(
            'ghn_tracking',
            '📦 GHN - Giao Hàng Nhanh',
            [$this, 'render_tracking_meta_box'],
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_tracking_meta_box(WP_Post $post): void {
        $order    = wc_get_order($post->ID);
        $ghn_code = $order->get_meta('_ghn_order_code');

        if (!$ghn_code) {
            echo '<p style="color:#999;">Chưa tạo vận đơn GHN.</p>';
            printf(
                '<button class="button button-primary ghn-btn-create" data-order-id="%d" style="width:100%%;">📦 Tạo vận đơn GHN</button>',
                $post->ID
            );
            return;
        }

        $status     = $order->get_meta('_ghn_status');
        $fee        = $order->get_meta('_ghn_fee');
        $created    = $order->get_meta('_ghn_created_date');

        echo '<div class="ghn-tracking-box">';
        echo '<p><strong>Mã vận đơn:</strong><br><code>' . esc_html($ghn_code) . '</code></p>';
        echo '<p><strong>Trạng thái:</strong> ' . $this->status_badge($status) . '</p>';
        if ($fee) {
            echo '<p><strong>Phí ship:</strong> ' . number_format($fee) . 'đ</p>';
        }
        if ($created) {
            echo '<p><strong>Ngày tạo:</strong> ' . esc_html($created) . '</p>';
        }
        echo '<div class="ghn-tracking-log" id="ghn-tracking-log-' . esc_attr($post->ID) . '">';

        // Show last known log from meta
        $log = $order->get_meta('_ghn_log');
        if ($log && is_array($log)) {
            echo '<ul style="margin:5px 0;padding-left:18px;font-size:12px;">';
            foreach (array_reverse($log) as $entry) {
                $time = isset($entry['updated_date']) ? date('d/m H:i', strtotime($entry['updated_date'])) : '';
                echo '<li>' . esc_html($time) . ' - <strong>' . esc_html($entry['status'] ?? '') . '</strong></li>';
            }
            echo '</ul>';
        }

        echo '</div>';

        printf(
            '<button class="button ghn-btn-track" data-order-id="%d" data-ghn-code="%s" style="width:100%%;margin-top:8px;">🔍 Cập nhật trạng thái</button>',
            $post->ID,
            esc_attr($ghn_code)
        );

        printf(
            '<a href="https://donhang.ghn.vn/?order_code=%s" target="_blank" class="button" style="width:100%%;margin-top:5px;">↗ Xem trên GHN</a>',
            esc_attr($ghn_code)
        );

        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  AJAX: Create Order
     * ----------------------------------------------------------------*/

    public function ajax_create_order(): void {
        check_ajax_referer('ghn_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Không có quyền', 403);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $result   = $this->do_create_order($order_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Core logic: create GHN order from WooCommerce order.
     */
    private function do_create_order(int $order_id): array {
        if (!$this->api->is_configured()) {
            return ['success' => false, 'message' => 'Chưa cấu hình GHN API Token / Shop ID.'];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'message' => 'Không tìm thấy đơn hàng.'];
        }

        // Don't re-create if already has GHN code
        if ($order->get_meta('_ghn_order_code')) {
            return ['success' => false, 'message' => 'Đơn đã có mã GHN.'];
        }

        // Extract shipping address
        $shipping = $this->extract_shipping($order);
        if (!$shipping['valid']) {
            return ['success' => false, 'message' => $shipping['error']];
        }

        // Build items from order
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $weight  = $product ? (float) $product->get_weight() : 0.3;
            $items[] = [
                'name'     => mb_substr($item->get_name(), 0, 100),
                'code'     => $product ? $product->get_sku() ?: '' : '',
                'quantity' => $item->get_quantity(),
                'price'    => (int) $item->get_total(),
                'weight'   => max(1, (int) ($weight * 1000)), // gram
                'length'   => 15,
                'width'    => 15,
                'height'   => 10,
                'category' => ['level1' => 'Hàng hóa'],
            ];
        }

        // Calculate total weight
        $total_weight = 0;
        foreach ($items as $it) {
            $total_weight += $it['weight'] * $it['quantity'];
        }
        $total_weight = max(200, $total_weight); // min 200g

        // COD amount
        $cod_amount = 0;
        $payment_method = $order->get_payment_method();
        if ('cod' === $payment_method) {
            $cod_amount = (int) $order->get_total();
        }

        $params = [
            'payment_type_id'  => 'cod' === $payment_method ? 2 : 1, // 2=buyer, 1=shop
            'note'             => get_option('ghn_default_note', '') ?: $order->get_customer_note(),
            'required_note'    => get_option('ghn_required_note', 'KHONGCHOXEMHANG'),
            'return_phone'     => get_option('ghn_pick_phone', ''),
            'return_address'   => get_option('ghn_pick_address', ''),
            'return_district_id' => (int) get_option('ghn_pick_district_id', 0),
            'return_ward_code'   => get_option('ghn_pick_ward_code', ''),
            'client_order_code'  => 'WC-' . $order_id,
            'to_name'          => mb_substr($shipping['name'], 0, 100),
            'to_phone'         => $shipping['phone'],
            'to_address'       => mb_substr($shipping['address'], 0, 255),
            'to_ward_code'     => $shipping['ward_code'],
            'to_district_id'   => (int) $shipping['district_id'],
            'cod_amount'       => min($cod_amount, 10000000),
            'content'          => mb_substr('Đơn hàng #' . $order_id . ' - GearZone', 0, 200),
            'weight'           => $total_weight,
            'length'           => 30,
            'width'            => 25,
            'height'           => 15,
            'insurance_value'  => min((int) $order->get_total(), 5000000),
            'service_type_id'  => (int) get_option('ghn_service_type', 2),
            'service_id'       => 0,
            'coupon'           => null,
            'pick_shift'       => [2],
            'items'            => $items,
        ];

        $result = $this->api->create_order($params);

        if ($result['success']) {
            $data = $result['data'];
            // Save GHN data to order meta
            $order->update_meta_data('_ghn_order_code', $data['order_code']);
            $order->update_meta_data('_ghn_status', 'ready_to_pick');
            $order->update_meta_data('_ghn_fee', $data['total_fee'] ?? 0);
            $order->update_meta_data('_ghn_created_date', current_time('mysql'));
            $order->update_meta_data('_ghn_expected_delivery', $data['expected_delivery_time'] ?? '');
            $order->update_meta_data('_ghn_trans_type', $data['trans_type'] ?? '');
            $order->add_order_note(
                sprintf('📦 Đã tạo vận đơn GHN: %s | Phí ship: %sđ | Dự kiến: %s',
                    $data['order_code'],
                    number_format($data['total_fee'] ?? 0),
                    $data['expected_delivery_time'] ?? 'N/A'
                )
            );
            $order->save();

            return [
                'success' => true,
                'data'    => [
                    'order_code' => $data['order_code'],
                    'fee'        => $data['total_fee'] ?? 0,
                    'leadtime'   => $data['expected_delivery_time'] ?? '',
                ],
            ];
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     *  AJAX: Track / Check Status
     * ----------------------------------------------------------------*/

    public function ajax_track_order(): void {
        check_ajax_referer('ghn_nonce', 'nonce');

        $order_id  = absint($_POST['order_id'] ?? 0);
        $ghn_code  = sanitize_text_field($_POST['ghn_code'] ?? '');

        if (!$ghn_code) {
            wp_send_json_error('Thiếu mã vận đơn GHN.');
        }

        $result = $this->api->get_order($ghn_code);

        if ($result['success']) {
            $data = $result['data'];

            // Update order meta with latest status
            $order = wc_get_order($order_id);
            if ($order && is_array($data)) {
                $first = $data[0] ?? $data;
                $order->update_meta_data('_ghn_status', $first['status'] ?? '');
                $order->update_meta_data('_ghn_log', $first['log'] ?? []);
                $order->save();
            }

            wp_send_json_success($data);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajax_check_status(): void {
        check_ajax_referer('ghn_nonce', 'nonce');

        $order_id = absint($_POST['order_id'] ?? 0);
        $order    = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Không tìm thấy đơn.');
        }

        $ghn_code = $order->get_meta('_ghn_order_code');
        if (!$ghn_code) {
            wp_send_json_error('Đơn chưa có mã GHN.');
        }

        $result = $this->api->get_order($ghn_code);

        if ($result['success']) {
            $data = $result['data'];
            if ($order && is_array($data)) {
                $first = $data[0] ?? $data;
                $order->update_meta_data('_ghn_status', $first['status'] ?? '');
                $order->update_meta_data('_ghn_log', $first['log'] ?? []);
                $order->save();
            }
            wp_send_json_success($data);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /* ------------------------------------------------------------------
     *  Extract Shipping Address
     * ----------------------------------------------------------------*/

    private function extract_shipping(WC_Order $order): array {
        $name    = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        $phone   = $order->get_billing_phone();
        $address = $order->get_shipping_address_1();
        $city    = $order->get_shipping_city();
        $state   = $order->get_shipping_state();
        $country = $order->get_shipping_country();

        /* Get GHN codes from order meta (set by checkout dropdowns) */
        $district_id = $order->get_meta('_ghn_district_id');
        $ward_code   = $order->get_meta('_ghn_ward_code');

        /* Get human-readable names */
        $district_name = $order->get_meta('_ghn_district_name');
        $ward_name     = $order->get_meta('_ghn_ward_name');

        /* Fallback: try matching by city name if no meta */
        if (!$district_id && $city) {
            $district_id = $this->match_district($city, $state);
        }

        if (empty($name) || empty($phone) || empty($address)) {
            return ['valid' => false, 'error' => 'Thiếu thông tin người nhận (tên, SĐT, địa chỉ).'];
        }

        if (!$district_id) {
            return ['valid' => false, 'error' => "Không xác định được quận/huyện. Khách cần chọn Tỉnh/Quận/Phường tại checkout hoặc admin set _ghn_district_id."];
        }

        if (!$ward_code) {
            return ['valid' => false, 'error' => "Thiếu mã phường/xã. Khách cần chọn Phường/Xã tại checkout hoặc admin set _ghn_ward_code."];
        }

        /*
         * Build full address string.
         * If the address_1 already contains ward+district info (auto-filled by checkout JS), use as-is.
         * Otherwise, compose from names.
         */
        $full_address = $address;
        if ($order->get_shipping_address_2()) {
            $full_address .= ', ' . $order->get_shipping_address_2();
        }

        /* If address is just a bare street address without ward/district, append them */
        if ($ward_name && $district_name && $address) {
            $addr_lower = mb_strtolower($address);
            $ward_lower = mb_strtolower($ward_name);
            $dist_lower = mb_strtolower($district_name);
            if (strpos($addr_lower, $ward_lower) === false && strpos($addr_lower, $dist_lower) === false) {
                $full_address = $address . ', ' . $ward_name . ', ' . $district_name;
            }
        }

        return [
            'valid'       => true,
            'name'        => $name,
            'phone'       => $phone,
            'address'     => $full_address,
            'district_id' => (int) $district_id,
            'ward_code'   => $ward_code,
            'city'        => $city,
        ];
    }

    /**
     * Try to match GHN district by city name.
     * Returns district_id or empty string.
     */
    private function match_district(string $city, string $province): int|string {
        // Cache districts in transient for 1 day
        $cache_key  = 'ghn_districts';
        $districts  = get_transient($cache_key);

        if (false === $districts) {
            $result = $this->api->get_districts();
            if ($result['success'] && is_array($result['data'])) {
                $districts = $result['data'];
                set_transient($cache_key, $districts, DAY_IN_SECONDS);
            } else {
                return '';
            }
        }

        // Normalize for matching
        $search = mb_strtolower(trim($city));

        foreach ($districts as $d) {
            $name = mb_strtolower($d['DistrictName'] ?? '');
            if (false !== strpos($name, $search) || false !== strpos($search, $name)) {
                return (int) $d['DistrictID'];
            }
        }

        return '';
    }

    /* ------------------------------------------------------------------
     *  Assets
     * ----------------------------------------------------------------*/

    public function enqueue_assets(string $hook): void {
        if (!in_array($hook, ['edit.php', 'post.php', 'post-new.php'], true)) return;

        $screen = get_current_screen();
        if (!$screen || 'shop_order' !== $screen->post_type) return;

        wp_enqueue_style('ghn-admin', GHN_PLUGIN_URL . 'assets/admin.css', [], GHN_VERSION);
        wp_enqueue_script('ghn-admin', GHN_PLUGIN_URL . 'assets/admin.js', ['jquery'], GHN_VERSION, true);

        wp_localize_script('ghn-admin', 'ghnAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ghn_nonce'),
            'i18n'    => [
                'confirmCreate' => 'Tạo vận đơn GHN cho đơn hàng này?',
                'creating'      => 'Đang tạo vận đơn...',
                'tracking'      => 'Đang kiểm tra...',
                'success'       => 'Thành công!',
                'error'         => 'Lỗi',
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     *  Status Badge Helper
     * ----------------------------------------------------------------*/

    private function status_badge(string $status): string {
        $map = [
            'ready_to_pick'  => ['🟡', 'Chờ lấy hàng', '#f0ad4e'],
            'picking'        => ['🟠', 'Đang lấy hàng', '#ff9800'],
            'picked'         => ['🔵', 'Đã lấy hàng', '#2196f3'],
            'storing'        => ['🟣', 'Đang lưu kho', '#9c27b0'],
            'delivering'     => ['🚚', 'Đang giao', '#00bcd4'],
            'delivered'      => ['✅', 'Đã giao', '#4caf50'],
            'return'         => ['↩️', 'Trả hàng', '#f44336'],
            'cancel'         => ['❌', 'Đã hủy', '#9e9e9e'],
            'exception'      => ['⚠️', 'Lỗi giao hàng', '#ff5722'],
            'lost'           => ['💀', 'Mất hàng', '#e91e63'],
            'damage'         => ['💔', 'Hư hỏng', '#795548'],
        ];

        if (isset($map[$status])) {
            [$icon, $label, $color] = $map[$status];
            return "<span style='background:{$color};color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;'>{$icon} {$label}</span>";
        }

        return "<span style='background:#607d8b;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;'>{$status}</span>";
    }

}
