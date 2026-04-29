/**
 * GHN Checkout JS - Cascading Province → District → Ward dropdowns
 * Syncs selected values to WooCommerce standard shipping fields.
 */
(function ($) {
    'use strict';

    var $province, $district, $ward, $status;
    var cache = { provinces: null, districts: {}, wards: {} };
    var initialized = false;

    /* Maps for storing id→name lookups */
    var provinceMap = {};  // { id: name }
    var districtMap = {};  // { id: name }
    var wardMap     = {};  // { code: name }

    function init() {
        $province = $('#ghn_province_id');
        $district = $('#ghn_district_id');
        $ward     = $('#ghn_ward_code');
        $status   = $('#ghn-address-status');

        if (!$province.length || initialized) return;
        initialized = true;

        /* Hide downstream selects until parent is chosen */
        $district.closest('.form-row').hide();
        $ward.closest('.form-row').hide();

        loadProvinces();

        $province.on('change', onProvinceChange);
        $district.on('change', onDistrictChange);
        $ward.on('change', onWardChange);
    }

    /* ----------------------------------------------------------------
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
            $status.html('⚠️ Lỗi kết nối. Thử tải lại trang.');
        });
    }

    function renderProvinces(list) {
        $province.find('option:gt(0)').remove();
        provinceMap = {};
        $.each(list, function (_, p) {
            provinceMap[p.id] = p.name;
            $province.append($('<option>', { value: p.id, text: p.name }));
        });
        $status.text('');
        $province.closest('.form-row').slideDown(200);

        /* Try to pre-select from saved WooCommerce state */
        tryPreSelect();
    }

    /* ----------------------------------------------------------------
     *  Province Change → Load Districts
     * ----------------------------------------------------------------*/
    function onProvinceChange() {
        var pid = $province.val();
        var pname = provinceMap[pid] || '';

        /* Sync to WooCommerce shipping_state (province name) */
        setWooField('shipping_state', pname);
        ensureHiddenField('ghn_province_name', pname);
        ensureHiddenField('ghn_province_id', pid);

        /* Reset downstream */
        $district.find('option:gt(0)').remove();
        $ward.find('option:gt(0)').remove();
        $district.closest('.form-row').hide();
        $ward.closest('.form-row').hide();
        setWooField('shipping_city', '');
        setWooField('shipping_address_1', '');
        setWooField('ghn_district_id', '');
        setWooField('ghn_ward_code', '');
        ensureHiddenField('ghn_district_name', '');
        ensureHiddenField('ghn_ward_name', '');
        $status.text('');

        if (!pid) return;

        $status.text('Đang tải quận/huyện...');

        if (cache.districts[pid]) {
            renderDistricts(cache.districts[pid]);
            return;
        }

        $.get(ghnCheckout.ajaxUrl, {
            action:      'ghn_get_districts',
            nonce:       ghnCheckout.nonce,
            province_id: pid,
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
        districtMap = {};
        $.each(list, function (_, d) {
            districtMap[d.id] = d.name;
            var label = d.name;
            var $opt = $('<option>', { value: d.id, text: label });
            if (d.supportType === 0) {
                $opt.prop('disabled', true).text(label + ' (không hỗ trợ)');
            }
            $district.append($opt);
        });
        $status.text('');
        $district.closest('.form-row').slideDown(200);

        /* Try to pre-select saved district */
        var saved = $province.data('saved-district');
        if (saved && districtMap[saved]) {
            $province.removeData('saved-district');
            $district.val(saved).trigger('change');
        }
    }

    /* ----------------------------------------------------------------
     *  District Change → Load Wards
     * ----------------------------------------------------------------*/
    function onDistrictChange() {
        var did = $district.val();
        var dname = districtMap[did] || '';

        /* Sync to WooCommerce shipping_city (district name) */
        setWooField('shipping_city', dname);
        ensureHiddenField('ghn_district_name', dname);
        ensureHiddenField('ghn_district_id', did);

        /* Reset ward */
        $ward.find('option:gt(0)').remove();
        $ward.closest('.form-row').hide();
        setWooField('shipping_address_1', '');
        setWooField('ghn_ward_code', '');
        ensureHiddenField('ghn_ward_name', '');
        $status.text('');

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
        wardMap = {};
        $.each(list, function (_, w) {
            wardMap[w.code] = w.name;
            $ward.append($('<option>', { value: w.code, text: w.name }));
        });
        $status.text('');
        $ward.closest('.form-row').slideDown(200);

        /* Try to pre-select saved ward */
        var saved = $province.data('saved-ward');
        if (saved && wardMap[saved]) {
            $province.removeData('saved-ward');
            $ward.val(saved).trigger('change');
        }
    }

    /* ----------------------------------------------------------------
     *  Ward Change → Build full address
     * ----------------------------------------------------------------*/
    function onWardChange() {
        var wcode = $ward.val();
        var wname = wardMap[wcode] || '';
        var did   = $district.val();
        var dname = districtMap[did] || '';

        /* Build address line 1: "Phường X, Quận Y" */
        if (wname && dname) {
            setWooField('shipping_address_1', wname + ', ' + dname);
        }

        /* Update GHN meta fields */
        setWooField('ghn_ward_code', wcode);
        ensureHiddenField('ghn_ward_name', wname);

        /* Update status */
        if (wcode) {
            var pid   = $province.val();
            var pname = provinceMap[pid] || '';
            $status.html('✅ Giao đến: <strong>' + wname + ', ' + dname + ', ' + pname + '</strong>');
        }
    }

    /* ----------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Set value of a WooCommerce checkout field and trigger change event
     * so that WooCommerce / other plugins react properly.
     */
    function setWooField(name, value) {
        /* Try shipping_ prefixed field first, then plain */
        var $field = $('#shipping_' + name);
        if (!$field.length) {
            $field = $('[name="shipping_' + name + '"]');
        }
        if (!$field.length) {
            $field = $('[name="' + name + '"]');
        }
        if (!$field.length) {
            $field = $('#' + name);
        }
        if ($field.length) {
            $field.val(value).trigger('change').trigger('input');
        }
    }

    /**
     * Ensure a hidden input exists in the checkout form for submitting extra data.
     */
    function ensureHiddenField(name, value) {
        var $form = $('form.checkout');
        if (!$form.length) $form = $('form.woocommerce-checkout');
        var $field = $form.find('[name="' + name + '"]');
        if (!$field.length) {
            $field = $('<input>', { type: 'hidden', name: name });
            $form.append($field);
        }
        $field.val(value);
    }

    /**
     * Try to pre-select dropdowns from previously saved checkout values.
     * WooCommerce stores shipping_state (province name), _ghn_district_id, _ghn_ward_code in meta.
     */
    function tryPreSelect() {
        /* Province: match by shipping_state value (province name) */
        var savedState = getWooFieldValue('shipping_state');
        if (savedState && cache.provinces) {
            var matchedId = null;
            $.each(cache.provinces, function (_, p) {
                if (p.name === savedState || p.name.toLowerCase() === savedState.toLowerCase()) {
                    matchedId = p.id;
                    return false;
                }
            });
            if (matchedId) {
                $province.val(matchedId).trigger('change');
                return;
            }
        }

        /* If we have saved district/ward meta, store them for later pre-selection */
        var savedDistrict = getWooFieldValue('ghn_district_id');
        var savedWard     = getWooFieldValue('ghn_ward_code');
        if (savedDistrict) {
            $province.data('saved-district', savedDistrict);
        }
        if (savedWard) {
            $province.data('saved-ward', savedWard);
        }
    }

    function getWooFieldValue(name) {
        var $f = $('#shipping_' + name);
        if (!$f.length) $f = $('[name="shipping_' + name + '"]');
        if (!$f.length) $f = $('[name="' + name + '"]');
        if (!$f.length) $f = $('#' + name);
        return $f.length ? ($f.val() || '') : '';
    }

    /* ----------------------------------------------------------------
     *  Block Checkout Compatibility
     * ----------------------------------------------------------------*/

    /**
     * For WooCommerce Blocks checkout, we inject our fields into the
     * shipping address section via a custom extension. The selects are
     * rendered server-side inside #ghn-address-fields.
     * We re-init when the DOM changes.
     */
    function reinitIfNeeded() {
        if (!initialized && $('select#ghn_province_id').length) {
            init();
        }
    }

    /* ----------------------------------------------------------------
     *  Prevent checkout if GHN fields incomplete
     * ----------------------------------------------------------------*/
    function validateBeforeCheckout() {
        var $wrapper = $('#ghn-address-fields');
        if (!$wrapper.length) return true; /* GHN not active */

        var pid = $province ? $province.val() : '';
        var did = $district ? $district.val() : '';
        var wcode = $ward ? $ward.val() : '';

        if (!pid || !did || !wcode) {
            $status.html('⚠️ <strong>Vui lòng chọn đầy đủ Tỉnh/Quận/Phường</strong>');
            /* Scroll to GHN fields */
            $('html, body').animate({ scrollTop: $wrapper.offset().top - 100 }, 400);
            return false;
        }
        return true;
    }

    /* ----------------------------------------------------------------
     *  Init
     * ----------------------------------------------------------------*/
    $(document).ready(function () {
        init();

        /* Block checkout: watch for DOM re-renders */
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(reinitIfNeeded);
            observer.observe(document.body, { childList: true, subtree: true });
        }

        /* Validate before checkout submit */
        $(document.body).on('checkout_error', function () {
            validateBeforeCheckout();
        });

        /* Also validate on place order click */
        $('form.checkout').on('submit', function (e) {
            if (!validateBeforeCheckout()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });
    });

})(jQuery);
