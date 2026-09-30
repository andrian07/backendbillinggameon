-- Migration: promo billiard / mahjong - promo hanya muncul & berlaku untuk meja sejenisnya.
ALTER TABLE `ms_promo`
  ADD COLUMN `ms_promo_table_type` ENUM('billiard','mahjong') NOT NULL DEFAULT 'billiard' AFTER `ms_promo_tipe`;
