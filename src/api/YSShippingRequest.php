<?php
/**
 * YS Shipping Request
 *
 * 處理 PayNow 物流 API 請求。
 *
 * @package yangsheep\paynow\shipping\api
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\api;

use yangsheep\paynow\shipping\YSPaynowShipping;
use yangsheep\paynow\shipping\utils\YSOrderMeta;
use yangsheep\paynow\shipping\utils\YSLogisticService;

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

			YSPaynowShipping::log( sprintf( 'Create order #%d response: %s', $order->get_id(), wp_json_encode( $resp_obj, JSON_UNESCAPED_UNICODE ) ) );

			if ( ! is_object( $resp_obj ) ) {
				throw new \Exception( 'API 回應格式無效' );
			}

			if ( isset( $resp_obj->Status ) && 'F' === $resp_obj->Status ) {
				/* translators: %s: Error message */
				$order->add_order_note( sprintf( __( '建立物流單失敗：%s', 'ys-paynow-shipping' ), $resp_obj->ErrorMsg ?? '' ) );
				throw new \Exception( $resp_obj->ErrorMsg ?? 'Unknown error' );
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

		YSPaynowShipping::log( 'Create order args: ' . wp_json_encode( self::redact_sensitive_args( $args ), JSON_UNESCAPED_UNICODE ) );

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
			'Sender_address'   => self::get_sender_address(),

			'Remark'           => '',
		);

		// 超商取貨
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( in_array( $logistic_service_id, YSLogisticService::get_cvs_services(), true ) ) {
			$args['receiver_storeid']   = $order->get_meta( YSOrderMeta::StoreId );
			$args['receiver_storename'] = $order->get_meta( YSOrderMeta::StoreName );
			$args['Receiver_address']   = $order->get_meta( YSOrderMeta::StoreAddr );
			$args['return_storeid']     = '';

			// ★ 全家冷凍 C2C 需要保留編號
			if ( YSLogisticService::FAMIFROZEN_C2C === $logistic_service_id ) {
				$reserved_no = $order->get_meta( YSOrderMeta::ReservedNo );
				if ( ! empty( $reserved_no ) ) {
					$args['ReservedNo'] = $reserved_no;
				}
			}
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
				'PassCode'       => self::build_pass_code( $order, 'cancel' ),
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

		if ( ! is_object( $resp_obj ) ) {
			wp_send_json_error( __( 'API 回應格式無效', 'ys-paynow-shipping' ) );
		}

		// 檢查 API 是否有回傳有效資料（LogisticNumber 或 Detail_Status_Description）
		if ( ! empty( $resp_obj->ErrorMsg ) ) {
			wp_send_json_error( $resp_obj->ErrorMsg );
		}

		// 取得物流狀態：宅配優先 Detail_Status_Description，超取優先 Delivery_Status
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		$is_home_delivery    = in_array( $logistic_service_id, array( '06', '36' ), true );

		$status_text = null;
		if ( $is_home_delivery && ! empty( $resp_obj->Detail_Status_Description ) ) {
			$status_text = $resp_obj->Detail_Status_Description;
		} elseif ( ! empty( $resp_obj->Delivery_Status ) ) {
			$status_text = $resp_obj->Delivery_Status;
		} elseif ( ! empty( $resp_obj->Detail_Status_Description ) ) {
			$status_text = $resp_obj->Detail_Status_Description;
		}

		if ( null !== $status_text || ( is_object( $resp_obj ) && property_exists( $resp_obj, 'LogisticNumber' ) ) ) {
			// 更新物流狀態
			if ( null !== $status_text ) {
				$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_text );
			}
			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );

			// 更新其他欄位
			if ( ! empty( $resp_obj->paymentno ) ) {
				$order->update_meta_data( YSOrderMeta::PaymentNo, $resp_obj->paymentno );
			}
			if ( ! empty( $resp_obj->validationno ) ) {
				$order->update_meta_data( YSOrderMeta::ValidationNo, $resp_obj->validationno );
			}

			// 根據 PayNowLogisticCode 更新 WC 訂單狀態
			$status_code = $resp_obj->PayNowLogisticCode ?? '';
			if ( ! empty( $status_code ) ) {
				$order->update_meta_data( YSOrderMeta::LogisticCode, $status_code );
				if ( ! empty( $resp_obj->Detail_Status_Description ) ) {
					$order->update_meta_data( YSOrderMeta::DetailStatusDesc, $resp_obj->Detail_Status_Description );
				}

				$new_status = \yangsheep\paynow\shipping\utils\YSShippingStatus::get_wc_status_from_paynow_status( $status_code );
				if ( $new_status && $new_status !== 'wc-' . $order->get_status() && $new_status !== $order->get_status() ) {
					$order->update_status(
						str_replace( 'wc-', '', $new_status ),
						sprintf( __( '管理員查詢貨態更新: %s (%s)', 'ys-paynow-shipping' ), $status_text, $status_code )
					);
				}
			}

			$order->save();

			wp_send_json_success( array(
				'message' => __( '查詢成功', 'ys-paynow-shipping' ),
				'status'  => $status_text ?? __( '已建立', 'ys-paynow-shipping' ),
			) );
		} else {
			wp_send_json_error( __( '查詢失敗', 'ys-paynow-shipping' ) );
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

		if ( ! is_object( $resp_obj ) ) {
			wp_send_json_error( __( 'API 回應格式無效', 'ys-paynow-shipping' ) );
		}

		// API 回傳成功判斷: Status='S' 或 Status='0' 且 ErrorMsg 為空
		$is_success = false;
		if ( isset( $resp_obj->Status ) ) {
			if ( 'S' === $resp_obj->Status || '0' === $resp_obj->Status ) {
				$is_success = true;
			}
		}
		// 也可以用 ErrorMsg 判斷（宅配可能只有 Detail_Status_Description 而無 Delivery_Status）
		if ( ! $is_success && isset( $resp_obj->ErrorMsg ) && empty( $resp_obj->ErrorMsg ) ) {
			if ( isset( $resp_obj->Delivery_Status ) || isset( $resp_obj->Detail_Status_Description ) ) {
				$is_success = true;
			}
		}

		if ( $is_success ) {
			// 更新物流狀態 — 宅配優先 Detail_Status_Description，超取優先 Delivery_Status
			$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
			$is_home_delivery    = in_array( $logistic_service_id, array( '06', '36' ), true );

			$status_desc = '';
			if ( $is_home_delivery && ! empty( $resp_obj->Detail_Status_Description ) ) {
				$status_desc = $resp_obj->Detail_Status_Description;
			} elseif ( ! empty( $resp_obj->Delivery_Status ) ) {
				$status_desc = $resp_obj->Delivery_Status;
			} elseif ( ! empty( $resp_obj->Detail_Status_Description ) ) {
				$status_desc = $resp_obj->Detail_Status_Description;
			}

			if ( ! empty( $status_desc ) ) {
				$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_desc );
			}

			// 更新其他 Meta（與管理員端 / CRON 一致）
			if ( ! empty( $resp_obj->paymentno ) ) {
				$order->update_meta_data( YSOrderMeta::PaymentNo, $resp_obj->paymentno );
			}
			if ( ! empty( $resp_obj->Detail_Status_Description ) ) {
				$order->update_meta_data( YSOrderMeta::DetailStatusDesc, $resp_obj->Detail_Status_Description );
			}

			// 自動更新訂單狀態 (與 Cron 邏輯一致)
			$status_code = $resp_obj->PayNowLogisticCode ?? '';
			if ( ! empty( $status_code ) ) {
				$order->update_meta_data( YSOrderMeta::LogisticCode, $status_code );
				$new_status = \yangsheep\paynow\shipping\utils\YSShippingStatus::get_wc_status_from_paynow_status( $status_code );

				$new_status_clean = str_replace( 'wc-', '', $new_status );
				if ( $new_status && $new_status_clean !== $order->get_status() ) {
					$order->update_status( $new_status_clean, sprintf( __( '用戶前台更新貨態: %s (%s)', 'ys-paynow-shipping' ), $status_desc, $status_code ) );
				}
			}

			$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
			$order->save();

			// 判定流程類別 (宅配 vs 超取)，與 display_shipping_detail 邏輯一致
			$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
			$shipping_method_title = '';
			foreach ( $order->get_shipping_methods() as $shipping_method ) {
				$shipping_method_title = $shipping_method->get_name();
				break;
			}
			$flow_type = 'cvs';
			if ( in_array( $logistic_service_id, array( '06', '36' ), true ) || strpos( $shipping_method_title, '宅配' ) !== false || strpos( $shipping_method_title, '黑貓' ) !== false ) {
				$flow_type = 'home';
			}
			$total_steps = ( 'home' === $flow_type ) ? 4 : 5;

			// 計算狀態 Class（依據流程類別動態計算）
			$current_step = 1;
			$status_key   = 'pending';
			$status_class = 'status-pending';

			if ( ! empty( $status_desc ) ) {
				if ( strpos( $status_desc, '取貨' ) !== false || strpos( $status_desc, '完成' ) !== false || strpos( $status_desc, '已取' ) !== false ) {
					$current_step = $total_steps; // 最後一步
					$status_key   = 'completed';
					$status_class = 'status-completed';
				} elseif ( strpos( $status_desc, '到店' ) !== false || strpos( $status_desc, '待取' ) !== false || strpos( $status_desc, '配達' ) !== false ) {
					if ( 'home' === $flow_type ) {
						$current_step = ( strpos( $status_desc, '配達' ) !== false ) ? 4 : 3;
					} else {
						$current_step = 4; // 已到店
					}
					$status_key   = 'arrived';
					$status_class = 'status-arrived';
				} elseif ( strpos( $status_desc, '運送' ) !== false || strpos( $status_desc, '離店' ) !== false || strpos( $status_desc, '集貨' ) !== false || strpos( $status_desc, '暫置' ) !== false || strpos( $status_desc, '轉運' ) !== false || strpos( $status_desc, '配送' ) !== false || strpos( $status_desc, '理貨' ) !== false ) {
					$current_step = 3;
					$status_key   = 'shipping';
					$status_class = 'status-shipping';
				} elseif ( strpos( $status_desc, '等待' ) !== false || strpos( $status_desc, '寄件' ) !== false || strpos( $status_desc, '出貨' ) !== false || strpos( $status_desc, '準備' ) !== false ) {
					$current_step = 2;
					$status_key   = 'waiting';
					$status_class = 'status-waiting';
				}
			}

			// 根據步驟數動態計算進度百分比（與初始渲染公式一致）
			$step_percent = ( $current_step - 1 ) / ( $total_steps - 1 ) * 100;
			$progress_pct = max( 0, min( 100, $step_percent ) ) . '%';

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

		// 嚴格解析回應：嘗試 JSON 解碼後檢查 Status 欄位
		$is_cancel_success = false;
		$resp_obj          = json_decode( $body );
		if ( json_last_error() === JSON_ERROR_NONE && isset( $resp_obj->Status ) ) {
			$is_cancel_success = ( 'S' === $resp_obj->Status || '0' === $resp_obj->Status );
		} elseif ( preg_match( '/^(取消成功|訂單已取消)/', trim( $body ) ) ) {
			// Fallback：非 JSON 回應，嚴格比對開頭為明確的成功字串
			$is_cancel_success = true;
		}

		if ( $is_cancel_success ) {
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
			$order->add_order_note( __( '取消物流單失敗：', 'ys-paynow-shipping' ) . wp_strip_all_tags( $body ) );
			wp_send_json_error( __( '取消失敗：', 'ys-paynow-shipping' ) . wp_strip_all_tags( $body ) );
		}
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
				if ( is_object( $resp_obj ) && ( isset( $resp_obj->Delivery_Status ) || isset( $resp_obj->Detail_Status_Description ) ) ) {
					// 宅配優先 Detail_Status_Description，超取優先 Delivery_Status
					$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
					$is_home_delivery    = in_array( $logistic_service_id, array( '06', '36' ), true );

					$status_desc = '';
					if ( $is_home_delivery && ! empty( $resp_obj->Detail_Status_Description ) ) {
						$status_desc = $resp_obj->Detail_Status_Description;
					} elseif ( ! empty( $resp_obj->Delivery_Status ) ) {
						$status_desc = $resp_obj->Delivery_Status;
					} elseif ( ! empty( $resp_obj->Detail_Status_Description ) ) {
						$status_desc = $resp_obj->Detail_Status_Description;
					}

					if ( ! empty( $status_desc ) ) {
						$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_desc );
					}
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
		// 驗證 nonce（URL 參數）
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ys-paynow-print-label' ) ) {
			wp_die( esc_html__( '安全驗證失敗，請重新操作', 'ys-paynow-shipping' ), '', array( 'response' => 403 ) );
		}

		// 權限檢查
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足', 'ys-paynow-shipping' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['order_ids'] ) || ! isset( $_GET['service'] ) ) {
			wp_die( esc_html__( '缺少參數', 'ys-paynow-shipping' ) );
		}

		$order_ids     = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) ) );
		$service       = sanitize_text_field( wp_unslash( $_GET['service'] ) );
		$change_status = isset( $_GET['change_status'] ) && '1' === $_GET['change_status'];

		// 取得物流單號清單和訂單編號清單
		$logistic_numbers    = array();
		$order_numbers       = array();
		$printable_order_ids = array(); // 實際有物流單號可列印的訂單
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				// 取得物流單號（用於 B2C/冷凍/黑貓等需要 LogisticNumber 的服務）
				$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
				if ( ! empty( $logistic_number ) ) {
					$logistic_numbers[]    = $logistic_number . '_1'; // 格式：物流單號_1
					$printable_order_ids[] = $order_id;
				}

				// 取得訂單編號（用於 C2C 超商列印）
				$renew_order_no = $order->get_meta( YSOrderMeta::RenewOrderNo );
				$order_numbers[] = $renew_order_no ?: self::get_prefixed_order_no( $order );

			}
		}

		$order_numbers_string    = implode( ',', $order_numbers );
		$logistic_numbers_string = implode( ',', $logistic_numbers );

		// 判斷是否需要使用 POST 方法（B2C 大宗、冷凍、黑貓）
		$post_services = array(
			YSLogisticService::TCAT,
			YSLogisticService::TCAT_OWN,
			YSLogisticService::SEVENBULK,
			YSLogisticService::FAMIBULK,
			YSLogisticService::SEVENFROZEN,
			YSLogisticService::SEVENFROZEN_C2C,
			YSLogisticService::FAMIFROZEN,
			YSLogisticService::FAMIFROZEN_C2C,
		);

		$use_post = in_array( $service, $post_services, true );

		// ★ 檢查 POST 方法的服務是否有物流單號
		if ( $use_post && empty( $logistic_numbers ) ) {
			wp_die( esc_html__( '無法列印：所選訂單沒有物流單號。請先確認訂單已取號成功。', 'ys-paynow-shipping' ) );
		}

		// 根據物流服務取得列印 API URL
		$api_url = self::get_print_label_url( $service );

		if ( ! $api_url ) {
			wp_die( esc_html__( '不支援的物流服務', 'ys-paynow-shipping' ) );
		}

		YSPaynowShipping::log( sprintf(
			'Print label request: service=%s, use_post=%s, order_numbers=%s, logistic_numbers=%s, api_url=%s',
			$service,
			$use_post ? 'yes' : 'no',
			$order_numbers_string,
			$logistic_numbers_string,
			$api_url
		) );

		if ( $use_post ) {
			// B2C 大宗、冷凍、黑貓：使用 POST 方法（瀏覽器 redirect，無法得知列印結果）
			// 因流程離開 PHP，只能在 redirect 前標記列印狀態
			// ★ 僅標記實際有物流單號的訂單，避免混合選取時誤標
			self::mark_orders_printed( $printable_order_ids, $change_status );

			// ★ 使用自動提交表單重定向到 PayNow，讓瀏覽器直接載入頁面
			// 這樣可以正確載入 PayNow 頁面所需的外部資源（CSS、JS）
			?>
			<!DOCTYPE html>
			<html>
			<head>
				<meta charset="UTF-8">
				<title><?php esc_html_e( '正在跳轉到列印頁面...', 'ys-paynow-shipping' ); ?></title>
				<style>
					body {
						font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
						display: flex;
						justify-content: center;
						align-items: center;
						height: 100vh;
						margin: 0;
						background: #f5f5f5;
					}
					.loading {
						text-align: center;
						color: #666;
					}
					.spinner {
						border: 3px solid #f3f3f3;
						border-top: 3px solid #3498db;
						border-radius: 50%;
						width: 40px;
						height: 40px;
						animation: spin 1s linear infinite;
						margin: 0 auto 15px;
					}
					@keyframes spin {
						0% { transform: rotate(0deg); }
						100% { transform: rotate(360deg); }
					}
				</style>
			</head>
			<body>
				<div class="loading">
					<div class="spinner"></div>
					<p><?php esc_html_e( '正在跳轉到 PayNow 列印頁面...', 'ys-paynow-shipping' ); ?></p>
				</div>
				<form id="paynow-print-form" method="POST" action="<?php echo esc_url( $api_url ); ?>">
					<input type="hidden" name="LogisticNumbers" value="<?php echo esc_attr( $logistic_numbers_string ); ?>">
				</form>
				<script>
					document.getElementById('paynow-print-form').submit();
				</script>
			</body>
			</html>
			<?php
			exit;

		} else {
			// C2C 超商 (7-11/全家/萊爾富)：使用 GET 方法
			$api_url .= '?orderNumberStr=' . rawurlencode( $order_numbers_string ) . '&user_account=' . rawurlencode( YSPaynowShipping::$user_account );

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
			$real_url = '';
			if ( strpos( $body, '5,' ) === 0 ) {
				$real_url = substr( $body, 2 );
			} elseif ( strpos( $body, 'S,' ) === 0 ) {
				$real_url = substr( $body, 2 );
			} elseif ( filter_var( $body, FILTER_VALIDATE_URL ) ) {
				// 嘗試直接判定是否為 URL
				$real_url = $body;
			}

			if ( ! empty( $real_url ) && filter_var( $real_url, FILTER_VALIDATE_URL ) ) {
				// 安全檢查：限制跳轉網域為 PayNow 官方
				$allowed_hosts = array( 'logistic.paynow.com.tw', 'testlogistic.paynow.com.tw' );
				$parsed_host   = wp_parse_url( $real_url, PHP_URL_HOST );
				if ( ! in_array( $parsed_host, $allowed_hosts, true ) ) {
					YSPaynowShipping::log( '[Print] 列印 URL 網域不在白名單：' . $real_url, 'error' );
					wp_die( esc_html__( '列印網址網域異常，已拒絕跳轉。', 'ys-paynow-shipping' ) );
				}

				// C2C GET 方式：確認取得列印 URL 成功後才標記已列印
				self::mark_orders_printed( $order_ids, $change_status );
				wp_redirect( $real_url );
				exit;
			}

			// 若無法解析或格式錯誤
			wp_die( esc_html__( '無法取得標籤網址，API 回應：', 'ys-paynow-shipping' ) . esc_html( $body ) );
		}
	}

	/**
	 * 取得列印標籤 API URL (內部使用)
	 *
	 * 根據 PayNow API 文件：
	 * - C2C 超商 (7-11/全家/萊爾富)：使用 GET 方法，參數為 orderNumberStr
	 * - B2C 大宗、冷凍、黑貓：使用 POST 方法，參數為 LogisticNumbers（格式：物流單號_1）
	 *
	 * @param string $service 物流服務代碼。
	 * @return string|false 列印 URL 或 false。
	 */
	private static function get_print_label_url( $service ) {
		$base_url = YSPaynowShipping::$api_url;

		switch ( $service ) {
			// ========== C2C 超商（GET 方法）==========
			// 7-11 C2C (交貨便)
			case YSLogisticService::SEVEN:
				return $base_url . '/api/Order711';

			// 全家 C2C (店到店)
			case YSLogisticService::FAMI:
				return $base_url . '/api/OrderFamiC2C';

			// 萊爾富 C2C
			case YSLogisticService::HILIFE:
				return $base_url . '/api/OrderHiLife';

			// ========== B2C 大宗、冷凍、黑貓（POST 方法）==========
			// 7-11 B2C (大宗寄倉)
			case YSLogisticService::SEVENBULK:
				return $base_url . '/Member/Order/Print711bulkLabel';

			// 7-11 冷凍 C2C
			case YSLogisticService::SEVENFROZEN_C2C:
				return $base_url . '/Member/Order/Print711FreezingC2CLabel';

			// 7-11 冷凍 B2C
			case YSLogisticService::SEVENFROZEN:
				return $base_url . '/Member/Order/Print711FreezingB2CLabel';

			// 全家 B2C (大宗寄倉)
			case YSLogisticService::FAMIBULK:
				return $base_url . '/Member/Order/PrintFamiB2CLabel';

			// 全家 冷凍 C2C
			case YSLogisticService::FAMIFROZEN_C2C:
				return $base_url . '/Member/Order/PrintFamiFreezingC2CLabel';

			// 全家 冷凍 B2C
			case YSLogisticService::FAMIFROZEN:
				return $base_url . '/Member/Order/PrintFamiFreezingB2CLabel';

			// 黑貓宅配
			case YSLogisticService::TCAT:
			case YSLogisticService::TCAT_OWN:
				return $base_url . '/Member/Order/PrintBlackCatLabel';

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
				$cancel_resp = json_decode( $body );
				$cancel_ok   = false;
				if ( json_last_error() === JSON_ERROR_NONE && isset( $cancel_resp->Status ) ) {
					$cancel_ok = ( 'S' === $cancel_resp->Status || '0' === $cancel_resp->Status );
				} elseif ( preg_match( '/^(取消成功|訂單已取消)/', trim( $body ) ) ) {
					$cancel_ok = true;
				}

				if ( ! $cancel_ok ) {
					$order->add_order_note( __( '更換超商：舊物流單取消失敗（API 回應錯誤），但系統將繼續建立新單。', 'ys-paynow-shipping' ) . ' ' . wp_strip_all_tags( $body ) );
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
	 * 標記訂單為已列印，並視設定變更狀態
	 *
	 * @param array $order_ids     訂單 ID 清單。
	 * @param bool  $change_status 是否變更訂單狀態。
	 */
	private static function mark_orders_printed( $order_ids, $change_status ) {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$order->update_meta_data( '_ys_paynow_label_printed', 'yes' );
			$order->update_meta_data( '_ys_label_printed', 'yes' );

			if ( $change_status ) {
				$scheduled_status = self::get_scheduled_shipping_status();
				if ( ! empty( $scheduled_status ) && $order->get_status() !== $scheduled_status ) {
					$order->set_status( $scheduled_status, __( '批次列印後自動更改狀態', 'ys-paynow-shipping' ) );
				}
			}

			$order->save();
		}
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
	 * 移除敏感欄位用於 Log 輸出
	 *
	 * @param array $args 原始參數。
	 * @return array 移除敏感資訊後的參數。
	 */
	private static function redact_sensitive_args( $args ) {
		$redacted = $args;
		$keys     = array( 'apicode', 'PassCode', 'Receiver_Name', 'Receiver_Phone', 'Receiver_Email', 'Receiver_address', 'receiver_storeid', 'receiver_storename', 'Sender_Name', 'Sender_Phone', 'Sender_Email', 'Sender_address' );
		foreach ( $keys as $key ) {
			if ( isset( $redacted[ $key ] ) && '' !== $redacted[ $key ] ) {
				$redacted[ $key ] = '***';
			}
		}
		return $redacted;
	}

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
	 * 組合順序：郵遞區號 + 縣市 + 鄉鎮市區 + 地址
	 * 黑貓宅急便 API 需要郵遞區號才能正確驗證地址
	 *
	 * 在台灣 WooCommerce 設定中：
	 * - get_shipping_postcode() = 郵遞區號（如「114」）
	 * - get_shipping_state() = 縣市（如「臺北市」）
	 * - get_shipping_city() = 鄉鎮市區（如「內湖區」）
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 收件地址。
	 */
	private static function get_order_address( $order ) {
		$postcode = $order->get_shipping_postcode();
		$state    = $order->get_shipping_state();
		$city     = $order->get_shipping_city();
		$address1 = $order->get_shipping_address_1();
		$address2 = $order->get_shipping_address_2();

		// 組合地址：郵遞區號 + 縣市 + 鄉鎮市區 + 地址（黑貓需要郵遞區號）
		return $postcode . $state . $city . $address1 . $address2;
	}

	/**
	 * 取得寄件人完整地址
	 *
	 * 組合郵遞區號和地址，黑貓宅配需要郵遞區號才能驗證地址。
	 *
	 * @return string 完整寄件人地址（郵遞區號 + 地址）。
	 */
	private static function get_sender_address() {
		$postcode = get_option( 'ys_paynow_shipping_sender_postcode', '' );
		$address  = get_option( 'ys_paynow_shipping_sender_address', '' );

		// 組合地址：郵遞區號 + 地址
		return $postcode . $address;
	}
}
