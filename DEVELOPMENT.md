# YS PayNow Shipping - 開發文件

> 本文件說明外掛完整架構、各檔案職責、資料流程與開發注意事項。

---

## 目錄

1. [架構總覽](#架構總覽)
2. [檔案結構與職責](#檔案結構與職責)
3. [核心流程](#核心流程)
4. [類別詳細說明](#類別詳細說明)
5. [資料結構](#資料結構)
6. [Hooks 參考](#hooks-參考)
7. [前端資源](#前端資源)
8. [開發注意事項](#開發注意事項)

---

## 架構總覽

```
命名空間：yangsheep\paynow\shipping
自動載入：PSR-4（Composer 或 fallback autoloader）
最低需求：PHP 7.4+ / WordPress 5.8+ / WooCommerce
HPOS：完全相容
```

### 架構模式

外掛採用**靜態工具類**模式，各子系統透過 `init()` 方法在主類別 `YSPaynowShipping::init()` 中統一初始化。

```
ys-paynow-shipping.php          ← 進入點（常數定義、autoload、WC 檢查）
  └─ YSPaynowShipping::init()   ← 主類別（載入所有子系統）
       ├─ YSStoreSelector::init()      ← 前台：超商選擇器
       ├─ YSShippingRequest::init()    ← API：建立物流單
       ├─ YSShippingResponse::init()   ← API：Webhook 回傳
       ├─ YSOrderMetaBox::init()       ← 後台：訂單 Meta Box
       ├─ YSOrderListTable::init()     ← 後台：訂單列表頁
       ├─ YSAdminPrint::init()         ← 後台：標籤列印
       ├─ YSOrderStatus::init()        ← 工具：自訂訂單狀態
       ├─ YSStatusUpdater::init()      ← 排程：CRON 貨態更新
       └─ YSMyAccount::init()          ← 前台：我的帳號
```

---

## 檔案結構與職責

```
ys-paynow-shipping/
├── ys-paynow-shipping.php              # 主外掛檔案（進入點）
├── composer.json                        # PSR-4 自動載入設定
├── index.php                            # 安全防護（空檔案）
├── assets/
│   ├── css/
│   │   ├── ys-paynow-admin.css         # 後台樣式（莫蘭迪配色）
│   │   └── ys-paynow-frontend.css      # 前台樣式（超商選擇器、物流卡片）
│   └── js/
│       ├── ys-paynow-admin.js          # 後台 JS（查詢狀態、批次列印）
│       └── ys-paynow-frontend.js       # 前台 JS（門市選擇、手機驗證）
└── src/
    ├── YSPaynowShipping.php            # 核心主類別（1624 行）
    ├── admin/
    │   ├── YSAdminPrint.php            # 標籤列印功能
    │   ├── YSOrderEdit.php             # 訂單編輯頁擴充
    │   ├── YSOrderListTable.php        # 訂單列表頁欄位與批次操作
    │   └── YSOrderMetaBox.php          # 訂單物流 Meta Box
    ├── api/
    │   ├── YSShippingRequest.php       # PayNow API 請求（建立/查詢/取消）
    │   └── YSShippingResponse.php      # PayNow Webhook 回傳處理
    ├── cron/
    │   └── YSStatusUpdater.php         # WP-Cron 排程貨態更新
    ├── frontend/
    │   ├── YSMyAccount.php             # 我的帳號物流狀態顯示
    │   └── YSStoreSelector.php         # 超商門市選擇器
    ├── gateways/
    │   └── YSGatewayPaynowCOD.php      # PayNow 貨到付款金流閘道
    ├── providers/
    │   ├── YSAbstractShipping.php      # 運送方式抽象基類
    │   ├── YSShipping711.php           # 7-11 交貨便 (C2C)
    │   ├── YSShipping711Bulk.php       # 7-11 大智通 (B2C)
    │   ├── YSShipping711BulkFrozen.php # 7-11 大智通冷凍
    │   ├── YSShipping711Frozen.php     # 7-11 冷凍 (C2C)
    │   ├── YSShippingFamily.php        # 全家店到店 (C2C)
    │   ├── YSShippingFamilyBulk.php    # 全家大宗寄倉 (B2C)
    │   ├── YSShippingFamilyBulkFrozen.php # 全家大宗冷凍
    │   ├── YSShippingFamilyFrozen.php  # 全家冷凍 (C2C)
    │   ├── YSShippingHilife.php        # 萊爾富
    │   ├── YSShippingTcatChilled.php   # 黑貓冷藏宅配
    │   ├── YSShippingTcatFrozen.php    # 黑貓冷凍宅配
    │   └── YSShippingTcatNormal.php    # 黑貓常溫宅配
    ├── settings/
    │   └── YSSettingsTab.php           # WooCommerce 設定頁籤
    └── utils/
        ├── YSLogisticService.php       # 物流服務代碼與名稱對應
        ├── YSOrderMeta.php             # 訂單 Meta 鍵常數
        ├── YSOrderStatus.php           # 自訂 WC 訂單狀態
        └── YSShippingStatus.php        # 物流狀態碼對應表
```

---

## 核心流程

### 結帳 → 物流單建立流程

```
[消費者結帳]
     │
     ├── 選擇運送方式（超商 / 宅配）
     │     └── 超商：點擊「選擇門市」→ PayNow 地圖 → 回傳門市資訊
     │
     ├── 選擇付款方式
     │     └── 若為超取：顯示 PayNow 貨到付款（YSGatewayPaynowCOD）
     │
     ├── 結帳驗證
     │     ├── 前端：手機號碼格式（09 開頭 10 碼）
     │     ├── 後端：YSPaynowShipping::validate_shipping_fields()
     │     └── 超取必填：門市代號、名稱、地址
     │
     ├── 建立訂單
     │     └── YSPaynowShipping::save_order_shipping_meta()
     │         儲存物流服務 ID、門市資訊、收件人電話等
     │
     └── 訂單狀態 → processing
           └── YSShippingRequest::create_logistic_order()
               ├── 建立加密請求 → 發送至 PayNow API
               ├── 成功：儲存 LogisticNumber, PaymentNo, ValidationNo
               ├── 觸發 do_action('ys_paynow_shipping_order_created')
               └── 失敗：記錄錯誤日誌
```

### 物流狀態更新流程

```
[貨態更新來源]
     │
     ├── PayNow Webhook（即時）
     │     └── POST /wc-api/ys-paynow-response/
     │         └── YSShippingResponse::handle_callback()
     │             ├── 解析 POST/$_POST 或 php://input
     │             ├── 更新訂單 meta（狀態碼、描述、時間）
     │             └── 自動對應 WC 訂單狀態
     │
     ├── CRON 排程（每 6 小時）
     │     └── YSStatusUpdater::process_status_update()
     │         ├── 查詢「已建立物流單但未完成」的訂單
     │         ├── 批次呼叫 PayNow 查詢 API
     │         └── 更新訂單 meta 與 WC 狀態
     │
     └── 管理員手動查詢
           └── AJAX ys_paynow_query_status
               └── YSShippingRequest::ajax_query_status()
```

### 物流狀態對應 WC 訂單狀態

```
PayNow 狀態碼          →   WC 訂單狀態
──────────────────────────────────────
100  訂單建立完成       →   shipping-ordered（已安排出貨）
200  寄件人已送達門市   →   shipping-transit（運送中）
300  貨物配送中         →   shipping-transit
400  貨物已到達取貨門市 →   shipping-arrived（已到店）
500  消費者已取貨       →   completed（已完成）
600  貨物退回中         →   shipping-transit
700  貨物已退回寄件人   →   shipping-returned（逾時退回）
900  訂單已取消         →   cancelled
```

---

## 類別詳細說明

### YSPaynowShipping — 核心主類別

**檔案**：`src/YSPaynowShipping.php`
**命名空間**：`yangsheep\paynow\shipping`

外掛的核心入口，負責初始化所有子系統、註冊 WooCommerce 運送方式/金流/設定頁，並處理結帳欄位與驗證。

| 靜態屬性 | 說明 |
|----------|------|
| `$api_url` | PayNow API 端點 |
| `$cvs_map_url` | 超商地圖 URL |
| `$user_account` | 商家帳號 |
| `$apicode` | API 密碼 |
| `$testmode` | 測試模式 |

| 主要方法 | 說明 |
|----------|------|
| `init()` | 初始化外掛，註冊所有 hooks |
| `add_shipping_methods($methods)` | 註冊 13 種運送方式 |
| `add_payment_gateway($gateways)` | 註冊 COD 金流 |
| `validate_shipping_fields()` | 結帳時驗證超取欄位與手機號碼 |
| `save_order_shipping_meta($order, $data)` | 建立訂單時儲存物流資訊 |
| `enqueue_frontend_scripts()` | 載入前台 CSS/JS |
| `enqueue_admin_scripts()` | 載入後台 CSS/JS |

---

### YSAbstractShipping — 運送方式基類

**檔案**：`src/providers/YSAbstractShipping.php`
**繼承**：`WC_Shipping_Method`

所有 PayNow 運送方式的共用基礎。定義設定欄位（啟用、標題、運費、免運條件）和運費計算邏輯。

| 屬性 | 說明 |
|------|------|
| `$logistic_service` | 物流服務代碼（由子類別設定） |
| `$free_shipping_requires` | 免運條件類型 |
| `$free_shipping_min_amount` | 免運金額門檻 |

**免運費邏輯**：`min_amount`（金額門檻）/ `coupon`（優惠券）/ `either`（擇一）/ `both`（皆需）

**子類別**僅需設定 `$this->id`、`$this->method_title`、`$this->logistic_service` 三個屬性，其餘邏輯由基類處理。

---

### YSShippingRequest — API 請求

**檔案**：`src/api/YSShippingRequest.php`

處理所有對 PayNow API 的請求：建立物流單、查詢狀態、取消訂單、重新取號。

| 方法 | 說明 |
|------|------|
| `create_logistic_order($order)` | 建立物流單（訂單進入 processing 時自動觸發） |
| `api_create_order($order)` | 呼叫 PayNow API 建立物流單 |
| `build_create_order_args($order)` | 建構 API 請求參數 |
| `build_encrypted_args($args)` | AES 加密請求參數 |
| `ajax_query_status()` | AJAX 查詢物流狀態 |
| `ajax_cancel_order()` | AJAX 取消物流單 |
| `ajax_reissue_order()` | AJAX 重新取號 |

**API 端點**：
- `POST /api/Orderapi/Add_Order` — 建立物流單
- `POST /api/Orderapi/Get_order_info` — 查詢狀態
- `POST /api/Orderapi/cancel_order` — 取消訂單

---

### YSShippingResponse — Webhook 處理

**檔案**：`src/api/YSShippingResponse.php`

接收 PayNow 主動推送的物流狀態變更通知。

| 方法 | 說明 |
|------|------|
| `handle_callback()` | 處理 Webhook 回傳（支援 POST 和 php://input 備援） |
| `update_order_status_by_logistic_code($order, $code)` | 根據物流碼自動更新 WC 訂單狀態 |

**Webhook 端點**：`/wc-api/ys-paynow-response/`

---

### YSStoreSelector — 超商選擇器

**檔案**：`src/frontend/YSStoreSelector.php`

處理前台結帳的超商門市選擇流程，包含地圖開啟、門市回傳、Session 管理。

| 方法 | 說明 |
|------|------|
| `display_store_selector_after_shipping()` | 在運送方式後顯示選擇按鈕 |
| `ajax_save_store()` | AJAX 儲存門市到 Session |
| `handle_cvs_callback()` | 處理超商地圖回調 |
| `save_store_to_order($order, $data)` | 儲存門市資訊到訂單 meta |
| `is_ys_paynow_cvs_method($method_id)` | 判斷是否為超取方式 |

---

### YSOrderMetaBox — 訂單 Meta Box

**檔案**：`src/admin/YSOrderMetaBox.php`

在訂單編輯頁顯示物流資訊（物流服務、門市、物流單號、狀態）與操作按鈕（查詢狀態、取消、重新取號、重選超商）。

---

### YSOrderListTable — 訂單列表頁

**檔案**：`src/admin/YSOrderListTable.php`

在訂單列表頁新增物流狀態欄位，並提供批次列印標籤功能。

---

### YSAdminPrint — 標籤列印

**檔案**：`src/admin/YSAdminPrint.php`

處理物流標籤的列印功能，支援單張與批次列印。

---

### YSStatusUpdater — CRON 排程

**檔案**：`src/cron/YSStatusUpdater.php`

WP-Cron 排程更新物流狀態。

| 設定 | 說明 |
|------|------|
| 預設間隔 | 6 小時 |
| 可設定選項 | `ys_paynow_shipping_cron_interval` |
| 日誌啟用 | `ys_paynow_shipping_cron_log_enabled` |

---

### YSGatewayPaynowCOD — 貨到付款閘道

**檔案**：`src/gateways/YSGatewayPaynowCOD.php`
**繼承**：`WC_Payment_Gateway`
**Gateway ID**：`ys_paynow_cod`

僅在選擇 `ys_paynow_shipping_*` 運送方式時可見。付款後訂單直接進入 `processing` 狀態。

---

### YSSettingsTab — 設定頁籤

**檔案**：`src/settings/YSSettingsTab.php`
**繼承**：`WC_Settings_Page`
**位置**：WooCommerce > 設定 > YS PayNow

設定區段：基本設定、物流設定、寄件人資訊、進階設定、Webhook 資訊、CRON 日誌。

---

### 工具類別（utils/）

| 類別 | 說明 |
|------|------|
| `YSOrderMeta` | 訂單 Meta 鍵常數定義（`_ys_paynow_*`） |
| `YSLogisticService` | 物流服務代碼常數與名稱對應 |
| `YSOrderStatus` | 自訂 WC 訂單狀態註冊（已安排出貨、運送中、已到店、逾時退回） |
| `YSShippingStatus` | PayNow 物流狀態碼 ↔ WC 訂單狀態對應表 |

---

## 資料結構

### 訂單 Meta 鍵

```php
// 超商資訊
_ys_paynow_store_id              // 門市代號
_ys_paynow_store_name            // 門市名稱
_ys_paynow_store_addr            // 門市地址
_ys_paynow_store_tel             // 門市電話
_ys_paynow_store_data_json       // 完整門市 JSON

// 物流服務
_ys_paynow_logistic_service_id   // 物流服務代碼
_ys_paynow_logistic_service      // 物流服務名稱
_ys_paynow_logistic_number       // 物流單號
_ys_paynow_payment_no            // 託運單號
_ys_paynow_validation_no         // 驗證碼

// 物流狀態
_ys_paynow_sno                   // 物流單序號
_ys_paynow_status                // 狀態
_ys_paynow_delivery_status       // 配送狀態
_ys_paynow_logistic_code         // 物流狀態碼
_ys_paynow_detail_status_desc    // 狀態描述
_ys_paynow_status_update_at      // 最後更新時間

// 宅配專用
_ys_paynow_delivery_type         // 溫層（01 常溫 / 02 冷藏 / 03 冷凍）
_ys_paynow_delivery_time         // 配送時段

// 重新取號
_ys_paynow_renew_order_no        // 重新取號後的訂單編號
_ys_paynow_retry_count           // 重試取號次數
_ys_paynow_original_order_no     // 原始訂單編號（重新取號前）

// 全家冷凍專用
_ys_paynow_reserved_no           // 保留編號（全家冷凍 C2C 必填）
_ys_paynow_ship_date             // 預計運送日期

// 到店資訊
_ys_paynow_store_date            // 到店日期
_ys_paynow_store_time            // 到店時間

// API 回傳
_ys_paynow_return_msg            // API 回傳訊息
```

### 物流服務代碼

| 代碼 | 常數 | 說明 |
|------|------|------|
| `01` | `SEVEN` | 7-11 交貨便 (C2C) |
| `02` | `SEVENBULK` | 7-11 大智通 (B2C) |
| `03` | `FAMI` | 全家店到店 (C2C) |
| `04` | `FAMIBULK` | 全家大宗 (B2C) |
| `05` | `HILIFE` | 萊爾富 |
| `21` | `SEVENFROZEN_C2C` | 7-11 冷凍 (C2C) |
| `22` | `SEVENFROZEN` | 7-11 冷凍 (B2C) |
| `23` | `FAMIFROZEN_C2C` | 全家冷凍 (C2C) |
| `24` | `FAMIFROZEN` | 全家冷凍 (B2C) |
| `36` | `TCAT` | 黑貓宅配 |

### 自訂訂單狀態

| 狀態 | Slug | 顏色 |
|------|------|------|
| 已安排出貨 | `wc-shipping-ordered` | 莫蘭迪淡藍 `#e8eff5` |
| 運送中 | `wc-shipping-transit` | 莫蘭迪淡黃 `#fef6e8` |
| 已到達取貨門市 | `wc-shipping-arrived` | 莫蘭迪淡紫 `#f3e5f5` |
| 逾時退回 | `wc-shipping-returned` | 莫蘭迪淡紅 `#ffebee` |

---

## Hooks 參考

### 自訂 Action Hooks

```php
// 物流單建立成功時觸發
do_action( 'ys_paynow_shipping_order_created', $order, $response );

// 物流狀態更新時觸發
do_action( 'ys_paynow_shipping_status_updated', $order, $status_code );

// 後台超商變更後觸發（自動重建物流單）
do_action( 'ys_paynow_after_admin_changed_store', $order_id, $store_data );
```

### AJAX 端點

| Action | 方法 | 說明 |
|--------|------|------|
| `ys_paynow_save_store` | POST | 儲存超商選擇 |
| `ys_paynow_query_status` | POST | 查詢物流狀態 |
| `ys_paynow_cancel_order` | POST | 取消物流單 |
| `ys_paynow_reissue_order` | POST | 重新取號 |
| `ys_paynow_manual_create` | POST | 手動建立物流單 |
| `ys_paynow_get_map_url` | POST | 取得超商地圖 URL |
| `ys_paynow_print_label` | POST | 列印標籤 |
| `ys_paynow_admin_change_store` | POST | 後台重選超商 |
| `ys_paynow_get_cron_log` | POST | 取得 CRON 日誌 |
| `ys_paynow_clear_cron_log` | POST | 清除 CRON 日誌 |

### WC API 端點

| 端點 | 類別 | 說明 |
|------|------|------|
| `/wc-api/ys-paynow-response/` | `YSShippingResponse` | Webhook 回傳 |
| `/wc-api/ys-paynow-cvs-callback/` | `YSStoreSelector` | 超商地圖回調 |
| `/wc-api/ys-paynow-cvs-admin-callback/` | `YSOrderMetaBox` | 後台超商變更回調 |

---

## 前端資源

### ys-paynow-frontend.js

**主要物件**：`YSPaynowStoreSelector`

| 方法 | 說明 |
|------|------|
| `init()` | 初始化 |
| `getSelectedShippingMethod()` | 取得當前運送方式 |
| `isCVSMethod(methodId)` | 判斷是否為超取 |
| `handleShippingMethodChange()` | 運送方式切換時顯示/隱藏選擇器 |
| `openStoreMap()` | 開啟 PayNow 超商地圖 |
| `saveStore(data)` | AJAX 儲存門市 |
| `displayStore(data)` | 顯示已選門市卡片 |
| `restoreCheckoutFieldsFromSession()` | 恢復結帳欄位（sessionStorage） |
| `initMobilePhoneValidation()` | 手機號碼驗證初始化 |
| `validateMobilePhone($input)` | 驗證格式：`/^09\d{8}$/` |

### ys-paynow-admin.js

**主要物件**：`YSPaynowAdmin`

功能：查詢物流狀態、取消物流單、重新取號、重選超商、批次列印標籤、手動建立物流單。

### 配色系統（莫蘭迪淡藍灰）

```css
--ys-primary:       #8fa8b8;    /* 主色 */
--ys-primary-hover: #7a95a6;    /* 主色 hover */
--ys-primary-light: #e8eff5;    /* 主色淡 */
--ys-border:        #c5d1d8;    /* 邊框 */
--ys-bg-light:      #f5f8fa;    /* 淡背景 */
--ys-text:          #333;       /* 主文字 */
--ys-text-muted:    #6b8a9a;    /* 次要文字 */
```

---

## 開發注意事項

### 命名規範

| 類型 | 前綴 | 範例 |
|------|------|------|
| 類別 | `YS` | `YSPaynowShipping` |
| 函數/Hook | `ys_` | `ys_paynow_shipping_order_created` |
| Meta Key | `_ys_paynow_` | `_ys_paynow_store_id` |
| Option Key | `ys_paynow_shipping_` | `ys_paynow_shipping_testmode` |
| 常數 | `YS_PAYNOW_SHIPPING_` | `YS_PAYNOW_SHIPPING_VERSION` |
| AJAX Action | `ys_paynow_` | `ys_paynow_query_status` |
| CSS Class | `ys-paynow-` | `ys-paynow-store-selector-wrapper` |

### HPOS 相容性（必遵守）

```php
// 正確 — 使用 $order 物件方法
$value = $order->get_meta( '_ys_paynow_store_id' );
$order->update_meta_data( '_ys_paynow_store_id', $value );
$order->save();

// 錯誤 — 禁止使用 post meta
$value = get_post_meta( $order_id, '_ys_paynow_store_id', true );
```

### 安全性

- 所有 AJAX 端點使用 `wp_verify_nonce()` 驗證
- 所有輸入使用 `sanitize_text_field()` / `wp_unslash()` 清理
- 使用 `current_user_can( 'manage_woocommerce' )` 檢查權限
- HTML 輸出使用 `wp_kses_post()` / `esc_html()` / `esc_attr()`

### 日誌記錄

```php
// 標準日誌（寫入 WooCommerce 日誌）
YSPaynowShipping::log( '訊息', 'info' );    // info / error / warning

// CRON 專用日誌
YSStatusUpdater::cron_log( '訊息', 'info' );
```

### 新增運送方式

1. 在 `src/providers/` 建立新類別，繼承 `YSAbstractShipping`
2. 設定 `$this->id`、`$this->method_title`、`$this->logistic_service`
3. 在 `YSPaynowShipping::add_shipping_methods()` 中註冊

```php
class YSShippingNewProvider extends YSAbstractShipping {
    public function __construct( $instance_id = 0 ) {
        $this->id              = 'ys_paynow_shipping_new';
        $this->method_title    = __( 'PayNow 新運送方式', 'ys-paynow-shipping' );
        $this->logistic_service = YSLogisticService::NEW_CODE;
        parent::__construct( $instance_id );
    }
}
```

### 跨外掛整合

本外掛與 `yangsheep-checkout-optimizer` 外掛有整合：
- 結帳強化外掛透過 `class_exists('yangsheep\paynow\shipping\YSPaynowShipping')` 偵測本外掛
- 物流狀態卡片、進度條由結帳強化外掛渲染
- 手動配送 Meta Box 在 PayNow 物流訂單中自動隱藏

---

## 外掛常數

```php
YS_PAYNOW_SHIPPING_VERSION       // 外掛版本
YS_PAYNOW_SHIPPING_PLUGIN_URL    // 外掛 URL（含尾端斜線）
YS_PAYNOW_SHIPPING_PLUGIN_DIR    // 外掛目錄路徑（含尾端斜線）
YS_PAYNOW_SHIPPING_BASENAME      // 外掛基名（用於 action links）
```

---

*最後更新：2026-03-12*
