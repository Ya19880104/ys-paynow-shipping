<?php
/**
 * YS Order Meta 常數定義
 *
 * 定義所有訂單 meta 的鍵值常數，統一管理。
 *
 * @package YangSheep\PayNow\Shipping\Utils
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * YSOrderMeta 類別
 *
 * 包含所有訂單 meta 鍵值的常數。使用常數可確保一致性並避免打字錯誤。
 *
 * 使用範例：
 * ```php
 * $order->update_meta_data( YSOrderMeta::StoreId, '123456' );
 * $store_id = $order->get_meta( YSOrderMeta::StoreId );
 * ```
 *
 * @since 1.0.0
 */
class YSOrderMeta {

	/*
	|--------------------------------------------------------------------------
	| 超商資訊
	|--------------------------------------------------------------------------
	*/

	/**
	 * 超商店號
	 *
	 * @var string
	 */
	const StoreId = '_ys_paynow_store_id';

	/**
	 * 超商資料 JSON 格式 (統一格式 v2.0)
	 *
	 * @var string
	 */
	const STORE_DATA_JSON = '_ys_paynow_store_data_json';

	/**
	 * 超商名稱
	 *
	 * @var string
	 */
	const StoreName = '_ys_paynow_store_name';

	/**
	 * 超商地址
	 *
	 * @var string
	 */
	const StoreAddr = '_ys_paynow_store_addr';

	/**
	 * 超商電話
	 *
	 * @var string
	 */
	const StoreTel = '_ys_paynow_store_tel';

	/*
	|--------------------------------------------------------------------------
	| 物流服務資訊
	|--------------------------------------------------------------------------
	*/

	/**
	 * 物流服務名稱
	 *
	 * @var string
	 */
	const LogisticService = '_ys_paynow_logistic_service';

	/**
	 * 物流服務 ID (代碼)
	 *
	 * @var string
	 */
	const LogisticServiceId = '_ys_paynow_logistic_service_id';

	/**
	 * PayNow 物流單號
	 *
	 * @var string
	 */
	const LogisticNumber = '_ys_paynow_logistic_number';

	/**
	 * 物流商託運單號 (貨運編號)
	 *
	 * @var string
	 */
	const PaymentNo = '_ys_paynow_payment_no';

	/**
	 * 物流商驗證碼
	 *
	 * @var string
	 */
	const ValidationNo = '_ys_paynow_validation_no';

	/*
	|--------------------------------------------------------------------------
	| 物流狀態
	|--------------------------------------------------------------------------
	*/

	/**
	 * 物流單序號 (SNO)
	 *
	 * @var string
	 */
	const SNO = '_ys_paynow_sno';

	/**
	 * 訂單狀態 (0:成立中, 1:無效)
	 *
	 * @var string
	 */
	const Status = '_ys_paynow_status';

	/**
	 * 物流狀態描述
	 *
	 * @var string
	 */
	const DeliveryStatus = '_ys_paynow_delivery_status';

	/**
	 * PayNow 物流代碼
	 *
	 * @var string
	 */
	const LogisticCode = '_ys_paynow_logistic_code';

	/**
	 * 物流代碼詳細描述
	 *
	 * @var string
	 */
	const DetailStatusDesc = '_ys_paynow_detail_status_desc';

	/**
	 * 狀態更新時間
	 *
	 * @var string
	 */
	const StatusUpdateAt = '_ys_paynow_status_update_at';

	/**
	 * API 回傳訊息
	 *
	 * @var string
	 */
	const ReturnMsg = '_ys_paynow_return_msg';

	/*
	|--------------------------------------------------------------------------
	| 重新取號
	|--------------------------------------------------------------------------
	*/

	/**
	 * 重新取號後的 PayNow 訂單編號
	 *
	 * @var string
	 */
	const RenewOrderNo = '_ys_paynow_renew_order_no';

	/**
	 * 重試取號次數（用於生成 -1, -2 後綴）
	 *
	 * @var string
	 */
	const RetryCount = '_ys_paynow_retry_count';

	/*
	|--------------------------------------------------------------------------
	| 全家冷凍專用
	|--------------------------------------------------------------------------
	*/

	/**
	 * 保留編號 (全家冷凍 C2C 必填)
	 *
	 * @var string
	 */
	const ReservedNo = '_ys_paynow_reserved_no';

	/**
	 * 預計運送日期 (全家冷凍)
	 *
	 * @var string
	 */
	const ShipDate = '_ys_paynow_ship_date';

	/*
	|--------------------------------------------------------------------------
	| 黑貓宅配專用
	|--------------------------------------------------------------------------
	*/

	/**
	 * 配送類型 (01:常溫, 02:冷藏, 03:冷凍)
	 *
	 * @var string
	 */
	const DeliveryType = '_ys_paynow_delivery_type';

	/**
	 * 配送時段
	 *
	 * @var string
	 */
	const DeliveryTime = '_ys_paynow_delivery_time';

	/*
	|--------------------------------------------------------------------------
	| 貨態回傳資訊
	|--------------------------------------------------------------------------
	*/

	/**
	 * 原始訂單編號 (重新取號前)
	 *
	 * @var string
	 */
	const OriginalOrderNo = '_ys_paynow_original_order_no';

	/**
	 * 到店日期
	 *
	 * @var string
	 */
	const StoreDate = '_ys_paynow_store_date';

	/**
	 * 到店時間
	 *
	 * @var string
	 */
	const StoreTime = '_ys_paynow_store_time';
}
