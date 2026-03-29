<?php
/**
 * YS Shipping Hilife
 *
 * 萊爾富超商取貨運送方式。
 *
 * @package yangsheep\paynow\shipping\providers
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\providers;

use yangsheep\paynow\shipping\utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingHilife 類別
 *
 * 萊爾富店到店超商取貨運送方式。
 *
 * @since 1.0.0
 */
class YSShippingHilife extends YSAbstractShipping {

	/**
	 * 建構函式
	 *
	 * @param int $instance_id 運送方式實例 ID。
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_hilife';
		$this->method_title       = __( 'PayNow 萊爾富超商取貨', 'ys-paynow-shipping' );
		$this->method_description = __( '使用 PayNow 物流服務，提供萊爾富店到店超商取貨。', 'ys-paynow-shipping' );
		$this->logistic_service   = YSLogisticService::HILIFE;

		parent::__construct( $instance_id );
	}
}
