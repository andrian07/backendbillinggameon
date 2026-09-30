-- Migration: pemain mahjong sekarang harus member terdaftar (bukan teks bebas lagi) - simpan
-- customer_id-nya (table_player_ids, dipisah koma) supaya bisa divalidasi (tidak boleh sama
-- persis di 1 meja, tidak boleh sedang aktif di meja lain) DAN dicocokkan andal lintas meja.
-- table_players (nama, sudah ada dari migrasi sebelumnya) sekarang di-resolve backend dari
-- table_player_ids via ms_customer lokal - client cuma kirim id, bukan nama lagi.

ALTER TABLE `table_active`
  ADD COLUMN `table_player_ids` VARCHAR(100) DEFAULT NULL AFTER `table_players`;

ALTER TABLE `transaction`
  ADD COLUMN `transaction_player_ids` VARCHAR(100) DEFAULT NULL AFTER `transaction_players`;
