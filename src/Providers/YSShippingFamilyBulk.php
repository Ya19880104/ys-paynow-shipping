<?php
/**
 * YS Shipping Family Bulk B2C
 *
 * 全家 大宗寄倉 (B2C)。
 *
 * @package YangSheep\PayNow\Shipping\Providers
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Providers;

use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

class YSShippingFamilyBulk extends YSAbstractShipping {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_family_bulk';
		$this->method_title       = __( 'PayNow 全家大宗寄倉 (B2C)', 'ys-paynow-shipping' );
		$this->method_description = __( '使用 PayNow 物流服務，提供 全家大宗寄倉 (B2C)。', 'ys-paynow-shipping' );
		$this->logistic_service   = YSLogisticService::FAMIBULK;

		parent::__construct( $instance_id );
	}

	public function is_available( $package ) {
		if ( 'no' === get_option( 'ys_paynow_shipping_enable_family_bulk', 'no' ) ) {
			return false;
		}
		return parent::is_available( $package );
	}
}
