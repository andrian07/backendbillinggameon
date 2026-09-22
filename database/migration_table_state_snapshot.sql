-- Migration: SNAPSHOT state semua meja cabang ke laporan online (gameon) - "papan meja live"
--
-- Beda dari table_status_event (event open/close, berbasis histori): ini state PENUH
-- semua meja cabang, di-push tiap kali `table_active` berubah (Billing::book_table /
-- payment / cancel_table / move_table / add_duration / round_up_duration /
-- edit_table_active / reset_table) dan MENIMPA baris di gameon per (branch, table_id).
--
-- Karena tiap push berisi seluruh papan, kegagalan sesaat sembuh sendiri di aksi meja
-- berikutnya. Untuk kasus cabang langsung idle setelah push gagal, `table_state_sync`
-- menandai `dirty='Y'` supaya bisa diulang dari halaman "Sinkron Online"
-- (Sync::pending / Sync::retry, type = 'table_snapshot', id = branch).
--
-- Jalankan bagian [BILLING_API] di database billing_flutter, dan bagian [GAMEON]
-- di database gameon (server pusat / laporan online).

-- ============================ [BILLING_API] billing_flutter ============================

-- 1 baris per cabang: status push snapshot terakhir. dirty='Y' = ada perubahan meja
-- yang belum sampai ke gameon.
CREATE TABLE IF NOT EXISTS `table_state_sync` (
  `branch` INT(11) NOT NULL,
  `dirty` ENUM('Y','N') NOT NULL DEFAULT 'N',
  `last_snapshot_at` DATETIME DEFAULT NULL,
  `last_ok_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================== [GAMEON] gameon ==============================

-- state meja terkini per cabang (dipakai laporan online). Di-upsert per (branch, table_id)
-- oleh Internal::receive_table_snapshot. Baris dengan `snapshot_at` lebih lama dari yang
-- tersimpan diabaikan (anti out-of-order kalau ada retry telat).
CREATE TABLE IF NOT EXISTS `branch_table_state` (
  `branch` INT(11) NOT NULL,
  `table_id` INT(11) NOT NULL,
  `table_number` VARCHAR(100) DEFAULT NULL,
  -- 1 = meja sedang dipakai, 0 = kosong
  `in_use` TINYINT(1) NOT NULL DEFAULT 0,
  -- 'Timer' | 'Reguler' (NULL kalau meja kosong)
  `mode` VARCHAR(20) DEFAULT NULL,
  `customer_id` INT(11) DEFAULT NULL,
  `customer_name` VARCHAR(250) DEFAULT NULL,
  -- promo yang dipakai sesi ini (NULL / 0 = tanpa promo)
  `promo_id` INT(11) DEFAULT NULL,
  `promo_name` VARCHAR(150) DEFAULT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `category_name` VARCHAR(150) DEFAULT NULL,
  -- jam mulai sesi
  `start_time` DATETIME DEFAULT NULL,
  -- Timer: jam waktu habis (target hitung mundur). Reguler: NULL (open-ended)
  `end_time` DATETIME DEFAULT NULL,
  `duration` TIME DEFAULT NULL,
  `use_saved_time` ENUM('Y','N') DEFAULT 'N',
  `bill` INT(11) NOT NULL DEFAULT 0,
  -- waktu snapshot dibuat di cabang - dipakai untuk menolak update yang datang telat
  `snapshot_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch`, `table_id`),
  KEY `idx_bts_in_use` (`branch`, `in_use`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
