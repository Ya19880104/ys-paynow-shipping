<?php
/**
 * YS Settings Tab
 *
 * PayNow 物流設定頁面（電商工具箱子選單）。
 *
 * @package yangsheep\paynow\shipping\settings
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\settings;

defined( 'ABSPATH' ) || exit;

/**
 * YSSettingsTab 類別
 *
 * 在「電商工具箱」選單下新增 PayNow 物流設定頁面。
 *
 * @since 1.0.0
 * @since 1.3.0 從 WC_Settings_Page 遷移至獨立設定頁面（電商工具箱選單）。
 */
class YSSettingsTab {

	/**
	 * 設定頁面 ID
	 *
	 * @var string
	 */
	private $id = 'ys_paynow_shipping';

	/**
	 * Option group（Nonce 用）
	 *
	 * @var string
	 */
	private $option_group = 'ys_paynow_shipping';

	/**
	 * 預設顏色 - 莫蘭迪淡藍色系
	 */
	private static $default_colors = array(
		'ys_paynow_cvs_store_bg'     => '#e8eff5',
		'ys_paynow_cvs_store_border' => '#c5d1d8',
	);

	/**
	 * Singleton 實例
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * 取得單例
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構函式
	 */
	private function __construct() {
		// 註冊選單
		add_action( 'admin_menu', array( $this, 'register_toolbox_menu' ), 21 );
		add_filter( 'ys_toolbox_plugins', array( $this, 'register_toolbox_card' ) );

		// 註冊自定義欄位類型（WC_Admin_Settings 輸出時觸發）
		add_action( 'woocommerce_admin_field_ys_callback_url_guide', array( $this, 'output_callback_url_guide' ) );
		add_action( 'woocommerce_admin_field_ys_cron_next_run', array( $this, 'output_cron_next_run' ) );
		add_action( 'woocommerce_admin_field_ys_cron_log_viewer', array( $this, 'output_cron_log_viewer' ) );
	}

	/**
	 * 註冊本外掛的工具箱卡片資訊
	 *
	 * @param array $plugins 已註冊的外掛列表。
	 * @return array
	 */
	public function register_toolbox_card( $plugins ) {
		$plugins[] = array(
			'name'    => 'PayNow 物流',
			'version' => YS_PAYNOW_SHIPPING_VERSION,
			'icon'    => 'dashicons-car',
			'desc'    => '整合 PayNow 物流服務，支援 7-11、全家、萊爾富超商取貨及黑貓宅配。',
			'url'     => admin_url( 'admin.php?page=ys-paynow-shipping' ),
		);
		return $plugins;
	}

	/**
	 * 註冊「電商工具箱」選單
	 *
	 * 依據 ys-plugin-development-standard.md 第 4 節，
	 * 所有 YS 外掛共用 ys-toolbox 頂層選單。
	 */
	public function register_toolbox_menu() {
		global $menu;

		// 檢查「電商工具箱」頂層選單是否已存在
		$toolbox_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && 'ys-toolbox' === $item[2] ) {
					$toolbox_exists = true;
					break;
				}
			}
		}

		// 只建立一次頂層選單（含歡迎頁面）
		if ( ! $toolbox_exists ) {
			$welcome_callback = $this->get_toolbox_welcome_callback();

			add_menu_page(
				__( '電商工具箱', 'ys-paynow-shipping' ),
				__( '電商工具箱', 'ys-paynow-shipping' ),
				'manage_options',
				'ys-toolbox',
				$welcome_callback,
				'dashicons-store',
				56
			);

			// 將第一個子選單顯示為「總覽」而非重複的「電商工具箱」
			add_submenu_page(
				'ys-toolbox',
				__( '電商工具箱', 'ys-paynow-shipping' ),
				__( '總覽', 'ys-paynow-shipping' ),
				'manage_options',
				'ys-toolbox',
				$welcome_callback
			);
		}

		// 註冊 PayNow 物流設定子選單
		add_submenu_page(
			'ys-toolbox',
			__( 'PayNow 物流設定', 'ys-paynow-shipping' ),
			__( 'PayNow 物流', 'ys-paynow-shipping' ),
			'manage_options',
			'ys-paynow-shipping',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * 取得歡迎頁面回調
	 *
	 * 優先使用 Shopline 外掛的靜態方法（避免重複定義），
	 * 否則使用自身的 fallback。
	 *
	 * @return callable
	 */
	private function get_toolbox_welcome_callback() {
		$fallback_classes = array(
			'\YangSheep\ShoplinePayment\Admin\YSAdminSettings',
			'\YangSheep\CheckoutOptimizer\Admin\YSCheckoutSettings',
		);

		foreach ( $fallback_classes as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'render_toolbox_welcome' ) ) {
				return array( $class, 'render_toolbox_welcome' );
			}
		}

		return array( $this, 'render_toolbox_welcome' );
	}

	/**
	 * 渲染電商工具箱歡迎頁面（fallback）
	 *
	 * 當 Shopline / 結帳強化外掛皆未啟用時，由本外掛提供歡迎頁面。
	 * 透過 ys_toolbox_plugins filter 自動偵測所有已註冊的 YS 外掛。
	 */
	public function render_toolbox_welcome() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plugins = apply_filters( 'ys_toolbox_plugins', array() );

		?>
		<div class="wrap">
			<h1 style="display:none;"><?php esc_html_e( '電商工具箱', 'ys-paynow-shipping' ); ?></h1>
		</div>

		<div class="ys-toolbox-welcome">
			<div class="ys-toolbox-header">
				<div class="ys-toolbox-header-content">
					<div class="ys-toolbox-logo">
						<span class="dashicons dashicons-store"></span>
					</div>
					<h2>電商工具箱</h2>
					<p class="ys-toolbox-subtitle">WooCommerce 電商擴充套件，由 YANGSHEEP DESIGN 開發維護</p>
				</div>
			</div>

			<?php if ( ! empty( $plugins ) ) : ?>
			<div class="ys-toolbox-cards">
				<?php
				foreach ( $plugins as $plugin ) :
					$plugin = wp_parse_args( $plugin, array(
						'name'    => __( '未知外掛', 'ys-paynow-shipping' ),
						'version' => '0.0.0',
						'icon'    => 'dashicons-admin-plugins',
						'desc'    => '',
						'url'     => '#',
					) );
				?>
				<a href="<?php echo esc_url( $plugin['url'] ); ?>" class="ys-toolbox-card">
					<div class="ys-toolbox-card-icon">
						<span class="dashicons <?php echo esc_attr( $plugin['icon'] ); ?>"></span>
					</div>
					<div class="ys-toolbox-card-body">
						<h3><?php echo esc_html( $plugin['name'] ); ?></h3>
						<span class="ys-toolbox-card-version">v<?php echo esc_html( $plugin['version'] ); ?></span>
						<p><?php echo esc_html( $plugin['desc'] ); ?></p>
					</div>
					<span class="ys-toolbox-card-arrow dashicons dashicons-arrow-right-alt2"></span>
				</a>
				<?php endforeach; ?>
			</div>
			<?php else : ?>
			<div class="ys-toolbox-empty">
				<span class="dashicons dashicons-info-outline"></span>
				<p>尚未偵測到已啟用的 YS 外掛。</p>
			</div>
			<?php endif; ?>

			<div class="ys-toolbox-footer">
				<div class="ys-toolbox-footer-info">
					<span class="dashicons dashicons-heart"></span>
					<span>由 <strong>YANGSHEEP DESIGN</strong> 用心開發</span>
					<span class="ys-toolbox-sep">|</span>
					<a href="https://yangsheep.com.tw" target="_blank" rel="noopener">yangsheep.com.tw</a>
				</div>
			</div>
		</div>

		<style>
			.ys-toolbox-welcome{max-width:860px;margin:20px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,sans-serif}
			.ys-toolbox-header{background:linear-gradient(135deg,#3a4f63 0%,#2c3e50 100%);border-radius:12px;padding:40px;margin-bottom:24px;color:#fff}
			.ys-toolbox-header-content{text-align:center}
			.ys-toolbox-logo{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:16px;margin-bottom:16px}
			.ys-toolbox-logo .dashicons{font-size:32px;width:32px;height:32px;color:#fff}
			.ys-toolbox-header h2{font-size:24px;font-weight:600;margin:0 0 8px;color:#fff}
			.ys-toolbox-subtitle{font-size:14px;opacity:.8;margin:0}
			.ys-toolbox-cards{display:flex;flex-direction:column;gap:12px;margin-bottom:24px}
			.ys-toolbox-card{display:flex;align-items:center;gap:20px;background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:24px;text-decoration:none;color:inherit;transition:all .2s ease}
			.ys-toolbox-card:hover{border-color:#8fa8b8;box-shadow:0 2px 12px rgba(0,0,0,.08);transform:translateY(-1px)}
			.ys-toolbox-card:focus{outline:2px solid #8fa8b8;outline-offset:2px}
			.ys-toolbox-card-icon{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:52px;height:52px;background:#f0f4f7;border-radius:12px}
			.ys-toolbox-card-icon .dashicons{font-size:24px;width:24px;height:24px;color:#3a4f63}
			.ys-toolbox-card-body{flex:1;min-width:0}
			.ys-toolbox-card-body h3{font-size:15px;font-weight:600;margin:0 0 4px;color:#1d2327;display:inline}
			.ys-toolbox-card-version{display:inline-block;font-size:11px;color:#8fa8b8;background:#f0f4f7;padding:1px 8px;border-radius:10px;margin-left:8px;vertical-align:middle}
			.ys-toolbox-card-body p{font-size:13px;color:#646970;margin:6px 0 0;line-height:1.5}
			.ys-toolbox-card-arrow{flex-shrink:0;color:#c3c4c7;transition:color .2s ease}
			.ys-toolbox-card:hover .ys-toolbox-card-arrow{color:#8fa8b8}
			.ys-toolbox-empty{text-align:center;padding:48px 24px;background:#fff;border:1px solid #e0e0e0;border-radius:10px;margin-bottom:24px}
			.ys-toolbox-empty .dashicons{font-size:40px;width:40px;height:40px;color:#c3c4c7;margin-bottom:12px}
			.ys-toolbox-empty p{color:#646970;font-size:14px;margin:0}
			.ys-toolbox-footer{text-align:center;padding:16px 0}
			.ys-toolbox-footer-info{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#8c8f94}
			.ys-toolbox-footer-info .dashicons{font-size:14px;width:14px;height:14px;color:#cc99c2}
			.ys-toolbox-footer-info a{color:#8fa8b8;text-decoration:none}
			.ys-toolbox-footer-info a:hover{color:#3a4f63}
			.ys-toolbox-sep{color:#ddd;margin:0 4px}
		</style>
		<?php
	}

	/**
	 * 渲染設定頁面
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 處理儲存
		if ( isset( $_POST['submit'] ) ) {
			check_admin_referer( $this->option_group . '-options' );
			$this->save_settings();
			echo '<div class="updated"><p>' . esc_html__( '設定已儲存。', 'ys-paynow-shipping' ) . '</p></div>';
		}

		// 顯示 WC_Admin_Settings 的訊息（如排程更新通知）
		\WC_Admin_Settings::show_messages();

		$sections = $this->get_sections();
		?>
		<div class="wrap ys-settings-wrap">

			<!-- 頁面標頭 -->
			<div class="ys-settings-header">
				<h2><span class="dashicons dashicons-car"></span> <?php esc_html_e( 'PayNow 物流設定', 'ys-paynow-shipping' ); ?></h2>
				<p class="ys-settings-desc"><?php esc_html_e( '設定 PayNow 物流服務的 API 連線、運送方式與排程更新。', 'ys-paynow-shipping' ); ?></p>
			</div>

			<!-- 頁籤導覽 -->
			<?php if ( ! empty( $sections ) && count( $sections ) > 1 ) : ?>
			<nav class="ys-paynow-tabs">
				<?php
				$first = true;
				foreach ( $sections as $id => $label ) {
					$active_class = $first ? ' nav-tab-active' : '';
					$section_key  = empty( $id ) ? 'general' : $id;
					echo '<a href="#" class="nav-tab ys-tab-link' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $section_key ) . '">' . esc_html( $label ) . '</a>';
					$first = false;
				}
				?>
			</nav>
			<?php endif; ?>

			<!-- 設定表單 -->
			<form method="post" action="" class="ys-settings-form">
				<?php wp_nonce_field( $this->option_group . '-options' ); ?>

				<div class="ys-paynow-settings">
					<?php
					foreach ( $sections as $id => $label ) {
						$section_key  = empty( $id ) ? 'general' : $id;
						$hidden_class = ( $section_key === 'general' ) ? '' : ' ys-tab-hidden';

						echo '<div class="ys-tab-content' . esc_attr( $hidden_class ) . '" id="ys-tab-' . esc_attr( $section_key ) . '">';

						$settings = $this->get_settings( $id );
						$this->output_settings_fields( $settings );

						echo '</div>';
					}
					?>
				</div>

				<!-- 儲存按鈕 -->
				<div class="ys-submit-wrap" id="ys-submit-button">
					<?php submit_button( __( '儲存設定', 'ys-paynow-shipping' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<?php
		// 輸出 JavaScript 和 CSS
		$this->output_tabs_script();
	}

	/**
	 * 儲存設定
	 *
	 * 使用 WC_Admin_Settings::save_fields() 儲存所有區段。
	 */
	private function save_settings() {
		// 記錄舊的 CRON 間隔值（用於偵測變更）
		$old_interval = absint( get_option( 'ys_paynow_shipping_cron_interval', 6 ) );

		$sections = $this->get_sections();

		foreach ( $sections as $section_id => $section_label ) {
			$settings = $this->get_settings( $section_id );
			\WC_Admin_Settings::save_fields( $settings );
		}

		// 偵測 CRON 間隔是否變更
		$new_interval = absint( get_option( 'ys_paynow_shipping_cron_interval', 6 ) );
		if ( $new_interval < 1 ) {
			$new_interval = 6;
			update_option( 'ys_paynow_shipping_cron_interval', 6 );
		}

		if ( $old_interval !== $new_interval ) {
			// 移除舊排程
			$timestamp = wp_next_scheduled( 'ys_paynow_status_update' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'ys_paynow_status_update' );
			}
			// 立即重新排程
			wp_schedule_event( time(), 'ys_paynow_custom_interval', 'ys_paynow_status_update' );
			\WC_Admin_Settings::add_message(
				sprintf(
					/* translators: %d: hours */
					__( '排程間隔已更新為每 %d 小時，下次執行時間已重新設定。', 'ys-paynow-shipping' ),
					$new_interval
				)
			);
		}
	}

	/**
	 * 取得預設顏色
	 */
	public static function get_default_colors() {
		return self::$default_colors;
	}

	/*
	|--------------------------------------------------------------------------
	| 設定區段與欄位
	|--------------------------------------------------------------------------
	*/

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
			'cron'   => __( '排程設定', 'ys-paynow-shipping' ),
		);

		return apply_filters( 'ys_paynow_shipping_get_sections', $sections );
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
		} elseif ( 'cron' === $current_section ) {
			$settings = $this->get_cron_settings();
		} else {
			$settings = $this->get_general_settings();
		}

		return apply_filters( 'ys_paynow_shipping_get_settings', $settings, $current_section );
	}

	/*
	|--------------------------------------------------------------------------
	| 設定欄位輸出
	|--------------------------------------------------------------------------
	*/

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

	/*
	|--------------------------------------------------------------------------
	| 各區段設定欄位定義
	|--------------------------------------------------------------------------
	*/

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
				'title'    => __( '顯示訂單物流欄位', 'ys-paynow-shipping' ),
				'desc'     => __( '在後台訂單列表和前台「我的帳號」顯示物流狀態欄位', 'ys-paynow-shipping' ),
				'desc_tip' => __( '啟用後，將在 WooCommerce 訂單列表和客戶的「我的帳號 > 訂單」頁面顯示 PayNow 物流狀態欄位。若已啟用 YangSheep 結帳強化外掛的訂單增強功能，建議關閉此選項避免重複。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_show_order_column',
				'type'     => 'checkbox',
				'default'  => 'yes',
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
				'title'    => __( '寄件人郵遞區號', 'ys-paynow-shipping' ),
				'desc'     => __( '3 碼郵遞區號（用於黑貓宅配驗證）', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_shipping_sender_postcode',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'width: 80px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( '寄件人地址', 'ys-paynow-shipping' ),
				'desc'     => __( '完整寄件地址，例如：臺北市內湖區康寧路三段99巷17弄40號', 'ys-paynow-shipping' ),
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
	 * 檢查 YangSheep Checkout Optimizer 是否啟用
	 *
	 * @return bool 是否啟用。
	 */
	private function is_checkout_optimizer_active() {
		return class_exists( 'YANGSHEEP_Checkout_Fields' );
	}

	/**
	 * 超商取貨設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_cvs_settings() {
		$is_optimizer_active = $this->is_checkout_optimizer_active();

		$settings = array();

		// ========== 超商取貨結帳行為設定 ==========
		if ( $is_optimizer_active ) {
			$settings[] = array(
				'title' => __( '超商取貨結帳行為', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => '<div class="notice notice-info inline" style="margin: 10px 0; padding: 10px 12px;"><p>' .
					__( '已偵測到 <strong>YangSheep 結帳強化外掛</strong>，超商取貨時的欄位隱藏功能已由該外掛統一處理。', 'ys-paynow-shipping' ) .
					'<br>' .
					__( '下方「隱藏帳單地址」與「隱藏運送地址」設定已自動停用，請至結帳強化外掛設定頁面管理。', 'ys-paynow-shipping' ) .
					'</p></div>',
				'id'    => 'ys_paynow_shipping_cvs_checkout_options',
			);
			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_cvs_checkout_options',
			);
		} else {
			$settings[] = array(
				'title' => __( '超商取貨結帳行為', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定超商取貨時結帳頁面的欄位顯示行為。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_cvs_checkout_options',
			);
			$settings[] = array(
				'title'    => __( '隱藏帳單地址', 'ys-paynow-shipping' ),
				'desc'     => __( '超商取貨時自動隱藏帳單地址欄位（地址、城市、郵遞區號等）', 'ys-paynow-shipping' ),
				'desc_tip' => __( '啟用後，當用戶選擇超商取貨時，帳單區域的地址相關欄位會自動隱藏，僅保留姓名、電話、Email。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_cvs_hide_billing_address',
				'type'     => 'checkbox',
				'default'  => 'no',
			);
			$settings[] = array(
				'title'    => __( '隱藏運送地址', 'ys-paynow-shipping' ),
				'desc'     => __( '超商取貨時自動隱藏運送地址欄位（若有啟用運送區域）', 'ys-paynow-shipping' ),
				'desc_tip' => __( '啟用後，當用戶選擇超商取貨時，運送區域的地址相關欄位會自動隱藏，僅保留收件人姓名、電話。', 'ys-paynow-shipping' ),
				'id'       => 'ys_paynow_cvs_hide_shipping_address',
				'type'     => 'checkbox',
				'default'  => 'yes',
			);
			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_cvs_checkout_options',
			);
		}

		// ========== 超商服務啟用設定 ==========
		$settings[] = array(
			'title' => __( '超商取貨服務', 'ys-paynow-shipping' ),
			'type'  => 'title',
			'desc'  => __( '啟用或停用各類超商取貨服務。', 'ys-paynow-shipping' ),
			'id'    => 'ys_paynow_shipping_cvs_options',
		);
		$settings[] = array(
			'title'   => __( '7-11 C2C 冷凍', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 7-11 交貨便 (冷凍)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_711_frozen_c2c',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'   => __( '7-11 B2C 大宗', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 7-11 大宗寄倉 (常溫)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_711_bulk',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'   => __( '7-11 B2C 大宗冷凍', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 7-11 大宗寄倉 (冷凍)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_711_bulk_frozen',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'   => __( '全家 C2C 冷凍', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 全家店到店 (冷凍)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_family_frozen_c2c',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'   => __( '全家 B2C 大宗', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 全家大宗寄倉 (常溫)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_family_bulk',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'   => __( '全家 B2C 大宗冷凍', 'ys-paynow-shipping' ),
			'desc'    => __( '啟用 全家大宗寄倉 (冷凍)', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_shipping_enable_family_bulk_frozen',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'ys_paynow_shipping_cvs_options',
		);

		// ========== 超商視覺設定區塊 ==========
		$settings[] = array(
			'title' => __( '超商取貨區域', 'ys-paynow-shipping' ),
			'type'  => 'title',
			'desc'  => __( '自訂結帳頁超商門市選擇器的顏色配置。', 'ys-paynow-shipping' ),
			'id'    => 'ys_paynow_cvs_style_options',
		);
		$settings[] = array(
			'title'   => __( '門市資訊背景', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_cvs_store_bg',
			'type'    => 'color',
			'default' => self::$default_colors['ys_paynow_cvs_store_bg'],
			'css'     => 'width: 6em;',
		);
		$settings[] = array(
			'title'   => __( '門市資訊邊框', 'ys-paynow-shipping' ),
			'id'      => 'ys_paynow_cvs_store_border',
			'type'    => 'color',
			'default' => self::$default_colors['ys_paynow_cvs_store_border'],
			'css'     => 'width: 6em;',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'ys_paynow_cvs_style_options',
		);

		return $settings;
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
	 * 排程設定
	 *
	 * @return array 設定欄位陣列。
	 */
	private function get_cron_settings() {
		return array(
			array(
				'title' => __( '排程設定', 'ys-paynow-shipping' ),
				'type'  => 'title',
				'desc'  => __( '設定物流狀態自動更新排程。', 'ys-paynow-shipping' ),
				'id'    => 'ys_paynow_shipping_cron_options',
			),
			array(
				'title'             => __( '排程間隔（小時）', 'ys-paynow-shipping' ),
				'desc'              => __( '每隔幾小時自動查詢並更新物流狀態（1-24）', 'ys-paynow-shipping' ),
				'id'                => 'ys_paynow_shipping_cron_interval',
				'type'              => 'number',
				'default'           => '6',
				'css'               => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '24',
					'step' => '1',
				),
				'desc_tip'          => true,
			),
			// 自訂欄位：下次執行時間
			array(
				'type' => 'ys_cron_next_run',
				'id'   => 'ys_cron_next_run',
			),
			array(
				'title'   => __( '啟用 CRON LOG', 'ys-paynow-shipping' ),
				'desc'    => __( '記錄排程執行的詳細日誌（獨立於一般除錯 LOG）', 'ys-paynow-shipping' ),
				'id'      => 'ys_paynow_shipping_cron_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			// 自訂欄位：LOG 檢視器
			array(
				'type' => 'ys_cron_log_viewer',
				'id'   => 'ys_cron_log_viewer',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ys_paynow_shipping_cron_options',
			),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| 自訂欄位輸出
	|--------------------------------------------------------------------------
	*/

	/**
	 * 輸出「下次排程執行時間」自訂欄位
	 *
	 * @param array $value 欄位設定。
	 */
	public function output_cron_next_run( $value ) {
		$next_timestamp = wp_next_scheduled( 'ys_paynow_status_update' );

		if ( $next_timestamp ) {
			$wp_tz = wp_timezone();
			$dt    = new \DateTime( '@' . $next_timestamp );
			$dt->setTimezone( $wp_tz );
			$next_time_str = $dt->format( 'Y-m-d H:i:s' );

			// 計算倒數
			$diff    = $next_timestamp - time();
			$hours   = floor( $diff / 3600 );
			$minutes = floor( ( $diff % 3600 ) / 60 );

			if ( $diff > 0 ) {
				$countdown = sprintf( __( '%d 小時 %d 分鐘後', 'ys-paynow-shipping' ), $hours, $minutes );
			} else {
				$countdown = __( '即將執行', 'ys-paynow-shipping' );
			}
		} else {
			$next_time_str = __( '尚未排程', 'ys-paynow-shipping' );
			$countdown     = '';
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( '下次執行時間', 'ys-paynow-shipping' ); ?></label>
			</th>
			<td class="forminp">
				<span style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: #f0f5f8; border: 1px solid #c5d1d8; border-radius: 6px; font-family: monospace;">
					<span class="dashicons dashicons-clock" style="color: #8fa8b8;"></span>
					<?php echo esc_html( $next_time_str ); ?>
					<?php if ( ! empty( $countdown ) ) : ?>
						<span style="color: #666; font-size: 12px;">（<?php echo esc_html( $countdown ); ?>）</span>
					<?php endif; ?>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * 輸出「CRON LOG 檢視器」自訂欄位
	 *
	 * @param array $value 欄位設定。
	 */
	public function output_cron_log_viewer( $value ) {
		$is_enabled = 'yes' === get_option( 'ys_paynow_shipping_cron_log_enabled', 'no' );
		$display    = $is_enabled ? '' : ' style="display: none;"';
		?>
		<tr valign="top" id="ys-cron-log-viewer-row"<?php echo $display; ?>>
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'CRON LOG', 'ys-paynow-shipping' ); ?></label>
			</th>
			<td class="forminp">
				<div style="margin-bottom: 8px; display: flex; gap: 8px;">
					<button type="button" class="button" id="ys-cron-log-reload">
						<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px;"></span>
						<?php esc_html_e( '重新載入', 'ys-paynow-shipping' ); ?>
					</button>
					<button type="button" class="button" id="ys-cron-log-clear" style="color: #a00;">
						<span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px;"></span>
						<?php esc_html_e( '清除日誌', 'ys-paynow-shipping' ); ?>
					</button>
				</div>
				<pre id="ys-cron-log-content" style="
					background: #1e1e2e;
					color: #cdd6f4;
					padding: 15px;
					border-radius: 8px;
					max-height: 400px;
					overflow-y: auto;
					font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
					font-size: 12px;
					line-height: 1.6;
					white-space: pre-wrap;
					word-wrap: break-word;
					border: 1px solid #313244;
				"><?php esc_html_e( '載入中...', 'ys-paynow-shipping' ); ?></pre>
				<p class="description"><?php esc_html_e( 'LOG 檔案每 7 天自動清除。儲存位置：WooCommerce > 狀態 > 日誌 > ys-paynow-cron-log', 'ys-paynow-shipping' ); ?></p>
			</td>
		</tr>
		<?php
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

		// 產生回傳網址（含 Webhook Token 驗證）
		$webhook_token = get_option( 'ys_paynow_shipping_webhook_token', '' );
		if ( empty( $webhook_token ) ) {
			$webhook_token = wp_generate_password( 32, false );
			update_option( 'ys_paynow_shipping_webhook_token', $webhook_token );
		}
		$callback_url = add_query_arg( 'token', $webhook_token, \WC()->api_request_url( 'ys-paynow-response' ) );

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

	/*
	|--------------------------------------------------------------------------
	| 頁籤切換 JS 與 CSS
	|--------------------------------------------------------------------------
	*/

	/**
	 * 輸出頁籤切換 JavaScript 與完整樣式
	 */
	private function output_tabs_script() {
		?>
		<style>
		/* ===== YS PayNow Settings Styles ===== */

		/* 頁面標頭 */
		.ys-settings-header {
			margin: 20px 0 0 0;
		}

		.ys-settings-header h2 {
			display: flex;
			align-items: center;
			gap: 8px;
			color: #5a7080;
			font-size: 22px;
		}

		.ys-settings-header h2 .dashicons {
			color: #8fa8b8;
			font-size: 24px;
		}

		.ys-settings-desc {
			color: #888;
			font-size: 14px;
			margin-top: 5px;
		}

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

		/* 儲存按鈕 */
		.ys-submit-wrap {
			margin-top: 20px;
		}

		.ys-submit-wrap .button-primary {
			background: #8fa8b8 !important;
			border-color: #7a95a6 !important;
			padding: 10px 30px !important;
			height: auto !important;
			font-size: 14px !important;
			border-radius: 8px !important;
			box-shadow: 0 2px 8px rgba(143, 168, 184, 0.3) !important;
			transition: all 0.2s !important;
		}

		.ys-submit-wrap .button-primary:hover {
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

				// 切換到排程分頁時自動載入 CRON LOG
				if (tab === 'cron') {
					ysLoadCronLog();
				}
			});

			// CRON LOG 啟用 checkbox 切換時顯示/隱藏 LOG 檢視器
			$('#ys_paynow_shipping_cron_log_enabled').on('change', function() {
				if ($(this).is(':checked')) {
					$('#ys-cron-log-viewer-row').show();
				} else {
					$('#ys-cron-log-viewer-row').hide();
				}
			});

			// CRON LOG 重新載入按鈕
			$('#ys-cron-log-reload').on('click', function() {
				ysLoadCronLog();
			});

			// CRON LOG 清除按鈕
			$('#ys-cron-log-clear').on('click', function() {
				if (!confirm('<?php echo esc_js( __( '確定要清除所有 CRON LOG 紀錄嗎？', 'ys-paynow-shipping' ) ); ?>')) {
					return;
				}
				$.post(ajaxurl, {
					action: 'ys_paynow_clear_cron_log',
					nonce: '<?php echo wp_create_nonce( 'ys-paynow-shipping-admin' ); ?>'
				}, function(response) {
					if (response.success) {
						$('#ys-cron-log-content').text(response.data.message);
					} else {
						$('#ys-cron-log-content').text('<?php echo esc_js( __( '清除失敗', 'ys-paynow-shipping' ) ); ?>');
					}
				});
			});

			// AJAX 載入 CRON LOG
			function ysLoadCronLog() {
				var $content = $('#ys-cron-log-content');
				$content.text('<?php echo esc_js( __( '載入中...', 'ys-paynow-shipping' ) ); ?>');
				$.post(ajaxurl, {
					action: 'ys_paynow_get_cron_log',
					nonce: '<?php echo wp_create_nonce( 'ys-paynow-shipping-admin' ); ?>'
				}, function(response) {
					if (response.success) {
						$content.text(response.data.content);
						// 自動捲動到底部
						$content.scrollTop($content[0].scrollHeight);
					} else {
						$content.text('<?php echo esc_js( __( '載入失敗', 'ys-paynow-shipping' ) ); ?>');
					}
				}).fail(function() {
					$content.text('<?php echo esc_js( __( '請求失敗，請重試', 'ys-paynow-shipping' ) ); ?>');
				});
			}
		});
		</script>
		<?php
	}

}
