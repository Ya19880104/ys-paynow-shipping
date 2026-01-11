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

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingResponse 類別
 *
 * 處理 PayNow 物流狀態更新的 Webhook 回調。
 *
 * @since 1.0.0
 */
class YSShippingResponse {

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 註冊 Webhook endpoint
		add_action( 'woocommerce_api_ys-paynow-shipping-callback', array( __CLASS__, 'handle_callback' ) );
	}

	/**
	 * 處理回調
	 *
	 * PayNow 會在物流狀態變更時發送 POST 請求到此 endpoint。
	 *
	 * @return void
	 */
	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw_data = file_get_contents( 'php://input' );
		YSPaynowShipping::log( 'Webhook received: ' . $raw_data );

		// 解析回傳資料
		$data = json_decode( $raw_data, true );
		if ( ! $data ) {
			parse_str( $raw_data, $data );
		}

		if ( empty( $data ) ) {
			YSPaynowShipping::log( 'Webhook: Empty data received', 'error' );
			wp_die( 'Invalid data', 'YS PayNow Shipping', array( 'response' => 400 ) );
		}

		// 取得訂單編號
		$order_no = isset( $data['OrderNo'] ) ? sanitize_text_field( $data['OrderNo'] ) : '';
		$logistic_number = isset( $data['LogisticNumber'] ) ? sanitize_text_field( $data['LogisticNumber'] ) : '';

		if ( empty( $logistic_number ) ) {
			YSPaynowShipping::log( 'Webhook: Missing LogisticNumber', 'error' );
			wp_die( 'Missing LogisticNumber', 'YS PayNow Shipping', array( 'response' => 400 ) );
		}

		// 查找訂單
		$order = self::find_order_by_logistic_number( $logistic_number );

		if ( ! $order ) {
			YSPaynowShipping::log( sprintf( 'Webhook: Order not found for LogisticNumber: %s', $logistic_number ), 'error' );
			wp_die( 'Order not found', 'YS PayNow Shipping', array( 'response' => 404 ) );
		}

		// 更新訂單物流狀態 (HPOS 相容)
		self::update_order_status( $order, $data );

		// 回應成功
		echo 'OK';
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * 根據物流單號查找訂單
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

	/**
	 * 更新訂單物流狀態
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $data  回調資料。
	 * @return void
	 */
	private static function update_order_status( $order, $data ) {
		$delivery_status = isset( $data['Delivery_Status'] ) ? sanitize_text_field( $data['Delivery_Status'] ) : '';
		$logistic_code   = isset( $data['PayNowLogisticCode'] ) ? sanitize_text_field( $data['PayNowLogisticCode'] ) : '';
		$detail_desc     = isset( $data['Detail_Status_Description'] ) ? sanitize_text_field( $data['Detail_Status_Description'] ) : '';
		$payment_no      = isset( $data['paymentno'] ) ? sanitize_text_field( $data['paymentno'] ) : '';
		$validation_no   = isset( $data['validationno'] ) ? sanitize_text_field( $data['validationno'] ) : '';

		// 更新 meta (HPOS 相容)
		if ( ! empty( $delivery_status ) ) {
			$order->update_meta_data( YSOrderMeta::DeliveryStatus, $delivery_status );
		}
		if ( ! empty( $logistic_code ) ) {
			$order->update_meta_data( YSOrderMeta::LogisticCode, $logistic_code );
		}
		if ( ! empty( $detail_desc ) ) {
			$order->update_meta_data( YSOrderMeta::DetailStatusDesc, $detail_desc );
		}
		if ( ! empty( $payment_no ) ) {
			$order->update_meta_data( YSOrderMeta::PaymentNo, $payment_no );
		}
		if ( ! empty( $validation_no ) ) {
			$order->update_meta_data( YSOrderMeta::ValidationNo, $validation_no );
		}

		$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
		$order->save();

		// 添加訂單備註
		if ( ! empty( $delivery_status ) ) {
			/* translators: %s: Delivery status */
			$order->add_order_note( sprintf( __( '物流狀態更新：%s', 'ys-paynow-shipping' ), $delivery_status ) );
		}

		YSPaynowShipping::log( sprintf( 'Order #%d status updated: %s', $order->get_id(), $delivery_status ) );

		do_action( 'ys_paynow_shipping_status_updated', $order, $data );
	}
}
