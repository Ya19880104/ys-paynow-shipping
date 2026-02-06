<?php
/**
 * YS Status Updater Class
 *
 * 處理定時排程更新物流狀態（可自訂間隔）
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
	 * CRON LOG 的 WC Logger 實例
	 *
	 * @var \WC_Logger|null
	 */
	private static $cron_logger = null;

	/**
	 * 初始化
	 */
	public static function init() {
		// 註冊自訂排程間隔
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );

		// 註冊 Cron Event
		add_action( 'ys_paynow_status_update', array( __CLASS__, 'process_status_update' ) );

		// 註冊 CRON LOG 清除排程
		add_action( 'ys_paynow_cron_log_cleanup', array( __CLASS__, 'cleanup_cron_logs' ) );

		// 移除舊的每日排程（從 daily → every_six_hours 升級）
		$old_hook = wp_next_scheduled( 'ys_paynow_daily_status_update' );
		if ( $old_hook ) {
			wp_unschedule_event( $old_hook, 'ys_paynow_daily_status_update' );
		}

		// 移除舊的固定 every_six_hours 排程（改為動態間隔）
		self::maybe_reschedule();

		// 排程設定（動態間隔）
		if ( ! wp_next_scheduled( 'ys_paynow_status_update' ) ) {
			wp_schedule_event( time(), 'ys_paynow_custom_interval', 'ys_paynow_status_update' );
		}

		// CRON LOG 清除排程（每日）
		if ( ! wp_next_scheduled( 'ys_paynow_cron_log_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ys_paynow_cron_log_cleanup' );
		}
	}

	/**
	 * 檢查排程間隔是否變更，變更時自動重新排程
	 */
	private static function maybe_reschedule() {
		$timestamp = wp_next_scheduled( 'ys_paynow_status_update' );
		if ( ! $timestamp ) {
			return;
		}

		// 取得目前排程的 recurrence
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return;
		}

		foreach ( $crons as $ts => $cron_hooks ) {
			if ( isset( $cron_hooks['ys_paynow_status_update'] ) ) {
				foreach ( $cron_hooks['ys_paynow_status_update'] as $hash => $data ) {
					$current_schedule = isset( $data['schedule'] ) ? $data['schedule'] : '';

					// 如果使用的是舊排程名稱（every_six_hours）或不是自訂間隔，重新排程
					if ( 'ys_paynow_custom_interval' !== $current_schedule ) {
						wp_unschedule_event( $ts, 'ys_paynow_status_update' );
						return;
					}

					// 檢查間隔是否與設定值一致
					$configured_hours = absint( get_option( 'ys_paynow_shipping_cron_interval', 6 ) );
					if ( $configured_hours < 1 ) {
						$configured_hours = 6;
					}
					$configured_interval = $configured_hours * HOUR_IN_SECONDS;
					$current_interval    = isset( $data['interval'] ) ? $data['interval'] : 0;

					if ( $current_interval !== $configured_interval ) {
						wp_unschedule_event( $ts, 'ys_paynow_status_update' );
						return;
					}
				}
				break;
			}
		}
	}

	/**
	 * 註冊自訂 Cron 間隔（動態讀取設定值）
	 *
	 * @param array $schedules 現有排程間隔。
	 * @return array 新增後的排程間隔。
	 */
	public static function add_cron_interval( $schedules ) {
		$hours = absint( get_option( 'ys_paynow_shipping_cron_interval', 6 ) );
		if ( $hours < 1 ) {
			$hours = 6;
		}

		$schedules['ys_paynow_custom_interval'] = array(
			'interval' => $hours * HOUR_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: hours */
				__( '每 %d 小時', 'ys-paynow-shipping' ),
				$hours
			),
		);

		return $schedules;
	}

	/*
	|--------------------------------------------------------------------------
	| CRON LOG 方法
	|--------------------------------------------------------------------------
	*/

	/**
	 * 寫入 CRON 專用 LOG
	 *
	 * 受 ys_paynow_shipping_cron_log_enabled option 控制。
	 *
	 * @param string $message 日誌訊息。
	 * @param string $level   日誌等級 (info, error, warning)。
	 */
	public static function cron_log( $message, $level = 'info' ) {
		if ( 'yes' !== get_option( 'ys_paynow_shipping_cron_log_enabled', 'no' ) ) {
			return;
		}

		if ( ! self::$cron_logger ) {
			self::$cron_logger = wc_get_logger();
		}

		self::$cron_logger->log( $level, $message, array( 'source' => 'ys-paynow-cron-log' ) );
	}

	/**
	 * 取得 CRON LOG 內容
	 *
	 * @param int $max_lines 最多回傳行數。
	 * @return string LOG 內容。
	 */
	public static function get_cron_log_content( $max_lines = 200 ) {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			return __( 'WooCommerce LOG 目錄未定義。', 'ys-paynow-shipping' );
		}

		$log_dir = WC_LOG_DIR;
		$pattern = $log_dir . 'ys-paynow-cron-log-*.log';
		$files   = glob( $pattern );

		if ( empty( $files ) ) {
			return __( '目前沒有 CRON LOG 紀錄。', 'ys-paynow-shipping' );
		}

		// 按修改時間排序（最新在後）
		usort( $files, function ( $a, $b ) {
			return filemtime( $a ) - filemtime( $b );
		} );

		// 從最新的檔案向舊的收集行數
		$lines     = array();
		$remaining = $max_lines;

		for ( $i = count( $files ) - 1; $i >= 0 && $remaining > 0; $i-- ) {
			$content    = file_get_contents( $files[ $i ] );
			$file_lines = explode( "\n", trim( $content ) );

			if ( count( $file_lines ) <= $remaining ) {
				$lines     = array_merge( $file_lines, $lines );
				$remaining -= count( $file_lines );
			} else {
				// 只取最後 N 行
				$slice     = array_slice( $file_lines, -$remaining );
				$lines     = array_merge( $slice, $lines );
				$remaining = 0;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * 清除所有 CRON LOG 檔案
	 *
	 * @return int 刪除的檔案數。
	 */
	public static function clear_cron_log_files() {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			return 0;
		}

		$pattern = WC_LOG_DIR . 'ys-paynow-cron-log-*.log';
		$files   = glob( $pattern );
		$count   = 0;

		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) && wp_delete_file( $file ) !== false ) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * 清除 7 天前的 CRON LOG 檔案（由每日 CRON 觸發）
	 */
	public static function cleanup_cron_logs() {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			return;
		}

		$pattern   = WC_LOG_DIR . 'ys-paynow-cron-log-*.log';
		$files     = glob( $pattern );
		$threshold = time() - ( 7 * DAY_IN_SECONDS );
		$deleted   = 0;

		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) && filemtime( $file ) < $threshold ) {
					wp_delete_file( $file );
					$deleted++;
				}
			}
		}

		if ( $deleted > 0 ) {
			YSPaynowShipping::log( sprintf( '[Cron] 已清除 %d 個過期 CRON LOG 檔案', $deleted ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| 排程更新
	|--------------------------------------------------------------------------
	*/

	/**
	 * 執行定時物流狀態更新
	 */
	public static function process_status_update() {
		$hours = absint( get_option( 'ys_paynow_shipping_cron_interval', 6 ) );
		YSPaynowShipping::log( sprintf( '[Cron] 開始排程物流狀態更新（間隔: %d 小時）...', $hours ) );
		self::cron_log( sprintf( '========== 排程開始（間隔: %d 小時） ==========', $hours ) );

		$orders = wc_get_orders( array(
			'limit'  => -1,
			'status' => array( 'processing', YSOrderStatus::SHIPPING_ORDERED, YSOrderStatus::SHIPPING_TRANSIT, YSOrderStatus::SHIPPING_ARRIVED ),
			'meta_key'     => YSOrderMeta::LogisticNumber,
			'meta_compare' => 'EXISTS',
		) );

		if ( empty( $orders ) ) {
			YSPaynowShipping::log( '[Cron] 無待更新訂單。' );
			self::cron_log( '無待更新訂單，排程結束。' );
			return;
		}

		$total   = count( $orders );
		$updated = 0;
		$failed  = 0;
		self::cron_log( sprintf( '找到 %d 筆待更新訂單', $total ) );

		foreach ( $orders as $order ) {
			$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
			if ( empty( $logistic_number ) ) {
				continue;
			}

			try {
				$response = YSShippingRequest::api_query_order( $order );

				if ( is_wp_error( $response ) ) {
					YSPaynowShipping::log( sprintf( '[Cron] 訂單 #%d 查詢失敗: %s', $order->get_id(), $response->get_error_message() ) );
					self::cron_log( sprintf( '訂單 #%d 查詢失敗: %s', $order->get_id(), $response->get_error_message() ), 'error' );
					$failed++;
					continue;
				}

				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body );

				YSPaynowShipping::log( sprintf( '[Cron] 訂單 #%d 查詢回應: %s', $order->get_id(), $body ) );

				// 檢查是否有錯誤訊息
				if ( ! empty( $data->ErrorMsg ) ) {
					YSPaynowShipping::log( sprintf( '[Cron] 訂單 #%d 錯誤: %s', $order->get_id(), $data->ErrorMsg ) );
					self::cron_log( sprintf( '訂單 #%d 錯誤: %s', $order->get_id(), $data->ErrorMsg ), 'warning' );
					$failed++;
					continue;
				}

				// API 回傳的 Status: "0"=成立中, "1"=無效
				if ( isset( $data->Status ) && '1' === $data->Status ) {
					YSPaynowShipping::log( sprintf( '[Cron] 訂單 #%d 無效 (Status=1)', $order->get_id() ) );
					self::cron_log( sprintf( '訂單 #%d 無效 (Status=1)', $order->get_id() ), 'warning' );
					$failed++;
					continue;
				}

				// 取得物流狀態碼和描述
				$status_code = $data->PayNowLogisticCode ?? '';
				$status_desc = '';

				// 宅配訂單優先使用 Detail_Status_Description（如「商品配送完成」）
				// 超取訂單優先使用 Delivery_Status（如「取件完成」）
				$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
				$is_home_delivery    = in_array( $logistic_service_id, array( '06', '36' ), true );

				if ( $is_home_delivery && ! empty( $data->Detail_Status_Description ) ) {
					$status_desc = $data->Detail_Status_Description;
				} elseif ( ! empty( $data->Delivery_Status ) ) {
					$status_desc = $data->Delivery_Status;
				} elseif ( ! empty( $data->Detail_Status_Description ) ) {
					$status_desc = $data->Detail_Status_Description;
				}

				// 更新 Meta
				if ( ! empty( $status_desc ) ) {
					$order->update_meta_data( YSOrderMeta::DeliveryStatus, $status_desc );
				}
				if ( ! empty( $data->LogisticNumber ) ) {
					$order->update_meta_data( YSOrderMeta::LogisticCode, $status_code );
				}
				if ( ! empty( $data->Detail_Status_Description ) ) {
					$order->update_meta_data( YSOrderMeta::DetailStatusDesc, $data->Detail_Status_Description );
				}
				if ( ! empty( $data->paymentno ) ) {
					$order->update_meta_data( YSOrderMeta::PaymentNo, $data->paymentno );
				}

				// 狀態對應與更新
				$status_changed = false;
				if ( ! empty( $status_code ) ) {
					$new_status = YSShippingStatus::get_wc_status_from_paynow_status( $status_code );

					if ( $new_status && $new_status !== $order->get_status() && 'wc-' . $order->get_status() !== $new_status ) {
						$order->update_status( $new_status, sprintf( __( '物流狀態自動更新: %s (%s)', 'ys-paynow-shipping' ), $status_desc, $status_code ) );
						$status_changed = true;
						YSPaynowShipping::log( sprintf( '[Cron] 訂單 #%d 狀態更新為 %s (code: %s, desc: %s)', $order->get_id(), $new_status, $status_code, $status_desc ) );
					}
				}

				$order->update_meta_data( YSOrderMeta::StatusUpdateAt, current_time( 'mysql' ) );
				$order->save();

				// CRON LOG 記錄
				if ( $status_changed ) {
					self::cron_log( sprintf( '訂單 #%d → 狀態更新: %s (%s)', $order->get_id(), $status_desc, $status_code ) );
				} else {
					self::cron_log( sprintf( '訂單 #%d → 無變更 (目前: %s)', $order->get_id(), $status_desc ?: '(空)' ) );
				}
				$updated++;

			} catch ( \Exception $e ) {
				YSPaynowShipping::log( '[Cron] 訂單 #' . $order->get_id() . ' 例外: ' . $e->getMessage() );
				self::cron_log( sprintf( '訂單 #%d 例外: %s', $order->get_id(), $e->getMessage() ), 'error' );
				$failed++;
			}

			// 避免過於頻繁請求
			usleep( 200000 ); // 0.2s
		}

		$summary = sprintf( '排程完成 — 共 %d 筆，成功 %d，失敗 %d', $total, $updated, $failed );
		YSPaynowShipping::log( '[Cron] ' . $summary );
		self::cron_log( $summary );
		self::cron_log( '========== 排程結束 ==========' );
	}
}
