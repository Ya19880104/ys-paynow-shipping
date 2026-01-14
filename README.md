# YS PayNow Shipping for WooCommerce

整合 PayNow 物流服務至 WooCommerce，支援超商取貨與宅配。

## 版本資訊

**當前版本**：1.1.0
**最後更新**：2026-01-12
**開發者**：羊羊數位科技有限公司（YANGSHEEP DESIGN）
**網站**：https://yangsheep.com.tw
**命名空間**：`YangSheep\PayNow\Shipping`

---

## 功能特色

### 1. 超商取貨
- **7-11 交貨便（C2C）** - 店到店
- **7-11 大智通（B2C）** - 大宗寄倉
- **7-11 冷凍** - 冷凍店到店、冷凍大宗寄倉
- **全家店到店（C2C）**
- **全家大宗寄倉（B2C）**
- **全家冷凍** - 冷凍店到店、冷凍大宗寄倉
- **萊爾富 Hi-Life**

### 2. 黑貓宅配
- **常溫宅配** - TCat Normal
- **冷藏宅配** - TCat Chilled
- **冷凍宅配** - TCat Frozen

### 3. 貨到付款支援
- **PayNow 超取付款** - 超商取貨付款整合
- 自動偵測超取物流，限制僅顯示 PayNow 超取付款

### 4. 後台訂單管理
- **訂單 Meta Box** - 顯示物流狀態、物流單號、門市資訊
- **一鍵建立託運單** - 支援 API 串接自動建立
- **批次列印標籤** - 訂單列表頁批次操作
- **貨態即時更新** - Cron 排程自動更新物流狀態

### 5. 前台功能
- **門市選擇器** - 超商地圖選擇門市
- **CVS 欄位自動填入** - 門市名稱、代號、地址
- **我的帳號物流查詢** - 訂單詳情頁顯示物流狀態
- **手機號碼驗證** - 台灣手機格式驗證（09 開頭、10 位數字）

### 6. 結帳欄位處理
- 超取時自動隱藏帳單地址欄位
- 填入預設值通過 WooCommerce 驗證
- 支援 Block Checkout 區塊結帳
- 門市選擇後自動恢復已填欄位

---

## 檔案結構

```
ys-paynow-shipping/
├── assets/
│   ├── css/
│   │   └── ys-paynow-admin.css          # 後台樣式
│   └── js/
│       ├── ys-paynow-frontend.js        # 前台 JS（門市選擇、手機驗證）
│       └── ys-paynow-admin.js           # 後台 JS
├── src/
│   ├── Admin/
│   │   ├── YSAdminPrint.php             # 標籤列印
│   │   ├── YSOrderEdit.php              # 訂單編輯頁
│   │   ├── YSOrderListTable.php         # 訂單列表頁
│   │   └── YSOrderMetaBox.php           # 物流 Meta Box
│   ├── Api/
│   │   ├── YSShippingRequest.php        # API 請求（建立託運單）
│   │   └── YSShippingResponse.php       # API 回應（回傳處理）
│   ├── Cron/
│   │   └── YSStatusUpdater.php          # 排程更新物流狀態
│   ├── Frontend/
│   │   ├── YSMyAccount.php              # 我的帳號頁面
│   │   └── YSStoreSelector.php          # 門市選擇器
│   ├── Gateways/
│   │   └── WCGatewayPaynowCOD.php       # PayNow 超取付款閘道
│   ├── Providers/
│   │   ├── YSAbstractShipping.php       # 物流抽象類別
│   │   ├── YSShipping711.php            # 7-11 交貨便
│   │   ├── YSShipping711Bulk.php        # 7-11 大智通
│   │   ├── YSShipping711BulkFrozen.php  # 7-11 大智通冷凍
│   │   ├── YSShipping711Frozen.php      # 7-11 冷凍
│   │   ├── YSShippingFamily.php         # 全家店到店
│   │   ├── YSShippingFamilyBulk.php     # 全家大宗寄倉
│   │   ├── YSShippingFamilyBulkFrozen.php # 全家大宗冷凍
│   │   ├── YSShippingFamilyFrozen.php   # 全家冷凍
│   │   ├── YSShippingHilife.php         # 萊爾富
│   │   ├── YSShippingTcatChilled.php    # 黑貓冷藏
│   │   ├── YSShippingTcatFrozen.php     # 黑貓冷凍
│   │   └── YSShippingTcatNormal.php     # 黑貓常溫
│   ├── Settings/
│   │   └── YSSettingsTab.php            # WooCommerce 設定頁籤
│   ├── Utils/
│   │   ├── YSLogisticService.php        # 物流服務工具
│   │   ├── YSOrderMeta.php              # 訂單 Meta 常數
│   │   ├── YSOrderStatus.php            # 訂單狀態工具
│   │   └── YSShippingStatus.php         # 物流狀態對應
│   └── YSPaynowShipping.php             # 主要類別
├── templates/
│   └── checkout/
│       └── store-selector.php           # 門市選擇器模板
├── languages/
│   └── ys-paynow-shipping.pot           # 翻譯模板
├── vendor/                              # Composer 依賴
├── README.md                            # 本檔案
└── ys-paynow-shipping.php               # 主外掛檔案
```

---

## 物流方式與 Method ID

| 物流類型 | Method ID | 說明 |
|---------|-----------|------|
| 7-11 交貨便 | `ys_paynow_shipping_711` | C2C 店到店 |
| 7-11 大智通 | `ys_paynow_shipping_711_bulk` | B2C 大宗寄倉 |
| 7-11 冷凍 | `ys_paynow_shipping_711_frozen` | C2C 冷凍 |
| 7-11 大宗冷凍 | `ys_paynow_shipping_711_bulk_frozen` | B2C 冷凍 |
| 全家店到店 | `ys_paynow_shipping_family` | C2C |
| 全家大宗寄倉 | `ys_paynow_shipping_family_bulk` | B2C |
| 全家冷凍 | `ys_paynow_shipping_family_frozen` | C2C 冷凍 |
| 全家大宗冷凍 | `ys_paynow_shipping_family_bulk_frozen` | B2C 冷凍 |
| 萊爾富 | `ys_paynow_shipping_hilife` | C2C |
| 黑貓常溫 | `ys_paynow_shipping_tcat_normal` | 宅配 |
| 黑貓冷藏 | `ys_paynow_shipping_tcat_chilled` | 宅配 |
| 黑貓冷凍 | `ys_paynow_shipping_tcat_frozen` | 宅配 |

---

## 訂單 Meta 欄位

```php
// 檔案：src/Utils/YSOrderMeta.php

const LOGISTIC_SERVICE_ID = '_ys_logistic_service_id';  // 物流服務 ID
const LOGISTIC_SERVICE    = '_ys_logistic_service';     // 物流服務名稱
const LOGISTIC_NUMBER     = '_ys_logistic_number';      // 物流單號
const TRACKING_NO         = '_ys_tracking_no';          // 追蹤號碼
const STATUS              = '_ys_shipping_status';      // 物流狀態
const STATUS_DESC         = '_ys_shipping_status_desc'; // 狀態描述
const STATUS_TIME         = '_ys_shipping_status_time'; // 狀態時間
const STORE_ID            = '_ys_store_id';             // 門市代號
const STORE_NAME          = '_ys_store_name';           // 門市名稱
const STORE_ADDRESS       = '_ys_store_address';        // 門市地址
```

---

## 核心類別說明

### YSPaynowShipping
主要類別，負責：
- 初始化所有子系統
- 註冊物流方式到 WooCommerce
- 載入前後端資源

### YSAbstractShipping
物流抽象類別，提供：
- 共用的物流設定欄位
- 運費計算邏輯
- 可用性判斷（重量、金額、地區限制）

### YSStoreSelector
門市選擇器，處理：
- 超商地圖跳轉
- 門市資料回傳接收
- CVS 欄位動態控制
- 結帳欄位保存與恢復（sessionStorage）

### YSShippingRequest
API 請求類別：
- 建立託運單 API 呼叫
- 標籤列印 API
- 錯誤處理與重試

### YSShippingResponse
API 回應處理：
- 物流商回傳通知（Callback）
- 貨態更新儲存
- 訂單狀態自動變更

### YSStatusUpdater
排程更新：
- WP-Cron 排程查詢物流狀態
- 批次更新未完成訂單
- 貨態變更通知

---

## 手機號碼驗證機制

### 前端驗證（JS）
位置：`assets/js/ys-paynow-frontend.js`

```javascript
initMobilePhoneValidation: function() {
    // 綁定 input/blur/submit 事件
},

validateMobilePhone: function($input, showError) {
    // 驗證格式：必須是 09 開頭的 10 位數字
    var isValid = /^09\d{8}$/.test(numericValue);
    // ...
}
```

### 後端驗證（PHP）
位置：`src/YSPaynowShipping.php`

```php
add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_shipping_fields' ) );

public static function validate_shipping_fields() {
    // 驗證 shipping_phone 格式
    if ( ! preg_match( '/^09\d{8}$/', $phone ) ) {
        wc_add_notice( '錯誤訊息', 'error' );
    }
}
```

---

## CVS 欄位自動隱藏

選擇超取物流時，自動處理：

1. **隱藏地址欄位**（可在設定中開關）
   - `shipping_postcode`、`shipping_state`、`shipping_city`
   - `shipping_address_1`、`shipping_address_2`
   - `billing_*` 相關欄位

2. **填入預設值**
   - `country` = `TW`
   - `address_1` = `N/A` 或 `超商取貨`

3. **移除必填驗證**
   - 動態設定 `required = false`
   - 移除 `validate-required` class

4. **Block Checkout 支援**
   - 透過 `wp.data.dispatch('wc/store/cart')` 操作
   - 備用 DOM 直接操作方案

---

## 與其他外掛的整合

### YANGSHEEP 結帳強化外掛
- 物流狀態卡片顯示（前台訂單詳情）
- 貨態進度條
- 物流單號複製功能
- 手動配送 Meta Box 自動隱藏（YS PayNow 物流訂單不顯示）

### 相容性注意
- 保留標準 WooCommerce Action Hooks
- 使用標準 `shipping_method[...]` input name
- 支援 HPOS（High-Performance Order Storage）

---

## 設定選項

WooCommerce > 設定 > 運送 > YS PayNow

| 設定項目 | 說明 |
|---------|------|
| API 金鑰 | PayNow 商店 API Key |
| 商店代號 | PayNow 商店代號 |
| 測試模式 | 開啟時使用測試環境 |
| 隱藏帳單地址 | 超取時隱藏帳單地址欄位 |
| 隱藏運送地址 | 超取時隱藏運送地址欄位 |
| 預設寄件人姓名 | 標籤列印用 |
| 預設寄件人電話 | 標籤列印用 |
| 預估出貨日期 | 黑貓宅配預設出貨天數 |

---

## 版本紀錄

### v1.1.0 (2026-01-12)
- **新增**：手機號碼驗證（09 開頭、10 位數字）
- **新增**：前端 JS 即時驗證 + 後端 PHP 驗證
- **優化**：門市選擇後自動恢復已填欄位（sessionStorage）
- **優化**：Block Checkout 支援

### v1.0.9 (2026-01-10)
- **新增**：全家冷凍大宗寄倉
- **新增**：7-11 冷凍大宗寄倉
- **優化**：貨態對應表更新

### v1.0.8 (2026-01-08)
- **新增**：我的帳號物流狀態顯示
- **新增**：貨態進度條 UI
- **優化**：排程更新效能

### v1.0.5 (2026-01-05)
- **新增**：黑貓宅配（常溫/冷藏/冷凍）
- **新增**：訂單列表頁批次列印
- **修復**：API 逾時處理

### v1.0.0 (2026-01-01)
- **首發**：7-11、全家、萊爾富超商取貨
- **首發**：PayNow 超取付款閘道
- **首發**：後台訂單管理功能

---

## 開發者

羊羊數位科技有限公司（YANGSHEEP DESIGN）
https://yangsheep.com.tw
