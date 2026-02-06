<?php
/**
 * YS Shipping Status 常數定義
 *
 * 定義 PayNow 物流狀態代碼常數。
 *
 * @package YangSheep\PayNow\Shipping\Utils
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Utils;

use YangSheep\PayNow\Shipping\Utils\YSOrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingStatus 類別
 *
 * 包含所有 PayNow 物流狀態代碼的常數及說明。
 *
 * @since 1.0.0
 */
class YSShippingStatus {

	/*
	|--------------------------------------------------------------------------
	| 訂單狀態
	|--------------------------------------------------------------------------
	*/

	/**
	 * 成立中訂單
	 *
	 * @var string
	 */
	const ORDER_ACTIVE = '0';

	/**
	 * 無效訂單
	 *
	 * @var string
	 */
	const ORDER_INVALID = '1';

	/*
	|--------------------------------------------------------------------------
	| 物流狀態代碼（通用）
	|--------------------------------------------------------------------------
	*/

	/**
	 * 訂單建立完成
	 *
	 * @var string
	 */
	const CREATED = '100';

	/**
	 * 寄件人已將貨物送達指定門市
	 *
	 * @var string
	 */
	const SENDER_DELIVERED = '200';

	/**
	 * 貨物配送中
	 *
	 * @var string
	 */
	const IN_TRANSIT = '300';

	/**
	 * 貨物已到達取貨門市
	 *
	 * @var string
	 */
	const ARRIVED_AT_STORE = '400';

	/**
	 * 消費者已取貨
	 *
	 * @var string
	 */
	const PICKED_UP = '500';

	/**
	 * 貨物退回中
	 *
	 * @var string
	 */
	const RETURNING = '600';

	/**
	 * 貨物已退回寄件人
	 *
	 * @var string
	 */
	const RETURNED = '700';

	/**
	 * 訂單已取消
	 *
	 * @var string
	 */
	const CANCELLED = '900';

	/*
	|--------------------------------------------------------------------------
	| PayNow 物流代碼（黑貓宅配）
	|--------------------------------------------------------------------------
	*/

	/**
	 * 已集貨轉運中
	 *
	 * @var string
	 */
	const TCAT_IN_TRANSIT = '4500';

	/**
	 * 物流中心理貨中
	 *
	 * @var string
	 */
	const TCAT_AT_CENTER = '4060';

	/**
	 * 暫置營業所
	 *
	 * @var string
	 */
	const TCAT_AT_STATION = '5505';

	/**
	 * 配送中
	 *
	 * @var string
	 */
	const TCAT_DELIVERING = '5500';

	/**
	 * 商品配送完成（黑貓）
	 *
	 * @var string
	 */
	const TCAT_DELIVERED = '8500';

	/**
	 * 黑貓收退
	 *
	 * @var string
	 */
	const TCAT_RETURNED = '8520';

	/*
	|--------------------------------------------------------------------------
	| 狀態說明對照
	|--------------------------------------------------------------------------
	*/

	/**
	 * 取得物流狀態說明
	 *
	 * @param string $status_code 物流狀態代碼。
	 * @return string 狀態說明。
	 */
	public static function get_status_description( $status_code ) {
		$descriptions = array(
			self::CREATED           => '訂單建立完成',
			self::SENDER_DELIVERED  => '寄件人已將貨物送達門市',
			self::IN_TRANSIT        => '貨物配送中',
			self::ARRIVED_AT_STORE  => '貨物已到達取貨門市',
			self::PICKED_UP         => '消費者已取貨',
			self::RETURNING         => '貨物退回中',
			self::RETURNED          => '貨物已退回寄件人',
			self::CANCELLED         => '訂單已取消',
		);

		return isset( $descriptions[ $status_code ] ) ? $descriptions[ $status_code ] : $status_code;
	}

	/**
	 * 取得所有狀態及說明
	 *
	 * @return array 狀態陣列 (code => description)。
	 */
	public static function get_all_statuses() {
		return array(
			self::CREATED           => '訂單建立完成',
			self::SENDER_DELIVERED  => '寄件人已將貨物送達門市',
			self::IN_TRANSIT        => '貨物配送中',
			self::ARRIVED_AT_STORE  => '貨物已到達取貨門市',
			self::PICKED_UP         => '消費者已取貨',
			self::RETURNING         => '貨物退回中',
			self::RETURNED          => '貨物已退回寄件人',
			self::CANCELLED         => '訂單已取消',
		);
	}
	/**
	 * 將 PayNow 物流狀態碼對應到 WooCommerce 訂單狀態
	 *
	 * @param string $status_code PayNow 物流狀態碼.
	 * @return string|null 對應的 WC 狀態，若無對應則回傳 null.
	 */
	public static function get_wc_status_from_paynow_status( $status_code ) {
		// 檢查是否啟用自動狀態配置
		$auto_status = get_option( 'ys_paynow_shipping_auto_status', 'yes' );
		
		// 定義目標狀態
		$status_ordered  = ( 'yes' === $auto_status ) ? YSOrderStatus::SHIPPING_ORDERED : get_option( 'ys_paynow_shipping_status_ordered', 'wc-processing' );
		$status_transit  = ( 'yes' === $auto_status ) ? YSOrderStatus::SHIPPING_TRANSIT : get_option( 'ys_paynow_shipping_status_transit', 'wc-processing' );
		$status_arrived  = ( 'yes' === $auto_status ) ? YSOrderStatus::SHIPPING_ARRIVED : get_option( 'ys_paynow_shipping_status_arrived', 'wc-processing' );
		$status_returned = ( 'yes' === $auto_status ) ? YSOrderStatus::SHIPPING_RETURNED : get_option( 'ys_paynow_shipping_status_returned', 'wc-failed' );

		// 已完成 (取貨/配達) - 一律對應 wc-completed
		if ( in_array( $status_code, array( '8000', '8500', self::PICKED_UP, self::TCAT_DELIVERED ), true ) ) {
			return 'wc-completed';
		}

		// 已到達取貨商店 / 配送營業所
		if ( in_array( $status_code, array( '5000', '400', self::ARRIVED_AT_STORE ), true ) ) {
			return $status_arrived;
		}

		// 運送中（含黑貓集貨、轉運、配送、暫置營業所）
		if ( in_array( $status_code, array(
			'0101', '0102', '300', '5202',
			self::SENDER_DELIVERED, self::IN_TRANSIT,
			self::TCAT_IN_TRANSIT, self::TCAT_AT_CENTER, self::TCAT_AT_STATION, self::TCAT_DELIVERING,
		), true ) ) {
			return $status_transit;
		}

		// 已安排出貨 (訂單成立)
		if ( in_array( $status_code, array( '0000', '100', self::CREATED ), true ) ) {
			return $status_ordered;
		}

		// 退回 / 異常
		if ( in_array( $status_code, array( '0103', '4031', '4032', '5001', '600', '700', self::RETURNING, self::RETURNED, self::CANCELLED, self::TCAT_RETURNED ), true ) ) {
			return $status_returned;
		}

		return null;
	}
}
