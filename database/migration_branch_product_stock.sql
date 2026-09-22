-- Migration: SNAPSHOT stok & HPP/COGS katalog produk cabang ke laporan online (gameon)
--
-- Sama pola dengan migration_table_state_snapshot.sql ("papan meja live"): ini state PENUH
-- seluruh katalog produk aktif cabang, di-push tiap 30 menit oleh ProductStockSyncWatcher
-- (billinggameon, lihat Master::sync_product_stock()) dan MENIMPA baris di gameon per
-- (branch, product_id) - jadi selalu mencerminkan stok & HPP terbaru, bukan cuma di titik
-- terjadinya transaksi.
--
-- Karena tiap push berisi seluruh katalog, kegagalan sesaat sembuh sendiri di tick 30 menit
-- berikutnya. `product_stock_sync` menandai `dirty='Y'` supaya juga bisa diulang manual dari
-- halaman "Sinkron Online" (Sync::pending / Sync::retry, type = 'product_stock', id = branch).
--
-- Jalankan bagian [BILLING_API] di database billing_flutter, dan bagian [GAMEON]
-- di database gameon (server pusat / laporan online).

-- ============================ [BILLING_API] billing_flutter ============================

-- 1 baris per cabang: status push snapshot stok terakhir. dirty='Y' = push terakhir gagal,
-- belum sampai ke gameon.
CREATE TABLE IF NOT EXISTS `product_stock_sync` (
  `branch` INT(11) NOT NULL,
  `dirty` ENUM('Y','N') NOT NULL DEFAULT 'N',
  `last_snapshot_at` DATETIME DEFAULT NULL,
  `last_ok_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================== [GAMEON] gameon ==============================

-- stok & HPP/COGS produk terkini per cabang (dipakai laporan online). Di-upsert per
-- (branch, product_id) oleh Internal::receive_product_stock(). Baris dengan `snapshot_at`
-- lebih lama dari yang tersimpan diabaikan (anti out-of-order kalau ada retry telat).
CREATE TABLE IF NOT EXISTS `branch_product_stock` (
  `branch` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `product_code` VARCHAR(100) DEFAULT NULL,
  `product_name` VARCHAR(250) DEFAULT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `category_name` VARCHAR(150) DEFAULT NULL,
  `unit_name` VARCHAR(100) DEFAULT NULL,
  `stock` INT(11) NOT NULL DEFAULT 0,
  -- HPP / COGS per unit
  `cogs` INT(11) NOT NULL DEFAULT 0,
  `price` INT(11) NOT NULL DEFAULT 0,
  -- waktu snapshot dibuat di cabang - dipakai untuk menolak update yang datang telat
  `snapshot_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch`, `product_id`),
  KEY `idx_bps_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
