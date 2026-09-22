-- Migration: kembalikan waktu tersimpan saat meja dibatalkan
--
-- Saat buka meja dgn use_saved_time=Y, saldo waktu member (customer_time di gameon)
-- langsung dipotong. Kalau meja lalu DIBATALKAN (cancel_table, dalam 6 menit),
-- potongan itu dulu hangus. Kolom ini menyimpan BERAPA DETIK/waktu yang benar-benar
-- dipotong (bisa < table_duration kalau promo Fix "beli X gratis Y" dipakai) supaya
-- cancel_table bisa memanggil gameon->save_customer_time() untuk mengembalikannya persis.
--
-- NULL = meja tidak memakai waktu tersimpan (atau belum dibooking). Direset ke NULL
-- oleh clear_table() (default_table_state) tiap meja dikosongkan.

ALTER TABLE `table_active`
  ADD COLUMN `saved_time_deducted` TIME NULL DEFAULT NULL AFTER `use_saved_time`;
