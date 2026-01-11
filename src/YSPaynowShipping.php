<?php
/**
 * YS PayNow Shipping 主類別
 *
 * 負責初始化外掛、註冊 hooks、載入運送方式等核心功能。
 *
 * @package YangSheep\PayNow\Shipping
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping;

use YangSheep\PayNow\Shipping\Admin\YSAdminPrint;
use YangSheep\PayNow\Shipping\Admin\YSOrderListTable;
use YangSheep\PayNow\Shipping\Admin\YSOrderMetaBox;
use YangSheep\PayNow\Shipping\Cron\YSStatusUpdater;
use YangSheep\PayNow\Shipping\Frontend\YSMyAccount;
use YangSheep\PayNow\Shipping\Gateways\WCGatewayPaynowCOD;
use YangSheep\PayNow\Shipping\Api\YSShippingRequest;
use YangSheep\PayNow\Shipping\Api\YSShippingResponse;
use YangSheep\PayNow\Shipping\Frontend\YSStoreSelector;
use YangSheep\PayNow\Shipping\Settings\YSSettingsTab;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSOrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * YSPaynowShipping 主類別
 *
 * 處理所有結帳、訂單相關的物流邏輯。
 *
 * @since 1.0.0
 */
class YSPaynowShipping {

	/*
	|--------------------------------------------------------------------------
	| 靜態屬性 - API 設定
	|--------------------------------------------------------------------------
	*/

	/**
	 * PayNow API 網址
	 *
	 * @var string
	 */
	public static $api_url = '';

	/**
	 * 超商地圖網址
	 *
	 * @var string
	 */
	public static $cvs_map_url = '';

	/**
	 * 商家帳號
	 *
	 * @var string
	 */
	public static $user_account = '';

	/**
	 * API 密碼
	 *
	 * @var string
	 */
	public static $apicode = '';

	/**
	 * 是否為測試模式
	 *
	 * @var bool
	 */
	public static $testmode = false;

	/**
	 * Logger 實例
	 *
	 * @var \WC_Logger
	 */
	private static $logger;

	/*
	|--------------------------------------------------------------------------
	| 初始化方法
	|--------------------------------------------------------------------------
	*/

	/**
	 * 初始化外掛
	 *
	 * 註冊所有必要的 hooks 和 filters。
	 *
	 * @return void
	 */
	public static function init() {
		// 載入設定值
		self::load_settings();

		// 註冊運送方式
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'add_shipping_methods' ) );

		// 註冊設定頁面
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'add_settings_page' ), 15 );

		// 結帳頁相關 hooks
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_cvs_fields' ) );
		// ★ 確保 shipping_phone 欄位存在並強制顯示
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'ensure_shipping_phone_field' ), 5 );
		// 超商選擇器由 YSStoreSelector::maybe_display_store_selector() 使用 woocommerce_after_shipping_rate hook 渲染
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_shipping_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_shipping_meta' ), 10, 2 );

		// AJAX fragment 更新
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'update_cvs_info_fragment' ) );

		// 根據運送方式調整必填欄位
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'setup_cvs_shipping_fields_requirements' ), 999 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'setup_hd_shipping_fields_requirements' ), 999 );

		// ★ 超商取貨時修改預設地址欄位驗證
		add_filter( 'woocommerce_default_address_fields', array( __CLASS__, 'modify_default_address_fields' ), 999 );

		// ★ 超商取貨時跳過地址驗證
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'skip_address_validation_for_cvs' ), 10, 2 );

		// ★ Block Checkout (區塊結帳頁) 支援
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'init_blocks_integration' ) );

		// 地址格式化
		add_filter( 'woocommerce_get_order_address', array( __CLASS__, 'format_shipping_address' ), 10, 3 );
		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'add_address_format' ) );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'address_replacements' ), 10, 2 );

		// 訂單詳情頁顯示物流資訊
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'display_shipping_detail' ) );

		// 感謝頁清除 localStorage
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_checkout_storage' ) );

		// 載入前後台腳本
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );

		// PayNow 設定頁面添加 body class
		add_filter( 'admin_body_class', array( __CLASS__, 'add_settings_body_class' ) );

		// 註冊 PayNow COD 金流
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_payment_gateway' ) );

		// 初始化子模組
		YSOrderStatus::init();
		YSStoreSelector::init();
		YSShippingRequest::init();
		YSShippingResponse::init();
		YSOrderMetaBox::init();
		YSOrderListTable::init();
		YSStatusUpdater::init();
		YSAdminPrint::init();
		YSMyAccount::init();
	}

	/**
	 * 載入設定值
	 *
	 * @return void
	 */
	private static function load_settings() {
		self::$testmode     = 'yes' === get_option( 'ys_paynow_shipping_testmode', 'yes' );
		self::$user_account = get_option( 'ys_paynow_shipping_user_account', '' );
		self::$apicode      = get_option( 'ys_paynow_shipping_apicode', '' );

		// 根據測試模式設定 API 網址
		if ( self::$testmode ) {
			self::$api_url     = 'https://testlogistic.paynow.com.tw';
			self::$cvs_map_url = 'https://testlogistic.paynow.com.tw/Member/Order/Choselogistics';
		} else {
			self::$api_url     = 'https://logistic.paynow.com.tw';
			self::$cvs_map_url = 'https://logistic.paynow.com.tw/Member/Order/Choselogistics';
		}
	}

	/*
	|--------------------------------------------------------------------------
	| 運送方式註冊
	|--------------------------------------------------------------------------
	*/

	/**
	 * 註冊 PayNow 運送方式
	 *
	 * @param array $methods 現有的運送方式陣列。
	 * @return array 加入 PayNow 運送方式後的陣列。
	 */
	public static function add_shipping_methods( $methods ) {
		$methods['ys_paynow_shipping_711']         = 'YangSheep\PayNow\Shipping\Providers\YSShipping711';
		$methods['ys_paynow_shipping_family']      = 'YangSheep\PayNow\Shipping\Providers\YSShippingFamily';
		$methods['ys_paynow_shipping_hilife']      = 'YangSheep\PayNow\Shipping\Providers\YSShippingHilife';
		
		// 7-11 擴充
		$methods['ys_paynow_shipping_711_frozen']      = 'YangSheep\PayNow\Shipping\Providers\YSShipping711Frozen';
		$methods['ys_paynow_shipping_711_bulk']        = 'YangSheep\PayNow\Shipping\Providers\YSShipping711Bulk';
		$methods['ys_paynow_shipping_711_bulk_frozen'] = 'YangSheep\PayNow\Shipping\Providers\YSShipping711BulkFrozen';
		
		// 全家 擴充
		$methods['ys_paynow_shipping_family_frozen']      = 'YangSheep\PayNow\Shipping\Providers\YSShippingFamilyFrozen';
		$methods['ys_paynow_shipping_family_bulk']        = 'YangSheep\PayNow\Shipping\Providers\YSShippingFamilyBulk';
		$methods['ys_paynow_shipping_family_bulk_frozen'] = 'YangSheep\PayNow\Shipping\Providers\YSShippingFamilyBulkFrozen';

		$methods['ys_paynow_shipping_tcat_normal'] = 'YangSheep\PayNow\Shipping\Providers\YSShippingTcatNormal';
		
		if ( 'yes' === get_option( 'ys_paynow_shipping_tcat_enable_cool', 'no' ) ) {
			$methods['ys_paynow_shipping_tcat_chilled'] = 'YangSheep\PayNow\Shipping\Providers\YSShippingTcatChilled';
		}
		
		if ( 'yes' === get_option( 'ys_paynow_shipping_tcat_enable_frozen', 'no' ) ) {
			$methods['ys_paynow_shipping_tcat_frozen']  = 'YangSheep\PayNow\Shipping\Providers\YSShippingTcatFrozen';
		}
		
		return $methods;
	}

	/**
	 * 註冊設定頁面
	 *
	 * @param array $settings 現有的設定頁面陣列。
	 * @return array 加入 YS PayNow 設定頁後的陣列。
	 */
	public static function add_settings_page( $settings ) {
		// 防禦性程式碼：確保 $settings 是陣列
		if ( ! is_array( $settings ) ) {
			// 如果不是陣列，將其包裝成陣列，以免發生 Fatal Error
			$settings = array( $settings );
		}
		
		$settings[] = new YSSettingsTab();
		return $settings;
	}

	/**
	 * 新增金流
	 *
	 * @param array $gateways 金流陣列。
	 * @return array 修改後的陣列。
	 */
	public static function add_payment_gateway( $gateways ) {
		$gateways[] = 'YangSheep\PayNow\Shipping\Gateways\WCGatewayPaynowCOD';
		return $gateways;
	}

	/*
	|--------------------------------------------------------------------------
	| 結帳頁欄位處理
	|--------------------------------------------------------------------------
	*/

	/**
	 * 新增超商欄位至結帳表單
	 *
	 * 注意：舊版使用 ys_cvs_store_* 欄位，現已改用 JS 動態建立的
	 * ys_paynow_selected_store_id 和 ys_paynow_selected_store_data 欄位。
	 * 此方法保留但不再添加欄位，避免在 shipping fields wrapper 中產生多餘的隱藏欄位。
	 *
	 * @param array $fields 結帳欄位陣列。
	 * @return array 修改後的欄位陣列。
	 */
	public static function add_cvs_fields( $fields ) {
		// 不再添加欄位到 shipping fields，改由 JS 在 form 層級動態建立
		// 這樣可以避免隱藏欄位被渲染在 shipping fields wrapper 中造成版面問題
		return $fields;
	}

	/**
	 * 確保 shipping_phone 欄位存在並強制顯示
	 *
	 * 當啟用「運送到不同地址」功能時，確保運送電話欄位可用。
	 * 位置設定在姓名欄位之後。
	 *
	 * @param array $fields 結帳欄位陣列。
	 * @return array 修改後的欄位陣列。
	 */
	public static function ensure_shipping_phone_field( $fields ) {
		// 確保 shipping 欄位陣列存在
		if ( ! isset( $fields['shipping'] ) ) {
			$fields['shipping'] = array();
		}

		// 如果 shipping_phone 欄位不存在，則添加它
		if ( ! isset( $fields['shipping']['shipping_phone'] ) ) {
			// 計算優先級：放在 shipping_last_name 或 shipping_first_name 之後
			$priority = 25; // 預設優先級

			if ( isset( $fields['shipping']['shipping_last_name']['priority'] ) ) {
				$priority = $fields['shipping']['shipping_last_name']['priority'] + 5;
			} elseif ( isset( $fields['shipping']['shipping_first_name']['priority'] ) ) {
				$priority = $fields['shipping']['shipping_first_name']['priority'] + 10;
			}

			$fields['shipping']['shipping_phone'] = array(
				'label'        => __( '手機', 'ys-paynow-shipping' ),
				'placeholder'  => __( '請輸入手機號碼', 'ys-paynow-shipping' ),
				'required'     => true,
				'class'        => array( 'form-row-wide', 'ys-validate-mobile' ),
				'validate'     => array( 'phone' ),
				'autocomplete' => 'tel',
				'priority'     => $priority,
				'type'         => 'tel',
				'maxlength'    => 10,
			);
		} else {
			// 欄位已存在，確保它是顯示的（非隱藏）
			if ( isset( $fields['shipping']['shipping_phone']['class'] ) ) {
				// 移除可能的隱藏 class
				$fields['shipping']['shipping_phone']['class'] = array_diff(
					(array) $fields['shipping']['shipping_phone']['class'],
					array( 'hidden', 'ys-hidden' )
				);
			}
		}

		// 強制顯示（移除隱藏屬性）
		if ( isset( $fields['shipping']['shipping_phone']['hidden'] ) ) {
			unset( $fields['shipping']['shipping_phone']['hidden'] );
		}

		return $fields;
	}

	/**
	 * 在運送方式後顯示「選擇超商」按鈕
	 *
	 * @param \WC_Shipping_Rate $method 運送方式物件。
	 * @param int               $index  索引。
	 * @return void
	 */
	public static function display_store_selector_button( $method, $index ) {
		// 僅在結帳頁顯示
		if ( ! is_checkout() ) {
			return;
		}

		// 檢查是否為需要選擇超商的運送方式
		$method_id = $method->get_method_id();
		if ( ! self::needs_cvs( $method_id ) ) {
			return;
		}

		// 取得物流服務 ID
		$logistic_service_id = self::get_logistic_service_id( $method_id );

		?>
		<div class="ys-paynow-cvs-selector" data-method-id="<?php echo esc_attr( $method_id ); ?>" data-logistic-service="<?php echo esc_attr( $logistic_service_id ); ?>">
			<button type="button" class="button ys-paynow-choose-cvs-btn">
				<?php esc_html_e( '選擇超商', 'ys-paynow-shipping' ); ?>
			</button>
			<div class="ys-paynow-cvs-info"></div>
		</div>
		<?php
	}

	/**
	 * 驗證運送欄位
	 *
	 * 若選擇超商取貨但未選擇超商，顯示錯誤訊息。
	 *
	 * @return void
	 */
	public static function validate_shipping_fields() {
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( empty( $chosen_shipping_methods ) ) {
			return;
		}

		$shipping_method = $chosen_shipping_methods[0];
		$method_id       = explode( ':', $shipping_method )[0];

		// 檢查是否為需要超商的運送方式
		if ( self::needs_cvs( $method_id ) ) {
			self::log( 'validate_shipping_fields checking method: ' . $method_id );
			
			// 確認已選擇超商 - 先從 POST 檢查，再從 Session 檢查
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$store_id = isset( $_POST['ys_paynow_selected_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ys_paynow_selected_store_id'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			self::log( 'validate_shipping_fields POST store_id: ' . $store_id );

			// 如果 POST 沒有，嘗試從 Session 取得
			if ( empty( $store_id ) && WC()->session ) {
				$stored_data = WC()->session->get( 'ys_paynow_selected_store_data', null );
				if ( $stored_data && is_array( $stored_data ) ) {
					$store_id = $stored_data['id'] ?? '';
				}
				self::log( 'validate_shipping_fields Session store_id: ' . $store_id );
			}

			if ( empty( $store_id ) ) {
				self::log( 'validate_shipping_fields failed: No store selected' );
				wc_add_notice( __( '請選擇取貨超商', 'ys-paynow-shipping' ), 'error' );
			}
		}
	}

	/**
	 * 訂單成立時儲存物流 meta 資料
	 *
	 * 使用 HPOS 相容的 $order->update_meta_data() 方法。
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $data  訂單資料。
	 * @return void
	 */
	public static function save_order_shipping_meta( $order, $data ) {
		self::log( 'save_order_shipping_meta called for order #' . $order->get_id() );
		
		// 取得運送方式
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			self::log( 'No shipping methods found, returning early' );
			return;
		}

		$shipping_method = reset( $shipping_methods );
		$method_id       = $shipping_method->get_method_id();
		
		self::log( 'Shipping method ID: ' . $method_id );

		// 儲存物流服務 ID
		$logistic_service_id = self::get_logistic_service_id( $method_id );
		if ( $logistic_service_id ) {
			$order->update_meta_data( YSOrderMeta::LogisticServiceId, $logistic_service_id );
			self::log( 'Saved logistic service ID: ' . $logistic_service_id );
		}

		// 若為超商取貨，使用 YSStoreSelector 儲存超商資訊
		$needs_cvs = self::needs_cvs( $method_id );
		self::log( 'needs_cvs(' . $method_id . ') = ' . ( $needs_cvs ? 'true' : 'false' ) );
		
		if ( $needs_cvs ) {
			YSStoreSelector::save_store_to_order( $order, $data );
		}

		// 黑貓宅配相關設定
		if ( 'ys_paynow_shipping_tcat_normal' === $method_id ) {
			$order->update_meta_data( YSOrderMeta::DeliveryType, '0001' ); // 常溫
		} elseif ( 'ys_paynow_shipping_tcat_chilled' === $method_id ) {
			$order->update_meta_data( YSOrderMeta::DeliveryType, '0002' ); // 冷藏
		} elseif ( 'ys_paynow_shipping_tcat_frozen' === $method_id ) {
			$order->update_meta_data( YSOrderMeta::DeliveryType, '0003' ); // 冷凍
		}
	}

	/*
	|--------------------------------------------------------------------------
	| 欄位需求調整
	|--------------------------------------------------------------------------
	*/

	/**
	 * 超商取貨時移除不需要的運送欄位
	 *
	 * @param array $fields 結帳欄位陣列。
	 * @return array 修改後的欄位陣列。
	 */
	public static function setup_cvs_shipping_fields_requirements( $fields ) {
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( empty( $chosen_methods ) ) {
			return $fields;
		}

		$method_id = explode( ':', $chosen_methods[0] )[0];

		if ( self::needs_cvs( $method_id ) ) {
			// 超商取貨不需要完整運送地址
			if ( isset( $fields['shipping']['shipping_address_1'] ) ) {
				$fields['shipping']['shipping_address_1']['required'] = false;
			}
			if ( isset( $fields['shipping']['shipping_address_2'] ) ) {
				$fields['shipping']['shipping_address_2']['required'] = false;
			}
			if ( isset( $fields['shipping']['shipping_city'] ) ) {
				$fields['shipping']['shipping_city']['required'] = false;
			}
			if ( isset( $fields['shipping']['shipping_state'] ) ) {
				$fields['shipping']['shipping_state']['required'] = false;
			}
			if ( isset( $fields['shipping']['shipping_postcode'] ) ) {
				$fields['shipping']['shipping_postcode']['required'] = false;
			}

			// ★ 如果啟用隱藏帳單地址，也取消帳單地址必填
			$hide_billing = 'yes' === get_option( 'ys_paynow_cvs_hide_billing_address', 'no' );
			if ( $hide_billing ) {
				if ( isset( $fields['billing']['billing_address_1'] ) ) {
					$fields['billing']['billing_address_1']['required'] = false;
				}
				if ( isset( $fields['billing']['billing_address_2'] ) ) {
					$fields['billing']['billing_address_2']['required'] = false;
				}
				if ( isset( $fields['billing']['billing_city'] ) ) {
					$fields['billing']['billing_city']['required'] = false;
				}
				if ( isset( $fields['billing']['billing_state'] ) ) {
					$fields['billing']['billing_state']['required'] = false;
				}
				if ( isset( $fields['billing']['billing_postcode'] ) ) {
					$fields['billing']['billing_postcode']['required'] = false;
				}
				if ( isset( $fields['billing']['billing_country'] ) ) {
					$fields['billing']['billing_country']['required'] = false;
				}
			}
		}

		return $fields;
	}

	/**
	 * 修改預設地址欄位驗證
	 *
	 * 超商取貨時，將地址相關欄位設為非必填。
	 *
	 * @param array $fields 預設地址欄位陣列。
	 * @return array 修改後的欄位陣列。
	 */
	public static function modify_default_address_fields( $fields ) {
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( empty( $chosen_methods ) ) {
			return $fields;
		}

		$method_id = explode( ':', $chosen_methods[0] )[0];

		// 超商取貨時，地址欄位設為非必填
		if ( self::needs_cvs( $method_id ) ) {
			$address_fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

			foreach ( $address_fields as $field ) {
				if ( isset( $fields[ $field ] ) ) {
					$fields[ $field ]['required'] = false;
				}
			}
		}

		return $fields;
	}

	/**
	 * 超商取貨時跳過地址驗證錯誤
	 *
	 * 清除 WooCommerce 產生的地址驗證錯誤。
	 *
	 * @param array     $data   提交的結帳資料。
	 * @param \WP_Error $errors 驗證錯誤物件。
	 * @return void
	 */
	public static function skip_address_validation_for_cvs( $data, $errors ) {
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( empty( $chosen_methods ) ) {
			return;
		}

		$method_id = explode( ':', $chosen_methods[0] )[0];

		// 超商取貨時，移除地址相關的驗證錯誤
		if ( self::needs_cvs( $method_id ) ) {
			$hide_billing = 'yes' === get_option( 'ys_paynow_cvs_hide_billing_address', 'no' );

			// 取得所有錯誤
			$error_codes = $errors->get_error_codes();

			foreach ( $error_codes as $code ) {
				// 移除運送地址相關錯誤
				$shipping_patterns = array(
					'shipping_address_1',
					'shipping_address_2',
					'shipping_city',
					'shipping_state',
					'shipping_postcode',
					'shipping_country',
				);

				foreach ( $shipping_patterns as $pattern ) {
					if ( strpos( $code, $pattern ) !== false ) {
						$errors->remove( $code );
						continue 2;
					}
				}

				// 如果啟用隱藏帳單地址，也移除帳單地址錯誤
				if ( $hide_billing ) {
					$billing_patterns = array(
						'billing_address_1',
						'billing_address_2',
						'billing_city',
						'billing_state',
						'billing_postcode',
						'billing_country',
					);

					foreach ( $billing_patterns as $pattern ) {
						if ( strpos( $code, $pattern ) !== false ) {
							$errors->remove( $code );
							continue 2;
						}
					}
				}
			}

			// ★ 額外處理：移除含有「地址」關鍵字的通用錯誤訊息
			foreach ( $error_codes as $code ) {
				$messages = $errors->get_error_messages( $code );
				foreach ( $messages as $message ) {
					// 檢查是否為地址相關的錯誤訊息
					if ( strpos( $message, '地址' ) !== false || strpos( $message, 'address' ) !== false ) {
						$errors->remove( $code );
						break;
					}
				}
			}
		}
	}

	/**
	 * 初始化 WooCommerce Blocks 整合
	 *
	 * Block Checkout（區塊結帳頁）使用不同的驗證機制，
	 * 需要透過 Store API 的 filter 來修改欄位驗證。
	 *
	 * @return void
	 */
	public static function init_blocks_integration() {
		// 修改 Store API 的地址驗證 schema
		add_filter( 'woocommerce_store_api_checkout_update_customer_from_request', array( __CLASS__, 'blocks_update_customer_from_request' ), 10, 2 );

		// ★ 核心：修改 Store API 回應，告訴前端哪些欄位不需要驗證
		add_filter( '__experimental_woocommerce_blocks_checkout_update_order_from_request', array( __CLASS__, 'blocks_update_order_from_request' ), 10, 2 );

		// 修改地址欄位 schema（讓前端知道欄位非必填）
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'modify_country_locale_for_cvs' ), 999 );

		// Store API 驗證後處理
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'blocks_after_checkout_validation' ) );
	}

	/**
	 * Block Checkout：從請求更新客戶資料
	 *
	 * @param \WC_Customer $customer 客戶物件。
	 * @param \WP_REST_Request $request REST 請求物件。
	 * @return \WC_Customer
	 */
	public static function blocks_update_customer_from_request( $customer, $request ) {
		// 取得選擇的運送方式
		$shipping_method = '';
		if ( WC()->session ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			if ( ! empty( $chosen_methods ) ) {
				$shipping_method = explode( ':', $chosen_methods[0] )[0];
			}
		}

		// 超商取貨時，自動填入預設地址（避免驗證失敗）
		if ( self::needs_cvs( $shipping_method ) ) {
			$hide_billing = 'yes' === get_option( 'ys_paynow_cvs_hide_billing_address', 'no' );

			// 設定運送地址預設值
			if ( empty( $customer->get_shipping_address_1() ) ) {
				$customer->set_shipping_address_1( '超商取貨' );
			}
			if ( empty( $customer->get_shipping_city() ) ) {
				$customer->set_shipping_city( '超商取貨' );
			}
			if ( empty( $customer->get_shipping_country() ) ) {
				$customer->set_shipping_country( 'TW' );
			}

			// 如果啟用隱藏帳單地址，也設定帳單地址預設值
			if ( $hide_billing ) {
				if ( empty( $customer->get_billing_address_1() ) ) {
					$customer->set_billing_address_1( '超商取貨' );
				}
				if ( empty( $customer->get_billing_city() ) ) {
					$customer->set_billing_city( '超商取貨' );
				}
				if ( empty( $customer->get_billing_country() ) ) {
					$customer->set_billing_country( 'TW' );
				}
			}
		}

		return $customer;
	}

	/**
	 * Block Checkout：從請求更新訂單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param \WP_REST_Request $request REST 請求物件。
	 * @return \WC_Order
	 */
	public static function blocks_update_order_from_request( $order, $request ) {
		// 取得運送方式
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return $order;
		}

		$shipping_method = reset( $shipping_methods );
		$method_id       = $shipping_method->get_method_id();

		// 超商取貨時，自動填入預設地址
		if ( self::needs_cvs( $method_id ) ) {
			$hide_billing = 'yes' === get_option( 'ys_paynow_cvs_hide_billing_address', 'no' );

			// 設定運送地址預設值（避免驗證失敗）
			if ( empty( $order->get_shipping_address_1() ) ) {
				$order->set_shipping_address_1( '超商取貨' );
			}
			if ( empty( $order->get_shipping_city() ) ) {
				$order->set_shipping_city( '超商取貨' );
			}
			if ( empty( $order->get_shipping_country() ) ) {
				$order->set_shipping_country( 'TW' );
			}

			// 如果啟用隱藏帳單地址
			if ( $hide_billing ) {
				if ( empty( $order->get_billing_address_1() ) ) {
					$order->set_billing_address_1( '超商取貨' );
				}
				if ( empty( $order->get_billing_city() ) ) {
					$order->set_billing_city( '超商取貨' );
				}
				if ( empty( $order->get_billing_country() ) ) {
					$order->set_billing_country( 'TW' );
				}
			}
		}

		return $order;
	}

	/**
	 * 修改國家/地區的欄位設定（針對超商取貨）
	 *
	 * 這會影響 Block Checkout 的前端驗證。
	 *
	 * @param array $locale 國家/地區設定。
	 * @return array
	 */
	public static function modify_country_locale_for_cvs( $locale ) {
		// 取得選擇的運送方式
		$shipping_method = '';
		if ( WC()->session ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			if ( ! empty( $chosen_methods ) ) {
				$shipping_method = explode( ':', $chosen_methods[0] )[0];
			}
		}

		// 超商取貨時，修改台灣的地址欄位設定
		if ( self::needs_cvs( $shipping_method ) && isset( $locale['TW'] ) ) {
			$fields_to_optional = array( 'address_1', 'address_2', 'city', 'state', 'postcode' );

			foreach ( $fields_to_optional as $field ) {
				if ( isset( $locale['TW'][ $field ] ) ) {
					$locale['TW'][ $field ]['required'] = false;
				}
			}
		}

		return $locale;
	}

	/**
	 * Block Checkout：訂單處理後的驗證清理
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return void
	 */
	public static function blocks_after_checkout_validation( $order ) {
		// 取得運送方式
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return;
		}

		$shipping_method = reset( $shipping_methods );
		$method_id       = $shipping_method->get_method_id();

		// 超商取貨時，清除自動填入的預設地址（如果需要）
		// 這裡可以根據需求決定是否保留 "超商取貨" 文字
		if ( self::needs_cvs( $method_id ) ) {
			// 選擇性：將 "超商取貨" 替換為空值或保留
			// 目前保留以便訂單有記錄
		}
	}

	/**
	 * 宅配運送時設定必填電話欄位
	 *
	 * @param array $fields 結帳欄位陣列。
	 * @return array 修改後的欄位陣列。
	 */
	public static function setup_hd_shipping_fields_requirements( $fields ) {
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( empty( $chosen_methods ) ) {
			return $fields;
		}

		$method_id = explode( ':', $chosen_methods[0] )[0];

		if ( in_array( $method_id, array( 'ys_paynow_shipping_tcat_normal', 'ys_paynow_shipping_tcat_chilled', 'ys_paynow_shipping_tcat_frozen' ), true ) ) {
			// 黑貓宅配需要電話
			if ( isset( $fields['shipping']['shipping_phone'] ) ) {
				$fields['shipping']['shipping_phone']['required'] = true;
			}
		}

		return $fields;
	}

	/*
	|--------------------------------------------------------------------------
	| AJAX Fragment 更新
	|--------------------------------------------------------------------------
	*/

	/**
	 * 更新結帳頁的超商資訊區塊
	 *
	 * @param array $fragments AJAX fragments 陣列。
	 * @return array 更新後的 fragments。
	 */
	public static function update_cvs_info_fragment( $fragments ) {
		$cvs_info = WC()->session->get( 'ys_paynow_cvs_info', array() );

		ob_start();
		?>
		<div id="ys-paynow-cvs-selected-info">
			<?php if ( ! empty( $cvs_info['store_name'] ) ) : ?>
				<p><strong><?php esc_html_e( '取貨門市：', 'ys-paynow-shipping' ); ?></strong><?php echo esc_html( $cvs_info['store_name'] ); ?></p>
				<p><strong><?php esc_html_e( '門市地址：', 'ys-paynow-shipping' ); ?></strong><?php echo esc_html( $cvs_info['store_addr'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$fragments['#ys-paynow-cvs-selected-info'] = ob_get_clean();

		return $fragments;
	}

	/*
	|--------------------------------------------------------------------------
	| 地址格式化
	|--------------------------------------------------------------------------
	*/

	/**
	 * 格式化運送地址（超商取貨時顯示門市資訊）
	 *
	 * @param array     $address 地址陣列。
	 * @param string    $type    地址類型 (billing/shipping)。
	 * @param \WC_Order $order   訂單物件。
	 * @return array 修改後的地址陣列。
	 */
	public static function format_shipping_address( $address, $type, $order ) {
		if ( 'shipping' !== $type ) {
			return $address;
		}

		$store_name = $order->get_meta( YSOrderMeta::StoreName );
		if ( ! empty( $store_name ) ) {
			$address['ys_cvs_store']   = $store_name;
			$address['ys_cvs_address'] = $order->get_meta( YSOrderMeta::StoreAddr );
		}

		return $address;
	}

	/**
	 * 新增自訂地址格式
	 *
	 * @param array $formats 地址格式陣列。
	 * @return array 修改後的格式陣列。
	 */
	public static function add_address_format( $formats ) {
		$formats['TW_CVS'] = "{name}\n{ys_cvs_store}\n{ys_cvs_address}\n{phone}";
		return $formats;
	}

	/**
	 * 地址欄位替換
	 *
	 * @param array $replacements 替換陣列。
	 * @param array $args         地址參數。
	 * @return array 修改後的替換陣列。
	 */
	public static function address_replacements( $replacements, $args ) {
		$replacements['{ys_cvs_store}']   = isset( $args['ys_cvs_store'] ) ? $args['ys_cvs_store'] : '';
		$replacements['{ys_cvs_address}'] = isset( $args['ys_cvs_address'] ) ? $args['ys_cvs_address'] : '';
		return $replacements;
	}

	/*
	|--------------------------------------------------------------------------
	| 訂單詳情頁
	|--------------------------------------------------------------------------
	*/

	/**
	 * 在訂單詳情頁顯示物流資訊
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return void
	 */
	public static function display_shipping_detail( $order ) {
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( empty( $logistic_service_id ) ) {
			return;
		}

		$store_name      = $order->get_meta( YSOrderMeta::StoreName );
		$store_addr      = $order->get_meta( YSOrderMeta::StoreAddr );
		$logistic_no     = $order->get_meta( YSOrderMeta::LogisticNumber );
		$delivery_status = $order->get_meta( YSOrderMeta::DeliveryStatus ); // Raw status
		$update_time     = $order->get_meta( YSOrderMeta::StatusUpdateAt );
		$is_printed      = $order->get_meta( '_ys_label_printed' ) === 'yes';

        // 取得運送方式名稱
        $shipping_method_title = '';
        foreach( $order->get_shipping_methods() as $shipping_method ) {
            $shipping_method_title = $shipping_method->get_name();
            break; 
        }

        // 判定流程類別 (CVS vs Home)
        // 06=Pelican, 36=BlackCat -> Home
        $flow_type = 'cvs';
        if ( in_array( $logistic_service_id, array( '06', '36' ) ) || strpos( $shipping_method_title, '宅配' ) !== false || strpos( $shipping_method_title, '黑貓' ) !== false ) {
            $flow_type = 'home';
        }

        // 定義步驟
        if ( $flow_type === 'home' ) {
            $steps = array(
                1 => __( '訂單成立', 'ys-paynow-shipping' ),
                2 => __( '商品準備中', 'ys-paynow-shipping' ),
                3 => __( '運送中', 'ys-paynow-shipping' ),
                4 => __( '配送完成', 'ys-paynow-shipping' ),
            );
        } else {
            // CVS: 5 Steps
            $steps = array(
                1 => __( '訂單成立', 'ys-paynow-shipping' ),
                2 => __( '商品準備中', 'ys-paynow-shipping' ),
                3 => __( '運送中', 'ys-paynow-shipping' ),
                4 => __( '已到店', 'ys-paynow-shipping' ),
                5 => __( '已取貨', 'ys-paynow-shipping' ),
            );
        }
		
		// 判定當前步驟與顯示狀態
        $current_step = 1;
        $scan_status = $delivery_status;
        $display_status = __( '訂單成立', 'ys-paynow-shipping' );

        // 1. Check Printed -> Preparing
        if ( $is_printed ) {
            $current_step = 2;
            $display_status = __( '商品準備中', 'ys-paynow-shipping' );
        }

        // 2. Check Raw Status
        if ( ! empty( $scan_status ) ) {
            // Mapping Logic matches Enhancer
            if ( strpos( $scan_status, '完成' ) !== false || strpos( $scan_status, '取貨' ) !== false || strpos( $scan_status, '已取' ) !== false ) {
                $current_step = ( $flow_type === 'home' ) ? 4 : 5;
                $display_status = $scan_status;
            } elseif ( strpos( $scan_status, '到店' ) !== false || strpos( $scan_status, '待取' ) !== false || strpos( $scan_status, '配達' ) !== false ) {
                 // Home "Arrived" usually means delivered? Or arrived at local station?
                 // For Home, "配達" usually means Delivered.
                 // For CVS, "到店" is Arrived.
                 if ( $flow_type === 'home' ) {
                      // If '配達' (Delivered) -> Step 4
                      if ( strpos( $scan_status, '配達' ) !== false ) {
                          $current_step = 4;
                      } else {
                          // Arrived at station -> Step 3 (Shipping)
                          $current_step = 3;
                      }
                 } else {
                      $current_step = 4;
                 }
                 $display_status = $scan_status;
            } elseif ( strpos( $scan_status, '運送' ) !== false || strpos( $scan_status, '出貨' ) !== false || strpos( $scan_status, '離店' ) !== false ) {
                $current_step = 3;
                $display_status = $scan_status;
            } elseif ( strpos( $scan_status, '成立' ) === false ) {
                 // Other intermediate status?
                 $display_status = $scan_status;
            }
        }

        // Calculate progress %
        $total_steps = count( $steps );
        $step_percent = ($current_step - 1) / ($total_steps - 1) * 100;
        $progress_pct = max(0, min(100, $step_percent)) . '%';

		// 格式化時間
		$formatted_time = ! empty( $update_time ) ? date_i18n( 'F j, Y g:i a', strtotime( $update_time ) ) : '';

		?>
		<div id="ys-logistics-card" class="ys-logistics-card status-step-<?php echo esc_attr( $current_step ); ?>" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<div class="ys-card-header">
				<h2><?php esc_html_e( '物流狀態', 'ys-paynow-shipping' ); ?></h2>
				<?php if ( ! empty( $logistic_no ) ) : ?>
				<button class="ys-refresh-btn" type="button">
					<i class="fas fa-sync-alt"></i> <span><?php esc_html_e( '更新貨態', 'ys-paynow-shipping' ); ?></span>
				</button>
				<?php endif; ?>
			</div>
            
            <?php if ( ! empty( $shipping_method_title ) ) : ?>
            <div class="ys-shipping-method-title" style="text-align: center; margin-bottom: 10px; color: #555; font-weight: 500;">
                <?php echo esc_html( $shipping_method_title ); ?>
            </div>
            <?php endif; ?>
			
			<div class="ys-status-section">
				<p class="ys-current-status"><?php echo esc_html( $display_status ); ?></p>
				<p class="ys-status-time"><?php echo esc_html( $formatted_time ); ?></p>
			</div>

			<div class="ys-progress-bar">
				<div class="ys-progress-line"></div>
				<div class="ys-progress-line-active" style="width: <?php echo esc_attr( $progress_pct ); ?>;"></div>
				
                <?php foreach ( $steps as $step_num => $label ) : ?>
				<div class="ys-step <?php echo $current_step >= $step_num ? 'active' : ''; ?>" style="width: <?php echo (100 / ($total_steps - 1)); ?>%;">
					<div class="ys-step-circle"></div>
					<div class="ys-step-label"><?php echo esc_html( $label ); ?></div>
				</div>
                <?php endforeach; ?>
			</div>

            <!-- CSS Fix for dynamic steps -->
            <style>
                .ys-progress-bar { display: flex; justify-content: space-between; position: relative; margin-top: 30px; margin-bottom: 20px; }
                .ys-progress-line { position: absolute; top: 10px; left: 0; width: 100%; height: 4px; background: #e0e0e0; z-index: 1; }
                .ys-progress-line-active { position: absolute; top: 10px; left: 0; height: 4px; background: #007cba; z-index: 2; transition: width 0.3s ease; }
                .ys-step { position: relative; z-index: 3; text-align: center; display: flex; flex-direction: column; align-items: center; width: auto; flex: 1; }
                .ys-step-circle { width: 24px; height: 24px; background: #fff; border: 4px solid #e0e0e0; border-radius: 50%; margin-bottom: 5px; transition: all 0.3s ease; }
                .ys-step.active .ys-step-circle { border-color: #007cba; background: #007cba; }
                .ys-step-label { font-size: 12px; color: #999; margin-top: 5px; white-space: nowrap; }
                .ys-step.active .ys-step-label { color: #333; font-weight: bold; }
                /* Adjust flex for just space-between */
                .ys-step { flex: 0 1 auto; } 
            </style>

			<div class="ys-details-section">
				<ul>
					<?php if ( ! empty( $store_name ) && $flow_type !== 'home' ) : ?>
					<li>
						<span class="ys-icon"><i class="fas fa-store"></i></span>
						<span class="ys-label"><?php esc_html_e( '取貨門市', 'ys-paynow-shipping' ); ?></span>
						<span class="ys-value"><?php echo esc_html( $store_name ); ?></span>
					</li>
					<li>
						<span class="ys-icon"><i class="fas fa-map-marker-alt"></i></span>
						<span class="ys-label"><?php esc_html_e( '門市地址', 'ys-paynow-shipping' ); ?></span>
						<span class="ys-value"><?php echo esc_html( $store_addr ); ?></span>
					</li>
					<?php endif; ?>
					
					<?php if ( ! empty( $logistic_no ) ) : ?>
					<li>
						<span class="ys-icon"><i class="fas fa-barcode"></i></span>
						<span class="ys-label"><?php esc_html_e( '物流單號', 'ys-paynow-shipping' ); ?></span>
						<span class="ys-value ys-copyable" data-clipboard-text="<?php echo esc_attr( $logistic_no ); ?>">
							<?php echo esc_html( $logistic_no ); ?> 
							<i class="far fa-copy" style="margin-left: 5px;"></i>
						</span>
					</li>
					<?php endif; ?>
				</ul>
			</div>
			
			<div class="ys-toast-notification"></div>
		</div>
		<?php
	}

	/**
	 * 感謝頁清除 localStorage
	 *
	 * @param int $order_id 訂單 ID。
	 * @return void
	 */
	public static function clear_checkout_storage( $order_id ) {
		?>
		<script>
		if ( typeof localStorage !== 'undefined' ) {
			localStorage.removeItem( 'ys_paynow_cvs_store' );
		}
		</script>
		<?php
	}

	/*
	|--------------------------------------------------------------------------
	| 腳本與樣式
	|--------------------------------------------------------------------------
	*/

	/**
	 * 載入前台腳本與樣式
	 *
	 * @return void
	 */
	public static function enqueue_frontend_scripts() {
		if ( ! is_checkout() && ! is_account_page() && ! is_view_order_page() ) {
			return;
		}

		wp_enqueue_style(
			'ys-paynow-frontend',
			YS_PAYNOW_SHIPPING_PLUGIN_URL . 'assets/css/ys-paynow-frontend.css',
			array(),
			YS_PAYNOW_SHIPPING_VERSION
		);

		// 輸出動態 CSS 變數（從後台設定讀取）
		self::output_dynamic_css_variables();

		wp_enqueue_script(
			'ys-paynow-frontend',
			YS_PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/ys-paynow-frontend.js',
			array( 'jquery', 'wc-checkout' ),
			YS_PAYNOW_SHIPPING_VERSION,
			true
		);

		wp_localize_script(
			'ys-paynow-frontend',
			'ys_paynow_params',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'cvs_map_url' => self::$cvs_map_url,
				'nonce'       => wp_create_nonce( 'ys-paynow-shipping' ),
				'service_mapping' => array(
					'ys_paynow_shipping_711'    => YSLogisticService::SEVEN,
					'ys_paynow_shipping_family' => YSLogisticService::FAMI,
					'ys_paynow_shipping_hilife' => YSLogisticService::HILIFE,
				),
				// 超商取貨結帳行為設定
				'cvs_settings' => array(
					'hide_billing_address'  => 'yes' === get_option( 'ys_paynow_cvs_hide_billing_address', 'no' ),
					'hide_shipping_address' => 'yes' === get_option( 'ys_paynow_cvs_hide_shipping_address', 'yes' ),
				),
				'labels'      => array(
					'select_store'      => __( '選擇門市', 'ys-paynow-shipping' ),
					'change_store'      => __( '更換門市', 'ys-paynow-shipping' ),
					'no_store_selected' => __( '尚未選擇取貨門市', 'ys-paynow-shipping' ),
					'loading'           => __( '跳轉中...', 'ys-paynow-shipping' ),
					'error'             => __( '載入失敗，請稍後再試', 'ys-paynow-shipping' ),
				)
			)
		);
	}

	/**
	 * 輸出動態 CSS 變數
	 *
	 * @return void
	 */
	private static function output_dynamic_css_variables() {
		// 預設顏色（與 YSSettingsTab 保持同步）
		// 注意：不能直接呼叫 YSSettingsTab，因為前台可能尚未載入 WC_Settings_Page
		$default_store_bg     = '#e8eff5';
		$default_store_border = '#c5d1d8';

		$store_bg     = get_option( 'ys_paynow_cvs_store_bg', $default_store_bg );
		$store_border = get_option( 'ys_paynow_cvs_store_border', $default_store_border );

		// 輸出內聯 CSS 變數
		$custom_css = ":root { --ys-paynow-store-bg: {$store_bg}; --ys-paynow-store-border: {$store_border}; }";

		wp_add_inline_style( 'ys-paynow-frontend', $custom_css );
	}

	/**
	 * PayNow 設定頁面添加 body class
	 *
	 * @param string $classes 現有 body class。
	 * @return string 更新後的 body class。
	 */
	public static function add_settings_body_class( $classes ) {
		$screen = get_current_screen();

		if ( $screen
			&& 'woocommerce_page_wc-settings' === $screen->id
			&& isset( $_GET['tab'] )
			&& 'ys_paynow_shipping' === $_GET['tab']
		) {
			$classes .= ' ys-paynow-settings-page';
		}

		return $classes;
	}

	/**
	 * 載入後台腳本與樣式
	 *
	 * @return void
	 */
	public static function enqueue_admin_scripts() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		// 訂單相關頁面
		$order_screens = array(
			'shop_order',                    // 傳統訂單編輯
			'edit-shop_order',               // 傳統訂單列表
			'woocommerce_page_wc-orders',    // HPOS 訂單頁面
		);

		// WooCommerce 設定頁面（PayNow 物流分頁）
		$is_paynow_settings = 'woocommerce_page_wc-settings' === $screen->id
			&& isset( $_GET['tab'] )
			&& 'ys_paynow_shipping' === $_GET['tab'];

		// 判斷是否需要載入
		$is_order_screen = in_array( $screen->id, $order_screens, true );

		if ( ! $is_order_screen && ! $is_paynow_settings ) {
			return;
		}

		// 載入後台 CSS
		wp_enqueue_style(
			'ys-paynow-admin',
			YS_PAYNOW_SHIPPING_PLUGIN_URL . 'assets/css/ys-paynow-admin.css',
			array(),
			YS_PAYNOW_SHIPPING_VERSION
		);

		// 僅在訂單頁面載入後台 JS
		if ( $is_order_screen ) {
			wp_enqueue_script(
				'ys-paynow-admin',
				YS_PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/ys-paynow-admin.js',
				array( 'jquery' ),
				YS_PAYNOW_SHIPPING_VERSION,
				true
			);

			wp_localize_script(
				'ys-paynow-admin',
				'ys_paynow_admin_params',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'ys-paynow-shipping-admin' ),
					'available_services'  => self::get_enabled_cvs_services(),
				)
			);
		}
	}

	/**
	 * 取得 WooCommerce 啟用的超商物流服務
	 *
	 * @return array 啟用的服務陣列。
	 */
	private static function get_enabled_cvs_services() {
		$enabled_services = array();
		$shipping_zones   = \WC_Shipping_Zones::get_zones();
		
		// 遍歷所有運送區域
		foreach ( $shipping_zones as $zone_data ) {
			$zone    = new \WC_Shipping_Zone( $zone_data['zone_id'] );
			$methods = $zone->get_shipping_methods( true ); // true = enabled only
			
			foreach ( $methods as $method ) {
				$method_id = $method->id;
				// 檢查是否為 PayNow 超商運送方式
				if ( strpos( $method_id, 'ys_paynow_shipping_' ) !== false ) {
					$service_id = self::get_service_id_from_method( $method_id );
					if ( $service_id && ! isset( $enabled_services[ $service_id ] ) ) {
						$enabled_services[ $service_id ] = array(
							'id'   => $service_id,
							'name' => YSLogisticService::get_service_name( $service_id ),
						);
					}
				}
			}
		}
		
		// 也檢查 "Locations not covered by your other zones" (zone_id=0)
		$default_zone    = new \WC_Shipping_Zone( 0 );
		$default_methods = $default_zone->get_shipping_methods( true );
		foreach ( $default_methods as $method ) {
			$method_id = $method->id;
			if ( strpos( $method_id, 'ys_paynow_shipping_' ) !== false ) {
				$service_id = self::get_service_id_from_method( $method_id );
				if ( $service_id && ! isset( $enabled_services[ $service_id ] ) ) {
					$enabled_services[ $service_id ] = array(
						'id'   => $service_id,
						'name' => YSLogisticService::get_service_name( $service_id ),
					);
				}
			}
		}

		return array_values( $enabled_services );
	}

	/**
	 * 從 WC 運送方式 ID 取得 LogisticServiceId
	 *
	 * @param string $method_id 運送方式 ID。
	 * @return string|false 服務 ID 或 false。
	 */
	private static function get_service_id_from_method( $method_id ) {
		$service_mapping = array(
			'ys_paynow_shipping_711'              => YSLogisticService::SEVEN,
			'ys_paynow_shipping_family'           => YSLogisticService::FAMI,
			'ys_paynow_shipping_hilife'           => YSLogisticService::HILIFE,
			'ys_paynow_shipping_711_bulk'         => YSLogisticService::SEVENBULK,
			'ys_paynow_shipping_family_bulk'      => YSLogisticService::FAMIBULK,
			'ys_paynow_shipping_711_frozen'       => YSLogisticService::SEVENFROZEN_C2C,
			'ys_paynow_shipping_family_frozen'    => YSLogisticService::FAMIFROZEN_C2C,
			'ys_paynow_shipping_711_bulk_frozen'  => YSLogisticService::SEVENFROZEN,
			'ys_paynow_shipping_family_bulk_frozen' => YSLogisticService::FAMIFROZEN,
		);
		
		return isset( $service_mapping[ $method_id ] ) ? $service_mapping[ $method_id ] : false;
	}

	/*
	|--------------------------------------------------------------------------
	| 工具方法
	|--------------------------------------------------------------------------
	*/

	/**
	 * 檢查運送方式是否需要選擇超商
	 *
	 * @param string $method_id 運送方式 ID。
	 * @return bool
	 */
	public static function needs_cvs( $method_id ) {
		$cvs_methods = array(
			'ys_paynow_shipping_711',
			'ys_paynow_shipping_family',
			'ys_paynow_shipping_hilife',
			'ys_paynow_shipping_711_frozen',
			'ys_paynow_shipping_711_bulk',
			'ys_paynow_shipping_711_bulk_frozen',
			'ys_paynow_shipping_family_frozen',
			'ys_paynow_shipping_family_bulk',
			'ys_paynow_shipping_family_bulk_frozen',
		);
		return in_array( $method_id, $cvs_methods, true );
	}

	/**
	 * 檢查運送方式是否為 YS PayNow 物流
	 *
	 * @param string $method_id 運送方式 ID。
	 * @return bool
	 */
	public static function is_ys_paynow_shipping( $method_id ) {
		return strpos( $method_id, 'ys_paynow_shipping' ) === 0;
	}

	/**
	 * 檢查是否有任何 YS PayNow 超商取貨物流方式啟用
	 *
	 * 用於決定是否需要載入門市選擇器。
	 *
	 * @return bool
	 */
	public static function has_cvs_shipping_enabled() {
		// 檢查購物車是否需要運送
		if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return false;
		}

		// 取得可用的運送方式
		$packages = WC()->shipping()->get_packages();
		if ( empty( $packages ) ) {
			return false;
		}

		// 遍歷所有包裹的運送方式
		foreach ( $packages as $package ) {
			if ( empty( $package['rates'] ) ) {
				continue;
			}

			foreach ( $package['rates'] as $rate_id => $rate ) {
				$method_id = $rate->get_method_id();
				// 檢查是否為 YS PayNow 超商取貨
				if ( self::needs_cvs( $method_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * 取得運送方式對應的物流服務 ID
	 *
	 * @param string $method_id 運送方式 ID。
	 * @return string 物流服務 ID。
	 */
	public static function get_logistic_service_id( $method_id ) {
		$tcat_id = ( 'yes' === get_option( 'ys_paynow_shipping_tcat_own_code', 'no' ) ) ? '06' : '36';

		$mapping = array(
			'ys_paynow_shipping_711'          => YSLogisticService::SEVEN,
			'ys_paynow_shipping_family'       => YSLogisticService::FAMI,
			'ys_paynow_shipping_hilife'       => YSLogisticService::HILIFE,
			'ys_paynow_shipping_711_frozen'   => YSLogisticService::SEVENFROZEN_C2C,
			'ys_paynow_shipping_711_bulk'     => YSLogisticService::SEVENBULK,
			'ys_paynow_shipping_711_bulk_frozen' => YSLogisticService::SEVENFROZEN,
			'ys_paynow_shipping_family_frozen'   => YSLogisticService::FAMIFROZEN_C2C,
			'ys_paynow_shipping_family_bulk'     => YSLogisticService::FAMIBULK,
			'ys_paynow_shipping_family_bulk_frozen' => YSLogisticService::FAMIFROZEN,
			'ys_paynow_shipping_tcat_normal'  => $tcat_id,
			'ys_paynow_shipping_tcat_chilled' => $tcat_id,
			'ys_paynow_shipping_tcat_frozen'  => $tcat_id,
		);
		return isset( $mapping[ $method_id ] ) ? $mapping[ $method_id ] : '';
	}

	/**
	 * 取得訂單收件人電話
	 *
	 * 優先使用智慧收件人資訊（如有儲存），否則使用傳統邏輯。
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 電話號碼。
	 */
	public static function get_shipping_phone( $order ) {
		// 優先使用智慧收件人電話
		$smart_phone = $order->get_meta( '_ys_paynow_recipient_phone' );
		if ( ! empty( $smart_phone ) ) {
			return $smart_phone;
		}

		// 傳統邏輯：優先運送電話，否則帳單電話
		$phone = $order->get_shipping_phone();
		if ( empty( $phone ) ) {
			$phone = $order->get_billing_phone();
		}
		return $phone;
	}

	/**
	 * 取得訂單收件人姓名
	 *
	 * 優先使用智慧收件人資訊（如有儲存），否則使用傳統邏輯。
	 * 支援姓名順序設定（姓氏在前/名字在前）。
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 收件人姓名。
	 */
	public static function get_recipient_name( $order ) {
		// 優先使用智慧收件人姓名
		$smart_name = $order->get_meta( '_ys_paynow_recipient_name' );
		if ( ! empty( $smart_name ) ) {
			return $smart_name;
		}

		// 取得姓名順序設定
		$name_order = get_option( 'ys_paynow_shipping_name_order', 'last_first' );

		// 優先使用運送資訊
		$shipping_first = $order->get_shipping_first_name();
		$shipping_last  = $order->get_shipping_last_name();

		if ( ! empty( $shipping_first ) || ! empty( $shipping_last ) ) {
			return self::combine_name( $shipping_first, $shipping_last, $name_order );
		}

		// 使用帳單資訊
		$billing_first = $order->get_billing_first_name();
		$billing_last  = $order->get_billing_last_name();

		return self::combine_name( $billing_first, $billing_last, $name_order );
	}

	/**
	 * 組合姓名
	 *
	 * 根據設定的順序組合名字和姓氏。
	 * 如果只有一個欄位有值，直接返回該值。
	 *
	 * @param string $first_name 名字。
	 * @param string $last_name  姓氏。
	 * @param string $order      順序設定 (last_first 或 first_last)。
	 * @return string 組合後的姓名。
	 */
	private static function combine_name( $first_name, $last_name, $order = 'last_first' ) {
		$first_name = trim( $first_name );
		$last_name  = trim( $last_name );

		// 如果只有一個欄位有值，直接返回
		if ( empty( $first_name ) && ! empty( $last_name ) ) {
			return $last_name;
		}
		if ( ! empty( $first_name ) && empty( $last_name ) ) {
			return $first_name;
		}
		if ( empty( $first_name ) && empty( $last_name ) ) {
			return '';
		}

		// 根據設定順序組合
		if ( 'first_last' === $order ) {
			return $first_name . $last_name;
		}

		// 預設姓氏在前
		return $last_name . $first_name;
	}

	/**
	 * 記錄日誌
	 *
	 * @param string $message 日誌訊息。
	 * @param string $level   日誌等級 (info, error, warning)。
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		if ( ! self::$logger ) {
			self::$logger = wc_get_logger();
		}
		self::$logger->log( $level, $message, array( 'source' => 'ys-paynow-shipping' ) );
	}
}
