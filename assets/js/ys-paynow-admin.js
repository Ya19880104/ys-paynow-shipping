/**
 * YS PayNow Shipping - Admin JavaScript
 *
 * 後台訂單管理功能。
 *
 * @package YangSheep\PayNow\Shipping
 * @since   1.0.0
 */

(function ($) {
    'use strict';

    /**
     * YS PayNow Admin
     */
    var YSPaynowAdmin = {
        /**
         * 初始化
         */
        init: function () {
            console.log('[YS PayNow Admin] Initializing...');
            this.bindEvents();
            this.listenForCVSCallback();
            console.log('[YS PayNow Admin] Events bound');
        },

        /**
         * 綁定事件
         */
        bindEvents: function () {
            var self = this;

            // 查詢物流狀態
            $(document).on('click', '.ys-paynow-query-status', function (e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                self.queryStatus(orderId, $(this));
            });

            // 取消物流單
            $(document).on('click', '.ys-paynow-cancel-order', function (e) {
                e.preventDefault();
                if (!confirm('確定要取消此物流單嗎？')) {
                    return;
                }
                var orderId = $(this).data('order-id');
                self.cancelOrder(orderId, $(this));
            });

            // 重新取號
            $(document).on('click', '.ys-paynow-reissue-order', function (e) {
                e.preventDefault();
                if (!confirm('重新取號將會作廢目前的物流單號，確定要執行嗎？')) {
                    return;
                }
                var orderId = $(this).data('order-id');
                self.reissueOrder(orderId, $(this));
            });

            // 重選超商
            $(document).on('click', '.ys-paynow-change-store', function (e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                var currentService = $(this).data('logistic-service');

                // 開啟服務選擇 Modal
                self.openStoreSelectorModal(orderId, currentService);
            });

            // 監聽 Modal 確認按鈕
            $(document).on('click', '#ys-paynow-selector-confirm', function () {
                var orderId = $(this).data('order-id');
                var selectedService = $('#ys-paynow-service-select').val();

                // 關閉 Modal
                $('#ys-paynow-selector-modal').removeClass('ys-modal-open');

                // 開啟地圖
                self.openAdminCVSMap(orderId, selectedService);
            });

            // 監聽 Modal 關閉按鈕
            $(document).on('click', '.ys-paynow-selector-close', function () {
                $('#ys-paynow-selector-modal').removeClass('ys-modal-open');
            });

            // 列印標籤 (Popup Window)
            $(document).on('click', '.ys-paynow-print-btn', function (e) {
                e.preventDefault();
                var url = $(this).data('url');
                // PayNow 有設定 X-Frame-Options，無法使用 iframe，改用彈出視窗
                window.open(url, 'ys_paynow_print', 'width=800,height=600,scrollbars=yes,resizable=yes');
            });

            // 手動取號（當取號失敗時使用）
            $(document).on('click', '.ys-paynow-manual-create', function (e) {
                e.preventDefault();
                console.log('[YS PayNow] Manual create button clicked');
                var orderId = $(this).data('order-id');
                console.log('[YS PayNow] Order ID:', orderId);
                self.manualCreate(orderId, $(this));
            });
        },

        /**
         * 查詢物流狀態
         */
        queryStatus: function (orderId, $button) {
            var self = this;

            $button.prop('disabled', true).addClass('ys-paynow-loading');

            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_query_status',
                    nonce: ys_paynow_admin_params.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotice('success', '物流狀態已更新');
                        // 重新載入頁面以顯示更新後的資料
                        location.reload();
                    } else {
                        self.showNotice('error', '查詢失敗：' + response.data);
                    }
                },
                error: function () {
                    self.showNotice('error', '發生錯誤，請稍後再試');
                },
                complete: function () {
                    $button.prop('disabled', false).removeClass('ys-paynow-loading');
                }
            });
        },

        /**
         * 取消物流單
         */
        cancelOrder: function (orderId, $button) {
            var self = this;

            $button.prop('disabled', true).addClass('ys-paynow-loading');

            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_cancel_order',
                    nonce: ys_paynow_admin_params.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotice('success', '物流單已取消');
                        location.reload();
                    } else {
                        self.showNotice('error', '取消失敗：' + response.data);
                    }
                },
                error: function () {
                    self.showNotice('error', '發生錯誤，請稍後再試');
                },
                complete: function () {
                    $button.prop('disabled', false).removeClass('ys-paynow-loading');
                }
            });
        },

        /**
         * 重新取號
         */
        reissueOrder: function (orderId, $button) {
            var self = this;

            $button.prop('disabled', true).addClass('ys-paynow-loading');

            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_reissue_order',
                    nonce: ys_paynow_admin_params.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotice('success', '重新取號成功');
                        location.reload();
                    } else {
                        self.showNotice('error', '重新取號失敗：' + response.data);
                    }
                },
                error: function () {
                    self.showNotice('error', '發生錯誤，請稍後再試');
                },
                complete: function () {
                    $button.prop('disabled', false).removeClass('ys-paynow-loading');
                }
            });
        },

        /**
         * 手動建立物流單
         */
        manualCreate: function (orderId, $button) {
            var self = this;

            $button.prop('disabled', true).addClass('ys-paynow-loading');

            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_manual_create',
                    nonce: ys_paynow_admin_params.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotice('success', '取號成功');
                        location.reload();
                    } else {
                        self.showNotice('error', '取號失敗：' + response.data);
                    }
                },
                error: function () {
                    self.showNotice('error', '發生錯誤，請稍後再試');
                },
                complete: function () {
                    $button.prop('disabled', false).removeClass('ys-paynow-loading');
                }
            });
        },

        /**
         * 開啟服務選擇 Modal
         */
        openStoreSelectorModal: function (orderId, currentService) {
            var $modal = $('#ys-paynow-selector-modal');
            if ($modal.length === 0) {
                this.createStoreSelectorModal();
                $modal = $('#ys-paynow-selector-modal');
            }

            // 設定當前選中的服務
            $modal.find('select').val(currentService);
            $modal.find('#ys-paynow-selector-confirm').data('order-id', orderId);

            $modal.addClass('ys-modal-open');
        },

        /**
         * 建立服務選擇 Modal HTML
         */
        createStoreSelectorModal: function () {
            var serviceOptions = '';
            if (ys_paynow_admin_params.available_services) {
                $.each(ys_paynow_admin_params.available_services, function (index, service) {
                    serviceOptions += '<option value="' + service.id + '">' + service.name + '</option>';
                });
            }

            var modalHtml =
                '<div id="ys-paynow-selector-modal">' +
                '<div class="ys-paynow-modal-content">' +
                '<h3>更換物流服務與門市</h3>' +
                '<p>請選擇欲使用的物流服務：</p>' +
                '<select id="ys-paynow-service-select">' +
                serviceOptions +
                '</select>' +
                '<div class="ys-paynow-modal-buttons">' +
                '<button type="button" class="button ys-paynow-selector-close">取消</button>' +
                '<button type="button" class="button button-primary" id="ys-paynow-selector-confirm">前往地圖選店</button>' +
                '</div>' +
                '</div>' +
                '</div>';

            // 載入到 WP Admin 內容區域以確保正確的位置和寬度
            var $container = $('#wpbody-content');
            if ($container.length === 0) {
                $container = $('body');
            }
            $container.append(modalHtml);
        },

        openAdminCVSMap: function (orderId, logisticService) {
            var self = this;

            // 儲存訂單 ID
            localStorage.setItem('ys_paynow_admin_order_id', orderId);
            localStorage.setItem('ys_paynow_current_service', logisticService);

            // 取得地圖 URL
            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_get_map_url',
                    nonce: ys_paynow_admin_params.nonce,
                    logistic_service: logisticService,
                    is_admin_context: 'true'
                },
                success: function (response) {
                    if (response.success && response.data) {
                        // 支援新的 POST 表單方式
                        if (response.data.action_url && response.data.params) {
                            self.openMapWithPost(response.data.action_url, response.data.params);
                        }
                        // 兼容舊的直接 URL 方式
                        else if (response.data.map_url) {
                            window.open(
                                response.data.map_url,
                                'ys_paynow_cvs_map',
                                'width=1024,height=700,scrollbars=yes'
                            );
                        } else {
                            alert('無法取得超商地圖網址：回傳格式錯誤');
                        }
                    } else {
                        var errorMsg = response.data && response.data.message
                            ? response.data.message
                            : '無法取得超商地圖網址';
                        alert(errorMsg);
                    }
                },
                error: function () {
                    alert('請求失敗，請稍後再試');
                }
            });
        },

        /**
         * 使用 POST 表單開啟地圖視窗
         */
        openMapWithPost: function (actionUrl, params) {
            // 建立隱藏的表單
            var $form = $('<form>', {
                method: 'POST',
                action: actionUrl,
                target: 'ys_paynow_cvs_map_window'
            });

            // 添加參數
            $.each(params, function (key, value) {
                $('<input>').attr({
                    type: 'hidden',
                    name: key,
                    value: value
                }).appendTo($form);
            });

            // 先開啟視窗
            window.open('', 'ys_paynow_cvs_map_window', 'width=1024,height=700,scrollbars=yes');

            // 添加表單到 body 並提交
            $form.appendTo('body').submit().remove();
        },

        /**
         * 監聽超商選擇回傳
         */
        listenForCVSCallback: function () {
            var self = this;

            window.addEventListener('message', function (event) {
                if (event.data && event.data.action === 'ys_paynow_cvs_selected') {
                    self.handleAdminCVSSelected(event.data.data);
                }
            });
        },

        /**
         * 處理後台超商選擇結果
         */
        handleAdminCVSSelected: function (storeData) {
            var self = this;
            var orderId = localStorage.getItem('ys_paynow_admin_order_id');
            // 從 localStorage 獲取選擇的服務代碼 (因為可能是從 Modal 選的新的)
            var logisticService = localStorage.getItem('ys_paynow_current_service');

            if (!orderId) {
                return;
            }

            $.ajax({
                url: ys_paynow_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'ys_paynow_admin_change_store',
                    nonce: ys_paynow_admin_params.nonce,
                    order_id: orderId,
                    logistic_service: logisticService, // 傳送服務代碼
                    store_id: storeData.store_id,
                    store_name: storeData.store_name,
                    store_addr: storeData.store_addr
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotice('success', '超商已更新');
                        localStorage.removeItem('ys_paynow_admin_order_id');
                        localStorage.removeItem('ys_paynow_current_service'); // 清除
                        location.reload();
                    } else {
                        self.showNotice('error', '更新失敗：' + response.data);
                    }
                }
            });
        },

        /**
         * 顯示通知
         */
        showNotice: function (type, message) {
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';

            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

            $('.wrap h1').first().after($notice);

            // 3 秒後自動消失
            setTimeout(function () {
                $notice.fadeOut(function () {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * 建立並開啟列印 Modal
         */
        openPrintModal: function (url) {
            var $modal = $('#ys-paynow-print-modal');

            // 如果 Modal 不存在則建立
            if ($modal.length === 0) {
                this.createPrintModal();
                $modal = $('#ys-paynow-print-modal');
            }

            // 設定 Iframe
            var $iframe = $modal.find('iframe');
            $iframe.attr('src', 'about:blank'); // Reset

            $modal.show();

            // 載入 URL
            setTimeout(function () {
                $iframe.attr('src', url);
            }, 100);
        },

        /**
         * 建立列印 Modal HTML
         */
        createPrintModal: function () {
            var modalHtml =
                '<div id="ys-paynow-print-modal" class="ys-paynow-modal" style="display:none;">' +
                '<div class="ys-paynow-modal-content" style="width: 800px; height: 90vh; display: flex; flex-direction: column;">' +
                '<div class="ys-paynow-modal-header" style="padding: 10px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">' +
                '<h3 style="margin: 0;">列印標籤</h3>' +
                '<button type="button" class="ys-paynow-modal-close" style="background:none; border:none; font-size: 24px; cursor: pointer;">&times;</button>' +
                '</div>' +
                '<div class="ys-paynow-modal-body" style="flex: 1; padding: 0; position: relative;">' +
                '<iframe name="ys-print-frame" style="width: 100%; height: 100%; border: none;" src=""></iframe>' +
                '</div>' +
                '<div class="ys-paynow-modal-footer" style="padding: 10px 20px; border-top: 1px solid #ddd; text-align: right;">' +
                '<button type="button" class="button button-primary ys-paynow-do-print">列印</button> ' +
                '<button type="button" class="button ys-paynow-open-new">另開視窗</button> ' +
                '<button type="button" class="button ys-paynow-modal-close-btn">關閉</button>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '<style>' +
                '.ys-paynow-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; align-items: center; justify-content: center; }' +
                '.ys-paynow-modal-content { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-radius: 4px; }' +
                '</style>';

            $('body').append(modalHtml);

            // 綁定事件
            $(document).on('click', '.ys-paynow-modal-close, .ys-paynow-modal-close-btn', function () {
                $('#ys-paynow-print-modal').hide();
                $('#ys-paynow-print-modal iframe').attr('src', 'about:blank');
            });

            $(document).on('click', '.ys-paynow-do-print', function () {
                var iframe = $('#ys-paynow-print-modal iframe')[0];
                try {
                    iframe.contentWindow.print();
                } catch (e) {
                    alert('無法直接列印，請點擊「列印」或嘗試「另開視窗」後列印。');
                }
            });

            $(document).on('click', '.ys-paynow-open-new', function () {
                var url = $('#ys-paynow-print-modal iframe').attr('src');
                if (url && url !== 'about:blank') {
                    window.open(url, '_blank');
                }
            });
        }
    };

    // DOM Ready
    $(function () {
        YSPaynowAdmin.init();
    });

})(jQuery);
