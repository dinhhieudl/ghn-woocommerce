/**
 * GHN Checkout JS - Cascading Province → District → Ward dropdowns
 */
(function ($) {
    'use strict';

    var $province, $district, $ward, $status;
    var cache = { provinces: null, districts: {}, wards: {} };

    function init() {
        $province = $('#ghn_province_id');
        $district = $('#ghn_district_id');
        $ward     = $('#ghn_ward_code');
        $status   = $('#ghn-address-status');

        if (!$province.length) return;

        // Hide district/ward until province selected
        $district.closest('.form-row').hide();
        $ward.closest('.form-row').hide();

        // Load provinces on page load
        loadProvinces();

        // Cascade events
        $province.on('change', onProvinceChange);
        $district.on('change', onDistrictChange);
    }

    /* ------------------------------------------------------------------
     *  Load Provinces
     * ----------------------------------------------------------------*/
    function loadProvinces() {
        $status.text('Đang tải danh sách tỉnh/thành...');

        if (cache.provinces) {
            renderProvinces(cache.provinces);
            return;
        }

        $.get(ghnCheckout.ajaxUrl, {
            action: 'ghn_get_provinces',
            nonce: ghnCheckout.nonce,
        }).done(function (resp) {
            if (resp.success) {
                cache.provinces = resp.data;
                renderProvinces(resp.data);
            } else {
                $status.html('⚠️ ' + (resp.data || 'Lỗi tải tỉnh/thành'));
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối API GHN');
        });
    }

    function renderProvinces(list) {
        $province.find('option:gt(0)').remove();
        list.forEach(function (p) {
            $province.append('<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>');
        });
        $status.text('');
        $province.closest('.form-row').show();
    }

    /* ------------------------------------------------------------------
     *  Province Change → Load Districts
     * ----------------------------------------------------------------*/
    function onProvinceChange() {
        var pid = $province.val();

        // Reset downstream
        $district.find('option:gt(0)').remove();
        $ward.find('option:gt(0)').remove();
        $district.closest('.form-row').hide();
        $ward.closest('.form-row').hide();

        if (!pid) return;

        $status.text('Đang tải quận/huyện...');

        if (cache.districts[pid]) {
            renderDistricts(cache.districts[pid]);
            return;
        }

        $.get(ghnCheckout.ajaxUrl, {
            action: 'ghn_get_districts',
            nonce: ghnCheckout.nonce,
            province_id: pid,
        }).done(function (resp) {
            if (resp.success) {
                cache.districts[pid] = resp.data;
                renderDistricts(resp.data);
            } else {
                $status.html('⚠️ ' + (resp.data || 'Lỗi tải quận/huyện'));
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối');
        });
    }

    function renderDistricts(list) {
        $district.find('option:gt(0)').remove();
        list.forEach(function (d) {
            var label = d.name;
            if (d.supportType === 0) label += ' (không hỗ trợ)';
            $district.append('<option value="' + d.id + '"' + (d.supportType === 0 ? ' disabled' : '') + '>' + escapeHtml(label) + '</option>');
        });
        $status.text('');
        $district.closest('.form-row').show();
    }

    /* ------------------------------------------------------------------
     *  District Change → Load Wards
     * ----------------------------------------------------------------*/
    function onDistrictChange() {
        var did = $district.val();

        $ward.find('option:gt(0)').remove();
        $ward.closest('.form-row').hide();

        if (!did) return;

        $status.text('Đang tải phường/xã...');

        if (cache.wards[did]) {
            renderWards(cache.wards[did]);
            return;
        }

        $.get(ghnCheckout.ajaxUrl, {
            action: 'ghn_get_wards',
            nonce: ghnCheckout.nonce,
            district_id: did,
        }).done(function (resp) {
            if (resp.success) {
                cache.wards[did] = resp.data;
                renderWards(resp.data);
            } else {
                $status.html('⚠️ ' + (resp.data || 'Lỗi tải phường/xã'));
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối');
        });
    }

    function renderWards(list) {
        $ward.find('option:gt(0)').remove();
        list.forEach(function (w) {
            $ward.append('<option value="' + escapeHtml(w.code) + '">' + escapeHtml(w.name) + '</option>');
        });
        $status.text('');
        $ward.closest('.form-row').show();
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    $(document).ready(init);
})(jQuery);
