<?php

class master_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // sama pola dengan Opname_model::is_owner()/Pin_model::is_owner() - role 1 = owner/superadmin
    public function is_owner($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        $user = $this->db->get('ms_user')->row();
        return $user && (int) $user->userrole === 1;
    }

    public function get_user_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('is_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_user');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->select('*');
        $this->db->where('is_active', 'Y');
        $this->db->order_by('user_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('ms_user');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->user_id,
                'name' => $row->username,
                'role' => $row->userrole,
            );
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_user_by_id($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        return $this->db->get('ms_user')->row();
    }

    public function is_username_exists($username, $exclude_id = null) {
        $this->db->where('username', $username);
        $this->db->where('is_active', 'Y');
        if ($exclude_id) $this->db->where('user_id !=', $exclude_id);
        return $this->db->count_all_results('ms_user') > 0;
    }

    public function add_user($username, $password, $userrole) {
        $data = array(
            'username' => $username,
            'password' => md5($password),
            'userrole' => $userrole,
            'is_active' => 'Y',
            'created_at' => date('Y-m-d H:i:s'),
        );
        $this->db->insert('ms_user', $data);
        return $this->db->insert_id();
    }

    public function edit_user($user_id, $data) {
        if (!$this->get_user_by_id($user_id)) return false;
        $this->db->where('user_id', $user_id);
        return $this->db->update('ms_user', $data);
    }

    public function delete_user($user_id) {
        if (!$this->get_user_by_id($user_id)) return false;
        $this->db->where('user_id', $user_id);
        return $this->db->update('ms_user', array('is_active' => 'N'));
    }

    public function reset_pass_user($user_id) {
        if (!$this->get_user_by_id($user_id)) return false;
        $this->db->where('user_id', $user_id);
        return $this->db->update('ms_user', array('password' => md5('12345')));
    }

    public function get_customer_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('customer_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_customer');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->select('customer_id, customer_id_number, customer_name, customer_phone, customer_address, customer_email, customer_verification, customer_created_at');
        $this->db->where('customer_active', 'Y');
        $this->db->order_by('customer_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('ms_customer');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->customer_id,
                'id_number' => $row->customer_id_number,
                'name' => $row->customer_name,
                'phone' => $row->customer_phone,
                'address' => $row->customer_address,
                'email' => $row->customer_email,
                // saldo & point HANYA valid di gameon - fallback lokal ini (dipakai kalau gameon
                // sedang tidak bisa dihubungi) tidak tahu angkanya, jadi selalu 0
                'saldo' => 0,
                'point' => 0,
                'verification' => $row->customer_verification,
                'created_at' => $row->customer_created_at,
            );
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_customer_list_no_pagging() {
        $this->db->select('customer_id, customer_id_number, customer_name, customer_phone, customer_address, customer_email, customer_verification, customer_created_at');
        $this->db->where('customer_active', 'Y');
        $this->db->order_by('customer_id', 'ASC');
        $query = $this->db->get('ms_customer');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->customer_id,
                'id_number' => $row->customer_id_number,
                'name' => $row->customer_name,
                'phone' => $row->customer_phone,
                'address' => $row->customer_address,
                'email' => $row->customer_email,
                // saldo & point HANYA valid di gameon (fallback lokal tidak tahu) - selalu 0 di sini
                'saldo' => 0,
                'point' => 0,
                'verification' => $row->customer_verification,
                'created_at' => $row->customer_created_at,
            );
        }

        return $data;
    }

    // dipakai Master::sync_customer() untuk tahu customer_id mana dari gameon yang sudah ada di lokal,
    // supaya tidak insert dobel
    public function get_existing_customer_ids(array $customer_ids) {
        if (empty($customer_ids)) return array();
        $this->db->select('customer_id');
        $this->db->where_in('customer_id', $customer_ids);
        $query = $this->db->get('ms_customer');
        return array_map(function ($row) { return (int) $row->customer_id; }, $query->result());
    }

    // insert satu customer dari hasil gameon->latest_customer() ke DB lokal. customer_id & customer_id_number
    // dipakai APA ADANYA dari gameon (bukan di-generate ulang) supaya ID tetap identik dengan gameon
    public function insert_customer_from_gameon($row) {
        $data = array(
            'customer_id' => $row['id'],
            'customer_id_number' => $row['id_number'],
            'customer_name' => $row['name'],
            'customer_phone' => $row['phone'],
            'customer_address' => $row['address'],
            'customer_pass' => $row['password'],
            'customer_email' => $row['email'],
            // saldo & point milik gameon sepenuhnya - salinan lokal ini sengaja disimpan 0 supaya
            // tidak ada yang salah menganggap angka lokal sebagai kebenaran (lihat get_customer_list)
            'customer_saldo' => 0,
            'customer_point' => 0,
            'customer_verification' => $row['verification'],
            'customer_pin' => '',
            'customer_active' => 'Y',
            'customer_created_at' => $row['created_at'],
        );
        $this->db->insert('ms_customer', $data);
        return $this->db->affected_rows() > 0;
    }

    public function delete_customer($customer_id) {
        if (!$this->get_customer_by_id($customer_id)) return false;
        $this->db->where('customer_id', $customer_id);
        return $this->db->update('ms_customer', array('customer_active' => 'N'));
    }

    public function reset_pass_customer($customer_id) {
        if (!$this->get_customer_by_id($customer_id)) return false;
        $this->db->where('customer_id', $customer_id);
        return $this->db->update('ms_customer', array('customer_pass' => md5('12345')));
    }

    // NOTE (rekonstruksi): fungsi ini sebelumnya sempat hilang dari file ini (dipindahkan ke gameon) dan
    // tidak sempat terbaca isi aslinya sebelum hilang - ditulis ulang secara fungsional mengikuti pola
    // get_X_by_id lain di codebase ini, bukan hasil revert persis dari kode asli.
    public function get_customer_by_id($customer_id) {
        $this->db->where('customer_id', $customer_id);
        return $this->db->get('ms_customer')->row();
    }

    private function generate_transaksi_saldo_inv() {
        // format: INV/SLD/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('transaksi_saldo');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'INV/SLD/' . date('d') . '/' . date('m') . $seq;
    }

    // catat 1 notifikasi topup baru (kalau belum pernah dicatat - topup_request_id UNIQUE di table ini,
    // jadi aman dipanggil berkali-kali tiap polling tanpa bikin duplikat). return true kalau baris baru
    // ditambahkan (berarti genuinely baru, layak dikasih notifikasi OS), false kalau sudah ada sebelumnya.
    public function log_topup_notification($topup_request_id, $customer_id, $customer_name, $amount, $payment_method) {
        $this->db->where('topup_request_id', $topup_request_id);
        if ($this->db->count_all_results('topup_notification') > 0) return false;

        $this->db->insert('topup_notification', array(
            'topup_request_id' => $topup_request_id,
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'amount' => $amount,
            'payment_method' => $payment_method,
        ));
        return $this->db->affected_rows() > 0;
    }

    // daftar notifikasi topup, terbaru duluan. $unread_only = true buat cuma ambil yang belum dibaca.
    public function get_topup_notifications($unread_only = false) {
        if ($unread_only) $this->db->where('is_read', 'N');
        $this->db->order_by('topup_notification_id', 'DESC');
        $query = $this->db->get('topup_notification');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->topup_notification_id,
                'topup_request_id' => (int) $row->topup_request_id,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'amount' => (int) $row->amount,
                'payment_method' => $row->payment_method,
                'is_read' => $row->is_read === 'Y',
                'created_at' => $row->created_at,
            );
        }
        return $data;
    }

    // tandai 1 notifikasi (atau semua kalau $topup_notification_id null) sudah dibaca.
    public function mark_topup_notification_read($topup_notification_id = null) {
        if ($topup_notification_id !== null) {
            $this->db->where('topup_notification_id', $topup_notification_id);
        }
        return $this->db->update('topup_notification', array('is_read' => 'Y'));
    }

    // === Booking room (dari aplikasi member) - pola sama persis dengan topup notification di atas ===

    // catat 1 notifikasi booking baru (idempoten - booking_request_id UNIQUE). return true kalau baris
    // baru ditambahkan (genuinely baru, layak dikasih notifikasi OS), false kalau sudah ada sebelumnya.
    public function log_booking_notification($booking_request_id, $customer_id, $customer_name, $category_name, $booking_date, $booking_time, $duration_hours, $price, $branch = 1) {
        $this->db->where('booking_request_id', $booking_request_id);
        if ($this->db->count_all_results('booking_notification') > 0) return false;

        $this->db->insert('booking_notification', array(
            'booking_request_id' => $booking_request_id,
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'category_name' => $category_name,
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'duration_hours' => $duration_hours,
            'price' => $price,
            'branch' => (int) $branch,
        ));
        return $this->db->affected_rows() > 0;
    }

    // daftar notifikasi booking, terbaru duluan. $unread_only = true buat cuma ambil yang belum dibaca.
    // $branch = filter per cabang (tiap billinggameon cuma lihat notifikasi cabangnya sendiri).
    public function get_booking_notifications($unread_only = false, $branch = null) {
        if ($unread_only) $this->db->where('is_read', 'N');
        if ($branch !== null && (int) $branch > 0) $this->db->where('branch', (int) $branch);
        $this->db->order_by('booking_notification_id', 'DESC');
        $query = $this->db->get('booking_notification');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->booking_notification_id,
                'booking_request_id' => (int) $row->booking_request_id,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => $row->customer_name,
                'category_name' => $row->category_name,
                'booking_date' => $row->booking_date,
                'booking_time' => $row->booking_time,
                'duration_hours' => (int) $row->duration_hours,
                'price' => (int) $row->price,
                'branch' => (int) $row->branch,
                'is_read' => $row->is_read === 'Y',
                'created_at' => $row->created_at,
            );
        }
        return $data;
    }

    // tandai 1 notifikasi booking (atau semua kalau $booking_notification_id null) sudah dibaca.
    public function mark_booking_notification_read($booking_notification_id = null) {
        if ($booking_notification_id !== null) {
            $this->db->where('booking_notification_id', $booking_notification_id);
        }
        return $this->db->update('booking_notification', array('is_read' => 'Y'));
    }

    // tandai notifikasi booking untuk 1 booking_request_id sudah dibaca - dipakai saat kasir sudah
    // buka meja (accept) dari booking itu supaya notifnya tidak muncul lagi di badge / notifikasi OS.
    public function mark_booking_notification_read_by_request($booking_request_id) {
        $this->db->where('booking_request_id', (int) $booking_request_id);
        return $this->db->update('booking_notification', array('is_read' => 'Y'));
    }

    /* ============ Mirror lokal daftar booking room (per cabang) ============
       Diisi ulang total tiap kali Master::bookings() berhasil menarik dari gameon,
       supaya cek bentrok saat buka meja tidak perlu online. booking_sync_state
       menyimpan kapan terakhir berhasil supaya bisa memperingatkan data basi. */

    // ganti TOTAL isi booking_request lokal dengan $rows (dari gameon Api/bookings, sudah per cabang).
    public function replace_local_bookings($rows, $branch) {
        $this->db->trans_start();
        $this->db->truncate('booking_request');
        foreach ((array) $rows as $r) {
            if (empty($r['booking_request_id'])) continue;
            $this->db->insert('booking_request', array(
                'booking_request_id' => (int) $r['booking_request_id'],
                'customer_id' => isset($r['customer_id']) ? (int) $r['customer_id'] : null,
                'customer_name' => isset($r['customer_name']) ? $r['customer_name'] : null,
                'customer_phone' => isset($r['customer_phone']) ? $r['customer_phone'] : null,
                'category_meja_id' => isset($r['category_meja_id']) ? (int) $r['category_meja_id'] : null,
                'category_name' => isset($r['category_name']) ? $r['category_name'] : null,
                'booking_unit_id' => isset($r['booking_unit_id']) && $r['booking_unit_id'] !== null ? (int) $r['booking_unit_id'] : null,
                'unit_no' => isset($r['unit_no']) && $r['unit_no'] !== null ? (int) $r['unit_no'] : null,
                'area' => isset($r['area']) ? $r['area'] : null,
                'booking_date' => isset($r['booking_date']) ? $r['booking_date'] : null,
                'booking_time' => isset($r['booking_time']) ? $r['booking_time'] : null,
                'duration_hours' => isset($r['duration_hours']) ? (int) $r['duration_hours'] : 0,
                'price' => isset($r['price']) ? (int) $r['price'] : 0,
                'status' => 'Booked',
                'branch' => (int) $branch,
            ));
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    public function mark_booking_sync_success($count) {
        $now = date('Y-m-d H:i:s');
        $this->db->where('id', 1);
        return $this->db->update('booking_sync_state', array(
            'last_success_at' => $now,
            'last_attempt_at' => $now,
            'last_error' => null,
            'last_count' => (int) $count,
        ));
    }

    public function mark_booking_sync_failure($error) {
        $this->db->where('id', 1);
        return $this->db->update('booking_sync_state', array(
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'last_error' => substr((string) $error, 0, 255),
        ));
    }

    public function get_booking_sync_state() {
        $row = $this->db->where('id', 1)->get('booking_sync_state')->row();
        $last_success = $row ? $row->last_success_at : null;
        return array(
            'last_success_at' => $last_success,
            'last_attempt_at' => $row ? $row->last_attempt_at : null,
            'last_error' => $row ? $row->last_error : null,
            'last_count' => $row ? (int) $row->last_count : 0,
            'seconds_since_success' => $last_success ? (time() - strtotime($last_success)) : null,
        );
    }

    // booking berstatus 'Booked' untuk 1 kategori meja yang jendela jamnya beririsan dengan
    // [$start_dt, $end_dt] (string datetime). Dipakai Billing::book_table() buat peringatan bentrok.
    public function get_overlapping_bookings($category_meja_id, $start_dt, $end_dt) {
        if (empty($category_meja_id) || empty($start_dt) || empty($end_dt)) return array();

        $start = strtotime($start_dt);
        $end = strtotime($end_dt);
        if (!$start || !$end) return array();

        $rows = $this->db
            ->where('category_meja_id', (int) $category_meja_id)
            ->where('status', 'Booked')
            ->get('booking_request')->result();

        $out = array();
        foreach ($rows as $r) {
            if (empty($r->booking_date) || empty($r->booking_time)) continue;
            $b_start = strtotime($r->booking_date . ' ' . $r->booking_time);
            $b_end = $b_start + ((int) $r->duration_hours * 3600);
            if ($start < $b_end && $b_start < $end) { // beririsan
                $out[] = array(
                    'booking_request_id' => (int) $r->booking_request_id,
                    'customer_name' => $r->customer_name,
                    'booking_date' => $r->booking_date,
                    'booking_time' => $r->booking_time,
                    'duration_hours' => (int) $r->duration_hours,
                    'unit_no' => $r->unit_no !== null ? (int) $r->unit_no : null,
                    'area' => $r->area,
                );
            }
        }
        return $out;
    }

    // catat transaksi top up dari APPROVE permintaan member (self-service, nominal bebas - bukan dari
    // paket ms_saldo). Sama seperti add_customer_saldo() di bawah: billing_api HANYA mencatat transaksi
    // lokal (transaksi_saldo_id auto_increment lokal, upload_status='N') - saldo & histori TIDAK
    // disentuh di sini, itu tanggung jawab gameon lewat Gameon::confirm_topup() (lihat
    // Master::approve_topup()).
    public function record_topup_transaction($customer_id, $amount, $payment_id, $created_by, $paid_by, $branch = 1) {
        $inv = $this->generate_transaksi_saldo_inv();

        $this->db->insert('transaksi_saldo', array(
            'transaksi_saldo_inv' => $inv,
            'customer_id' => $customer_id,
            'transaksi_saldo_amount' => 0,
            'transaction_saldo_pay' => (int) $amount,
            'transaction_discount_pay' => 0,
            'transaksi_saldo_payment_id' => $payment_id,
            'transaksi_saldo_status' => 'Done',
            'created_by' => $created_by,
            'paid_by' => $paid_by,
            'transaksi_saldo_upload_status' => 'N',
            'branch' => $branch,
        ));
        $transaksi_saldo_id = $this->db->insert_id();
        if (!$transaksi_saldo_id) return false;

        return array(
            'transaksi_saldo_id' => (int) $transaksi_saldo_id,
            'transaksi_saldo_inv' => $inv,
        );
    }

    // tambah saldo customer dari pilihan ms_saldo (top up). billing_api HANYA mencatat transaksinya
    // (insert transaksi_saldo) - TIDAK update ms_customer.customer_saldo dan TIDAK ada history_saldo lokal.
    // Saldo & histori penuh jadi tanggung jawab gameon (lihat Master::add_customer_saldo() controller,
    // yang push hasil fungsi ini ke gameon lewat Gameon::update_online()).
    // return array('transaksi_saldo_id' => ..., ...) kalau berhasil, 'SALDO_OPTION_NOT_FOUND' kalau
    // ms_saldo_id tidak ditemukan/tidak aktif, false kalau customer tidak ditemukan
    // nominal/pay/discount_pay sekarang dikirim dari controller (diambil dari paket saldo di gameon -
    // katalog ms_saldo tidak lagi dikelola lokal). Fungsi ini HANYA mencatat transaksi_saldo lokal
    // sebagai rekap uang masuk kasir; saldo customer itu sendiri di-update di gameon (lihat
    // Master::add_customer_saldo() controller -> Gameon::update_online()).
    public function add_customer_saldo($customer_id, $ms_saldo_id, $nominal, $pay, $discount_pay, $payment_id, $created_by, $paid_by, $branch = 1) {
        $customer = $this->db->where('customer_id', $customer_id)->get('ms_customer')->row();
        if (!$customer) return false;

        $inv = $this->generate_transaksi_saldo_inv();
        $nominal = (int) $nominal;
        $pay = (int) $pay;
        $discount_pay = (int) $discount_pay;

        $this->db->insert('transaksi_saldo', array(
            'transaksi_saldo_inv' => $inv,
            'customer_id' => $customer_id,
            'transaksi_saldo_amount' => $ms_saldo_id,
            'transaksi_saldo_nominal' => $nominal,
            'transaction_saldo_pay' => $pay,
            'transaction_discount_pay' => $discount_pay,
            'transaksi_saldo_payment_id' => $payment_id,
            'transaksi_saldo_status' => 'Done',
            'created_by' => $created_by,
            'paid_by' => $paid_by,
            'transaksi_saldo_upload_status' => 'N',
            'branch' => $branch,
        ));
        $transaksi_saldo_id = $this->db->insert_id();
        if (!$transaksi_saldo_id) return false;

        return array(
            'transaksi_saldo_id' => (int) $transaksi_saldo_id,
            'transaksi_saldo_inv' => $inv,
            'nominal' => $nominal,
            'pay' => $pay,
            'discount_pay' => $discount_pay,
        );
    }

    // Rebuild payload gameon->update_online() dari baris transaksi_saldo yang tersimpan - dipakai
    // Sync::retry() supaya top up yang gagal push pertama kali bisa diulang tanpa data dari client.
    public function get_saldo_transaction_for_sync($transaksi_saldo_id) {
        $this->db->where('transaksi_saldo_id', $transaksi_saldo_id);
        $row = $this->db->get('transaksi_saldo')->row();
        if (!$row) return null;

        return array(
            'type' => 'saldo',
            'transaksi_saldo_id' => (int) $row->transaksi_saldo_id,
            'transaksi_saldo_inv' => $row->transaksi_saldo_inv,
            'customer_id' => (int) $row->customer_id,
            'ms_saldo_id' => (int) $row->transaksi_saldo_amount,
            'nominal' => (int) $row->transaksi_saldo_nominal,
            'pay' => (int) $row->transaction_saldo_pay,
            'discount_pay' => (int) $row->transaction_discount_pay,
            'payment_id' => (int) $row->transaksi_saldo_payment_id,
            'created_by' => $row->created_by,
            'paid_by' => (int) $row->paid_by,
            'branch' => (int) $row->branch,
        );
    }

    // ditandai 'Y' setelah push ke gameon (laporan online) berhasil - lihat
    // Master::add_customer_saldo() & Sync::retry(). Selamanya 'N' kalau belum pernah berhasil.
    public function mark_saldo_uploaded($transaksi_saldo_id) {
        $this->db->where('transaksi_saldo_id', $transaksi_saldo_id);
        $this->db->update('transaksi_saldo', array('transaksi_saldo_upload_status' => 'Y'));
    }

    // deduct_customer_saldo() / refund_customer_saldo() DIHAPUS - potong & refund saldo sekarang
    // 100% lewat gameon (Gameon::deduct_saldo / refund_saldo, atomik + idempoten via deduction_ref).
    // billing_api tidak lagi menyentuh ms_customer.customer_saldo sama sekali.

    // $table: 'ms_master_price' (billiard, default) atau 'ms_master_price_mahjong' - struktur
    // kedua tabel identik (168 baris hari x jam, 5 kolom tier harga), cuma datanya independen.
    public function get_price_list($table = 'ms_master_price') {
        $this->db->order_by('master_price_id', 'ASC');
        $query = $this->db->get($table);

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->master_price_id,
                'days' => $row->master_price_days,
                'time' => (int) $row->master_price_time,
                'price' => (int) $row->master_price_price,
                'price_2' => (int) $row->master_price_price_2,
                'price_3' => (int) $row->master_price_price_3,
                'price_4' => (int) $row->master_price_price_4,
                'price_5' => (int) $row->master_price_price_5,
            );
        }

        return $data;
    }

    public function get_price_by_id($master_price_id, $table = 'ms_master_price') {
        $this->db->where('master_price_id', $master_price_id);
        return $this->db->get($table)->row();
    }

    public function edit_price($master_price_id, $data, $table = 'ms_master_price') {
        if (!$this->get_price_by_id($master_price_id, $table)) return false;
        $this->db->where('master_price_id', $master_price_id);
        return $this->db->update($table, $data);
    }

    public function get_payment_list() {
        $this->db->where('payment_active', 'Y');
        $this->db->order_by('payment_id', 'ASC');
        $query = $this->db->get('ms_payment');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->payment_id,
                'name' => $row->payment_name,
            );
        }

        return $data;
    }

    public function get_promo_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('ms_promo_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_promo');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('ms_promo_active', 'Y');
        $this->db->order_by('ms_promo_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('ms_promo');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_promo($row);
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_promo_list_no_pagging() {
        $this->db->where('ms_promo_active', 'Y');
        $this->db->order_by('ms_promo_id', 'ASC');
        $query = $this->db->get('ms_promo');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_promo($row);
        }

        return $data;
    }

    // valid_days: string "1,3" (1=Senin..7=Minggu, lihat date('N')) atau null = berlaku semua hari.
    // valid_time_start/valid_time_end: jam 0-24 (dua-duanya wajib diisi bersamaan atau dikosongkan
    // bersamaan) = jendela jam berlaku promo, dicek di Billing_model::validate_promo_schedule().
    // category_ids: daftar category_meja_id yang promo ini boleh dipakai (dari ms_promo_category).
    // KOSONG = berlaku untuk SEMUA kategori meja. Dicek di Billing::book_table()/payment() supaya
    // promo tidak kepilih untuk kategori meja yang salah.
    private function get_promo_category_ids($promo_id) {
        $this->db->select('category_meja_id');
        $this->db->where('ms_promo_id', (int) $promo_id);
        return array_map(function ($r) { return (int) $r->category_meja_id; },
            $this->db->get('ms_promo_category')->result());
    }

    private function set_promo_category_ids($promo_id, array $category_ids) {
        $this->db->where('ms_promo_id', (int) $promo_id)->delete('ms_promo_category');
        foreach (array_unique(array_filter(array_map('intval', $category_ids), function ($v) { return $v > 0; })) as $cid) {
            $this->db->insert('ms_promo_category', array('ms_promo_id' => (int) $promo_id, 'category_meja_id' => $cid));
        }
    }

    // dipakai Billing_model - true kalau promo boleh dipakai untuk $category_meja_id (atau promo
    // tidak dibatasi kategori sama sekali)
    public function promo_category_ok($promo_id, $category_meja_id) {
        // jenis promo (billiard/mahjong) harus sama dengan jenis kategori meja
        $promo = $this->get_promo_by_id($promo_id);
        $cat = $this->db->select('category_meja_type')->where('category_meja_id', (int) $category_meja_id)->get('category_meja')->row();
        if ($promo && $cat && $promo->ms_promo_table_type !== $cat->category_meja_type) return false;

        $ids = $this->get_promo_category_ids($promo_id);
        if (empty($ids)) return true;
        return in_array((int) $category_meja_id, $ids, true);
    }

    private function map_promo($row) {
        return array(
            'id' => (int) $row->ms_promo_id,
            'name' => $row->ms_promo_name,
            'tipe' => $row->ms_promo_tipe,
            'table_type' => $row->ms_promo_table_type,
            'value' => (int) $row->ms_promo_value,
            'hour' => $row->hour !== null ? (int) $row->hour : null,
            'free_hour' => $row->free_hour !== null ? (int) $row->free_hour : null,
            'category_ids' => $this->get_promo_category_ids($row->ms_promo_id),
            'valid_days' => $row->valid_days,
            'valid_time_start' => $row->valid_time_start !== null ? (int) $row->valid_time_start : null,
            'valid_time_end' => $row->valid_time_end !== null ? (int) $row->valid_time_end : null,
        );
    }

    public function add_promo($name, $tipe, $value, $hour = null, $valid_days = null, $valid_time_start = null, $valid_time_end = null, $free_hour = null, $category_ids = null, $table_type = 'billiard') {
        $data = array(
            'ms_promo_table_type' => $table_type,
            'ms_promo_name' => $name,
            'ms_promo_tipe' => $tipe,
            'ms_promo_value' => $value,
            'hour' => $hour,
            'free_hour' => $free_hour,
            'valid_days' => $valid_days,
            'valid_time_start' => $valid_time_start,
            'valid_time_end' => $valid_time_end,
            'ms_promo_active' => 'Y',
        );
        $this->db->insert('ms_promo', $data);
        $promo_id = (int) $this->db->insert_id();
        if ($promo_id && is_array($category_ids)) {
            $this->set_promo_category_ids($promo_id, $category_ids);
        }
        return $promo_id;
    }

    public function get_promo_by_id($promo_id) {
        $this->db->where('ms_promo_id', $promo_id);
        return $this->db->get('ms_promo')->row();
    }

    public function edit_promo($promo_id, $data, $category_ids = null) {
        if (!$this->get_promo_by_id($promo_id)) return false;
        if (!empty($data)) {
            $this->db->where('ms_promo_id', $promo_id);
            $this->db->update('ms_promo', $data);
        }
        if (is_array($category_ids)) {
            $this->set_promo_category_ids($promo_id, $category_ids);
        }
        return true;
    }

    public function delete_promo($promo_id) {
        if (!$this->get_promo_by_id($promo_id)) return false;
        $this->db->where('ms_promo_id', $promo_id);
        return $this->db->update('ms_promo', array('ms_promo_active' => 'N'));
    }

    public function is_promo_name_exists($name, $exclude_id = null) {
        $this->db->where('ms_promo_name', $name);
        $this->db->where('ms_promo_active', 'Y');
        if ($exclude_id) $this->db->where('ms_promo_id !=', $exclude_id);
        return $this->db->count_all_results('ms_promo') > 0;
    }

    public function is_promo_in_use($promo_id) {
        $this->db->where('table_promo_id', $promo_id);
        $this->db->where('table_active', 1);
        return $this->db->count_all_results('table_active') > 0;
    }

    /* ===================== Promo Cafe (ms_cafe_promo + ms_cafe_promo_item) ===================== */

    private function map_cafe_promo($row) {
        $this->db->select('cpi.product_id, p.product_name, p.product_price', false);
        $this->db->from('ms_cafe_promo_item cpi');
        $this->db->join('ms_product p', 'p.product_id = cpi.product_id', 'left');
        $this->db->where('cpi.ms_cafe_promo_id', $row->ms_cafe_promo_id);
        $items = array();
        foreach ($this->db->get()->result() as $it) {
            $items[] = array(
                'product_id' => (int) $it->product_id,
                'product_name' => $it->product_name,
                'product_price' => (int) $it->product_price,
            );
        }
        return array(
            'id' => (int) $row->ms_cafe_promo_id,
            'ms_cafe_promo_id' => (int) $row->ms_cafe_promo_id,
            'name' => $row->ms_cafe_promo_name,
            'price' => (int) $row->ms_cafe_promo_price,
            'active' => $row->ms_cafe_promo_active,
            'product_ids' => array_map(function ($i) { return $i['product_id']; }, $items),
            'items' => $items,
        );
    }

    public function get_cafe_promo_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('ms_cafe_promo_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_cafe_promo');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('ms_cafe_promo_active', 'Y');
        $this->db->order_by('ms_cafe_promo_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $rows = $this->db->get('ms_cafe_promo')->result();

        $data = array();
        foreach ($rows as $row) $data[] = $this->map_cafe_promo($row);

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_cafe_promo_list_no_pagging() {
        $this->db->where('ms_cafe_promo_active', 'Y');
        $this->db->order_by('ms_cafe_promo_id', 'DESC');
        $rows = $this->db->get('ms_cafe_promo')->result();
        $data = array();
        foreach ($rows as $row) $data[] = $this->map_cafe_promo($row);
        return $data;
    }

    public function is_cafe_promo_name_exists($name, $exclude_id = null) {
        $this->db->where('ms_cafe_promo_name', $name);
        $this->db->where('ms_cafe_promo_active', 'Y');
        if ($exclude_id) $this->db->where('ms_cafe_promo_id !=', (int) $exclude_id);
        return $this->db->count_all_results('ms_cafe_promo') > 0;
    }

    public function add_cafe_promo($name, $price, array $product_ids) {
        $this->db->trans_start();
        $this->db->insert('ms_cafe_promo', array(
            'ms_cafe_promo_name' => $name,
            'ms_cafe_promo_price' => (int) $price,
            'ms_cafe_promo_active' => 'Y',
        ));
        $id = (int) $this->db->insert_id();
        if ($id) {
            foreach ($product_ids as $pid) {
                $this->db->insert('ms_cafe_promo_item', array('ms_cafe_promo_id' => $id, 'product_id' => (int) $pid));
            }
        }
        $this->db->trans_complete();
        return $this->db->trans_status() ? $id : false;
    }

    public function edit_cafe_promo($id, array $data, $product_ids = null) {
        $this->db->trans_start();
        if (!empty($data)) {
            $this->db->where('ms_cafe_promo_id', (int) $id);
            $this->db->update('ms_cafe_promo', $data);
        }
        if (is_array($product_ids)) {
            $this->db->where('ms_cafe_promo_id', (int) $id)->delete('ms_cafe_promo_item');
            foreach ($product_ids as $pid) {
                $this->db->insert('ms_cafe_promo_item', array('ms_cafe_promo_id' => (int) $id, 'product_id' => (int) $pid));
            }
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    public function delete_cafe_promo($id) {
        $this->db->where('ms_cafe_promo_id', (int) $id);
        return $this->db->update('ms_cafe_promo', array('ms_cafe_promo_active' => 'N'));
    }

    // dipakai Cafe_model saat checkout - {id, price, product_ids[]} atau null kalau tidak ada/nonaktif
    public function get_cafe_promo_for_apply($id) {
        $this->db->where('ms_cafe_promo_id', (int) $id);
        $this->db->where('ms_cafe_promo_active', 'Y');
        $row = $this->db->get('ms_cafe_promo')->row();
        if (!$row) return null;
        $this->db->select('product_id');
        $this->db->where('ms_cafe_promo_id', (int) $id);
        $ids = array_map(function ($r) { return (int) $r->product_id; }, $this->db->get('ms_cafe_promo_item')->result());
        return array('id' => (int) $row->ms_cafe_promo_id, 'price' => (int) $row->ms_cafe_promo_price, 'product_ids' => $ids);
    }

    public function get_category_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('is_active', 'Y');
        $total_items = (int) $this->db->count_all_results('category');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('is_active', 'Y');
        $this->db->order_by('category_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('category');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->category_id,
                'name' => $row->category_name,
            );
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_category_list_no_pagging() {
        $this->db->where('is_active', 'Y');
        $this->db->order_by('category_id', 'ASC');
        $query = $this->db->get('category');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->category_id,
                'name' => $row->category_name,
            );
        }

        return $data;
    }

    public function add_category($name) {
        $data = array(
            'category_name' => $name,
            'is_active' => 'Y',
        );
        $this->db->insert('category', $data);
        return $this->db->insert_id();
    }

    public function get_category_by_id($category_id) {
        $this->db->where('category_id', $category_id);
        return $this->db->get('category')->row();
    }

    public function is_category_name_exists($name, $exclude_id = null) {
        $this->db->where('category_name', $name);
        $this->db->where('is_active', 'Y');
        if ($exclude_id) $this->db->where('category_id !=', $exclude_id);
        return $this->db->count_all_results('category') > 0;
    }

    public function edit_category($category_id, $data) {
        if (!$this->get_category_by_id($category_id)) return false;
        $this->db->where('category_id', $category_id);
        return $this->db->update('category', $data);
    }

    public function delete_category($category_id) {
        if (!$this->get_category_by_id($category_id)) return false;
        $this->db->where('category_id', $category_id);
        return $this->db->update('category', array('is_active' => 'N'));
    }

    public function is_category_in_use($category_id) {
        $this->db->where('category_id', $category_id);
        $this->db->where('is_active', 'Y');
        return $this->db->count_all_results('ms_product') > 0;
    }

    public function get_unit_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('is_active', 'Y');
        $total_items = (int) $this->db->count_all_results('unit');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->where('is_active', 'Y');
        $this->db->order_by('unit_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('unit');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->unit_id,
                'name' => $row->unit_name,
            );
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    public function get_unit_list_no_pagging() {
        $this->db->where('is_active', 'Y');
        $this->db->order_by('unit_id', 'ASC');
        $query = $this->db->get('unit');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->unit_id,
                'name' => $row->unit_name,
            );
        }

        return $data;
    }

    public function add_unit($name) {
        $data = array(
            'unit_name' => $name,
            'is_active' => 'Y',
        );
        $this->db->insert('unit', $data);
        return $this->db->insert_id();
    }

    public function get_unit_by_id($unit_id) {
        $this->db->where('unit_id', $unit_id);
        return $this->db->get('unit')->row();
    }

    public function is_unit_name_exists($name, $exclude_id = null) {
        $this->db->where('unit_name', $name);
        $this->db->where('is_active', 'Y');
        if ($exclude_id) $this->db->where('unit_id !=', $exclude_id);
        return $this->db->count_all_results('unit') > 0;
    }

    public function edit_unit($unit_id, $data) {
        if (!$this->get_unit_by_id($unit_id)) return false;
        $this->db->where('unit_id', $unit_id);
        return $this->db->update('unit', $data);
    }

    public function delete_unit($unit_id) {
        if (!$this->get_unit_by_id($unit_id)) return false;
        $this->db->where('unit_id', $unit_id);
        return $this->db->update('unit', array('is_active' => 'N'));
    }

    public function is_unit_in_use($unit_id) {
        $this->db->where('unit_id', $unit_id);
        $this->db->where('is_active', 'Y');
        return $this->db->count_all_results('ms_product') > 0;
    }

    private function map_product($row) {
        return array(
            'id' => (int) $row->product_id,
            'category_id' => (int) $row->category_id,
            'category_name' => isset($row->category_name) ? $row->category_name : null,
            'unit_id' => (int) $row->unit_id,
            'unit_name' => isset($row->unit_name) ? $row->unit_name : null,
            'code' => $row->product_code,
            'name' => $row->product_name,
            'cogs' => (int) $row->product_cogs,
            'price' => (int) $row->product_price,
            'image' => $row->product_image,
            'image_url' => base_url('uploads/products/' . $row->product_image),
            'stock' => (int) $row->product_stock,
            'reduce_stock' => $row->reduce_stock,
        );
    }

    public function get_product_by_id($product_id) {
        $this->db->where('product_id', $product_id);
        return $this->db->get('ms_product')->row();
    }

    private function generate_product_code() {
        $this->db->select_max('product_id');
        $row = $this->db->get('ms_product')->row();
        $next_id = ($row && $row->product_id) ? ((int) $row->product_id + 1) : 1;
        return 'P-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
    }

    public function get_product_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $this->db->where('is_active', 'Y');
        $total_items = (int) $this->db->count_all_results('ms_product');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->select('p.*, c.category_name, u.unit_name');
        $this->db->from('ms_product p');
        $this->db->join('category c', 'c.category_id = p.category_id', 'left');
        $this->db->join('unit u', 'u.unit_id = p.unit_id', 'left');
        $this->db->where('p.is_active', 'Y');
        $this->db->order_by('p.product_id', 'ASC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_product($row);
        }

        return array(
            'data' => $data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_next_page' => $page < $total_pages,
                'has_prev_page' => $page > 1,
            ),
        );
    }


    public function add_product($category_id, $unit_id, $name, $price, $cogs = 0, $image = 'default.jpg', $stock = 0, $reduce_stock = 'Y') {
        $data = array(
            'category_id' => $category_id,
            'unit_id' => $unit_id,
            'product_code' => $this->generate_product_code(),
            'product_name' => $name,
            'product_cogs' => $cogs,
            'product_price' => $price,
            'product_image' => $image,
            'product_stock' => $stock,
            'reduce_stock' => $reduce_stock,
            'is_active' => 'Y',
        );
        $this->db->insert('ms_product', $data);
        return $this->db->insert_id();
    }

    public function edit_product($product_id, $data) {
        if (!$this->get_product_by_id($product_id)) return false;
        $this->db->where('product_id', $product_id);
        return $this->db->update('ms_product', $data);
    }

    public function delete_product($product_id) {
        if (!$this->get_product_by_id($product_id)) return false;
        $this->db->where('product_id', $product_id);
        return $this->db->update('ms_product', array('is_active' => 'N'));
    }

    // === Snapshot stok & HPP/COGS produk ke laporan online - lihat Master::sync_product_stock()
    // (dipanggil tiap 30 menit oleh ProductStockSyncWatcher + manual dari Sinkron Online) &
    // Sync (retry kalau push-nya gagal). Sama pola dengan get_table_snapshot_for_sync(): state
    // PENUH seluruh katalog produk aktif, menimpa baris gameon per (branch, product_id). ===

    public function get_product_stock_snapshot_for_sync($branch) {
        $this->db->select('p.product_id, p.product_code, p.product_name, p.category_id, c.category_name,'
            . ' u.unit_name, p.product_stock, p.product_cogs, p.product_price', false);
        $this->db->from('ms_product p');
        $this->db->join('category c', 'c.category_id = p.category_id', 'left');
        $this->db->join('unit u', 'u.unit_id = p.unit_id', 'left');
        $this->db->where('p.is_active', 'Y');
        $this->db->order_by('p.product_id', 'ASC');
        $rows = $this->db->get()->result();

        $products = array();
        foreach ($rows as $r) {
            $products[] = array(
                'product_id' => (int) $r->product_id,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'category_id' => !empty($r->category_id) ? (int) $r->category_id : null,
                'category_name' => $r->category_name,
                'unit_name' => $r->unit_name,
                'stock' => (int) $r->product_stock,
                'cogs' => (int) $r->product_cogs,
                'price' => (int) $r->product_price,
            );
        }

        return array(
            'branch' => (int) $branch,
            'snapshot_at' => date('Y-m-d H:i:s'),
            'products' => $products,
        );
    }

    // catat hasil push snapshot stok terakhir per cabang. dirty='Y' -> belum berhasil sampai ke
    // gameon, muncul di halaman Sinkron Online untuk diulang.
    public function mark_product_stock_synced($branch, $ok, $snapshot_at, $error = null) {
        $branch = (int) $branch;
        $row = array(
            'branch' => $branch,
            'dirty' => $ok ? 'N' : 'Y',
            'last_snapshot_at' => $snapshot_at,
            'last_error' => $ok ? null : (is_string($error) ? substr($error, 0, 500) : null),
        );
        if ($ok) $row['last_ok_at'] = $snapshot_at;

        $exists = $this->db->where('branch', $branch)->get('product_stock_sync')->row();
        if ($exists) {
            $this->db->where('branch', $branch);
            $this->db->update('product_stock_sync', $row);
        } else {
            $this->db->insert('product_stock_sync', $row);
        }
    }

    /**
     * Customer Time (waktu tersimpan dari billing timer yang belum selesai)
     */

    public function get_category_meja_by_id($category_meja_id) {
        $this->db->where('category_meja_id', $category_meja_id);
        $this->db->where('category_meja_active', 'Y');
        return $this->db->get('category_meja')->row();
    }

    // resolve category_meja_id dari 1 meja (table_id). null kalau meja tidak ditemukan / category belum di set
    public function get_table_category_meja_id($table_id) {
        $this->db->where('table_id', $table_id);
        $table_row = $this->db->get('table_active')->row();
        if (!$table_row || empty($table_row->table_category)) {
            return null;
        }
        return (int) $table_row->table_category;
    }

    // setting global apakah fitur simpan waktu billing timer aktif (ditampilkan di FE), diambil dari save_time_setting
    public function check_time_save() {
        $row = $this->db->get('save_time_setting')->row();
        return array(
            'check_time_save' => $row ? $row->save_time_setting_value : 'N',
        );
    }

    /**
     * End Customer Time
     */
}

?>