=== YS PAYNOW VIA WOOCOMMERCE ===
Contributors: yangsheepdesign
Tags: woocommerce, shipping, paynow, 超商取貨, 物流, 7-11, 全家, 萊爾富, 黑貓
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

整合 PayNow 物流服務至 WooCommerce，支援 7-11、全家、萊爾富超商取貨與黑貓宅配。

== Description ==

YS PAYNOW VIA WOOCOMMERCE 是一個 WooCommerce 物流外掛，整合台灣 PayNow 立吉富物流服務。

**支援的物流方式：**

* 7-11 交貨便 (C2C 常溫/冷凍)
* 7-11 大宗寄倉 (B2C 常溫/冷凍)
* 全家店到店 (C2C 常溫/冷凍)
* 全家大宗寄倉 (B2C 常溫/冷凍)
* 萊爾富 (超商取貨)
* 黑貓宅配 (常溫/冷藏/冷凍)

**功能特色：**

* 結帳頁超商選擇器
* 智慧收件人資訊處理
* 訂單成立自動建立物流單
* 後台訂單物流資訊顯示
* 物流狀態自動同步
* 批次列印托運單
* 支援 WooCommerce HPOS
* 莫蘭迪淡藍色系介面設計

== Installation ==

1. 上傳 `ys-paynow-shipping` 資料夾至 `/wp-content/plugins/` 目錄
2. 在 WordPress 後台「外掛」頁面啟用外掛
3. 前往 WooCommerce > 設定 > PayNow 物流 進行設定
4. 輸入您的 PayNow 商家帳號與 API 密碼
5. 在 WooCommerce > 設定 > 運送 新增運送區域並選擇 PayNow 運送方式

== Frequently Asked Questions ==

= 如何取得 PayNow API 帳號？ =

請至 PayNow 立吉富官網 (https://www.paynow.com.tw) 註冊商家帳號。

= 支援貨到付款嗎？ =

是的，超商取貨支援貨到付款（取貨付款）模式。

= 可以與其他結帳外掛搭配使用嗎？ =

本外掛設計時考量與 YANGSHEEP 結帳優化外掛的整合，但也可獨立運作。

== Changelog ==

= 1.1.0 - 2026-01-11 =
* 重構 - 門市選擇器架構重寫，採用統一中央選擇器模式
* 新增 - 物流變更自動清除門市資料
* 新增 - PayUni 衝突處理機制
* 新增 - 條件載入選擇器功能
* 新增 - AJAX Fragment 支援

= 1.0.9 - 2026-01-11 =
* 修復 - 門市選擇器重複顯示問題
* 修復 - 門市選擇器位置錯誤問題

= 1.0.8 - 2026-01-11 =
* 修復 - 門市選擇器重複顯示
* 修復 - 非 YS PayNow 物流時顯示選擇器的問題

= 1.0.7 - 2026-01-11 =
* 新增 - 超商取貨結帳行為設定
* 新增 - 智慧收件人資訊功能
* 新增 - 統一超商選擇器位置
* 改進 - 超商類型切換自動清理

= 1.0.6 - 2026-01-11 =
* 新增 - 自身物流切換清理功能

= 1.0.3 - 2026-01-11 =
* 修復 - 門市選取後跑版問題
* 改進 - 門市資訊樣式重新設計

= 1.0.2 - 2026-01-10 =
* 改進 - 設定頁面重新設計
* 新增 - 前端頁籤切換功能

= 1.0.1 - 2026-01-10 =
* 維護性更新
* 確認與結帳外掛整合正確

= 1.0.0 - 2026-01-08 =
* 初始版本發布
* 支援 7-11、全家、萊爾富超商取貨
* 支援黑貓宅配
* WooCommerce HPOS 相容

== Upgrade Notice ==

= 1.1.0 =
重大架構更新，門市選擇器重寫，建議更新前備份。

= 1.0.0 =
初始版本，請確認已安裝 WooCommerce。
