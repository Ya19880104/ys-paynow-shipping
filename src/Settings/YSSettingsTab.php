<?php
/**
 * YS Settings Tab
 *
 * WooCommerce 設定頁面。
 *
 * @package YangSheep\PayNow\Shipping\Settings
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * YSSettingsTab 類別
 *
 * 在 WooCommerce 設定中新增 YS PayNow 物流設定分頁。
 *
 * @since 1.0.0
 */
class YSSettingsTab extends \WC_Settings_Page {

	/**
	 * 預設顏色 - 莫蘭迪淡藍色系
	 */
	private static $default_colors = array(
		'ys_paynow_cvs_store_bg'     => '#e8eff5',
		'ys_paynow_cvs_store_border' => '#c5d1d8',
	);

	/**
	 * 建構函式
	 */
	public function __construct() {
		$this->id    = 'ys_paynow_shipping';
		$this->label = __( 'PayNow 物流', 'ys-paynow-shipping' );

		// 註冊自定義欄位類型
		add_action( 'woocommerce_admin_field_ys_callback_url_guide', array( $this, 'output_callback_url_guide' ) );

		parent::__construct();
	}

	/**
	 * 覆寫父類方法，不輸出 WooCommerce 預設的子導航
	 * 我們使用自訂的前端頁籤切換
	 */
	public function output_sections() {
		// 不輸出任何內容，完全移除 WooCommerce 預設的 subsubsub 子導航
	}

	/**
	 * 輸出設定頁面
	 * 覆寫父類方法，實現前端頁籤切換（不需重新載入）
	 */
	public function output() {
		global $current_section;

		$sections = $this->get_sections();

		// 外層容器，用於 CSS 樣式控制
		echo '<div class="ys-paynow-settings">';

		// 輸出頁籤導航（不使用 nav-tab-wrapper 避免 WP 預設樣式干擾）
		if ( ! empty( $sections ) && count( $sections ) > 1 ) {
			echo '<nav class="ys-paynow-tabs">';
			$first = true;
			foreach ( $sections as $id => $label ) {
				$active_class = $first ? ' nav-tab-active' : '';
				$section_key  = empty( $id ) ? 'general' : $id;
				echo '<a href="#" class="nav-tab ys-tab-link' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $section_key ) . '">' . esc_html( $label ) . '</a>';
				$first = false;
			}
			echo '</nav>';
		}

		// 輸出所有區段內容（用 div 包裹，使用 visibility 而非 display 以確保表單能提交）
		foreach ( $sections as $id => $label ) {
			$section_key = empty( $id ) ? 'general' : $id;
			$hidden_class = ( $section_key === 'general' ) ? '' : ' ys-tab-hidden';

			echo '<div class="ys-tab-content' . esc_attr( $hidden_class ) . '" id="ys-tab-' . esc_attr( $section_key ) . '">';

			// 取得該區段的設定
			$settings = $this->get_settings( $id );

			// 使用自訂方法輸出設定欄位（帶有 ys-section-header 包裝）
			$this->output_settings_fields( $settings );

			echo '</div>';
		}

		echo '</div>'; // .ys-paynow-settings

		// 輸出 JavaScript
		$this->output_tabs_script();
	}

	/**
	 * 自訂輸出設定欄位
	 * 將 title 類型的欄位包裝在 ys-section-header 內，並將描述放入同一個容器
	 *
	 * @param array $settings 設定欄位陣列。
	 */
	private function output_settings_fields( $settings ) {
		foreach ( $settings as $value ) {
			$type = isset( $value['type'] ) ? $value['type'] : '';

			// 處理 title 類型 - 輸出自訂的 section header
			if ( 'title' === $type ) {
				$title = isset( $value['title'] ) ? $value['title'] : '';
				$desc  = isset( $value['desc'] ) ? $value['desc'] : '';
				$id    = isset( $value['id'] ) ? $value['id'] : '';
				?>
				<div class="ys-section-header" <?php echo $id ? 'id="' . esc_attr( $id ) . '-header"' : ''; ?>>
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( ! empty( $desc ) ) : ?>
						<span class="ys-section-desc"><?php echo wp_kses_post( $desc ); ?></span>
					<?php endif; ?>
				</div>
				<table class="form-table" role="presentation">
				<?php
			} elseif ( 'sectionend' === $type ) {
				// 結束表格
				?>
				</table>
				<?php
			} else {
				// 其他欄位類型使用 WooCommerce 預設輸出
				\WC_Admin_Settings::output_fields( array( $value ) );
			}
		}
	}

	/**
	 * 輸出頁籤切換 JavaScript 與完整樣式
	 */
	private function output_tabs_script() {
		?>
		<style>
		/* ===== YS PayNow Settings Styles - 與結帳強化風格一致 ===== */

		/* 隱藏非活動頁籤內容 */
		.ys-tab-content.ys-tab-hidden {
			display: none;
		}

		/* 頁籤導航樣式 */
		.ys-paynow-tabs {
			border-bottom: 2px solid #c5d1d8;
			margin: 20px 0 0 0 !important;
			padding: 0;
			display: flex;
			flex-wrap: wrap;
			gap: 2px;
			background: transparent;
		}

		.ys-paynow-tabs .nav-tab {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 12px 20px;
			border: none;
			background: #f0f5f8;
			border-radius: 8px 8px 0 0;
			color: #6b8a9a;
			font-weight: 500;
			text-decoration: none;
			transition: all 0.2s;
			margin: 0;
			cursor: pointer;
		}

		.ys-paynow-tabs .nav-tab:hover {
			background: #e5eef3;
			color: #4a6a7a;
		}

		.ys-paynow-tabs .nav-tab-active {
			background: #fff;
			color: #8fa8b8;
			box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
		}

		/* 頁籤內容區 */
		.ys-tab-content {
			background: #fff;
			padding: 25px;
			border-radius: 0 0 12px 12px;
			box-shadow: 0 2px 10px rgba(0,0,0,0.05);
		}

		/* 區段標題容器 - 全寬，標題與描述同行 */
		.ys-tab-content .ys-section-header {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
			margin: 30px 0 0 0;
			padding: 15px 20px;
			background: #f8fafb;
			border: 1px solid #c5d1d8;
			border-bottom: none;
			border-radius: 10px 10px 0 0;
		}

		.ys-tab-content .ys-section-header:first-child {
			margin-top: 0;
		}

		/* 區段標題 h2 */
		.ys-tab-content .ys-section-header h2 {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			margin: 0;
			padding: 0;
			background: transparent;
			border: none;
			color: #5a7080;
			font-size: 15px;
			font-weight: 600;
		}

		.ys-tab-content .ys-section-header h2::before {
			font-family: dashicons;
			font-size: 18px;
			color: #8fa8b8;
			content: "\f165";
		}

		/* 區段描述文字 - 與標題同行 */
		.ys-tab-content .ys-section-header .ys-section-desc {
			color: #888;
			font-size: 13px;
			font-weight: normal;
		}

		/* form-table 樣式 */
		.ys-tab-content .form-table {
			background: #fff;
			border: 1px solid #c5d1d8;
			border-top: none;
			border-radius: 0 0 10px 10px;
			margin: 0 0 30px 0;
		}

		.ys-tab-content .form-table th {
			padding: 15px 20px;
			color: #5a7080;
			font-weight: 500;
			vertical-align: top;
		}

		.ys-tab-content .form-table td {
			padding: 15px 20px;
		}

		.ys-tab-content .form-table tr {
			border-bottom: 1px solid #f0f0f0;
		}

		.ys-tab-content .form-table tr:last-child {
			border-bottom: none;
		}

		/* 表單輸入欄位 */
		.ys-tab-content .form-table input[type="text"],
		.ys-tab-content .form-table input[type="email"],
		.ys-tab-content .form-table input[type="password"],
		.ys-tab-content .form-table input[type="number"],
		.ys-tab-content .form-table select {
			border: 1px solid #c5d1d8;
			border-radius: 6px;
			padding: 8px 12px;
			transition: border-color 0.2s, box-shadow 0.2s;
		}

		.ys-tab-content .form-table input:focus,
		.ys-tab-content .form-table select:focus {
			border-color: #8fa8b8;
			box-shadow: 0 0 0 2px rgba(143, 168, 184, 0.2);
			outline: none;
		}

		/* Checkbox 樣式 */
		.ys-tab-content .form-table input[type="checkbox"] {
			width: 18px;
			height: 18px;
			border: 2px solid #c5d1d8;
			border-radius: 4px;
			cursor: pointer;
			transition: all 0.2s;
		}

		.ys-tab-content .form-table input[type="checkbox"]:checked {
			background: #8fa8b8;
			border-color: #8fa8b8;
		}

		/* 顏色選擇器 */
		.ys-tab-content .wp-picker-container {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.ys-tab-content .wp-picker-container .wp-color-result {
			border-radius: 6px;
			border: 1px solid #c5d1d8;
		}

		/* Description 文字 */
		.ys-tab-content .form-table .description {
			color: #666;
			font-size: 12px;
			margin-top: 5px;
		}

		/* 送出按鈕 (WooCommerce 全域設定) */
		.woocommerce-save-button {
			background: #8fa8b8 !important;
			border-color: #7a95a6 !important;
			padding: 10px 30px !important;
			height: auto !important;
			font-size: 14px !important;
			border-radius: 8px !important;
			box-shadow: 0 2px 8px rgba(143, 168, 184, 0.3) !important;
			transition: all 0.2s !important;
		}

		.woocommerce-save-button:hover {
			background: #7a95a6 !important;
			border-color: #6a8596 !important;
			box-shadow: 0 4px 12px rgba(143, 168, 184, 0.4) !important;
		}

		/* Tooltip 圖示 */
		.ys-tab-content .woocommerce-help-tip {
			color: #8fa8b8;
		}

		/* 回傳網址引導區塊 */
		.ys-callback-url-guide {
			background: #f8fafb !important;
			border: 1px solid #c5d1d8 !important;
			border-radius: 8px !important;
			padding: 20px !important;
			max-width: 600px;
		}
		</style>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// 頁籤切換
			$('.ys-paynow-tabs .ys-tab-link').on('click', function(e) {
				e.preventDefault();
				var tab = $(this).data('tab');

				// 更新頁籤狀態
				$('.ys-paynow-tabs .ys-tab-link').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');

				// 更新內容顯示
				$('.ys-tab-content').addClass('ys-tab-hidden');
				$('#ys-tab-' + tab).removeClass('ys-tab-hidden');
			});
		});
		</script>
		<?php
	}

	/**
	 * 取得預設顏色
	 */
	public static function get_default_colors() {
		return self::$default_colors;
	}

	/**
	 * 儲存設定
	 * 覆寫父類方法，儲存所有區段的設定（因為我們一次輸出所有區段）
	 */
	public function save() {
		$sections = $this->get_sections();

		foreach ( $sections as $section_id => $section_label ) {
			$settings = $this->get_settings( $section_id );
			\WC_Admin_Settings::save_fields( $settings );
		}
	}

	/**
	 * 取得設定區段
	 *
	 * @return array 區段陣列。
	 */
	public function get_sections() {
		$sections = array(
			''       => __( '一般設定', 'ys-paynow-shipping' ),
			'sender' => __( '寄件人資訊', 'ys-paynow-shipping' ),
			'cvs'    => __( '超商取貨設定', 'ys-paynow-shipping' ),
			'tcat'   => __( '黑貓宅配設定', 'ys-paynow-shipping' ),
		);

		return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
	}

	/**
	 * 取得設定欄位
	 *
	 * @param string $current_section 目前區段。
	 * @return array 設定欄位陣列。
	 */
	public function get_settings( $current_section = '' ) {
		if ( 'sender' === $current_section ) {
			$settings = $this->get_sender_settings();
		} elseif ( 'cvs' === $current_section ) {
			$settings = $this->get_cvs_settings();
		} elseif ( 'tcat' === $current_section ) {
			$settings = $this->get_tcat_settings();
		} else {
			$settings = $this->get_general_settings();
		}

		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
	}

	/**
	 * 一般設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_general_settings() {
		return array(
			array(
				'title' => __( 'PayNow 物流設定', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定 PayNow 物流 API 連線資訊。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_general_options',
			),
			// 回傳網址設定引導
			array(
				'type' => 'ys_callback_url_guide',
				'id'   => 'ys_paynow_callback_url_guide',
			),
			array(
				'title'   => __( '測試模式', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用測試模式（使用測試環境 API）', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_testmode',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( '商家帳號', 'ys-paynow-shipping' ),
				'desc'     => __( 'PayNow 商家帳號 (user_account)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_user_account',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'API 密碼', 'ys-paynow-shipping' ),
				'desc'     => __( 'PayNow API 密碼 (apicode)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_apicode',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '訂單編號前綴', 'ys-paynow-shipping' ),
				'desc'     => __( '避免訂單編號重複，可設定前綴（選填）', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_order_prefix',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'   => __( '除錯日誌', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用除錯日誌記錄', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_debug',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( '姓名順序', 'ys-paynow-shipping' ),
				'desc'     => __( '收件人姓名的組合順序（姓氏與名字）', 'ys-paynow-shipping' ),
				'desc_tip' => __( '當結帳頁有姓氏(Last Name)與名字(First Name)兩個欄位時，決定組合成收件人姓名的順序。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_name_order',
				'type'     => 'select',
				'options'  => array(
					'last_first'  => __( '姓氏在前（王小明）', 'ys-paynow-shipping' ),
					'first_last'  => __( '名字在前（小明王）', 'ys-paynow-shipping' ),
				),
				'default'  => 'last_first',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_general_options',
			),
			array(
				'title' => __( '物流狀態設定', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定物流狀態與訂單狀態的對應關係。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_status_options',
			),
			array(
				'title'   => __( '自動配置訂單狀態', 'ys-paynow-shipping' ),
				'desc'    => __( '是 (啟用自動配置。注意：勾選此項時，下方的狀態對應設定將不生效)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_auto_status',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( '已安排出貨 對應狀態', 'ys-paynow-shipping' ),
				'desc'     => __( '當 "自動配置" 關閉時，對應的訂單狀態', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_status_ordered',
				'type'     => 'select',
				'options'  => wc_get_order_statuses(),
				'default'  => 'wc-processing',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '運送中 對應狀態', 'ys-paynow-shipping' ),
				'desc'     => __( '當 "自動配置" 關閉時，對應的訂單狀態', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_status_transit',
				'type'     => 'select',
				'options'  => wc_get_order_statuses(),
				'default'  => 'wc-processing',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '已到達取貨商店 對應狀態', 'ys-paynow-shipping' ),
				'desc'     => __( '當 "自動配置" 關閉時，對應的訂單狀態', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_status_arrived',
				'type'     => 'select',
				'options'  => wc_get_order_statuses(),
				'default'  => 'wc-processing',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '逾時退回/異常 對應狀態', 'ys-paynow-shipping' ),
				'desc'     => __( '當 "自動配置" 關閉時，對應的訂單狀態', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_status_returned',
				'type'     => 'select',
				'options'  => wc_get_order_statuses(),
				'default'  => 'wc-failed',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_status_options',
			),
		);
	}

	/**
	 * 寄件人設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_sender_settings() {
		return array(
			array(
				'title' => __( '寄件人資訊', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定物流單上的寄件人資訊。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_sender_options',
			),
			array(
				'title'    => __( '寄件人姓名', 'ys-paynow-shipping' ),
				'desc'     => __( '寄件人姓名或公司名稱', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_sender_name',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '寄件人電話', 'ys-paynow-shipping' ),
				'desc'     => __( '聯絡電話', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_sender_phone',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '寄件人 Email', 'ys-paynow-shipping' ),
				'desc'     => __( '電子郵件地址', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_sender_email',
				'type'     => 'email',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '寄件人地址', 'ys-paynow-shipping' ),
				'desc'     => __( '寄件地址（用於宅配）', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_sender_address',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_sender_options',
			),
		);
	}

	/**
	 * 超商取貨設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_cvs_settings() {
		return array(
			// ========== 超商取貨結帳行為設定 ==========
			array(
				'title' => __( '超商取貨結帳行為', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定超商取貨時結帳頁面的欄位顯示行為。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_cvs_checkout_options',
			),
			array(
				'title'    => __( '隱藏帳單地址', 'ys-paynow-shipping' ),
				'desc'     => __( '超商取貨時自動隱藏帳單地址欄位（地址、城市、郵遞區號等）', 'ys-paynow-shipping' ),
				'desc_tip' => __( '啟用後，當用戶選擇超商取貨時，帳單區域的地址相關欄位會自動隱藏，僅保留姓名、電話、Email。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_cvs_hide_billing_address',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( '隱藏運送地址', 'ys-paynow-shipping' ),
				'desc'     => __( '超商取貨時自動隱藏運送地址欄位（若有啟用運送區域）', 'ys-paynow-shipping' ),
				'desc_tip' => __( '啟用後，當用戶選擇超商取貨時，運送區域的地址相關欄位會自動隱藏，僅保留收件人姓名、電話。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_cvs_hide_shipping_address',
				'type'     => 'checkbox',
				'default'  => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_cvs_checkout_options',
			),
			// ========== 超商服務啟用設定 ==========
			array(
				'title' => __( '超商取貨服務', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '啟用或停用各類超商取貨服務。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_cvs_options',
			),
			array(
				'title'   => __( '7-11 C2C 冷凍', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 7-11 交貨便 (冷凍)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_711_frozen_c2c',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( '7-11 B2C 大宗', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 7-11 大宗寄倉 (常溫)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_711_bulk',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( '7-11 B2C 大宗冷凍', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 7-11 大宗寄倉 (冷凍)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_711_bulk_frozen',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( '全家 C2C 冷凍', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 全家店到店 (冷凍)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_family_frozen_c2c',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( '全家 B2C 大宗', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 全家大宗寄倉 (常溫)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_family_bulk',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( '全家 B2C 大宗冷凍', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用 全家大宗寄倉 (冷凍)', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_enable_family_bulk_frozen',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_cvs_options',
			),
			// 超商視覺設定區塊
			array(
				'title' => __( '超商取貨區域', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '自訂結帳頁超商門市選擇器的顏色配置。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_cvs_style_options',
			),
			array(
				'title'   => __( '門市資訊背景', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_cvs_store_bg',
				'type'    => 'color',
				'default' => self::$default_colors['ys_paynow_cvs_store_bg'],
				'css'     => 'width: 6em;',
			),
			array(
				'title'   => __( '門市資訊邊框', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_cvs_store_border',
				'type'    => 'color',
				'default' => self::$default_colors['ys_paynow_cvs_store_border'],
				'css'     => 'width: 6em;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_cvs_style_options',
			),
		);
	}

	/**
	 * 黑貓宅配設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_tcat_settings() {
		return array(
			array(
				'title' => __( '黑貓宅配設定', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定黑貓宅急便相關選項。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_tcat_options',
			),

			array(
				'title'    => __( '黑貓宅配串接方式', 'ys-paynow-shipping' ),
				'desc'     => __( '使用自有代號 (Logistic_service = 06)', 'ys-paynow-shipping' ),
				'desc_tip' => __( '當啟用時，API送出 Logistic_service 為 06，需要與PayNow設定黑貓自有代號(有設定費)；當取消時，API送出 Logistic_service 為 36，使用PayNow 黑貓費率。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_own_code',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'   => __( '啟用溫層', 'ys-paynow-shipping' ),
				'desc'    => __( '啟用冷藏宅配', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_tcat_enable_cool',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'desc'    => __( '啟用冷凍宅配', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_tcat_enable_frozen',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( '預設包裹尺寸', 'ys-paynow-shipping' ),
				'desc'     => __( '長 (cm)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_length',
				'type'     => 'number',
				'default'  => '5',
				'css'      => 'width: 80px;',
			),
			array(
				'desc'     => __( '寬 (cm)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_width',
				'type'     => 'number',
				'default'  => '5',
				'css'      => 'width: 80px;',
			),
			array(
				'desc'     => __( '高 (cm)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_height',
				'type'     => 'number',
				'default'  => '4',
				'css'      => 'width: 80px;',
			),
			array(
				'desc'     => __( '重量 (kg)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_weight',
				'type'     => 'number',
				'default'  => '5',
				'css'      => 'width: 80px;',
			),
			array(
				'title'    => __( '指定配達時段', 'ys-paynow-shipping' ),
				'desc'     => __( '預設黑貓配達時段 (Deadline)', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_tcat_deadline',
				'type'     => 'select',
				'options'  => array(
					'1' => __( '13時前', 'ys-paynow-shipping' ),
					'2' => __( '14時~18時', 'ys-paynow-shipping' ),
					'3' => __( '不指定', 'ys-paynow-shipping' ),
				),
				'default'  => '3',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_tcat_options',
			),
		);
	}

	/**
	 * 輸出回傳網址設定引導區塊
	 *
	 * @param array $value 欄位設定。
	 */
	public function output_callback_url_guide( $value ) {
		// 檢查永久連結設定
		$permalink_structure = get_option( 'permalink_structure' );
		$has_permalink       = ! empty( $permalink_structure );

		// 產生回傳網址
		$site_url     = trailingslashit( get_site_url() );
		$callback_url = $site_url . 'wc-api/ys-paynow-response/';

		// 測試模式
		$is_testmode = 'yes' === get_option( 'ys_paynow_shipping_testmode', 'yes' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'PayNow 回傳網址設定', 'ys-paynow-shipping' ); ?></label>
			</th>
			<td class="forminp">
				<div class="ys-callback-url-guide" style="background: #f8fafb; border: 1px solid #c5d1d8; border-radius: 8px; padding: 20px; max-width: 600px;">

					<!-- 永久連結狀態 -->
					<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e0e0e0;">
						<strong style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
							<span class="dashicons dashicons-admin-links" style="color: #8fa8b8;"></span>
							<?php esc_html_e( '永久連結狀態', 'ys-paynow-shipping' ); ?>
						</strong>
						<?php if ( $has_permalink ) : ?>
							<span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 4px; font-size: 13px;">
								<span class="dashicons dashicons-yes-alt" style="font-size: 16px;"></span>
								<?php esc_html_e( '已設定永久連結', 'ys-paynow-shipping' ); ?>
							</span>
						<?php else : ?>
							<span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 4px; font-size: 13px;">
								<span class="dashicons dashicons-warning" style="font-size: 16px;"></span>
								<?php esc_html_e( '尚未設定永久連結', 'ys-paynow-shipping' ); ?>
							</span>
							<p style="margin: 8px 0 0; color: #856404; font-size: 12px;">
								<?php
								printf(
									/* translators: %s: permalink settings URL */
									__( '請先至 <a href="%s" target="_blank">設定 > 永久連結</a> 設定非「預設」的永久連結結構。', 'ys-paynow-shipping' ),
									admin_url( 'options-permalink.php' )
								);
								?>
							</p>
						<?php endif; ?>
					</div>

					<!-- 回傳網址 -->
					<div style="margin-bottom: 15px;">
						<strong style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
							<span class="dashicons dashicons-external" style="color: #8fa8b8;"></span>
							<?php esc_html_e( '物流回傳網址', 'ys-paynow-shipping' ); ?>
						</strong>
						<p style="margin: 0 0 8px; color: #666; font-size: 12px;">
							<?php esc_html_e( '請將此網址複製並填入 PayNow 後台的「物流回傳網址」欄位', 'ys-paynow-shipping' ); ?>
						</p>
						<div style="display: flex; gap: 8px; align-items: center;">
							<input type="text"
								id="ys_paynow_callback_url"
								value="<?php echo esc_attr( $callback_url ); ?>"
								readonly
								style="flex: 1; padding: 8px 12px; border: 1px solid #c5d1d8; border-radius: 4px; background: #fff; font-family: monospace; font-size: 13px;"
							/>
							<button type="button"
								class="button"
								onclick="ysPaynowCopyUrl()"
								style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; white-space: nowrap;"
							>
								<span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px;"></span>
								<?php esc_html_e( '複製', 'ys-paynow-shipping' ); ?>
							</button>
						</div>
						<p id="ys_paynow_copy_feedback" style="margin: 5px 0 0; color: #28a745; font-size: 12px; display: none;">
							<?php esc_html_e( '已複製到剪貼簿！', 'ys-paynow-shipping' ); ?>
						</p>
					</div>

					<!-- 環境提示 -->
					<div style="background: <?php echo $is_testmode ? '#fff3cd' : '#d4edda'; ?>; padding: 10px 12px; border-radius: 4px; font-size: 12px;">
						<strong>
							<?php if ( $is_testmode ) : ?>
								<span class="dashicons dashicons-info" style="color: #856404;"></span>
								<?php esc_html_e( '目前為測試模式', 'ys-paynow-shipping' ); ?>
							<?php else : ?>
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<?php esc_html_e( '目前為正式模式', 'ys-paynow-shipping' ); ?>
							<?php endif; ?>
						</strong>
						<span style="color: #666;">
							<?php if ( $is_testmode ) : ?>
								- <?php esc_html_e( '請在 PayNow 測試環境後台設定此網址', 'ys-paynow-shipping' ); ?>
							<?php else : ?>
								- <?php esc_html_e( '請在 PayNow 正式環境後台設定此網址', 'ys-paynow-shipping' ); ?>
							<?php endif; ?>
						</span>
					</div>

				</div>

				<script type="text/javascript">
				function ysPaynowCopyUrl() {
					var input = document.getElementById('ys_paynow_callback_url');
					var feedback = document.getElementById('ys_paynow_copy_feedback');

					// 使用現代 API
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(input.value).then(function() {
							feedback.style.display = 'block';
							setTimeout(function() { feedback.style.display = 'none'; }, 2000);
						});
					} else {
						// 舊版瀏覽器
						input.select();
						document.execCommand('copy');
						feedback.style.display = 'block';
						setTimeout(function() { feedback.style.display = 'none'; }, 2000);
					}
				}
				</script>
			</td>
		</tr>
		<?php
	}

}
