-- Migration: kolom pelacakan pembatalan transaksi billing yang sudah "Done" -
-- transaction_status sudah punya nilai enum 'Cancel' sejak awal (belum pernah dipakai
-- untuk transaksi Done, cuma dipakai buat transaksi gagal di add_transaction()), jadi
-- yang perlu ditambah cuma siapa & kapan, sama seperti pola transaction_cafe_cancelled_by/_at.

ALTER TABLE `transaction`
  ADD COLUMN `transaction_cancelled_by` VARCHAR(100) DEFAULT NULL AFTER `transaction_payment_edited_at`,
  ADD COLUMN `transaction_cancelled_at` DATETIME DEFAULT NULL AFTER `transaction_cancelled_by`;
