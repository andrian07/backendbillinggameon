-- Migration: nama pemain mahjong (maks 4 orang, teks bebas dipisah koma) - beda dari
-- table_customer_name (1 member terdaftar buat poin/saldo), ini murni catatan siapa saja
-- yang main di meja itu, tidak perlu terdaftar sebagai member. Berlaku juga di kolom meja
-- billiard (tidak dibatasi di database), tapi UI cuma menampilkannya untuk meja mahjong.

ALTER TABLE `table_active`
  ADD COLUMN `table_players` VARCHAR(255) DEFAULT NULL AFTER `table_customer_name`;

ALTER TABLE `transaction`
  ADD COLUMN `transaction_players` VARCHAR(255) DEFAULT NULL AFTER `transaction_customer_id`;
