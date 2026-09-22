-- Migration: HPP (harga pokok / COGS) rata-rata bergerak saat pembelian stok
--
-- Setiap purchase_add menghitung ulang HPP tiap produk dengan MOVING AVERAGE:
--   cogs_after = (stock_before * cogs_before + qty * harga_beli) / (stock_before + qty)
-- lalu menyimpannya ke ms_product.product_cogs. Nilai sebelum/sesudah dicatat di
-- purchase_detail + product_cogs_history supaya keuntungan bisa dihitung
-- (harga jual - HPP saat itu). purchase_cancel membalik perhitungan (perkiraan).
--
-- Jalankan bagian [BILLING_API] di database billing_flutter, dan bagian [GAMEON]
-- di database gameon (server pusat / laporan online).

-- ============================ [BILLING_API] billing_flutter ============================

ALTER TABLE `purchase_detail`
  ADD COLUMN `cogs_before` INT(11) NOT NULL DEFAULT 0 AFTER `price`,
  ADD COLUMN `cogs_after`  INT(11) NOT NULL DEFAULT 0 AFTER `cogs_before`;

CREATE TABLE IF NOT EXISTS `product_cogs_history` (
  `product_cogs_history_id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id`   INT(11) NOT NULL,
  `product_name` VARCHAR(250) NOT NULL,
  `cogs_before`  INT(11) NOT NULL DEFAULT 0,
  `cogs_after`   INT(11) NOT NULL DEFAULT 0,
  `stock_before` INT(11) NOT NULL DEFAULT 0,
  `stock_after`  INT(11) NOT NULL DEFAULT 0,
  `qty`          INT(11) NOT NULL DEFAULT 0,
  `buy_price`    INT(11) NOT NULL DEFAULT 0,
  `ref_type`     VARCHAR(50) NOT NULL,
  `ref_id`       INT(11) DEFAULT NULL,
  `branch`       INT(11) NOT NULL DEFAULT 1,
  `created_by`   VARCHAR(100) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_cogs_history_id`),
  KEY `idx_pch_product` (`product_id`),
  KEY `idx_pch_ref` (`ref_type`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================== [GAMEON] gameon ==============================

ALTER TABLE `branch_purchase_detail`
  ADD COLUMN `cogs_before` INT(11) NOT NULL DEFAULT 0 AFTER `price`,
  ADD COLUMN `cogs_after`  INT(11) NOT NULL DEFAULT 0 AFTER `cogs_before`;
