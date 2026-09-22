<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// PIN keamanan global (satu PIN untuk seluruh aplikasi, bukan per-user) yang bisa diaktifkan/
// nonaktifkan oleh owner. Saat aktif, tindakan destruktif (batal meja, cancel transaksi cafe,
// hapus keep transaction) wajib memasukkan PIN ini dulu - lihat verify(). Hanya ada 1 baris di
// tabel pin_setting, selalu diambil/diupdate lewat baris pertama (dibuat kalau belum ada).
class Pin_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // sama seperti Opname_model::is_owner() - role 1 = owner/superadmin
    public function is_owner($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        $user = $this->db->get('ms_user')->row();
        return $user && (int) $user->userrole === 1;
    }

    private function get_row() {
        $row = $this->db->order_by('pin_setting_id', 'ASC')->limit(1)->get('pin_setting')->row();
        if ($row) return $row;

        $this->db->insert('pin_setting', array('pin_active' => 'N'));
        return $this->db->order_by('pin_setting_id', 'ASC')->limit(1)->get('pin_setting')->row();
    }

    // status yang aman ditampilkan ke semua role (tidak mengembalikan pin_code)
    public function get_status() {
        $row = $this->get_row();
        return array(
            'active' => $row->pin_active === 'Y',
            'is_set' => !empty($row->pin_code),
        );
    }

    public function set_pin($pin_code) {
        $row = $this->get_row();
        $this->db->where('pin_setting_id', $row->pin_setting_id);
        return $this->db->update('pin_setting', array(
            'pin_code' => password_hash($pin_code, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function set_active($active) {
        $row = $this->get_row();
        if ($active && empty($row->pin_code)) {
            return 'PIN_NOT_SET';
        }
        $this->db->where('pin_setting_id', $row->pin_setting_id);
        return $this->db->update('pin_setting', array(
            'pin_active' => $active ? 'Y' : 'N',
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    // dipanggil sebelum tindakan destruktif (batal meja/cancel transaksi/hapus keep transaction).
    // kalau PIN tidak aktif, selalu lolos (tidak wajib input apa-apa).
    public function verify($pin_code) {
        $row = $this->get_row();
        if ($row->pin_active !== 'Y') return true;
        if (empty($row->pin_code)) return true;
        return password_verify((string) $pin_code, $row->pin_code);
    }
}

?>
