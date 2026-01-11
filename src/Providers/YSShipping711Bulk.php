<?php
/**
 * YS Shipping 7-11 Bulk B2C
 *
 * 7-11 大宗寄倉 (B2C)。
 *
 * @package YangSheep\PayNow\Shipping\Providers
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Providers;

use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

class YSShipping711Bulk extends YSAbstractShipping {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_711_bulk';
		$this->method_title       = __( 'PayNow 7-11 大宗寄倉 (B2C)', 'ys-paynow-shipping' );
		$this->method_description = __( '使用 PayNow 物流服務，提供 7-11 大宗寄倉 (B2C)。', 'ys-paynow-shipping' );
		$this->logistic_service   = YSLogisticService::SEVENBULK;

		parent::__construct( $instance_id );
	}

	public function is_available( $package ) {
		if ( 'no' === get_option( 'ys_paynow_shipping_enable_711_bulk', 'no' ) ) {
			return false;
		}
		return parent::is_available( $package );
	}
}
