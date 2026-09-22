-- Migration: metode pembayaran "Potong Waktu" + PIN member saat buka meja pakai waktu tersimpan
--
-- Saat kasir buka meja dengan "pakai waktu tersimpan" (use_saved_time = Y), saldo waktu member
-- dipotong SAAT ITU (di Billing::book_table, bukan saat tutup meja). Perubahan alur:
--   1. Buka meja pakai waktu tersimpan sekarang minta KONFIRMASI PIN member dulu (aplikasi GAMEON) -
--      sama pola NEED_MEMBER_APPROVAL seperti Potong Saldo, tipe approval-nya 'billing_time', amount 0.
--   2. Metode pembayaran transaksinya "Potong Waktu" (Rp0), bukan "Potong Saldo".
--
-- gameon: tidak ada perubahan skema. member_approval.type sudah varchar(30) (muat 'billing_time');
-- transaction_payment_name di branch_transaction cuma varchar bebas, "Potong Waktu" lewat apa adanya.

-- ============================ [BILLING_API] billing_flutter ============================

INSERT INTO `ms_payment` (`payment_name`, `payment_active`)
SELECT 'Potong Waktu', 'Y'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ms_payment` WHERE `payment_name` = 'Potong Waktu');
