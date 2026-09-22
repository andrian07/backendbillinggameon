<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Maintenance_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // table yang TIDAK ikut di-truncate (data master/konfigurasi, bukan data transaksional)
    private $excluded_tables = array(
        'category',
        'category_meja',
        'minute_setting',
        'ms_master_price',
        'ms_menu',
        'ms_payment',
        'ms_role',
        'ms_saldo',
        'ms_user',
        'role_access',
        'save_time_setting',
        'unit',
        'table_active', // ditangani terpisah (reset kolom tertentu, bukan truncate)
    );

    // reset kolom transaksi di table_active ke kondisi kosong (meja belum dibooking),
    // TANPA truncate row-nya (nomor/data meja tetap ada, cuma sesi bookingnya yang dikosongkan)
    private function reset_table_active() {
        return $this->db->update('table_active', array(
            'table_customer_id' => null,
            'table_promo_id' => 0,
            'table_mode' => null,
            'table_start_time' => null,
            'table_end_time' => null,
            'table_duration' => null,
            'table_bill' => 0,
            'table_active' => 0,
        ));
    }

    // truncate semua table di database KECUALI yang ada di $excluded_tables.
    // table_active tidak di-truncate, hanya direset kolom transaksinya (lihat reset_table_active()).
    // return daftar nama table yang di-truncate
    public function reset_all_data() {
        $tables = $this->db->list_tables();
        $truncated = array();

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            if (in_array($table, $this->excluded_tables, true)) continue;
            $this->db->query('TRUNCATE TABLE `' . $table . '`');
            $truncated[] = $table;
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->reset_table_active();

        return $truncated;
    }
}

?>
