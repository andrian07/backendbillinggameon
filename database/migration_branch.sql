-- Migration: kolom cabang (branch)
-- ms_user.user_branch: cabang milik user yang login, dipakai untuk menentukan cabang saat
-- mencatat transaksi. Semua user default cabang 1.
ALTER TABLE ms_user ADD COLUMN user_branch INT(11) NOT NULL DEFAULT 1;

-- kolom branch di table-table transaksional yang butuh info cabang asal
ALTER TABLE customer_point_history ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
ALTER TABLE customer_time_history ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
ALTER TABLE history_saldo ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `transaction` ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
ALTER TABLE transaction_cafe ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
ALTER TABLE transaksi_saldo ADD COLUMN branch INT(11) NOT NULL DEFAULT 1;
