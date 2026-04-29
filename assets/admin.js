/**
 * GHN Admin JS - Order list & tracking interactions
 */
(function ($) {
    'use strict';

    var GHN = {
        init: function () {
            $(document).on('click', '.ghn-btn-create', this.createOrder);
            $(document).on('click', '.ghn-btn-track', this.trackOrder);
        },

        createOrder: function (e) {
            e.preventDefault();
            var $btn = $(this);
            var orderId = $btn.data('order-id');

            if (!confirm(ghnAjax.i18n.confirmCreate)) return;

            $btn.addClass('ghn-loading').text(ghnAjax.i18n.creating);

            $.post(ghnAjax.ajaxUrl, {
                action: 'ghn_create_order',
                nonce: ghnAjax.nonce,
                order_id: orderId,
            })
            .done(function (resp) {
                if (resp.success) {
                    $btn.replaceWith(
                        '<span style="color:#4caf50;font-weight:bold;">✅ ' +
                        ghnAjax.i18n.success +
                        ' <code>' + resp.data.order_code + '</code></span>'
                    );
                    // Reload after short delay to update all columns
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    alert(ghnAjax.i18n.error + ': ' + (resp.data || 'Unknown'));
                    $btn.removeClass('ghn-loading').text('📦 Đăng đơn');
                }
            })
            .fail(function (xhr) {
                alert(ghnAjax.i18n.error + ': ' + (xhr.responseText || 'Network'));
                $btn.removeClass('ghn-loading').text('📦 Đăng đơn');
            });
        },

        trackOrder: function (e) {
            e.preventDefault();
            var $btn = $(this);
            var orderId = $btn.data('order-id');
            var ghnCode = $btn.data('ghn-code');

            $btn.addClass('ghn-loading').text(ghnAjax.i18n.tracking);

            $.post(ghnAjax.ajaxUrl, {
                action: 'ghn_track_order',
                nonce: ghnAjax.nonce,
                order_id: orderId,
                ghn_code: ghnCode,
            })
            .done(function (resp) {
                $btn.removeClass('ghn-loading').text('🔍 Tracking');
                if (resp.success) {
                    GHN.showTrackingPopup($btn, resp.data);
                } else {
                    alert(ghnAjax.i18n.error + ': ' + (resp.data || 'Unknown'));
                }
            })
            .fail(function (xhr) {
                $btn.removeClass('ghn-loading').text('🔍 Tracking');
                alert(ghnAjax.i18n.error + ': ' + (xhr.responseText || 'Network'));
            });
        },

        showTrackingPopup: function ($btn, data) {
            // Remove existing popups
            $('.ghn-track-result').remove();

            var items = Array.isArray(data) ? data : [data];
            var order = items[0] || {};
            var logs = order.log || [];

            var statusMap = {
                'ready_to_pick': '🟡 Chờ lấy hàng',
                'picking': '🟠 Đang lấy hàng',
                'picked': '🔵 Đã lấy hàng',
                'storing': '🟣 Lưu kho',
                'delivering': '🚚 Đang giao',
                'delivered': '✅ Đã giao',
                'return': '↩️ Trả hàng',
                'cancel': '❌ Đã hủy',
                'exception': '⚠️ Lỗi',
            };

            var html = '<div class="ghn-track-result">';
            html += '<h4>📦 ' + (order.order_code || '') + '</h4>';
            html += '<p>Trạng thái: <strong>' + (statusMap[order.status] || order.status) + '</strong></p>';

            if (logs.length > 0) {
                html += '<ul class="ghn-track-steps">';
                for (var i = logs.length - 1; i >= 0; i--) {
                    var log = logs[i];
                    var time = log.updated_date ? new Date(log.updated_date).toLocaleString('vi-VN') : '';
                    html += '<li><small>' + time + '</small><br><strong>' + (statusMap[log.status] || log.status) + '</strong></li>';
                }
                html += '</ul>';
            }

            html += '</div>';

            $btn.closest('.ghn-actions-cell, .ghn-tracking-box').append(html);
        },
    };

    $(document).ready(function () {
        GHN.init();
    });

})(jQuery);
