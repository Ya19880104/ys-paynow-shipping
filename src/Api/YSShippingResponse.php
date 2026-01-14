<?php
/**
 * YS Shipping Response
 *
 * 處理 PayNow 物流 API 回應 (Webhook)。
 *
 * @package YangSheep\PayNow\Shipping\Api
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Api;

use YangSheep\PayNow\Shipping\YSPaynowShipping;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSShippingStatus;

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingResponse 類別
 *
 * 處理 PayNow 物流狀態更新的 Webhook 回調。
 *
 * @since 1.0.0
 */
class YSShippingResponse {

	/*
	|--------------------------------------------------------------------------
	| PayNow 物流狀態代碼常數
	|--------------------------------------------------------------------------
	*/

	/**
	 * 商品已到寄件門市
	 */
	const AT_SENDER_CVS = '0101';

	/**
	 * 交貨便收件
	 */
	const DELIVERING = '5202';

	/**
	 * EC 收退
	 */
	const EC_RETURN = '5201';

	/**
	 * 取件門市配達
	 */
	const AT_RECEIVER_CVS = '5000';

	/**
	 * 買家已取件
	 */
	const CUSTOMER_PICKUP = '8000';

	/**
	 * 黑貓收退
	 */
	const TCAT_RETURN = '8520';

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 註冊 Webhook endpoint (貨態回傳)
		add_action( 'woocommerce_api_ys-paynow-shipping-callback', array( __CLASS__, 'handle_callback' ) );

		// 根據貨態更新訂單狀態
		add_action( 'ys_paynow_shipping_status_updated', array( __CLASS__, 'update_order_status_by_logistic_code' ), 10, 2 );
	}

	/**
	 * 處理回調
	 *
	 * PayNow 會在物流狀態變更時發送 POST 請求到此 endpoint。
	 *
	 * 回傳資料格式 (根據 PayNow API 文件)：
	 * - orderno: 訂單編號
	 * - OriginOrderno: 原始訂單編號
	 * - PayNowLogisticCode: PayNow 物流代碼
	 * - Detail_Status_Description: 物流狀態詳細描述
	 * - paymentno: 物流商託運單號
	 * - StoreDate: 到店日期
	 * - StoreTime: 到店時間
	 *
	 * @return void
	 */
	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted = wc_clean( wp_unslash( $_POST ) );
		YSPaynowShipping::log( 'Webhook receive_order_status_update: ' . wp_json_encode( $posted ) );

		if ( empty( $posted ) ) {
			YSPaynowShipping::log( 'Webhook: Empty data received', 'error' );
			wp_die( 'Invalid data', 'YS PayNow Shipping', array( 'response' => 400 ) );
		}

		// 解析 PayNow 回傳資料
		$order_no              = isset( $posted['orderno'] ) ? sanitize_text_field( $posted['orderno'] ) : '';
		$original_order_no     = isset( $posted['OriginOrderno'] ) ? sanitize_text_field( $posted['OriginOrderno'] ) : '';
		$paynow_logistic_code  = isset( $posted['PayNowLogisticCode'] ) ? sanitize_text_field( $posted['PayNowLogisticCode'] ) : '';
		$detailed_status       = isset( $posted['Detail_Status_Description'] ) ? sanitize_text_field( $posted['Detail_Status_Description'] ) : '';
		$payment_no            = isset( $posted['paymentno'] ) ? sanitize_text_field( $posted['paymentno'] ) : '';
		$store_date            = isset( $posted['StoreDate'] ) ? sanitize_text_field( $posted['StoreDate'] ) : '';
		$store_time            = isset( $posted['StoreTime'] ) ? sanitize_text_field( $posted['StoreTime'] ) : '';

		if ( empty( $order_no ) ) {
			YSPaynowShipping::log( 'Webhook: Missing orderno', 'error' );
			wp_die( 'Missing orderno', 'YS PayNow Shipping', array( 'response' => 400 ) );
		}

		// 查找訂單
		// 注意：orderno 可能有前綴，需要處理
		$order = self::find_order_by_order_no( $order_no );

		if ( ! $order ) {
			YSPaynowShipping::log( sprintf( 'Webhook: Order not found for orderno: %s', $order_no ), 'error' );
			wp_die( 'Order not found', 'YS PayNow Shipping', array( 'response' => 404 ) );
		}

		// 更新訂單 meta (HPOS 相容)
		if ( ! empty( $original_order_no ) ) {
			$order->update_meta_data( YSOrderMeta::OriginalOrderNo, $original_order_no );
		}
		if ( ! empty( $paynow_logistic_code ) ) {
			$order->update_meta_data( YSOrderMeta::LogisticCode, $paynow_logistic_code );
		}
		if ( ! empty( $detailed_status ) ) {
			$order->update_meta_data( YSOrderMeta::DetailStatusDesc, $detailed_status );
			$order->update_meta_data( YSOrderMeta::DeliveryStatus, $detailed_status );
		}
		if ( ! empty( $payment_no ) ) {
			$order->update_meta_data( YSOrderMeta::PaymentNo, $payment_no );
		}
		if ( ! empty( $store_date ) ) {
			$order->update_meta_data( YSOrderMeta::StoreDate, $store_date );
		}
		if ( ! empty( $store_time ) ) {
			$order->update_meta_data( YSOrderMeta::StoreTime, $store_time );
		}

		$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
		$order->save();

		// 添加訂單備註
		$note = sprintf(
			/* translators: 1: logistic code, 2: detailed status, 3: payment no, 4: store date, 5: store time */
			__( 'PayNow 貨態更新：物流代碼: %1$s, 狀態: %2$s, 託運單號: %3$s, 到店日期: %4$s, 到店時間: %5$s', 'ys-paynow-shipping' ),
			$paynow_logistic_code,
			$detailed_status,
			$payment_no,
			$store_date,
			$store_time
		);
		$order->add_order_note( $note );

		YSPaynowShipping::log( sprintf( 'Order #%d status updated via webhook: %s', $order->get_id(), $detailed_status ) );

		// 觸發 action，根據物流代碼更新訂單狀態
		do_action( 'ys_paynow_shipping_status_updated', $order, $paynow_logistic_code );

		// 回應成功
		echo 'OK';
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * 根據訂單編號查找訂單
	 *
	 * 支援：
	 * 1. 直接訂單 ID
	 * 2. 帶前綴的訂單編號
	 * 3. 重新取號後的訂單編號
	 *
	 * @param string $order_no 訂單編號。
	 * @return \WC_Order|false 訂單物件或 false。
	 */
	private static function find_order_by_order_no( $order_no ) {
		// 1. 先嘗試直接取得訂單
		$order = wc_get_order( $order_no );
		if ( $order ) {
			return $order;
		}

		// 2. 移除可能的前綴後再嘗試
		$prefix = get_option( 'ys_paynow_shipping_order_prefix', '' );
		if ( ! empty( $prefix ) && strpos( $order_no, $prefix ) === 0 ) {
			$order_id = substr( $order_no, strlen( $prefix ) );
			// 移除可能的重試後綴 (-1, -2, ...)
			$order_id = preg_replace( '/-\d+$/', '', $order_id );
			$order    = wc_get_order( $order_id );
			if ( $order ) {
				return $order;
			}
		}

		// 3. 透過 RenewOrderNo meta 查找
		$orders = wc_get_orders( array(
			'meta_key'   => YSOrderMeta::RenewOrderNo,
			'meta_value' => $order_no,
			'limit'      => 1,
		) );

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		return false;
	}

	/**
	 * 根據物流代碼更新訂單狀態
	 *
	 * @param \WC_Order $order         訂單物件。
	 * @param string    $logistic_code PayNow 物流代碼。
	 * @return void
	 */
	public static function update_order_status_by_logistic_code( $order, $logistic_code ) {
		if ( empty( $logistic_code ) ) {
			return;
		}

		YSPaynowShipping::log( sprintf( 'Update order status. Order id: %d, logistic code: %s', $order->get_id(), $logistic_code ) );

		// 檢查是否啟用自動狀態更新
		$auto_update = get_option( 'ys_paynow_shipping_auto_update_status', 'yes' );
		if ( 'yes' !== $auto_update ) {
			return;
		}

		// 取得對應的 WC 訂單狀態
		$new_status = YSShippingStatus::get_wc_status_from_paynow_status( $logistic_code );

		if ( ! empty( $new_status ) ) {
			$current_status = 'wc-' . $order->get_status();

			// 避免重複更新相同狀態
			if ( $current_status !== $new_status && $order->get_status() !== str_replace( 'wc-', '', $new_status ) ) {
				$order->update_status(
					str_replace( 'wc-', '', $new_status ),
					__( 'PayNow 貨態回傳自動更新狀態', 'ys-paynow-shipping' )
				);
				YSPaynowShipping::log( sprintf( 'Order #%d status changed from %s to %s', $order->get_id(), $current_status, $new_status ) );
			}
		}
	}

	/**
	 * 根據物流單號查找訂單 (備用方法)
	 *
	 * @param string $logistic_number 物流單號。
	 * @return \WC_Order|false 訂單物件或 false。
	 */
	private static function find_order_by_logistic_number( $logistic_number ) {
		$orders = wc_get_orders( array(
			'meta_key'   => YSOrderMeta::LogisticNumber,
			'meta_value' => $logistic_number,
			'limit'      => 1,
		) );

		return ! empty( $orders ) ? $orders[0] : false;
	}
}
