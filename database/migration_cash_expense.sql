-- Migration: pengeluaran kas (cash_expense)
-- Dicatat kasir dari tombol "Pengeluaran" di header (keterangan + nominal).
-- Saat Tutup Kas, total pengeluaran milik kasir yang sama (created_by = paid_by
-- pada summary) untuk business_date tsb dikurangkan dari total pembayaran TUNAI
-- (CASH) pada channel yang dipilih kasir (billing / cafe) — Grand Total omzet
-- TIDAK ikut berubah, hanya baris rekonsiliasi kas per channel.
--
-- business_date = tanggal kalender saat entri dibuat (server tz Asia/Jakarta),
-- sejajar dengan rentang hari kasir 06:00:00–23:59:59 yang dipakai Report_model.
CREATE TABLE IF NOT EXISTS `cash_expense` (
  `cash_expense_id` INT(11) NOT NULL AUTO_INCREMENT,
  `keterangan` VARCHAR(255) NOT NULL,
  `nominal` INT(11) NOT NULL DEFAULT 0,
  `channel` ENUM('billing','cafe') NOT NULL DEFAULT 'billing',
  `business_date` DATE NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_by_name` VARCHAR(100) NOT NULL DEFAULT '',
  `branch` INT(11) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`cash_expense_id`),
  KEY `idx_cash_expense_lookup` (`created_by`, `business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
