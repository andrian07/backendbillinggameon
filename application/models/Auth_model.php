<?php

class auth_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    public function login($username, $password) {
        $this->db->select('user_id, username, userrole, user_branch');
        $this->db->where('username', $username);
        $this->db->where('password', $password);
        $query = $this->db->get('ms_user');
        if ($query->num_rows() == 1) {
            return $query->row();
        } else {
            return false;
        }
    }

    public function change_password($user_id, $old_password_hash, $new_password_hash) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        $user = $this->db->get('ms_user')->row();
        if (!$user) return 'USER_NOT_FOUND';

        if ($user->password !== $old_password_hash) return 'WRONG_PASSWORD';

        $this->db->where('user_id', $user_id);
        $success = $this->db->update('ms_user', array('password' => $new_password_hash));

        return $success;
    }

    /**
     * Absensi (attendance) karyawan
     */

    public function get_user_by_id($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        return $this->db->get('ms_user')->row();
    }

    public function add_absensi($user_id, $date, $time_in, $time_out, $note, $created_by) {
        $this->db->insert('absensi', array(
            'user_id' => $user_id,
            'absensi_date' => $date,
            'absensi_time_in' => $time_in,
            'absensi_time_out' => $time_out,
            'absensi_note' => $note,
            'absensi_active' => 'Y',
            'created_by' => $created_by,
        ));
        return $this->db->insert_id();
    }

    // dipakai saat scan QR code (GET): toggle otomatis. belum ada absensi hari ini / sudah check-out -> check-in baru.
    // sudah check-in tapi belum check-out -> lengkapi absensi_time_out (check-out)
    public function scan_absensi($user_id, $created_by) {
        $today = date('Y-m-d');
        $now = date('H:i:s');

        $this->db->where('user_id', $user_id);
        $this->db->where('absensi_date', $today);
        $this->db->where('absensi_active', 'Y');
        $this->db->order_by('absensi_id', 'DESC');
        $this->db->limit(1);
        $existing = $this->db->get('absensi')->row();

        if ($existing && empty($existing->absensi_time_out)) {
            $this->db->where('absensi_id', $existing->absensi_id);
            $this->db->update('absensi', array('absensi_time_out' => $now));
            return array('action' => 'check_out', 'absensi_id' => (int) $existing->absensi_id, 'time' => $now);
        }

        $absensi_id = $this->add_absensi($user_id, $today, $now, null, null, $created_by);
        return array('action' => 'check_in', 'absensi_id' => $absensi_id, 'time' => $now);
    }

    public function get_absensi_by_id($absensi_id) {
        $this->db->where('absensi_id', $absensi_id);
        $this->db->where('absensi_active', 'Y');
        return $this->db->get('absensi')->row();
    }

    public function edit_absensi($absensi_id, $data) {
        $this->db->where('absensi_id', $absensi_id);
        return $this->db->update('absensi', $data);
    }

    public function delete_absensi($absensi_id) {
        $this->db->where('absensi_id', $absensi_id);
        return $this->db->update('absensi', array('absensi_active' => 'N'));
    }

    // laporan absensi, filter tanggal (wajib), user_id (opsional)
    public function get_absensi_report($filter) {
        $this->db->select('a.absensi_id, a.user_id, u.username, a.absensi_date, a.absensi_time_in, a.absensi_time_out, a.absensi_note, a.created_at', false);
        $this->db->from('absensi a');
        $this->db->join('ms_user u', 'u.user_id = a.user_id', 'left');
        $this->db->where('a.absensi_active', 'Y');
        $this->db->where('a.absensi_date >=', $filter['date_from']);
        $this->db->where('a.absensi_date <=', $filter['date_to']);
        if (!empty($filter['user_id'])) $this->db->where('a.user_id', $filter['user_id']);
        $this->db->order_by('a.absensi_date', 'ASC');
        $this->db->order_by('a.absensi_time_in', 'ASC');
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->absensi_id,
                'user_id' => (int) $row->user_id,
                'username' => $row->username,
                'date' => $row->absensi_date,
                'time_in' => $row->absensi_time_in,
                'time_out' => $row->absensi_time_out,
                'note' => $row->absensi_note,
                'created_at' => $row->created_at,
            );
        }
        return array('filter' => $filter, 'data' => $data);
    }
}