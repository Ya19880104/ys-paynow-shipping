/**
 * YS PayNow CVS Store Selector
 * 超商選擇功能
 *
 * @version 1.0.0
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    const YSPaynowStoreSelector = {
        // Configuration
        config: {
            debug: false, // 生產環境關閉除錯模式
            namespace: 'ys_paynow_store',
            cvsMethodPrefixes: [
                // C2C 店到店
                'ys_paynow_shipping_711',
                'ys_paynow_shipping_family',
                'ys_paynow_shipping_hilife',
                // B2C 大宗寄倉 (需在基本前綴之後，因為使用 indexOf 匹配)
                'ys_paynow_shipping_711_bulk',
                'ys_paynow_shipping_family_bulk',
                // 冷凍 (C2C 和 B2C)
                'ys_paynow_shipping_711_frozen',
                'ys_paynow_shipping_family_frozen',
                'ys_paynow_shipping_711_bulk_frozen',
                'ys_paynow_shipping_family_bulk_frozen'
            ],
            checkDelay: 200
        },

        // State management
        state: {
            initialized: false,
            currentMethod: null,
            isProcessing: false
        },

        // Cached jQuery elements
        cache: {},

        /**
         * Initialize the store selector
         */
        init: function () {
            if (this.state.initialized) {
                return;
            }

            // Setup default config
            if (typeof ys_paynow_params === 'undefined') {
                window.ys_paynow_params = {
                    ajax_url: '/wp-admin/admin-ajax.php',
                    nonce: '',
                    cvs_map_url: '',
                    labels: {
                        select_store: '選擇門市',
                        change_store: '更換門市',
                        no_store_selected: '尚未選擇取貨門市',
                        loading: '跳轉中...',
                        error: '載入失敗，請稍後再試'
                    }
                };
            }

            this.cacheElements();
            this.bindEvents();
            this.initMobilePhoneValidation();

            // Delayed initialization check
            setTimeout(() => {
                this.initializeStoreData();
                this.checkShippingMethod();
            }, this.config.checkDelay);

            this.state.initialized = true;
            this.log('YS PayNow Store Selector initialized');
        },

        /**
         * Cache frequently used jQuery elements
         */
        cacheElements: function () {
            this.cache = {
                $body: $(document.body),
                $checkoutForm: $('form.checkout, form#order_review'),
                $shippingFields: $('.woocommerce-shipping-fields'),
                $shippingWrapper: $('.woocommerce-shipping-fields__field-wrapper')
            };
        },

        /**
         * Get the selected shipping method
         */
        getSelectedShippingMethod: function () {
            let selected = $('input[name="shipping_method[0]"]:checked').val();

            // Handle case when only one shipping method exists
            if (!selected) {
                selected = $('input[name="shipping_method[0]"]').val();
            }

            this.log('Selected shipping method:', selected);
            return selected;
        },

        /**
         * Check if method is YS PayNow CVS
         */
        isCVSMethod: function (methodId) {
            if (!methodId) return false;

            for (let prefix of this.config.cvsMethodPrefixes) {
                if (methodId.indexOf(prefix) !== -1) {
                    return true;
                }
            }
            return false;
        },

        /**
         * Bind all events
         */
        bindEvents: function () {
            const ns = this.config.namespace;

            // Shipping method change
            $(document).on(`change.${ns}`, 'input[name="shipping_method[0]"]', () => {
                this.handleShippingMethodChange();
            });

            // Store map button click
            $(document).on(`click.${ns}`, '.ys-paynow-store-map-btn', (e) => {
                e.preventDefault();
                this.openStoreMap($(e.currentTarget));
            });

            // Checkout update event
            this.cache.$body.on(`updated_checkout.${ns}`, (event, data) => {
                this.handleCheckoutUpdate(data);
            });
        },

        /**
         * Initialize store data from various sources
         */
        initializeStoreData: function () {
            // Check for POST data from store map return
            const postData = this.getPostData();
            if (postData) {
                this.log('Found POST data from store map');
                this.displayStore(postData);
                this.cleanupPostData();
                // ★ 從 session 恢復結帳欄位資料
                this.restoreCheckoutFieldsFromSession();
                return;
            }

            // Check hidden fields
            const hiddenData = this.getHiddenFieldData();
            if (hiddenData) {
                this.log('Found data in hidden fields');
                this.displayStore(hiddenData);
            }

            // ★ 檢查是否需要恢復欄位資料（從地圖返回時）
            this.restoreCheckoutFieldsFromSession();
        },

        /**
         * Get POST data from store map return
         */
        getPostData: function () {
            const $tempId = $('input[name="ys_paynow_selected_store_id"]');
            const $tempName = $('input[name="ys_paynow_selected_store_name"]');
            const $tempAddress = $('input[name="ys_paynow_selected_store_address"]');

            if ($tempId.length && $tempName.length && $tempAddress.length) {
                return {
                    id: $tempId.val(),
                    name: $tempName.val(),
                    address: $tempAddress.val()
                };
            }

            return null;
        },

        /**
         * Get data from hidden fields
         */
        getHiddenFieldData: function () {
            const dataStr = $('#ys_paynow_selected_store_data').val();
            if (dataStr) {
                try {
                    return JSON.parse(dataStr);
                } catch (e) {
                    this.log('Error parsing hidden field data', e);
                }
            }
            return null;
        },

        /**
         * Clean up temporary POST data fields
         */
        cleanupPostData: function () {
            $('input[name="ys_paynow_selected_store_id"]').remove();
            $('input[name="ys_paynow_selected_store_name"]').remove();
            $('input[name="ys_paynow_selected_store_address"]').remove();
            $('input[name="ys_paynow_selected_store_data"]:not(#ys_paynow_selected_store_data)').remove();
        },

        /**
         * Handle shipping method change
         */
        handleShippingMethodChange: function () {
            const selected = this.getSelectedShippingMethod();
            const methodId = selected ? selected.split(':')[0] : '';
            const previousMethodId = this.state.currentMethod ? this.state.currentMethod.split(':')[0] : '';

            if (selected !== this.state.currentMethod) {
                // Check if switching between different CVS types
                const wasCVS = this.isCVSMethod(previousMethodId);
                const isCVS = this.isCVSMethod(methodId);

                // If switching from one CVS to another CVS (different type), clear store data
                if (wasCVS && isCVS && previousMethodId !== methodId) {
                    this.log('Switching CVS types, clearing store data');
                    this.clearStoreData();
                }

                this.state.currentMethod = selected;
                this.checkShippingMethod();
            }
        },

        /**
         * Check current shipping method and update UI
         */
        checkShippingMethod: function () {
            const selected = this.getSelectedShippingMethod();
            const methodId = selected ? selected.split(':')[0] : '';
            const isCVS = this.isCVSMethod(methodId);

            if (isCVS) {
                this.showStoreSelector();
                this.updateFieldVisibility();
            } else {
                this.hideStoreSelector();
                this.restoreFieldVisibility();
            }
        },

        /**
         * Handle checkout update event
         */
        handleCheckoutUpdate: function (data) {
            this.checkShippingMethod();

            // Check for store data in fragments
            if (data && data.fragments && data.fragments.ys_paynow_stored_data) {
                const selected = this.getSelectedShippingMethod();
                const methodId = selected ? selected.split(':')[0] : '';

                if (this.isCVSMethod(methodId)) {
                    this.displayStore(data.fragments.ys_paynow_stored_data);
                }
            }

            setTimeout(() => {
                this.updateFieldVisibility();
            }, 100);
        },

        /**
         * Show store selector UI
         */
        showStoreSelector: function () {
            this.ensureHiddenFields();

            // 顯示外層容器（使用唯一 ID 選擇器）
            const $container = $('#ys-paynow-store-selector-container');

            if ($container.length === 0) {
                this.log('Store selector container not found - server may not have rendered it');
                return;
            }

            // 使用 CSS class 控制顯示（配合 AJAX fragment 更新）
            $container.removeClass('ys-paynow-hidden').show();
            $('.ys-paynow-store-selector').removeClass('ys-paynow-hidden').show();

            // 檢查 PHP 是否已經渲染了「未選擇門市」的狀態
            const $noStore = $container.find('.ys-paynow-no-store');
            const $selectedStore = $container.find('.ys-paynow-selected-store');
            const serverHasNoStore = $noStore.length > 0 && ($selectedStore.length === 0 || $selectedStore.find('.store-name').text().trim() === '');

            if (serverHasNoStore) {
                // Server 已清空，同步清空前端 hidden field
                $('#ys_paynow_selected_store_id').val('');
                $('#ys_paynow_selected_store_data').val('');
                $selectedStore.hide();
                $noStore.show();
                this.log('Store selector shown, server has no store data - synced');
                return;
            }

            // 檢查是否有已選擇的門市資料
            const hasStoreData = this.checkExistingStoreData();

            // 如果沒有已選擇的門市，確保顯示「選擇門市」區塊
            if (!hasStoreData) {
                $selectedStore.hide();
                $noStore.show();
            }

            this.log('Store selector shown, hasStoreData:', hasStoreData);
        },

        /**
         * Hide store selector UI
         */
        hideStoreSelector: function () {
            // 隱藏所有 YS PayNow 選擇器（使用 CSS class 配合 AJAX fragment）
            $('#ys-paynow-store-selector-container').addClass('ys-paynow-hidden').hide();
            $('.ys-paynow-store-selector').addClass('ys-paynow-hidden').hide();
            $('.ys-paynow-store-selector-row, .ys-paynow-store-selector-wrapper').addClass('ys-paynow-hidden').hide();
        },

        /**
         * Ensure hidden fields exist in the form
         */
        ensureHiddenFields: function () {
            const $form = this.cache.$checkoutForm;

            if ($form.length === 0) {
                this.log('Warning: Checkout form not found');
                return;
            }

            // Create hidden fields if they don't exist
            if ($('#ys_paynow_selected_store_id').length === 0) {
                $form.append('<input type="hidden" name="ys_paynow_selected_store_id" id="ys_paynow_selected_store_id" value="">');
            }

            if ($('#ys_paynow_selected_store_data').length === 0) {
                $form.append('<input type="hidden" name="ys_paynow_selected_store_data" id="ys_paynow_selected_store_data" value="">');
            }
        },

        /**
         * Check for existing store data
         */
        checkExistingStoreData: function () {
            const data = this.getHiddenFieldData();
            if (data) {
                this.displayStore(data);
                return true;
            }
            return false;
        },

        /**
         * Open store map for selection
         */
        openStoreMap: function ($btn) {
            if (this.state.isProcessing) {
                return;
            }

            // Fallback if no button passed (shouldn't happen with new binding)
            if (!$btn || !$btn.length) {
                $btn = $('.ys-paynow-store-map-btn').first();
            }

            this.state.isProcessing = true;
            $btn.prop('disabled', true).text(ys_paynow_params.labels?.loading || '跳轉中...');

            // ★ 跳轉前保存結帳欄位資料到 session
            this.saveCheckoutFieldsToSession();

            // Get selected shipping method
            const shippingMethod = this.getSelectedShippingMethod();
            const methodId = shippingMethod ? shippingMethod.split(':')[0] : '';

            // Get logistic service from the button's container
            const $selector = $btn.closest('.ys-paynow-store-selector');
            let logisticService = $selector.data('logistic-service');

            // Fallback: Use service mapping if DOM data is missing
            if (!logisticService && methodId && ys_paynow_params.service_mapping) {
                logisticService = ys_paynow_params.service_mapping[methodId];
                this.log('Using fallback service mapping for:', methodId, '=>', logisticService);
            }

            this.log('Opening map for service:', logisticService, 'Method:', shippingMethod);

            // Request map URL via AJAX
            $.ajax({
                url: ys_paynow_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_get_map_url',
                    nonce: ys_paynow_params.nonce,
                    logistic_service: logisticService,
                    shipping_method: shippingMethod
                },
                success: (response) => {
                    if (response.success && response.data) {
                        if (response.data.action_url && response.data.params) {
                            this.submitStoreMapForm(response.data.action_url, response.data.params);
                        } else if (response.data.map_url) {
                            // Fallback for old way
                            window.location.href = response.data.map_url;
                        }
                    } else {
                        this.handleMapError(response.data ? response.data.message : null);
                    }
                },
                error: () => {
                    this.handleMapError();
                },
                complete: () => {
                    // Don't turn off processing immediately if we are redirecting
                    setTimeout(() => {
                        this.state.isProcessing = false;
                    }, 5000); // Reset after 5s to prevent double click, but allow user to stay on page if new window opens
                }
            });
        },

        /**
         * Submit store map form via POST
         */
        submitStoreMapForm: function (actionUrl, params) {
            // Create form
            let $form = $('<form>', {
                action: actionUrl,
                method: 'POST',
                target: '_self' // Or _blank if you prefer, but usually self for this flow
            });

            // Append params
            $.each(params, function (key, value) {
                $form.append($('<input>', {
                    type: 'hidden',
                    name: key,
                    value: value
                }));
            });

            // Append to body and submit
            $('body').append($form);
            $form.submit();
        },

        /**
         * Handle map error
         */
        handleMapError: function (message) {
            alert(message || ys_paynow_params.labels?.error || '載入失敗');

            // Reset ALL buttons text just in case
            $('.ys-paynow-store-map-btn')
                .prop('disabled', false)
                .text(ys_paynow_params.labels?.select_store || '選擇門市');

            this.state.isProcessing = false;
        },

        /**
         * Display selected store information
         */
        displayStore: function (storeData) {
            if (!storeData || !storeData.id || !storeData.name) {
                this.log('Invalid store data');
                return;
            }

            // Ensure hidden fields exist
            this.ensureHiddenFields();

            // Save to hidden fields
            $('#ys_paynow_selected_store_id').val(storeData.id);
            $('#ys_paynow_selected_store_data').val(JSON.stringify(storeData));

            // Update UI
            const $selectedStore = $('.ys-paynow-selected-store');
            $selectedStore.find('.store-name').text(storeData.name);
            $selectedStore.find('.store-address').text(storeData.address);
            $selectedStore.find('.store-id').text('門市代號: ' + storeData.id);

            // Show selected store, hide no-store message
            $('.ys-paynow-no-store').hide();
            $selectedStore.show();

            // Update button text
            $selectedStore.find('.ys-paynow-store-map-btn').text('更換門市');
        },

        /**
         * Clear store data
         */
        clearStoreData: function () {
            this.log('Clearing store data...');

            $('#ys_paynow_selected_store_id').val('');
            $('#ys_paynow_selected_store_data').val('');

            $('.ys-paynow-selected-store').hide();
            $('.ys-paynow-no-store').show();

            // Clear from server session
            if (ys_paynow_params.ajax_url) {
                $.post(ys_paynow_params.ajax_url, {
                    action: 'ys_paynow_clear_store_data',
                    nonce: ys_paynow_params.nonce
                }).done(() => {
                    this.log('Store data cleared from server');
                    // Trigger checkout update to refresh UI from server
                    $(document.body).trigger('update_checkout');
                });
            }
        },

        /**
         * Update field visibility for CVS shipping
         * 根據後台設定決定是否隱藏帳單/運送地址欄位
         */
        updateFieldVisibility: function () {
            const selected = this.getSelectedShippingMethod();
            const methodId = selected ? selected.split(':')[0] : '';
            const isCVS = this.isCVSMethod(methodId);

            if (isCVS) {
                // Hide address fields for CVS based on settings
                this.hideAddressFields();
            } else {
                this.showAddressFields();
            }
        },

        /**
         * Hide address fields for CVS shipping
         * 根據後台設定 (cvs_settings) 決定隱藏哪些欄位
         * ★ 支援傳統結帳頁和 Block Checkout
         */
        hideAddressFields: function () {
            const settings = (typeof ys_paynow_params !== 'undefined' && ys_paynow_params.cvs_settings)
                ? ys_paynow_params.cvs_settings
                : {};

            // ★ 檢測是否為 Block Checkout
            const isBlockCheckout = this.isBlockCheckout();

            if (isBlockCheckout) {
                // Block Checkout：填入預設值而非隱藏欄位
                this.fillDefaultAddressForBlocks(settings);
                return;
            }

            // ===== 傳統結帳頁處理 =====
            const defaultAddress = 'N/A';
            const defaultCountry = 'TW';

            // ★ 判斷是否啟用「運送到不同地址」
            const shipToDifferentAddress = $('#ship-to-different-address-checkbox').is(':checked');

            // 運送地址欄位處理（當啟用運送地址區塊時）
            if (settings.hide_shipping_address !== false && shipToDifferentAddress) {
                const shippingFieldsToHide = [
                    '#shipping_postcode_field',
                    '#shipping_state_field',
                    '#shipping_city_field',
                    '#shipping_address_1_field',
                    '#shipping_address_2_field',
                    '#shipping_company_field'
                ];

                shippingFieldsToHide.forEach(field => {
                    const $field = $(field);
                    const $input = $field.find('input, select');

                    // 隱藏欄位
                    $field.hide();

                    // ★ 移除必填驗證
                    if ($input.length) {
                        if ($input.prop('required') || $field.hasClass('validate-required')) {
                            $input.data('ys-was-required', true);
                        }
                        $input.prop('required', false).removeAttr('required');
                        $field.removeClass('validate-required');
                    }
                });

                // ★ 國家隱藏，只在空時填入 TW（不覆蓋已有值）
                const $shippingCountryField = $('#shipping_country_field');
                $shippingCountryField.hide();
                const $shippingCountry = $('#shipping_country');
                if ($shippingCountry.length && !$shippingCountry.val()) {
                    $shippingCountry.val(defaultCountry).trigger('change');
                }

                // ★ 只填入地址（只在空時填入 N/A，不覆蓋已有值）
                const $shippingAddress1 = $('#shipping_address_1');
                if ($shippingAddress1.length && !$shippingAddress1.val()) {
                    $shippingAddress1.val(defaultAddress);
                }

                // 確保姓名和電話欄位顯示
                $('#shipping_first_name_field').show();
                $('#shipping_last_name_field').show();
                $('#shipping_phone_field').show();
            }

            // ★ 帳單地址欄位處理
            // 情況1：啟用隱藏帳單地址設定
            // 情況2：沒有啟用「運送到不同地址」，帳單地址是主要地址
            const shouldHandleBilling = settings.hide_billing_address === true || !shipToDifferentAddress;

            if (shouldHandleBilling) {
                const billingFieldsToHide = [
                    '#billing_postcode_field',
                    '#billing_state_field',
                    '#billing_city_field',
                    '#billing_address_1_field',
                    '#billing_address_2_field',
                    '#billing_company_field'
                ];

                billingFieldsToHide.forEach(field => {
                    const $field = $(field);
                    const $input = $field.find('input, select');

                    // 隱藏欄位
                    $field.hide();

                    // ★ 移除必填驗證
                    if ($input.length) {
                        if ($input.prop('required') || $field.hasClass('validate-required')) {
                            $input.data('ys-was-required', true);
                        }
                        $input.prop('required', false).removeAttr('required');
                        $field.removeClass('validate-required');
                    }
                });

                // ★ 國家隱藏，只在空時填入 TW（不覆蓋已有值）
                const $billingCountryField = $('#billing_country_field');
                $billingCountryField.hide();
                const $billingCountry = $('#billing_country');
                if ($billingCountry.length && !$billingCountry.val()) {
                    $billingCountry.val(defaultCountry).trigger('change');
                }

                // ★ 只填入地址（只在空時填入 N/A，不覆蓋已有值）
                const $billingAddress1 = $('#billing_address_1');
                if ($billingAddress1.length && !$billingAddress1.val()) {
                    $billingAddress1.val(defaultAddress);
                }

                // 確保帳單姓名、電話、Email 顯示
                $('#billing_first_name_field').show();
                $('#billing_last_name_field').show();
                $('#billing_phone_field').show();
                $('#billing_email_field').show();
            }
        },

        /**
         * 檢測是否為 Block Checkout（區塊結帳頁）
         */
        isBlockCheckout: function () {
            // Block Checkout 使用 wc-block-checkout class
            return $('.wc-block-checkout').length > 0 ||
                   $('.wp-block-woocommerce-checkout').length > 0 ||
                   $('[data-block-name="woocommerce/checkout"]').length > 0;
        },

        /**
         * Block Checkout：填入預設地址值
         * Block Checkout 無法直接隱藏欄位或移除驗證，
         * 所以改為自動填入預設值來通過驗證。
         */
        fillDefaultAddressForBlocks: function (settings) {
            const defaultValue = '超商取貨';
            const defaultCountry = 'TW';

            // 運送地址欄位
            if (settings.hide_shipping_address !== false) {
                // 使用 WooCommerce Blocks 的 data store（如果可用）
                if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                    try {
                        const store = wp.data.dispatch('wc/store/cart');
                        if (store && store.setShippingAddress) {
                            store.setShippingAddress({
                                address_1: defaultValue,
                                city: defaultValue,
                                country: defaultCountry,
                            });
                            this.log('Block Checkout: Set shipping address via store');
                        }
                    } catch (e) {
                        this.log('Block Checkout: Store API not available', e);
                    }
                }

                // ★ 備用方案：直接操作 DOM input
                this.fillBlockInput('shipping-address_1', defaultValue);
                this.fillBlockInput('shipping-city', defaultValue);
                this.fillBlockInput('shipping-country', defaultCountry);
                this.fillBlockInput('shipping-postcode', '100'); // 台北市中正區
                this.fillBlockInput('shipping-state', '');
            }

            // 帳單地址欄位
            if (settings.hide_billing_address === true) {
                if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                    try {
                        const store = wp.data.dispatch('wc/store/cart');
                        if (store && store.setBillingAddress) {
                            store.setBillingAddress({
                                address_1: defaultValue,
                                city: defaultValue,
                                country: defaultCountry,
                            });
                            this.log('Block Checkout: Set billing address via store');
                        }
                    } catch (e) {
                        this.log('Block Checkout: Store API not available', e);
                    }
                }

                // ★ 備用方案：直接操作 DOM input
                this.fillBlockInput('billing-address_1', defaultValue);
                this.fillBlockInput('billing-city', defaultValue);
                this.fillBlockInput('billing-country', defaultCountry);
                this.fillBlockInput('billing-postcode', '100');
                this.fillBlockInput('billing-state', '');
            }
        },

        /**
         * 填入 Block Checkout 的 input 欄位
         */
        fillBlockInput: function (fieldId, value) {
            // Block Checkout 使用 id 格式如 "shipping-address_1"
            const $input = $(`#${fieldId}, input[id="${fieldId}"], input[name="${fieldId}"]`);
            if ($input.length && !$input.val()) {
                $input.val(value).trigger('change').trigger('input');
                this.log(`Block Checkout: Filled ${fieldId} with "${value}"`);
            }
        },

        /**
         * Show all address fields
         * 恢復所有地址欄位顯示，並恢復必填驗證
         */
        showAddressFields: function () {
            // 運送地址欄位（需恢復必填和啟用）
            const shippingFieldsToRestore = [
                '#shipping_country_field',
                '#shipping_postcode_field',
                '#shipping_state_field',
                '#shipping_city_field',
                '#shipping_address_1_field',
                '#shipping_address_2_field',
                '#shipping_company_field'
            ];

            shippingFieldsToRestore.forEach(field => {
                const $field = $(field);
                const $input = $field.find('input, select');

                // 顯示欄位
                $field.show();

                // ★ 恢復啟用和必填狀態
                if ($input.length) {
                    // 啟用欄位
                    $input.prop('disabled', false);

                    // 恢復必填（如果之前是必填的）
                    if ($input.data('ys-was-required')) {
                        $input.prop('required', true).attr('required', 'required');
                        $field.addClass('validate-required');
                    }
                }
            });

            // 其他運送欄位（只需顯示）
            const shippingFieldsToShow = [
                '#shipping_first_name_field',
                '#shipping_last_name_field',
                '#shipping_phone_field'
            ];

            shippingFieldsToShow.forEach(field => {
                $(field).show();
            });

            // 帳單地址欄位（需恢復必填和啟用）
            const billingFieldsToRestore = [
                '#billing_country_field',
                '#billing_postcode_field',
                '#billing_state_field',
                '#billing_city_field',
                '#billing_address_1_field',
                '#billing_address_2_field',
                '#billing_company_field'
            ];

            billingFieldsToRestore.forEach(field => {
                const $field = $(field);
                const $input = $field.find('input, select');

                // 顯示欄位
                $field.show();

                // ★ 恢復啟用和必填狀態
                if ($input.length) {
                    // 啟用欄位
                    $input.prop('disabled', false);

                    // 恢復必填（如果之前是必填的）
                    if ($input.data('ys-was-required')) {
                        $input.prop('required', true).attr('required', 'required');
                        $field.addClass('validate-required');
                    }
                }
            });

            // 其他帳單欄位（只需顯示）
            const billingFieldsToShow = [
                '#billing_first_name_field',
                '#billing_last_name_field',
                '#billing_phone_field',
                '#billing_email_field'
            ];

            billingFieldsToShow.forEach(field => {
                $(field).show();
            });
        },

        /**
         * Restore normal field visibility
         */
        restoreFieldVisibility: function () {
            this.showAddressFields();
        },

        /**
         * ★ 保存結帳欄位資料到 sessionStorage
         * 在跳轉到超商地圖前調用
         */
        saveCheckoutFieldsToSession: function () {
            const fieldsToSave = {
                // 帳單欄位
                billing_first_name: $('#billing_first_name').val() || '',
                billing_last_name: $('#billing_last_name').val() || '',
                billing_phone: $('#billing_phone').val() || '',
                billing_email: $('#billing_email').val() || '',
                billing_country: $('#billing_country').val() || '',
                billing_postcode: $('#billing_postcode').val() || '',
                billing_state: $('#billing_state').val() || '',
                billing_city: $('#billing_city').val() || '',
                billing_address_1: $('#billing_address_1').val() || '',
                billing_address_2: $('#billing_address_2').val() || '',
                billing_company: $('#billing_company').val() || '',
                // 運送欄位
                shipping_first_name: $('#shipping_first_name').val() || '',
                shipping_last_name: $('#shipping_last_name').val() || '',
                shipping_phone: $('#shipping_phone').val() || '',
                shipping_country: $('#shipping_country').val() || '',
                shipping_postcode: $('#shipping_postcode').val() || '',
                shipping_state: $('#shipping_state').val() || '',
                shipping_city: $('#shipping_city').val() || '',
                shipping_address_1: $('#shipping_address_1').val() || '',
                shipping_address_2: $('#shipping_address_2').val() || '',
                shipping_company: $('#shipping_company').val() || '',
                // 其他設定
                ship_to_different_address: $('#ship-to-different-address-checkbox').is(':checked'),
                order_comments: $('#order_comments').val() || ''
            };

            // 過濾掉空值和預設值
            const filteredFields = {};
            for (const [key, value] of Object.entries(fieldsToSave)) {
                if (value && value !== 'N/A' && value !== '超商取貨') {
                    filteredFields[key] = value;
                }
            }

            try {
                sessionStorage.setItem('ys_paynow_checkout_fields', JSON.stringify(filteredFields));
                this.log('Saved checkout fields to session:', Object.keys(filteredFields).length, 'fields');
            } catch (e) {
                this.log('Error saving checkout fields:', e);
            }
        },

        /**
         * ★ 從 sessionStorage 恢復結帳欄位資料
         * 從超商地圖返回後調用
         */
        restoreCheckoutFieldsFromSession: function () {
            try {
                const savedData = sessionStorage.getItem('ys_paynow_checkout_fields');
                if (!savedData) {
                    this.log('No saved checkout fields found in session');
                    return;
                }

                const fields = JSON.parse(savedData);
                this.log('Restoring checkout fields from session:', Object.keys(fields).length, 'fields', fields);

                // 先處理國家欄位（因為它會影響其他欄位的選項）
                if (fields.billing_country) {
                    const $billingCountry = $('#billing_country');
                    if ($billingCountry.length) {
                        $billingCountry.val(fields.billing_country).trigger('change');
                    }
                }
                if (fields.shipping_country) {
                    const $shippingCountry = $('#shipping_country');
                    if ($shippingCountry.length) {
                        $shippingCountry.val(fields.shipping_country).trigger('change');
                    }
                }

                // 稍微延遲後填入其他欄位（等待國家變更觸發的 AJAX 完成）
                setTimeout(() => {
                    this.fillFieldsFromData(fields);
                }, 500);

                // 清除 session 資料（只恢復一次）
                sessionStorage.removeItem('ys_paynow_checkout_fields');

            } catch (e) {
                this.log('Error restoring checkout fields:', e);
            }
        },

        /**
         * ★ 填入欄位資料
         */
        fillFieldsFromData: function (fields) {
            // 需要填入的欄位對應
            const fieldMapping = {
                billing_first_name: '#billing_first_name',
                billing_last_name: '#billing_last_name',
                billing_phone: '#billing_phone',
                billing_email: '#billing_email',
                billing_postcode: '#billing_postcode',
                billing_state: '#billing_state',
                billing_city: '#billing_city',
                billing_address_1: '#billing_address_1',
                billing_address_2: '#billing_address_2',
                billing_company: '#billing_company',
                shipping_first_name: '#shipping_first_name',
                shipping_last_name: '#shipping_last_name',
                shipping_phone: '#shipping_phone',
                shipping_postcode: '#shipping_postcode',
                shipping_state: '#shipping_state',
                shipping_city: '#shipping_city',
                shipping_address_1: '#shipping_address_1',
                shipping_address_2: '#shipping_address_2',
                shipping_company: '#shipping_company',
                order_comments: '#order_comments'
            };

            for (const [key, selector] of Object.entries(fieldMapping)) {
                if (fields[key]) {
                    const $field = $(selector);
                    // ★ 強制覆蓋欄位值（用戶在跳轉前填寫的值優先）
                    if ($field.length) {
                        $field.val(fields[key]).trigger('change');
                        this.log('Restored field:', key, '=', fields[key]);
                    }
                }
            }

            // 處理「運送到不同地址」checkbox
            if (fields.ship_to_different_address !== undefined) {
                const $checkbox = $('#ship-to-different-address-checkbox');
                if ($checkbox.length) {
                    $checkbox.prop('checked', fields.ship_to_different_address);
                    if (fields.ship_to_different_address) {
                        $('.shipping_address').show();
                    }
                }
            }

            this.log('Fields restoration completed');
        },

        /**
         * ★ 初始化手機號碼驗證
         * 針對 shipping_phone 欄位進行台灣手機格式驗證（09 開頭，10 位數字）
         */
        initMobilePhoneValidation: function () {
            const self = this;
            const ns = this.config.namespace;

            // 綁定 input 事件（即時驗證）
            $(document).on(`input.${ns}`, '#shipping_phone, .ys-validate-mobile input, input[name="shipping_phone"]', function () {
                self.validateMobilePhone($(this));
            });

            // 綁定 blur 事件（失焦時驗證）
            $(document).on(`blur.${ns}`, '#shipping_phone, .ys-validate-mobile input, input[name="shipping_phone"]', function () {
                self.validateMobilePhone($(this), true);
            });

            // 綁定表單提交事件
            $(document).on(`submit.${ns}`, 'form.checkout, form#order_review', function (e) {
                const $phoneField = $('#shipping_phone, .ys-validate-mobile input, input[name="shipping_phone"]').first();
                if ($phoneField.length && $phoneField.is(':visible')) {
                    if (!self.validateMobilePhone($phoneField, true)) {
                        e.preventDefault();
                        $phoneField.focus();
                        return false;
                    }
                }
            });

            // 綁定 WooCommerce checkout 驗證事件
            $(document).on(`checkout_error.${ns}`, function () {
                const $phoneField = $('#shipping_phone, .ys-validate-mobile input, input[name="shipping_phone"]').first();
                if ($phoneField.length) {
                    self.validateMobilePhone($phoneField, true);
                }
            });

            this.log('Mobile phone validation initialized');
        },

        /**
         * ★ 驗證手機號碼格式
         * @param {jQuery} $input - 輸入欄位
         * @param {boolean} showError - 是否顯示錯誤訊息
         * @returns {boolean} - 是否驗證通過
         */
        validateMobilePhone: function ($input, showError = false) {
            if (!$input || !$input.length) return true;

            const value = $input.val().trim();
            const $field = $input.closest('.form-row, .woocommerce-input-wrapper').parent();
            const fieldId = $input.attr('id') || 'shipping_phone';

            // 移除舊的錯誤訊息
            $field.find('.ys-phone-error').remove();
            $field.removeClass('woocommerce-invalid woocommerce-invalid-phone ys-invalid-phone');
            $input.removeClass('ys-input-error');

            // 如果欄位為空且不是必填，跳過驗證
            if (!value) {
                if ($input.prop('required') || $field.hasClass('validate-required')) {
                    if (showError) {
                        this.showPhoneError($input, $field, '請輸入手機號碼');
                    }
                    return false;
                }
                return true;
            }

            // 只允許數字
            const numericValue = value.replace(/\D/g, '');
            if (numericValue !== value) {
                // 自動移除非數字字元
                $input.val(numericValue);
            }

            // 驗證格式：必須是 09 開頭的 10 位數字
            const isValid = /^09\d{8}$/.test(numericValue);

            if (!isValid && showError) {
                let errorMsg = '請輸入有效的手機號碼';
                if (numericValue.length > 0 && !numericValue.startsWith('09')) {
                    errorMsg = '手機號碼必須為 09 開頭';
                } else if (numericValue.length !== 10) {
                    errorMsg = '手機號碼必須為 10 位數字';
                }
                this.showPhoneError($input, $field, errorMsg);
            }

            return isValid;
        },

        /**
         * ★ 顯示手機號碼錯誤訊息
         */
        showPhoneError: function ($input, $field, message) {
            $field.addClass('woocommerce-invalid woocommerce-invalid-phone ys-invalid-phone');
            $input.addClass('ys-input-error');

            // 加入錯誤訊息
            const $error = $('<span class="ys-phone-error" role="alert" style="color:#e2401c;font-size:12px;display:block;margin-top:5px;">' + message + '</span>');

            // 嘗試插入到適當位置
            const $wrapper = $input.closest('.woocommerce-input-wrapper');
            if ($wrapper.length) {
                $wrapper.append($error);
            } else {
                $input.after($error);
            }
        },

        /**
         * Logging utility
         */
        log: function (...args) {
            if (this.config.debug) {
                console.log('[YS PayNow Store]', ...args);
            }
        }
    };

    /**
     * YS PayNow Logistic Status
     * 物流狀態查詢功能 (新版卡片式 UI)
     */
    var YSPaynowLogisticStatus = {
        init: function () {
            $(document).on('click', '.ys-refresh-btn', this.handleRefresh);
            $(document).on('click', '.ys-copyable', this.handleCopy);
        },

        handleRefresh: function (e) {
            e.preventDefault();
            var $btn = $(this);
            var orderId = $btn.closest('.ys-logistics-card').data('order-id');
            var $card = $btn.closest('.ys-logistics-card');
            var $icon = $btn.find('.fa-sync-alt');

            if ($btn.hasClass('loading')) return;

            $btn.addClass('loading');
            $icon.addClass('loading');

            $.ajax({
                url: ys_paynow_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_user_query_status',
                    nonce: ys_paynow_params.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        var data = response.data;

                        // Update Status Text & Time
                        $card.find('.ys-current-status').text(data.status_text);
                        if (data.update_time) {
                            $card.find('.ys-status-time').text(data.update_time);
                        }

                        // Update Card Class (Color)
                        $card.removeClass('status-pending status-shipping status-arrived status-completed')
                            .addClass(data.status_class);

                        // Update Progress Bar
                        if (data.progress_pct) {
                            $card.find('.ys-progress-line-active').css('width', data.progress_pct);
                        }

                        // Update Steps
                        // PHP 步驟從 1 開始，JS index 從 0 開始
                        // current_step = 2 表示「商品準備中」，需要讓 index 0 和 1 都 active
                        if (data.current_step) {
                            var stepIndex = parseInt(data.current_step);
                            $card.find('.ys-step').each(function (index) {
                                // index + 1 <= current_step 表示該步驟應該 active
                                if (index + 1 <= stepIndex) {
                                    $(this).addClass('active');
                                } else {
                                    $(this).removeClass('active');
                                }
                            });
                        }

                        YSPaynowLogisticStatus.showToast('貨態已是最新');
                    } else {
                        YSPaynowLogisticStatus.showToast('查詢失敗: ' + (response.data || '未知錯誤'));
                    }
                },
                error: function () {
                    YSPaynowLogisticStatus.showToast('連線失敗，請稍後再試');
                },
                complete: function () {
                    $btn.removeClass('loading');
                    $icon.removeClass('loading');
                }
            });
        },

        handleCopy: function (e) {
            e.preventDefault();
            var text = $(this).data('clipboard-text');
            if (text && navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () {
                    YSPaynowLogisticStatus.showToast('物流單號已複製！');
                }).catch(function () {
                    YSPaynowLogisticStatus.showToast('複製失敗');
                });
            }
        },

        showToast: function (message) {
            var $toast = $('.ys-toast-notification');
            if ($toast.length === 0) {
                $toast = $('<div class="ys-toast-notification"></div>');
                $('body').append($toast);
            }
            $toast.text(message).addClass('show');
            setTimeout(function () {
                $toast.removeClass('show');
            }, 2000);
        }
    };

    // Expose for debugging
    window.YSPaynowStoreSelector = YSPaynowStoreSelector;
    window.YSPaynowLogisticStatus = YSPaynowLogisticStatus;

    // Initialize on document ready
    $(document).ready(function () {
        YSPaynowStoreSelector.init();
        YSPaynowLogisticStatus.init();
    });

})(jQuery);
