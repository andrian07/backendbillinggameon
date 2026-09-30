-- Migration: pengeluaran kas bisa dicatat untuk kas Mahjong (terpisah dari Billiard & Cafe)
ALTER TABLE `cash_expense`
  MODIFY COLUMN `channel` ENUM('billing','mahjong','cafe') NOT NULL DEFAULT 'billing';
