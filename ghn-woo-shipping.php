<?php
/**
 * Plugin Name: GHN Shipping for WooCommerce
 * Plugin URI:  https://gearzone.vn
 * Description: Tích hợp Giao Hàng Nhanh (GHN) cho WooCommerce - Đăng đơn tự động, tra cứu vận đơn ngay trên danh sách đơn hàng.
 * Version:     1.0.0
 * Author:      GearZone
 * Author URI:  https://gearzone.vn
 * License:     GPL-2.0+
 * Text Domain: ghn-woo
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined('ABSPATH') || exit;

define('GHN_VERSION', '1.0.0');
define('GHN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GHN_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload
require_once GHN_PLUGIN_DIR . 'includes/class-ghn-api.php';
require_once GHN_PLUGIN_DIR . 'includes/class-ghn-admin.php';

final class GHN_Woo_Shipping {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p><strong>GHN Shipping</strong> yêu cầu WooCommerce đã được cài đặt.</p></div>';
            });
            return;
        }

        $this->init_settings();
        new GHN_Admin();
    }

    private function init_settings() {
        // Register settings page
        add_filter('woocommerce_get_sections_shipping', function ($sections) {
            $sections['ghn'] = 'GHN - Giao Hàng Nhanh';
            return $sections;
        });

        add_filter('woocommerce_get_settings_shipping', function ($settings, $section) {
            if ('ghn' !== $section) return $settings;

            return [
                [
                    'title' => 'Cấu hình GHN API',
                    'type'  => 'title',
                    'id'    => 'ghn_api_options',
                ],
                [
                    'title'   => 'API Token',
                    'desc'    => 'Token từ tài khoản GHN (https://khachhang.ghn.vn)',
                    'id'      => 'ghn_token',
                    'type'    => 'text',
                    'default' => '',
                    'css'     => 'width:400px;',
                ],
                [
                    'title'   => 'Shop ID',
                    'desc'    => 'ID cửa hàng trên GHN',
                    'id'      => 'ghn_shop_id',
                    'type'    => 'number',
                    'default' => '',
                    'css'     => 'width:150px;',
                ],
                [
                    'title'   => 'Địa chỉ lấy hàng',
                    'desc'    => 'Địa chỉ shop (mặc định)',
                    'id'      => 'ghn_pick_address',
                    'type'    => 'text',
                    'default' => '',
                    'css'     => 'width:400px;',
                ],
                [
                    'title'   => 'SĐT người gửi',
                    'desc'    => 'Số điện thoại liên hệ lấy hàng',
                    'id'      => 'ghn_pick_phone',
                    'type'    => 'text',
                    'default' => '',
                    'css'     => 'width:200px;',
                ],
                [
                    'title'   => 'Tên người gửi',
                    'id'      => 'ghn_pick_name',
                    'type'    => 'text',
                    'default' => 'GearZone',
                    'css'     => 'width:200px;',
                ],
                [
                    'title'   => 'District ID (lấy hàng)',
                    'desc'    => 'Mã quận/huyện lấy hàng từ GHN',
                    'id'      => 'ghn_pick_district_id',
                    'type'    => 'number',
                    'default' => '',
                    'css'     => 'width:150px;',
                ],
                [
                    'title'   => 'Ward Code (lấy hàng)',
                    'desc'    => 'Mã phường/xã lấy hàng từ GHN',
                    'id'      => 'ghn_pick_ward_code',
                    'type'    => 'text',
                    'default' => '',
                    'css'     => 'width:150px;',
                ],
                [
                    'title'   => 'Dịch vụ mặc định',
                    'id'      => 'ghn_service_type',
                    'type'    => 'select',
                    'options' => [
                        2 => 'Tiêu chuẩn (Standard)',
                        1 => 'Nhanh (Express)',
                        3 => 'Tiết kiệm',
                    ],
                    'default' => '2',
                ],
                [
                    'title'   => 'Ghi chú mặc định',
                    'id'      => 'ghn_default_note',
                    'type'    => 'text',
                    'default' => 'Gọi trước khi giao',
                    'css'     => 'width:400px;',
                ],
                [
                    'title'   => 'Required Note',
                    'id'      => 'ghn_required_note',
                    'type'    => 'select',
                    'options' => [
                        'KHONGCHOXEMHANG'     => 'Không cho xem hàng',
                        'CHOXEMHANGKHONGTHU'   => 'Cho xem không cho thử',
                        'CHOTHUHANG'           => 'Cho thử hàng',
                    ],
                    'default' => 'KHONGCHOXEMHANG',
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'ghn_api_options',
                ],
            ];
        }, 10, 2);
    }

    public function activate() {
        // Set default options if not exist
        $defaults = [
            'ghn_service_type'   => 2,
            'ghn_default_note'   => 'Gọi trước khi giao',
            'ghn_required_note'  => 'KHONGCHOXEMHANG',
            'ghn_pick_name'      => 'GearZone',
        ];
        foreach ($defaults as $key => $val) {
            if (false === get_option($key)) {
                update_option($key, $val);
            }
        }
    }
}

GHN_Woo_Shipping::instance();
