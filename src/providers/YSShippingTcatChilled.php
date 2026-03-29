<?php
/**
 * YS Black Cat Chilled Shipping (冷藏)
 *
 * @package yangsheep\paynow\shipping\providers
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\providers;

defined( 'ABSPATH' ) || exit;

/**
 * YSShippingTcatChilled Class
 */
class YSShippingTcatChilled extends YSAbstractShipping {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_tcat_chilled';
		$this->method_description = __( 'PayNow 黑貓宅配 (冷藏)', 'ys-paynow-shipping' );
		
		// 預設標題與描述
		$this->title       = __( '黑貓宅配 (冷藏)', 'ys-paynow-shipping' );
		$this->method_title = __( 'PayNow 黑貓宅配 (冷藏)', 'ys-paynow-shipping' );

		parent::__construct( $instance_id );
	}
}
