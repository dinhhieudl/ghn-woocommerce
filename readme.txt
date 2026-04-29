=== GHN Shipping for WooCommerce ===
Contributors: gearzone
Tags: woocommerce, ghn, shipping, giao-hang-nhanh, logistics
Requires PHP: 7.4
WC requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPL-2.0+

Tích hợp Giao Hàng Nhanh (GHN) cho WooCommerce - Đăng đơn & tra cứu vận đơn ngay trên trang quản lý.

== Description ==

Plugin nhẹ gọn tích hợp API GHN vào WooCommerce:

* **Đăng đơn GHN** - Nút "📦 Đăng đơn" ngay trên mỗi đơn hàng trong danh sách
* **Bulk tạo đơn** - Chọn nhiều đơn, tạo hàng loạt 1 click
* **Tra cứu trạng thái** - Nút "🔍 Tracking" xem log vận đơn realtime
* **Tự động lưu mã vận đơn** - Mã GHN, phí ship, trạng thái được lưu vào order meta
* **Hiển thị trạng thái** - Badge màu sắc trực quan trong danh sách đơn

== Installation ==

1. Upload thư mục `ghn-woo-shipping` vào `/wp-content/plugins/`
2. Activate plugin trong WordPress admin
3. Vào WooCommerce → Settings → Shipping → GHN
4. Nhập Token và Shop ID từ https://khachhang.ghn.vn
5. Cấu hình địa chỉ lấy hàng

== Changelog ==

= 1.0.0 =
* Phiên bản đầu tiên
* Tạo vận đơn GHN từ WooCommerce order list
* Tracking trạng thái vận đơn
* Bulk create orders
* Meta box tracking ở trang chi tiết đơn
