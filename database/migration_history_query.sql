-- Migration: table history_query, untuk mencatat SEMUA query tulis (insert/update/delete/
-- replace/truncate) yang dijalankan aplikasi, lengkap dengan table yang dieksekusi dan
-- status sukses/gagal. Diisi otomatis oleh hook di application/hooks/query_logger.php
-- (lihat juga application/config/hooks.php - 'pre_system').

CREATE TABLE IF NOT EXISTS history_query (
    history_query_id INT(11) NOT NULL AUTO_INCREMENT,
    query_text TEXT NOT NULL,
    query_type VARCHAR(20) NOT NULL,
    table_name VARCHAR(100) DEFAULT NULL,
    status ENUM('success','gagal') NOT NULL DEFAULT 'gagal',
    execution_time DECIMAL(10,6) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_query_id),
    KEY idx_table_status (table_name, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
