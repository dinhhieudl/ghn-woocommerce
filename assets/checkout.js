/**
 * GHN Checkout JS - Cascading Province → District → Ward dropdowns
 * Works with both classic and block-based WooCommerce checkout.
 */
(function ($) {
    'use strict';

    var $province, $district, $ward, $status;
    var cache = { provinces: null, districts: {}, wards: {} };
    var initialized = false;

    function init() {
        // Find our select fields (classic checkout)
        $province = $('select[name="ghn_province_id"], #ghn_province_id');
        $district = $('select[name="ghn_district_id"], #ghn_district_id');
        $ward     = $('select[name="ghn_ward_code"], #ghn_ward_code');
        $status   = $('#ghn-address-status');

        if (!$province.length || initialized) return;
        initialized = true;

        // Initially hide district & ward until province selected
        $district.closest('.form-row').hide();
        $ward.closest('.form-row').hide();

        // Load provinces immediately
        loadProvinces();

        // Bind cascade events
        $province.on('change', onProvinceChange);
        $district.on('change', onDistrictChange);

        // Also try to pre-fill from WooCommerce saved checkout data
        var savedProvince = $province.val();
        if (savedProvince) {
            $province.trigger('change');
        }
    }

    /* ------------------------------------------------------------------
     *  Load Provinces
     * ----------------------------------------------------------------*/
    function loadProvinces() {
        $status.text('Đang tải tỉnh/thành...');

        if (cache.provinces) {
            renderProvinces(cache.provinces);
            return;
        }

        $.get(ghnCheckout.ajaxUrl, {
            action: 'ghn_get_provinces',
            nonce:  ghnCheckout.nonce,
        }).done(function (resp) {
            if (resp.success && resp.data) {
                cache.provinces = resp.data;
                renderProvinces(resp.data);
            } else {
                $status.html('⚠️ Không tải được danh sách tỉnh/thành');
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối. Thử lại trang.');
        });
    }

    function renderProvinces(list) {
        $province.find('option:gt(0)').remove();
        $.each(list, function (_, p) {
            $province.append(
                $('<option>', { value: p.id, text: p.name })
            );
        });
        $status.text('');
        $province.closest('.form-row').slideDown(200);
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
            action:       'ghn_get_districts',
            nonce:        ghnCheckout.nonce,
            province_id:  pid,
        }).done(function (resp) {
            if (resp.success && resp.data) {
                cache.districts[pid] = resp.data;
                renderDistricts(resp.data);
            } else {
                $status.html('⚠️ Không tải được quận/huyện');
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối');
        });
    }

    function renderDistricts(list) {
        $district.find('option:gt(0)').remove();
        $.each(list, function (_, d) {
            var label = d.name;
            if (d.supportType === 0) label += ' (không hỗ trợ)';
            var $opt = $('<option>', { value: d.id, text: label });
            if (d.supportType === 0) $opt.prop('disabled', true);
            $district.append($opt);
        });
        $status.text('');
        $district.closest('.form-row').slideDown(200);
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
            action:      'ghn_get_wards',
            nonce:       ghnCheckout.nonce,
            district_id: did,
        }).done(function (resp) {
            if (resp.success && resp.data) {
                cache.wards[did] = resp.data;
                renderWards(resp.data);
            } else {
                $status.html('⚠️ Không tải được phường/xã');
            }
        }).fail(function () {
            $status.html('⚠️ Lỗi kết nối');
        });
    }

    function renderWards(list) {
        $ward.find('option:gt(0)').remove();
        $.each(list, function (_, w) {
            $ward.append(
                $('<option>', { value: w.code, text: w.name })
            );
        });
        $status.text('');
        $ward.closest('.form-row').slideDown(200);
    }

    /* ------------------------------------------------------------------
     *  Init — wait for DOM ready, also handle block checkout re-renders
     * ----------------------------------------------------------------*/
    $(document).ready(function () {
        init();

        // Block checkout may re-render the form — observe DOM for our fields
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function () {
                if (!initialized && $('select[name="ghn_province_id"]').length) {
                    init();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });

})(jQuery);
