-- Migration: pelacakan sinkron ke laporan online (gameon) + halaman "Sinkron Online"
--
-- transaction / transaction_cafe / transaksi_saldo SUDAH punya kolom *_upload_status sejak lama,
-- tapi tidak pernah di-set 'Y' di kode manapun (selalu 'N' abadi) - jadi tidak bisa dipakai untuk
-- membedakan "sudah kekirim ke gameon" vs "belum". Migration ini menambah kolom yang belum ada di
-- `purchase` (yang belum punya keduanya sama sekali) supaya semua 4 sumber data yang di-curl ke gameon
-- (transaction, transaction_cafe, transaksi_saldo, purchase) punya kolom yang sama untuk dipakai
-- Sync::pending() & Sync::retry().
--
-- `branch` ditambahkan juga ke `purchase` (sebelumnya cuma diresolve on-the-fly dari created_by,
-- tidak disimpan) supaya retry sync tidak perlu resolve ulang - juga berguna untuk laporan per cabang.

ALTER TABLE `purchase`
  ADD COLUMN `branch` INT(11) NOT NULL DEFAULT 1 AFTER `purchase_supplier_invoice`,
  ADD COLUMN `purchase_upload_status` ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER `branch`;
