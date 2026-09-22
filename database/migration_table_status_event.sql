-- Migration: status meja (buka/tutup) ikut di-curl ke laporan online (gameon)
--
-- Setiap kali meja dibuka (Billing::book_table) atau ditutup (Billing::payment /
-- Billing::cancel_table), 1 baris event dicatat di sini lalu di-push ke gameon
-- (Internal::receive_table_status) - sama pola idempoten/retry seperti transaction,
-- transaction_cafe, transaksi_saldo, dan purchase: `upload_status` cuma jadi 'Y'
-- setelah push benar-benar sukses, dan baris yang gagal muncul di halaman
-- "Sinkron Online" (Sync::pending/retry) untuk diulang.
--
-- Jalankan bagian [BILLING_API] di database billing_flutter, dan bagian [GAMEON]
-- di database gameon (server pusat / laporan online).

-- ============================ [BILLING_API] billing_flutter ============================

CREATE TABLE IF NOT EXISTS `table_status_event` (
  `table_status_event_id` INT(11) NOT NULL AUTO_INCREMENT,
  `table_id` INT(11) NOT NULL,
  `table_number` VARCHAR(100) NOT NULL,
  `event` ENUM('open','close') NOT NULL,
  `mode` ENUM('Timer','Reguler') DEFAULT NULL,
  `customer_id` INT(11) DEFAULT NULL,
  `customer_name` VARCHAR(250) DEFAULT NULL,
  `start_time` DATETIME DEFAULT NULL,
  `end_time` DATETIME DEFAULT NULL,
  -- kenapa meja ditutup: 'payment' | 'cancel' - NULL untuk event 'open'
  `reason` VARCHAR(50) DEFAULT NULL,
  `branch` INT(11) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `upload_status` ENUM('Y','N') NOT NULL DEFAULT 'N',
  PRIMARY KEY (`table_status_event_id`),
  KEY `idx_tse_table` (`table_id`),
  KEY `idx_tse_upload` (`upload_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================== [GAMEON] gameon ==============================

CREATE TABLE IF NOT EXISTS `branch_table_event` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch` INT(11) NOT NULL,
  -- id baris table_status_event di cabang asal - kunci idempoten bersama `branch`
  -- (retry Sinkron Online tidak boleh menduplikasi baris yang sudah masuk)
  `local_event_id` INT(11) NOT NULL,
  `table_id` INT(11) NOT NULL,
  `table_number` VARCHAR(100) DEFAULT NULL,
  `event` ENUM('open','close') NOT NULL,
  `mode` ENUM('Timer','Reguler') DEFAULT NULL,
  `customer_id` INT(11) DEFAULT NULL,
  `customer_name` VARCHAR(250) DEFAULT NULL,
  `start_time` DATETIME DEFAULT NULL,
  `end_time` DATETIME DEFAULT NULL,
  `reason` VARCHAR(50) DEFAULT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_branch_local_event` (`branch`, `local_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
