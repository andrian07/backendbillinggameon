-- Migration: "Potong Saldo di awal" saat buka meja (special case member)
--
-- Saat buka meja Timer dengan member, kasir bisa pilih bayar di muka dari SALDO customer
-- sejumlah harga durasi penuh yang dibooking. Alurnya: PIN member (aplikasi GAMEON) ->
-- deduct_saldo -> meja dibuka. Saat tutup meja TIDAK dipotong lagi:
--   - tagihan aktual <= prepaid  -> selisih dikembalikan ke saldo, metode "Potong Saldo" (locked)
--   - tagihan aktual  > prepaid  -> kekurangannya ditagih saat checkout via metode bayar lain
--
-- billing_api perlu mengingat berapa yang sudah dibayar di muka + ref potongannya (untuk
-- refund/rekonsiliasi), jadi 3 kolom baru di table_active.
--
-- gameon: TIDAK ada perubahan skema. deduct_saldo / refund_saldo sudah ada; potongan tercatat
-- di history_saldo & saldo_deduction seperti pembayaran Potong Saldo biasa.

-- ============================ [BILLING_API] billing_flutter ============================

ALTER TABLE `table_active`
  ADD COLUMN `prepaid_saldo` ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER `saved_time_deducted`,
  ADD COLUMN `saldo_prepaid_amount` INT(11) NOT NULL DEFAULT 0 AFTER `prepaid_saldo`,
  ADD COLUMN `saldo_prepaid_ref` VARCHAR(80) DEFAULT NULL AFTER `saldo_prepaid_amount`;
