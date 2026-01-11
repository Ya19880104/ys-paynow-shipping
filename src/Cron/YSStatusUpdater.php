<?php
/**
 * YS Status Updater Class
 *
 * 處理每日排程更新物流狀態
 *
 * @package YangSheep\PayNow\Shipping\Cron
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Cron;

use YangSheep\PayNow\Shipping\Api\YSShippingRequest;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSShippingStatus;
use YangSheep\PayNow\Shipping\Utils\YSOrderStatus;
use YangSheep\PayNow\Shipping\YSPaynowShipping;

defined( 'ABSPATH' ) || exit;

class YSStatusUpdater {

	/**
	 * 初始化
	 */
	public static function init() {
		// 註冊 Cron Event
		add_action( 'ys_paynow_daily_status_update', array( __CLASS__, 'process_daily_update' ) );

		// 排程設定
		if ( ! wp_next_scheduled( 'ys_paynow_daily_status_update' ) ) {
			// 設定為明天早上 06:00
			$time = strtotime( 'tomorrow 06:00:00' );
			wp_schedule_event( $time, 'daily', 'ys_paynow_daily_status_update' );
		}
	}

	/**
	 * 執行每日更新
	 */
	public static function process_daily_update() {
		YSPaynowShipping::log( 'Starting daily status update...' );

		$orders = wc_get_orders( array(
			'limit'  => -1,
			'status' => array( 'processing', YSOrderStatus::SHIPPING_ORDERED, YSOrderStatus::SHIPPING_TRANSIT, YSOrderStatus::SHIPPING_ARRIVED ),
			'meta_key'     => YSOrderMeta::LogisticNumber,
			'meta_compare' => 'EXISTS',
		) );

		if ( empty( $orders ) ) {
			YSPaynowShipping::log( 'No orders to update.' );
			return;
		}

		foreach ( $orders as $order ) {
			$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
			if ( empty( $logistic_number ) ) {
				continue;
			}

			try {
				$response = YSShippingRequest::api_query_order( $order );
				
				if ( is_wp_error( $response ) ) {
					continue;
				}

				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body );

				if ( isset( $data->Status ) && 'S' === $data->Status ) {
					// 取得物流狀態碼 (某些 API 回傳 LogisticStatus, 某些是其他欄位, 這裡假設是 DeliveryStatus 或需解析)
					// PayNow API 回傳的 JSON 結構通常包含 'DeliveryStatus' (文字) 和 'PayNowLogisticCode' (代碼)
					// 若沒有代碼，可能需要依賴文字 (較不準確)，或檢查 XML/API 文件
					// 根據 browser agent 查詢，欄位可能是 PayNowLogisticCode
					
					$status_code = $data->PayNowLogisticCode ?? '';
					$status_desc = $data->DeliveryStatus ?? '';

					if ( empty( $status_code ) && ! empty( $status_desc ) ) {
						// 若無代碼，嘗試從描述反推 (不推薦，但作為 fallback)
						// 這裡先略過，避免錯誤判斷
					}

					// 更新 Meta
					if ( ! empty( $status_desc ) ) {
						$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_desc );
					}
					
					// 狀態對應與更新
					if ( ! empty( $status_code ) ) {
						$new_status = YSShippingStatus::get_wc_status_from_paynow_status( $status_code );
						
						if ( $new_status && $new_status !== $order->get_status() && 'wc-' . $order->get_status() !== $new_status ) {
							$order->update_status( $new_status, sprintf( __( '物流狀態更新: %s (%s)', 'ys-paynow-shipping' ), $status_desc, $status_code ) );
							YSPaynowShipping::log( sprintf( 'Order #%d status updated to %s', $order->get_id(), $new_status ) );
						}
					}
					
					$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
					$order->save();
				}

			} catch ( \Exception $e ) {
				YSPaynowShipping::log( 'Error updating order #' . $order->get_id() . ': ' . $e->getMessage() );
			}
			
			// 避免過於頻繁請求
			usleep( 200000 ); // 0.2s
		}

		YSPaynowShipping::log( 'Daily status update completed.' );
	}
}
