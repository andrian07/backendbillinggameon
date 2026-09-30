-- Migration: kolom langsung di table_active buat tandain billiard/mahjong, supaya
-- get_table_list() tidak perlu JOIN ke category_meja tiap kali cuma buat baca jenisnya.
-- category_meja.category_meja_type TETAP dipakai (masih source of truth utama, dan
-- masih dipakai buat resolve tabel harga di get_category_meja_price_tier()) - kolom
-- ini murni salinan cepatnya di sisi meja, di-sync otomatis tiap table_category diubah
-- lewat Billing::update_setting_table().

ALTER TABLE `table_active`
  ADD COLUMN `table_type` ENUM('billiard','mahjong') NOT NULL DEFAULT 'billiard' AFTER `table_category`;

-- backfill dari kategori yang sudah terpasang saat ini
UPDATE `table_active` t
  JOIN `category_meja` c ON c.category_meja_id = t.table_category
  SET t.table_type = c.category_meja_type;
