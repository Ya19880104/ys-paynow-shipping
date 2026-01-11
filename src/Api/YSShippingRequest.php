<?php
/**
 * YS Shipping Request
 *
 * 處理 PayNow 物流 API 請求。
 *
 * @package YangSheep\PayNow\Shipping\Api
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Api;

use YangSheep\PayNow\Shipping\YSPaynowShipping;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingRequest 類別
 *
 * 封裝所有 PayNow 物流 API 呼叫，包括建立物流單、查詢狀態、取消訂單、列印標籤等。
 *
 * @since 1.0.0
 */
class YSShippingRequest {

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 訂單狀態變更為處理中時，自動建立物流單
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'create_logistic_order' ), 10, 1 );

		// AJAX: 查詢物流狀態
		add_action( 'wp_ajax_ys_paynow_query_status', array( __CLASS__, 'ajax_query_status' ) );

		// AJAX: 取消物流單
		add_action( 'wp_ajax_ys_paynow_cancel_order', array( __CLASS__, 'ajax_cancel_order' ) );

		// AJAX: 列印標籤
		add_action( 'wp_ajax_ys_paynow_print_label', array( __CLASS__, 'ajax_print_label' ) );

		// AJAX: 取得地圖網址 (後台重選超商用)
		add_action( 'wp_ajax_ys_paynow_get_map_url', array( __CLASS__, 'ajax_get_map_url' ) );

		// AJAX: 前台查詢物流狀態 (用戶端)
		add_action( 'wp_ajax_ys_paynow_user_query_status', array( __CLASS__, 'ajax_user_query_status' ) );

		// 後台重選超商後重新建立物流單
		add_action( 'ys_paynow_after_admin_changed_store', array( __CLASS__, 'recreate_order_after_store_change' ), 10, 1 );

		// AJAX: 重新取號
		add_action( 'wp_ajax_ys_paynow_reissue_order', array( __CLASS__, 'ajax_reissue_order' ) );

		// AJAX: 手動取號
		add_action( 'wp_ajax_ys_paynow_manual_create', array( __CLASS__, 'ajax_manual_create' ) );
	}

	/**
	 * 建立物流單
	 *
	 * 當訂單狀態變更為處理中時，自動呼叫 PayNow API 建立物流單。
	 *
	 * @param int|\WC_Order $order 訂單 ID 或訂單物件。
	 * @return void
	 */
	public static function create_logistic_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return;
		}

		// 檢查是否為 YS PayNow 物流方式
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( empty( $logistic_service_id ) ) {
			return;
		}

		// 檢查是否已建立物流單
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		if ( ! empty( $logistic_number ) ) {
			YSPaynowShipping::log( sprintf( 'Order #%d already has logistic number: %s', $order->get_id(), $logistic_number ) );
			return;
		}

		try {
			$response = self::api_create_order( $order );
			$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );

			YSPaynowShipping::log( sprintf( 'Create order #%d response: %s', $order->get_id(), wp_json_encode( $resp_obj ) ) );

			if ( isset( $resp_obj->Status ) && 'F' === $resp_obj->Status ) {
				/* translators: %s: Error message */
				$order->add_order_note( sprintf( __( '建立物流單失敗：%s', 'ys-paynow-shipping' ), $resp_obj->ErrorMsg ) );
				throw new \Exception( $resp_obj->ErrorMsg );
			}

			// 儲存物流單資訊 (使用 HPOS 相容方法)
			if ( isset( $resp_obj->LogisticNumber ) ) {
				$order->update_meta_data( YSOrderMeta::LogisticNumber, $resp_obj->LogisticNumber );
				$order->update_meta_data( YSOrderMeta::PaymentNo, $resp_obj->paymentno ?? '' );
				$order->update_meta_data( YSOrderMeta::ValidationNo, $resp_obj->validationno ?? '' );
				$order->update_meta_data( YSOrderMeta::LogisticService, $resp_obj->LogisticService ?? '' );
				$order->update_meta_data( YSOrderMeta::ReturnMsg, $resp_obj->ReturnMsg ?? '' );
				$order->save();

				/* translators: %s: Logistic number */
				$order->add_order_note( sprintf( __( '物流單建立成功，物流單號：%s', 'ys-paynow-shipping' ), $resp_obj->LogisticNumber ) );

				do_action( 'ys_paynow_shipping_order_created', $order );
			}

		} catch ( \Exception $e ) {
			YSPaynowShipping::log( sprintf( 'Create order #%d failed: %s', $order->get_id(), $e->getMessage() ), 'error' );
		}
	}

	/**
	 * 呼叫 PayNow API 建立物流單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return array|\WP_Error API 回應。
	 */
	public static function api_create_order( $order ) {
		$args = self::build_create_order_args( $order );

		YSPaynowShipping::log( 'Create order args: ' . wp_json_encode( $args ) );

		$encrypted_json = self::build_encrypted_args( $args );
		$url            = YSPaynowShipping::$api_url . '/api/Orderapi/Add_Order';

		$response = wp_remote_post( $url, array(
			'timeout'     => 45,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent'   => 'YS-PayNow-Shipping/' . YS_PAYNOW_SHIPPING_VERSION,
			),
			'body'        => array(
				'JsonOrder' => $encrypted_json,
			),
		) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: Error message */
			$order->add_order_note( sprintf( __( '建立物流單失敗：%s', 'ys-paynow-shipping' ), $response->get_error_message() ) );
			throw new \Exception( $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * 建立物流單請求參數
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return array 請求參數陣列。
	 */
	private static function build_create_order_args( $order ) {
		$args = array(
			'user_account'     => YSPaynowShipping::$user_account,
			'apicode'          => YSPaynowShipping::$apicode,
			'OrderNo'          => self::get_prefixed_order_no( $order ),
			'Logistic_service' => $order->get_meta( YSOrderMeta::LogisticServiceId ),
			'Description'      => self::get_items_description( $order ),
			'DeliverMode'      => ( 'cod' === $order->get_payment_method() ) ? '01' : '02', // 01:取貨付款 02:取貨不付款
			'TotalAmount'      => $order->get_total(),
			'PassCode'         => self::build_pass_code( $order ),
			'EC'               => 'WooCommerce',

			// 收件人資訊（使用智慧收件人取用邏輯）
			'Receiver_Name'    => YSPaynowShipping::get_recipient_name( $order ),
			'Receiver_Phone'   => YSPaynowShipping::get_shipping_phone( $order ),
			'Receiver_Email'   => $order->get_billing_email(),

			// 寄件人資訊
			'Sender_Name'      => get_option( 'ys_paynow_shipping_sender_name', '' ),
			'Sender_Phone'     => get_option( 'ys_paynow_shipping_sender_phone', '' ),
			'Sender_Email'     => get_option( 'ys_paynow_shipping_sender_email', '' ),
			'Sender_address'   => get_option( 'ys_paynow_shipping_sender_address', '' ),

			'Remark'           => '',
		);

		// 超商取貨
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( in_array( $logistic_service_id, YSLogisticService::get_cvs_services(), true ) ) {
			$args['receiver_storeid']   = $order->get_meta( YSOrderMeta::StoreId );
			$args['receiver_storename'] = $order->get_meta( YSOrderMeta::StoreName );
			$args['Receiver_address']   = $order->get_meta( YSOrderMeta::StoreAddr );
			$args['return_storeid']     = '';
		} else {
			// 宅配
			$args['Receiver_address'] = self::get_order_address( $order );
			$args['receiver_storeid']   = '';
			$args['receiver_storename'] = '';
			$args['return_storeid']     = '';

			// 黑貓宅配專用參數
			if ( YSLogisticService::TCAT === $logistic_service_id || YSLogisticService::TCAT_OWN === $logistic_service_id ) {
				$args['DeliveryType'] = $order->get_meta( YSOrderMeta::DeliveryType ) ?: get_option( 'ys_paynow_shipping_tcat_delivery_type', '0001' );
				$args['Weight']       = get_option( 'ys_paynow_shipping_tcat_weight', '5' );
				$args['Length']       = get_option( 'ys_paynow_shipping_tcat_length', '5' );
				$args['Width']        = get_option( 'ys_paynow_shipping_tcat_width', '5' );
				$args['Height']       = get_option( 'ys_paynow_shipping_tcat_height', '4' );
				$args['Deadline']     = get_option( 'ys_paynow_shipping_tcat_deadline', '3' );
			}
		}

		return apply_filters( 'ys_paynow_shipping_create_order_args', $args, $order );
	}

	/**
	 * 查詢物流狀態
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return array|\WP_Error API 回應。
	 */
	public static function api_query_order( $order ) {
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$url             = YSPaynowShipping::$api_url . '/api/Orderapi/Get_Order_Info?LogisticNumber=' . $logistic_number . '&sno=1';

		return wp_remote_get( $url, array(
			'timeout'     => 45,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent'   => 'YS-PayNow-Shipping/' . YS_PAYNOW_SHIPPING_VERSION,
			),
		) );
	}

	/**
	 * 取消物流單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return array|\WP_Error API 回應。
	 */
	public static function api_cancel_order( $order ) {
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$url             = YSPaynowShipping::$api_url . '/api/Orderapi/CancelOrder';

		// PassCode 計算使用訂單編號（與原始外掛一致）
		return wp_remote_request( $url, array(
			'method' => 'DELETE',
			'body'   => array(
				'LogisticNumber' => $logistic_number,
				'sno'            => '1',
				'PassCode'       => self::build_pass_code( $order, 'new' ), // 使用 'new' mode
			),
		) );
	}

	/**
	 * AJAX: 查詢物流狀態
	 *
	 * @return void
	 */
	public static function ajax_query_status() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		$response = self::api_query_order( $order );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body     = wp_remote_retrieve_body( $response );
		$resp_obj = json_decode( $body );

		YSPaynowShipping::log( 'Query order #' . $order_id . ' response: ' . $body );

		// API 回傳的欄位是 Delivery_Status (有底線)
		if ( isset( $resp_obj->Delivery_Status ) ) {
			// 更新物流狀態
			$order->update_meta_data( YSOrderMeta::DeliveryStatus, $resp_obj->Delivery_Status );
			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
			
			// 更新其他欄位
			if ( isset( $resp_obj->paymentno ) ) {
				$order->update_meta_data( YSOrderMeta::PaymentNo, $resp_obj->paymentno );
			}
			if ( isset( $resp_obj->validationno ) ) {
				$order->update_meta_data( YSOrderMeta::ValidationNo, $resp_obj->validationno );
			}
			$order->save();

			wp_send_json_success( array(
				'message' => __( '查詢成功', 'ys-paynow-shipping' ),
				'status'  => $resp_obj->Delivery_Status,
			) );
		} else {
			// 檢查是否有錯誤訊息
			$error_msg = isset( $resp_obj->ErrorMsg ) ? $resp_obj->ErrorMsg : __( '查詢失敗', 'ys-paynow-shipping' );
			wp_send_json_error( $error_msg );
		}
	}

	/**
	 * AJAX: 前台查詢物流狀態
	 *
	 * @return void
	 */
	public static function ajax_user_query_status() {
		check_ajax_referer( 'ys-paynow-shipping', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( '請先登入', 'ys-paynow-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		if ( ! current_user_can( 'view_order', $order_id ) ) {
			wp_send_json_error( __( '無權限檢視此訂單', 'ys-paynow-shipping' ) );
		}

		$response = self::api_query_order( $order );

		if ( is_wp_error( $response ) ) {
			YSPaynowShipping::log( 'User query status API error: ' . $response->get_error_message(), 'error' );
			wp_send_json_error( $response->get_error_message() );
		}

		$body     = wp_remote_retrieve_body( $response );
		$resp_obj = json_decode( $body );

		YSPaynowShipping::log( 'User query status API response: ' . $body );

		// API 回傳成功判斷: Status='S' 或 Status='0' 且 ErrorMsg 為空
		$is_success = false;
		if ( isset( $resp_obj->Status ) ) {
			if ( 'S' === $resp_obj->Status || '0' === $resp_obj->Status ) {
				$is_success = true;
			}
		}
		// 也可以用 ErrorMsg 判斷
		if ( ! $is_success && isset( $resp_obj->ErrorMsg ) && empty( $resp_obj->ErrorMsg ) && isset( $resp_obj->Delivery_Status ) ) {
			$is_success = true;
		}

		if ( $is_success ) {
			// 更新物流狀態 - 注意 API 回傳的 key 是 Delivery_Status 不是 DeliveryStatus
			$status_desc = $resp_obj->Delivery_Status ?? $resp_obj->DeliveryStatus ?? '';
			
			if ( ! empty( $status_desc ) ) {
				$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_desc );
			}
			
			// 自動更新訂單狀態 (與 Cron 邏輯一致)
			$status_code = $resp_obj->PayNowLogisticCode ?? '';
			// 若 API 回傳無 Code，嘗試使用 DeliveryStatus (需非常小心，暫不實作以免誤判)
			
			// 根據 browser agent 資訊，TCAT API 回傳可能有差異，但 status_code 應可在某處找到
			// 若有 status_code，則進行對應
			if ( ! empty( $status_code ) ) {
				$new_status = \YangSheep\PayNow\Shipping\Utils\YSShippingStatus::get_wc_status_from_paynow_status( $status_code );
				
				if ( $new_status && $new_status !== 'wc-' . $order->get_status() && $new_status !== $order->get_status() ) {
					$order->update_status( $new_status, sprintf( __( '用戶前台更新貨態: %s (%s)', 'ys-paynow-shipping' ), $status_desc, $status_code ) );
				}
			}

			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
			$order->save();

			// 計算狀態 Class (新版邏輯)
			$current_step = 1;
			$status_key   = 'pending';
			$status_class = 'status-pending'; // 注意這裡改成與前端一致
			$progress_pct = '0%';

			if ( ! empty( $status_desc ) ) {
				if ( strpos( $status_desc, '取貨' ) !== false || strpos( $status_desc, '完成' ) !== false ) {
					$current_step = 4;
					$status_key   = 'completed';
					$status_class = 'status-completed';
					$progress_pct = '100%';
				} elseif ( strpos( $status_desc, '到店' ) !== false || strpos( $status_desc, '待取' ) !== false ) {
					$current_step = 3;
					$status_key   = 'arrived';
					$status_class = 'status-arrived';
					$progress_pct = '66%';
				} elseif ( strpos( $status_desc, '運送' ) !== false || strpos( $status_desc, '出貨' ) !== false || strpos( $status_desc, '離店' ) !== false || strpos( $status_desc, '集貨' ) !== false ) {
					$current_step = 2;
					$status_key   = 'shipping';
					$status_class = 'status-shipping';
					$progress_pct = '33%';
				}
			}

			// 保留舊版邏輯作為 fallback 或 backward compatibility (雖然後端可能不再依賴它)
			// $wc_status = $order->get_status(); ... 

			$update_time = $order->get_meta( YSOrderMeta::StatusUpdateAt );
			
			wp_send_json_success( array(
				'message'      => __( '已更新', 'ys-paynow-shipping' ),
				'status_text'  => $status_desc ?: __( '已更新', 'ys-paynow-shipping' ),
				'status_class' => $status_class, // 這是前端卡片的 class
				'status_key'   => $status_key,
				'current_step' => $current_step,
				'progress_pct' => $progress_pct,
				'update_time'  => ! empty( $update_time ) ? date_i18n( 'F j, Y g:i a', strtotime( $update_time ) ) : '',
			) );
		} else {
			wp_send_json_error( $resp_obj->ErrorMsg ?? __( '查詢失敗', 'ys-paynow-shipping' ) );
		}
	}

	/**
	 * AJAX: 取消物流單
	 *
	 * @return void
	 */
	public static function ajax_cancel_order() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		YSPaynowShipping::log( 'Cancel order #' . $order_id . ' logistic number: ' . $logistic_number );

		$response = self::api_cancel_order( $order );

		if ( is_wp_error( $response ) ) {
			YSPaynowShipping::log( 'Cancel order #' . $order_id . ' error: ' . $response->get_error_message() );
			wp_send_json_error( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		YSPaynowShipping::log( 'Cancel order #' . $order_id . ' response: ' . $body );

		// 檢查回應是否成功 (假設回應包含 'S' 表示成功)
		if ( strpos( $body, 'S' ) !== false || strpos( $body, '成功' ) !== false ) {
			// 清除所有物流相關的 meta
			$order->update_meta_data( YSOrderMeta::LogisticNumber, '' );
			$order->update_meta_data( YSOrderMeta::PaymentNo, '' );
			$order->update_meta_data( YSOrderMeta::ValidationNo, '' );
			$order->update_meta_data( YSOrderMeta::DeliveryStatus, '' );
			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, '' );
			$order->save();
			$order->add_order_note( sprintf( __( '物流單已取消（單號：%s）', 'ys-paynow-shipping' ), $logistic_number ) );

			wp_send_json_success( array( 'message' => __( '物流單已取消', 'ys-paynow-shipping' ) ) );
		} else {
			$order->add_order_note( __( '取消物流單失敗：', 'ys-paynow-shipping' ) . $body );
			wp_send_json_error( __( '取消失敗：', 'ys-paynow-shipping' ) . $body );
		}
	}

	/**
	 * AJAX: 取得地圖網址
	 *
	 * @return void
	 */
	public static function ajax_get_map_url() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		$logistic_service = isset( $_POST['logistic_service'] ) ? sanitize_text_field( wp_unslash( $_POST['logistic_service'] ) ) : '';

		if ( empty( $logistic_service ) ) {
			wp_send_json_error( array( 'message' => __( '缺少物流服務參數', 'ys-paynow-shipping' ) ) );
		}

		$map_url = YSPaynowShipping::$cvs_map_url . '?Logistics=' . $logistic_service . '&user_account=' . YSPaynowShipping::$user_account;

		wp_send_json_success( array(
			'map_url' => $map_url,
		) );
	}

	/**
	 * AJAX: 重新取號
	 *
	 * @return void
	 */
	public static function ajax_reissue_order() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		// 嘗試取消舊單
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		if ( ! empty( $logistic_number ) ) {
			try {
				$response = self::api_cancel_order( $order );
				$body     = wp_remote_retrieve_body( $response );
				
				YSPaynowShipping::log( 'Cancel order response for reissue: ' . $body );
				
				// 不論取消成功與否，都記錄並繼續（因為可能已經被取消過了）
				if ( is_wp_error( $response ) ) {
					$order->add_order_note( __( '取消舊物流單請求失敗，但繼續重新取號', 'ys-paynow-shipping' ) );
				} else {
					$order->add_order_note( __( '已嘗試取消舊物流單 (重新取號)', 'ys-paynow-shipping' ) );
				}
			} catch ( \Exception $e ) {
				YSPaynowShipping::log( 'Cancel order exception: ' . $e->getMessage() );
				$order->add_order_note( __( '取消舊單時發生錯誤，繼續重新取號', 'ys-paynow-shipping' ) );
			}
		}

		// 清除舊資料
		$order->update_meta_data( YSOrderMeta::LogisticNumber, '' );
		$order->update_meta_data( YSOrderMeta::PaymentNo, '' );
		$order->update_meta_data( YSOrderMeta::ValidationNo, '' );
		$order->update_meta_data( YSOrderMeta::DeliveryStatus, '' );

		// 增加重試計數器（用於生成新的訂單編號後綴 -1, -2, ...）
		$current_retry = (int) $order->get_meta( YSOrderMeta::RetryCount );
		$order->update_meta_data( YSOrderMeta::RetryCount, $current_retry + 1 );
		$order->save();

		// 記錄重新取號的訂單編號
		$new_order_no = self::get_prefixed_order_no( $order );
		$order->add_order_note( sprintf( __( '開始重新取號，新訂單編號：%s', 'ys-paynow-shipping' ), $new_order_no ) );

		// 重新建立
		self::create_logistic_order( $order );

		// 重新取得訂單以確保資料最新
		$order = wc_get_order( $order_id );
		$new_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		
		if ( ! empty( $new_number ) ) {
			// 主動查詢一次狀態以更新前端顯示
			$query_response = self::api_query_order( $order );
			if ( ! is_wp_error( $query_response ) ) {
				$resp_obj = json_decode( wp_remote_retrieve_body( $query_response ) );
				if ( isset( $resp_obj->Delivery_Status ) ) {
					$order->update_meta_data( YSOrderMeta::DeliveryStatus, $resp_obj->Delivery_Status );
					$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
					if ( isset( $resp_obj->paymentno ) ) {
						$order->update_meta_data( YSOrderMeta::PaymentNo, $resp_obj->paymentno );
					}
					$order->save();
				}
			}

			wp_send_json_success( array(
				'message'         => __( '重新取號成功', 'ys-paynow-shipping' ),
				'logistic_number' => $new_number,
			) );
		} else {
			wp_send_json_error( __( '重新取號失敗，請查看訂單備註', 'ys-paynow-shipping' ) );
		}
	}

	/**
	 * AJAX: 手動建立物流單
	 *
	 * @return void
	 */
	public static function ajax_manual_create() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		// 檢查是否已有物流單號
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		if ( ! empty( $logistic_number ) ) {
			wp_send_json_error( __( '該訂單已有物流單號，請使用「重新取號」', 'ys-paynow-shipping' ) );
		}

		// 如果有重試計數器，增加它（用於避免訂單編號重複）
		$current_retry = (int) $order->get_meta( YSOrderMeta::RetryCount );
		if ( $current_retry > 0 ) {
			$order->update_meta_data( YSOrderMeta::RetryCount, $current_retry + 1 );
			$order->save();
		}

		// 記錄手動取號
		$new_order_no = self::get_prefixed_order_no( $order );
		$order->add_order_note( sprintf( __( '手動取號，訂單編號：%s', 'ys-paynow-shipping' ), $new_order_no ) );

		// 建立物流單
		self::create_logistic_order( $order );

		// 重新取得訂單以確保資料最新
		$order = wc_get_order( $order_id );
		$new_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		
		if ( ! empty( $new_number ) ) {
			wp_send_json_success( array(
				'message'         => __( '取號成功', 'ys-paynow-shipping' ),
				'logistic_number' => $new_number,
			) );
		} else {
			// 取號失敗，增加計數器供下次使用
			$order->update_meta_data( YSOrderMeta::RetryCount, $current_retry + 1 );
			$order->save();
			wp_send_json_error( __( '取號失敗，請查看訂單備註', 'ys-paynow-shipping' ) );
		}
	}

	/**
	 * AJAX: 列印標籤
	 *
	 * @return void
	 */
	public static function ajax_print_label() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['order_ids'] ) || ! isset( $_GET['service'] ) ) {
			wp_die( esc_html__( '缺少參數', 'ys-paynow-shipping' ) );
		}

		$order_ids     = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) ) );
		$service       = sanitize_text_field( wp_unslash( $_GET['service'] ) );
		$change_status = isset( $_GET['change_status'] ) && '1' === $_GET['change_status'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 取得物流單號清單和訂單編號清單
		$logistic_numbers = array();
		$order_numbers    = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				// 取得物流單號（用於黑貓等需要 LogisticNumber 的服務）
				$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
				if ( ! empty( $logistic_number ) ) {
					$logistic_numbers[] = $logistic_number;
				}

				// 取得訂單編號（用於超商列印）
				$renew_order_no = $order->get_meta( YSOrderMeta::RenewOrderNo );
				$order_numbers[] = $renew_order_no ?: self::get_prefixed_order_no( $order );

				// 標記為已列印 (同時寫入新舊欄位以確保向下相容)
				$order->update_meta_data( '_ys_paynow_label_printed', 'yes' );
				$order->update_meta_data( '_ys_label_printed', 'yes' );

				// 如果勾選了「列印後更改狀態」，則更改為「已安排出貨」
				if ( $change_status ) {
					// 檢查是否有自訂的「已安排出貨」狀態
					$scheduled_status = self::get_scheduled_shipping_status();
					if ( ! empty( $scheduled_status ) && $order->get_status() !== $scheduled_status ) {
						$order->set_status( $scheduled_status, __( '批次列印後自動更改狀態', 'ys-paynow-shipping' ) );
					}
				}

				$order->save();
			}
		}

		$order_numbers_string    = implode( ',', $order_numbers );
		$logistic_numbers_string = implode( ',', $logistic_numbers );

		// 根據物流服務取得列印 API URL
		$api_url = self::get_print_label_url( $service, $order_numbers_string, $logistic_numbers_string, $order_ids );

		if ( ! $api_url ) {
			wp_die( esc_html__( '不支援的物流服務', 'ys-paynow-shipping' ) );
		}

		// 對於 TCAT (黑貓)，直接重導向
		if ( YSLogisticService::TCAT === $service || YSLogisticService::TCAT_OWN === $service ) {
			// 黑貓可能需要 POST，確認文件。若這裡回傳的是 URL，則直接導向。
			// 根據 get_print_label_url 實作，它是回傳 PrintBlackCatLabel URL。
			// 假設黑貓是直接訪問頁面。
			wp_redirect( $api_url );
			exit;
		}

		// 對於超商 (7-11/全家/萊爾富)，API 回傳的是 "5,URL" 格式
		// 我們需要先呼叫 API 取得真正的 PDF/網頁 URL
		$response = wp_remote_get( $api_url, array(
			'timeout' => 30,
			'headers' => array(
				'User-Agent' => 'YS-PayNow-Shipping/' . YS_PAYNOW_SHIPPING_VERSION,
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		
		// 移除可能存在的引號 (API 回傳有時會包含引號)
		$body = trim( $body, '"' );

		// 解析回應
		// 格式 1: "5,https://..." (常見)
		// 格式 2: "S,https://..." (使用者回報)
		if ( strpos( $body, '5,' ) === 0 ) {
			$real_url = substr( $body, 2 );
		} elseif ( strpos( $body, 'S,' ) === 0 ) {
			$real_url = substr( $body, 2 );
		} else {
             // 嘗試直接判定是否為 URL
             if ( filter_var( $body, FILTER_VALIDATE_URL ) ) {
                 $real_url = $body;
             } else {
				 $real_url = '';
			 }
        }

		if ( ! empty( $real_url ) && filter_var( $real_url, FILTER_VALIDATE_URL ) ) {
			wp_redirect( $real_url );
			exit;
		}

		// 若無法解析或格式錯誤
		wp_die( esc_html__( '無法取得標籤網址，API 回應：', 'ys-paynow-shipping' ) . esc_html( $body ) );
	}

	/**
	 * 取得列印標籤 API URL (內部使用)
	 *
	 * @param string $service                 物流服務代碼。
	 * @param string $order_numbers_string    訂單編號字串（超商用）。
	 * @param string $logistic_numbers_string 物流單號字串（黑貓用）。
	 * @param array  $order_ids               訂單 ID 陣列。
	 * @return string|false 列印 URL 或 false。
	 */
	private static function get_print_label_url( $service, $order_numbers_string, $logistic_numbers_string, $order_ids ) {
		$base_url = YSPaynowShipping::$api_url;

		switch ( $service ) {
			case YSLogisticService::SEVEN:
				return $base_url . '/api/Order711?orderNumberStr=' . $order_numbers_string . '&user_account=' . YSPaynowShipping::$user_account;

			case YSLogisticService::FAMI:
				return $base_url . '/api/OrderFamiC2C?orderNumberStr=' . $order_numbers_string . '&user_account=' . YSPaynowShipping::$user_account;

			case YSLogisticService::HILIFE:
				return $base_url . '/api/OrderHiLife?orderNumberStr=' . $order_numbers_string . '&user_account=' . YSPaynowShipping::$user_account;

			case YSLogisticService::TCAT:
			case YSLogisticService::TCAT_OWN:
				// 黑貓使用 LogisticNumber 列印
				return $base_url . '/Member/Order/PrintBlackCatLabel?LogisticNumbers=' . $logistic_numbers_string;

			default:
				return false;
		}
	}

	/**
	 * 重選超商後重新建立物流單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return void
	 */
	public static function recreate_order_after_store_change( $order ) {
		// 先嘗試取消原有物流單 (如果有的話)
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		if ( ! empty( $logistic_number ) ) {
			YSPaynowShipping::log( sprintf( 'Canceling old order #%d (Logistic: %s) due to store change.', $order->get_id(), $logistic_number ) );
			$response = self::api_cancel_order( $order );

			// 無論取消 API 是否成功，我們都必須清除舊資料以建立新單，
			// 但為了安全起見，如果 API 失敗最好記錄一下。
			// 若 API 失敗但我們強行清除，可能會導致 PayNow 端沒取消，但我們這邊換該了。
			// 考慮到用戶已經換超商了，舊單號必須廢棄。
			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __( '更換超商：舊物流單取消失敗（API 錯誤），但系統將繼續建立新單。', 'ys-paynow-shipping' ) );
			} else {
				$body = wp_remote_retrieve_body( $response );
				if ( strpos( $body, 'S' ) === false && strpos( $body, '成功' ) === false ) {
					$order->add_order_note( __( '更換超商：舊物流單取消失敗（API 回應錯誤），但系統將繼續建立新單。', 'ys-paynow-shipping' ) . ' ' . $body );
				} else {
					$order->add_order_note( __( '更換超商：舊物流單已自動取消。', 'ys-paynow-shipping' ) );
				}
			}

			// 清除舊物流相關資訊
			$order->update_meta_data( YSOrderMeta::LogisticNumber, '' );
			$order->update_meta_data( YSOrderMeta::PaymentNo, '' );
			$order->update_meta_data( YSOrderMeta::ValidationNo, '' );
			$order->update_meta_data( YSOrderMeta::DeliveryStatus, '' );
			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, '' );
			$order->save();
		}

		// 重新建立物流單
		self::create_logistic_order( $order );
	}

	/**
	 * 取得「已安排出貨」狀態
	 *
	 * 根據設定返回適當的訂單狀態：
	 * - 若啟用自動配置，使用 shipping-ordered 狀態
	 * - 若未啟用自動配置，使用設定中的對應狀態
	 *
	 * @return string 訂單狀態（不含 wc- 前綴）
	 */
	private static function get_scheduled_shipping_status() {
		$auto_status = get_option( 'ys_paynow_shipping_auto_status', 'yes' );

		if ( 'yes' === $auto_status ) {
			// 使用自動配置的「已安排出貨」狀態
			return 'shipping-ordered';
		}

		// 使用設定中的對應狀態
		$mapped_status = get_option( 'ys_paynow_shipping_status_ordered', 'wc-processing' );

		// 移除 wc- 前綴
		if ( strpos( $mapped_status, 'wc-' ) === 0 ) {
			$mapped_status = substr( $mapped_status, 3 );
		}

		return $mapped_status;
	}

	/*
	|--------------------------------------------------------------------------
	| 工具方法
	|--------------------------------------------------------------------------
	*/

	/**
	 * 取得帶前綴的訂單編號
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 訂單編號。
	 */
	private static function get_prefixed_order_no( $order ) {
		$prefix      = get_option( 'ys_paynow_shipping_order_prefix', '' );
		$retry_count = (int) $order->get_meta( YSOrderMeta::RetryCount );
		$base_no     = $prefix . $order->get_order_number();

		// 第一次取號不加後綴，之後加 -1, -2, ...
		if ( $retry_count > 0 ) {
			return $base_no . '-' . $retry_count;
		}

		return $base_no;
	}

	/**
	 * 建立 PassCode
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param string    $mode  模式 (new/cancel)。
	 * @return string PassCode。
	 */
	private static function build_pass_code( $order, $mode = 'new' ) {
		if ( 'cancel' === $mode ) {
			$order_no = $order->get_meta( YSOrderMeta::LogisticNumber );
		} else {
			$order_no = self::get_prefixed_order_no( $order );
		}

		// 規則: user_account + OrderNo + TotalAmount + apicode
		return strtoupper( hash( 'sha1', YSPaynowShipping::$user_account . $order_no . $order->get_total() . YSPaynowShipping::$apicode ) );
	}

	/**
	 * 建立加密參數
	 *
	 * @param array $args 請求參數。
	 * @return string 加密後的 JSON 字串。
	 */
	public static function build_encrypted_args( $args ) {
		$json_string = wp_json_encode( $args );

		// 補齊長度為 8 的倍數
		if ( strlen( $json_string ) % 8 ) {
			$json_string = str_pad( $json_string, strlen( $json_string ) + 8 - strlen( $json_string ) % 8, "\0" );
		}

		$key = utf8_encode( '123456789070828783123456' );

		// 使用 DES-EDE3 (無 CBC/IV)，與 PayNow 一致
		$ciphertext = openssl_encrypt( $json_string, 'DES-EDE3', $key, OPENSSL_NO_PADDING );

		if ( ! $ciphertext ) {
			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $msg = openssl_error_string() ) {
				YSPaynowShipping::log( 'OpenSSL encrypt error: ' . $msg, 'error' );
			}
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $ciphertext );
	}

	/**
	 * 取得訂單商品描述
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 商品描述。
	 */
	private static function get_items_description( $order ) {
		$items = $order->get_items();
		$description = array();

		foreach ( $items as $item ) {
			$description[] = $item->get_name() . 'x' . $item->get_quantity();
		}

		$result = implode( ', ', $description );
		// 過濾特殊字元，限制長度
		$result = preg_replace( '/[^A-Za-z0-9 \p{Han}]+/u', '', $result );
		return mb_substr( $result, 0, 25 );
	}

	/**
	 * 取得訂單收件地址
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 收件地址。
	 */
	private static function get_order_address( $order ) {
		return $order->get_shipping_city() . $order->get_shipping_state() . $order->get_shipping_address_1() . $order->get_shipping_address_2();
	}
}
