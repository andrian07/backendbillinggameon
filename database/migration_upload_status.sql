-- Migration: kolom {table}_upload_status (Y/N, default N) di semua table yang
-- terhubung ke notifikasi Api::notify(), + table history_hit_api_logs untuk
-- mencatat kegagalan hit API (module, function, ref table/id).
--
-- upload_status = Y -> notify() ke server pusat sukses (HTTP 2xx)
-- upload_status tetap N -> notify() gagal (curl error ATAU HTTP non-2xx),
--                           detailnya dicatat di history_hit_api_logs

ALTER TABLE ms_role ADD COLUMN ms_role_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_menu ADD COLUMN ms_menu_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE role_access ADD COLUMN role_access_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE absensi ADD COLUMN absensi_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_user ADD COLUMN ms_user_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE transaksi_saldo ADD COLUMN transaksi_saldo_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_master_price ADD COLUMN ms_master_price_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_promo ADD COLUMN ms_promo_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE category ADD COLUMN category_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE unit ADD COLUMN unit_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_product ADD COLUMN ms_product_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE customer_time ADD COLUMN customer_time_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE table_active ADD COLUMN table_active_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE transaction_cafe ADD COLUMN transaction_cafe_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE keep_transaction ADD COLUMN keep_transaction_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_point_exchange ADD COLUMN ms_point_exchange_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE ms_saldo ADD COLUMN ms_saldo_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';
ALTER TABLE category_meja ADD COLUMN category_meja_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';

-- table `transaction` sudah punya kolom `upload_status` generik dari sebelumnya
-- (diisi 'N' saat insert, tidak pernah dipakai) - disamakan penamaannya
ALTER TABLE `transaction` CHANGE COLUMN upload_status transaction_upload_status ENUM('Y','N') NOT NULL DEFAULT 'N';

CREATE TABLE IF NOT EXISTS history_hit_api_logs (
    history_hit_api_logs_id INT(11) NOT NULL AUTO_INCREMENT,
    module VARCHAR(50) NOT NULL,
    action VARCHAR(20) NOT NULL,
    function_name VARCHAR(100) NOT NULL,
    status ENUM('success','gagal') NOT NULL DEFAULT 'gagal',
    endpoint_url VARCHAR(255) DEFAULT NULL,
    payload TEXT DEFAULT NULL,
    ref_table VARCHAR(100) DEFAULT NULL,
    ref_id INT(11) DEFAULT NULL,
    http_code INT(11) DEFAULT NULL,
    response_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_hit_api_logs_id),
    KEY idx_module_action (module, action),
    KEY idx_ref (ref_table, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
