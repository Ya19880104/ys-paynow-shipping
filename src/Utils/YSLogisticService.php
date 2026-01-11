<?php
/**
 * YS Logistic Service 常數定義
 *
 * 定義 PayNow 物流服務代碼常數。
 *
 * @package YangSheep\PayNow\Shipping\Utils
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * YSLogisticService 類別
 *
 * 包含所有 PayNow 物流服務代碼的常數。
 *
 * @since 1.0.0
 */
class YSLogisticService {

	/*
	|--------------------------------------------------------------------------
	| 超商取貨 - C2C (店到店)
	|--------------------------------------------------------------------------
	*/

	/**
	 * 7-11 交貨便
	 *
	 * @var string
	 */
	/**
	 * 7-11 交貨便
	 *
	 * @var string
	 */
	const SEVEN = '01';

	/**
	 * 全家店到店
	 *
	 * @var string
	 */
	const FAMI = '03';

	/**
	 * 萊爾富店到店
	 *
	 * @var string
	 */
	const HILIFE = '05';

	/*
	|--------------------------------------------------------------------------
	| 超商取貨 - B2C (大宗/冷凍)
	|--------------------------------------------------------------------------
	*/

	/**
	 * 7-11 大宗 (B2C)
	 *
	 * @var string
	 */
	const SEVENBULK = '02';

	/**
	 * 全家大宗 (B2C)
	 *
	 * @var string
	 */
	const FAMIBULK = '04';

	/**
	 * 7-11 冷凍 (B2C)
	 *
	 * @var string
	 */
	const SEVENFROZEN = '22';

	/**
	 * 全家冷凍 (B2C)
	 *
	 * @var string
	 */
	const FAMIFROZEN = '24';

	/**
	 * 7-11 冷凍 (C2C)
	 *
	 * @var string
	 */
	const SEVENFROZEN_C2C = '21';

	/**
	 * 全家冷凍 (C2C)
	 *
	 * @var string
	 */
	const FAMIFROZEN_C2C = '23';

	/*
	|--------------------------------------------------------------------------
	| 宅配
	|--------------------------------------------------------------------------
	*/

	/**
	 * 黑貓宅配 (PayNow 費率)
	 *
	 * @var string
	 */
	const TCAT = '36';

	/**
	 * 黑貓宅配 (自有代號)
	 *
	 * @var string
	 */
	const TCAT_OWN = '06';

	/*
	|--------------------------------------------------------------------------
	| 服務名稱對照
	|--------------------------------------------------------------------------
	*/

	/**
	 * 取得物流服務名稱
	 *
	 * @param string $service_id 物流服務代碼。
	 * @return string 物流服務名稱。
	 */
	public static function get_service_name( $service_id ) {
		$names = array(
			self::SEVEN          => '7-11 交貨便',
			self::FAMI           => '全家店到店',
			self::HILIFE         => '萊爾富店到店',
			self::SEVENBULK      => '7-11 大宗',
			self::FAMIBULK       => '全家大宗',
			self::SEVENFROZEN    => '7-11 冷凍 (B2C)',
			self::FAMIFROZEN     => '全家冷凍 (B2C)',
			self::SEVENFROZEN_C2C => '7-11 冷凍 (C2C)',
			self::FAMIFROZEN_C2C  => '全家冷凍 (C2C)',
			self::TCAT           => '黑貓宅配',
		);

		return isset( $names[ $service_id ] ) ? $names[ $service_id ] : $service_id;
	}

	/**
	 * 取得所有超商服務代碼
	 *
	 * @return array 超商服務代碼陣列。
	 */
	public static function get_cvs_services() {
		return array(
			self::SEVEN,
			self::FAMI,
			self::HILIFE,
			self::SEVENBULK,
			self::FAMIBULK,
			self::SEVENFROZEN,
			self::FAMIFROZEN,
			self::SEVENFROZEN_C2C,
			self::FAMIFROZEN_C2C,
		);
	}

	/**
	 * 取得所有宅配服務代碼
	 *
	 * @return array 宅配服務代碼陣列。
	 */
	public static function get_hd_services() {
		return array(
			self::TCAT,
		);
	}
}
