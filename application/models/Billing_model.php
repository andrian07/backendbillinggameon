<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class billing_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // $table_type: null = semua meja (dipakai Sync/laporan dkk), 'billiard'/'mahjong' = filter
    // langsung di WHERE (dipakai halaman Billing & Mahjong, lihat Billing::get_table_list())
    public function get_table_list($table_type = null) {
        // table_type (billiard/mahjong) sekarang kolom langsung di table_active (bukan JOIN ke
        // category_meja lagi) - di-sync otomatis tiap table_category diubah, lihat
        // update_setting_table() di bawah. Di-alias 'category_type' di output karena itu nama
        // field yang sudah dipakai Flutter (PoolTable.categoryType).
        $this->db->select('*, table_type AS category_type', false);
        $this->db->where('is_active', 1);
        if ($table_type !== null) $this->db->where('table_type', $table_type);
        $query = $this->db->get('table_active');
        return $query->result();
    }

    public function get_table_by_id($table_id) {
        $this->db->where('table_id', $table_id);
        return $this->db->get('table_active')->row();
    }

    // === Status meja (buka/tutup) di-curl ke laporan online - lihat Billing::book_table/payment/
    // cancel_table (yang mencatat event ini) & Sync (retry kalau push-nya gagal). ===

    // $data: table_id, table_number, event ('open'|'close'), mode, customer_id, customer_name,
    // start_time, end_time, reason ('payment'|'cancel', null untuk 'open'), branch, created_by.
    // return id baris yang baru dibuat, dipakai buat push ke gameon.
    public function record_table_event($data) {
        $this->db->insert('table_status_event', array(
            'table_id' => (int) $data['table_id'],
            'table_number' => $data['table_number'],
            'event' => $data['event'],
            'mode' => isset($data['mode']) ? $data['mode'] : null,
            'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'customer_name' => isset($data['customer_name']) ? $data['customer_name'] : null,
            'start_time' => isset($data['start_time']) ? $data['start_time'] : null,
            'end_time' => isset($data['end_time']) ? $data['end_time'] : null,
            'reason' => isset($data['reason']) ? $data['reason'] : null,
            'branch' => isset($data['branch']) ? (int) $data['branch'] : 1,
            'created_by' => $data['created_by'],
        ));
        return (int) $this->db->insert_id();
    }

    // rebuild payload gameon->push_table_status() dari baris yang tersimpan - dipakai retry (Sync)
    // supaya event yang gagal push pertama kali bisa diulang tanpa data baru dari client.
    public function get_table_event_for_sync($table_status_event_id) {
        $this->db->where('table_status_event_id', $table_status_event_id);
        $row = $this->db->get('table_status_event')->row();
        if (!$row) return null;

        return array(
            'branch' => (int) $row->branch,
            'local_event_id' => (int) $row->table_status_event_id,
            'table_id' => (int) $row->table_id,
            'table_number' => $row->table_number,
            'event' => $row->event,
            'mode' => $row->mode,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'customer_name' => $row->customer_name,
            'start_time' => $row->start_time,
            'end_time' => $row->end_time,
            'reason' => $row->reason,
            'created_by' => $row->created_by,
            'created_at' => $row->created_at,
        );
    }

    public function mark_table_event_uploaded($table_status_event_id) {
        $this->db->where('table_status_event_id', $table_status_event_id);
        $this->db->update('table_status_event', array('upload_status' => 'Y'));
    }

    // === Snapshot "papan meja live" ke laporan online - lihat Billing::_sync_table_snapshot_to_gameon()
    // (dipanggil tiap table_active berubah) & Sync (retry kalau push-nya gagal). Beda dari
    // table_status_event yang event-based: ini state PENUH semua meja, menimpa baris gameon. ===

    // payload push_table_snapshot(): state terkini SEMUA meja cabang. Meja kosong dikirim dengan
    // in_use=0 dan field sesi dikosongkan supaya gameon bisa "membersihkan" meja yang baru ditutup.
    public function get_table_snapshot_for_sync($branch) {
        $this->db->select(
            // table_active kolomnya bit(1) - "+ 0" memaksa jadi 0/1 supaya tidak ambigu di PHP
            'table_active.table_id, table_active.table_number, (table_active.table_active + 0) AS in_use_flag,'
            . ' table_active.table_mode, table_active.table_customer_id, table_active.table_customer_name,'
            . ' table_active.table_promo_id, table_active.table_start_time, table_active.table_end_time,'
            . ' table_active.table_duration, table_active.use_saved_time, table_active.table_bill,'
            . ' table_active.table_category, category_meja.category_meja_name, ms_promo.ms_promo_name',
            false
        );
        $this->db->from('table_active');
        $this->db->join('category_meja', 'category_meja.category_meja_id = table_active.table_category', 'left');
        $this->db->join('ms_promo', 'ms_promo.ms_promo_id = table_active.table_promo_id', 'left');
        $this->db->where('table_active.is_active', 1);
        $this->db->order_by('table_active.table_id', 'ASC');
        $rows = $this->db->get()->result();

        $tables = array();
        foreach ($rows as $r) {
            $in_use = ((int) $r->in_use_flag) === 1;
            $promo_id = (int) $r->table_promo_id;
            $category_id = (!empty($r->table_category) && (int) $r->table_category > 0) ? (int) $r->table_category : null;

            $tables[] = array(
                'table_id' => (int) $r->table_id,
                'table_number' => $r->table_number,
                'in_use' => $in_use ? 1 : 0,
                'mode' => $in_use ? $r->table_mode : null,
                'customer_id' => ($in_use && !empty($r->table_customer_id)) ? (int) $r->table_customer_id : null,
                'customer_name' => $in_use ? $r->table_customer_name : null,
                'promo_id' => ($in_use && $promo_id > 0) ? $promo_id : null,
                'promo_name' => ($in_use && $promo_id > 0) ? $r->ms_promo_name : null,
                'category_id' => $category_id,
                'category_name' => $category_id !== null ? $r->category_meja_name : null,
                'start_time' => $in_use ? $r->table_start_time : null,
                // Timer: jam waktu habis. Reguler: null (open-ended, hitung naik).
                'end_time' => $in_use ? $r->table_end_time : null,
                'duration' => $in_use ? $r->table_duration : null,
                'use_saved_time' => ($in_use && strtoupper((string) $r->use_saved_time) === 'Y') ? 'Y' : 'N',
                'bill' => (int) $r->table_bill,
            );
        }

        return array(
            'branch' => (int) $branch,
            'snapshot_at' => date('Y-m-d H:i:s'),
            'tables' => $tables,
        );
    }

    // catat hasil push snapshot terakhir per cabang. dirty='Y' -> ada perubahan meja yang belum
    // sampai ke gameon, muncul di halaman Sinkron Online untuk diulang.
    public function mark_table_snapshot_synced($branch, $ok, $snapshot_at, $error = null) {
        $branch = (int) $branch;
        $row = array(
            'branch' => $branch,
            'dirty' => $ok ? 'N' : 'Y',
            'last_snapshot_at' => $snapshot_at,
            'last_error' => $ok ? null : (is_string($error) ? substr($error, 0, 500) : null),
        );
        if ($ok) $row['last_ok_at'] = $snapshot_at;

        $exists = $this->db->where('branch', $branch)->get('table_state_sync')->row();
        if ($exists) {
            $this->db->where('branch', $branch);
            $this->db->update('table_state_sync', $row);
        } else {
            $this->db->insert('table_state_sync', $row);
        }
    }

    // promo tipe 'Fix' = paket harga+durasi tetap (lihat calculate_price()) - meja yang memakainya
    // tidak boleh ditambah durasi lagi karena akan merusak asumsi paket tetapnya
    public function is_fix_promo($promo_id) {
        if (empty($promo_id)) return false;
        $promo = $this->db->where('ms_promo_id', $promo_id)->get('ms_promo')->row();
        return $promo && $promo->ms_promo_tipe === 'Fix';
    }

    public function get_table_list_ordered() {
        $this->db->select('table_id, relay_number, table_active');
        $this->db->where('is_active', 1);
        $this->db->order_by('table_id', 'ASC');
        $query = $this->db->get('table_active');
        return $query->result();
    }

    public function get_payment_by_id($payment_id) {
        $this->db->where('payment_id', $payment_id);
        return $this->db->get('ms_payment')->row();
    }

    // dipakai payment() untuk meng-override metode bayar jadi "Potong Waktu" saat used_save_time = Y
    public function get_payment_by_name($payment_name) {
        $this->db->where('payment_name', $payment_name);
        return $this->db->get('ms_payment')->row();
    }

    private function map_transaction($row) {
        return array(
            'id' => (int) $row->transaction_id,
            'inv' => $row->transaction_inv,
            'date' => $row->transaction_date,
            'mode' => $row->transaction_mode,
            'customer_id' => $row->transaction_customer_id !== null ? (int) $row->transaction_customer_id : null,
            'players' => isset($row->transaction_players) ? $row->transaction_players : null,
            'player_ids' => isset($row->transaction_player_ids) ? $row->transaction_player_ids : null,
            'payment_id' => (int) $row->transaction_payment_id,
            'promo_id' => (int) $row->transaction_promo_id,
            'start_time' => $row->transaction_start_time,
            'end_time' => $row->transaction_end_time,
            'duration' => $row->transaction_duration,
            'sub_total' => (int) $row->transaction_sub_total,
            'discount' => (int) $row->transaction_discount,
            'tax' => (int) $row->transaction_tax,
            'total_bill' => (int) $row->transaction_total_bill,
            'table' => (int) $row->transaction_table,
            // label meja SEBENARNYA (mis. "Private 1", "VIP", "01") dari table_active.table_number -
            // "table" di atas cuma table_id (PK), BUKAN nomor/nama meja yang ditampilkan ke user.
            // null kalau mejanya sudah dihapus dari table_active sejak transaksi ini dibuat.
            'table_number' => isset($row->table_number) ? $row->table_number : null,
            'status' => $row->transaction_status,
            'payment_type' => $row->transaction_type,
            'created_by' => $row->created_by,
            'paid_by' => (int) $row->paid_by,
            'payment_edited_by' => $row->transaction_payment_edited_by,
            'payment_edited_at' => $row->transaction_payment_edited_at,
            'cancelled_by' => isset($row->transaction_cancelled_by) ? $row->transaction_cancelled_by : null,
            'cancelled_at' => isset($row->transaction_cancelled_at) ? $row->transaction_cancelled_at : null,
            // billiard/mahjong - snapshot yang disimpan add_transaction() saat payment(), dipakai
            // Flutter buat filter tab Transaksi (lihat TransactionPage)
            'category_type' => $row->transaction_category_type,
        );
    }

    // JOIN table_active dipisah jadi helper supaya list & detail (dua query beda) sama-sama
    // membawa table_number tanpa duplikasi select/join.
    private function _transaction_select_with_table() {
        $this->db->select('transaction.*, table_active.table_number', false);
        $this->db->from('transaction');
        $this->db->join('table_active', 'table_active.table_id = transaction.transaction_table', 'left');
    }

    public function get_transaction_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $total_items = (int) $this->db->count_all_results('transaction');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->_transaction_select_with_table();
        $this->db->order_by('transaction.transaction_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_transaction($row);
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

    public function get_transaction_detail($transaction_id) {
        if (empty($transaction_id)) return null;
        $this->_transaction_select_with_table();
        $this->db->where('transaction.transaction_id', $transaction_id);
        $row = $this->db->get()->row();
        return $row ? $this->map_transaction($row) : null;
    }

    // payload untuk push ke gameon (Gameon::push_transaction). id transaksi cabang TIDAK
    // disertakan; nama payment/promo/customer ikut dibawa karena id-nya beda per cabang.
    public function get_transaction_for_sync($transaction_id) {
        if (empty($transaction_id)) return null;

        $this->db->select('t.*, pay.payment_name, c.customer_name, p.ms_promo_name, p.ms_promo_tipe, p.ms_promo_value', false);
        $this->db->from('transaction t');
        $this->db->join('ms_payment pay', 'pay.payment_id = t.transaction_payment_id', 'left');
        $this->db->join('ms_customer c', 'c.customer_id = t.transaction_customer_id', 'left');
        $this->db->join('ms_promo p', 'p.ms_promo_id = t.transaction_promo_id', 'left');
        $this->db->where('t.transaction_id', $transaction_id);
        $row = $this->db->get()->row();
        if (!$row) return null;

        return array(
            'branch' => (int) $row->branch,
            'transaction_inv' => $row->transaction_inv,
            'transaction_date' => $row->transaction_date,
            'transaction_created_at' => $row->created_at,
            'transaction_mode' => $row->transaction_mode,
            'transaction_customer_id' => $row->transaction_customer_id !== null ? (int) $row->transaction_customer_id : null,
            'transaction_customer_name' => $row->customer_name,
            'transaction_payment_id' => (int) $row->transaction_payment_id,
            'transaction_payment_name' => $row->payment_name,
            'transaction_promo_id' => (int) $row->transaction_promo_id,
            'transaction_promo_name' => $row->ms_promo_name,
            'transaction_promo_tipe' => $row->ms_promo_tipe,
            'transaction_promo_value' => $row->ms_promo_value !== null ? (int) $row->ms_promo_value : null,
            'transaction_start_time' => $row->transaction_start_time,
            'transaction_end_time' => $row->transaction_end_time,
            'transaction_duration' => $row->transaction_duration,
            'transaction_sub_total' => (int) $row->transaction_sub_total,
            'transaction_discount' => (int) $row->transaction_discount,
            'transaction_tax' => (int) $row->transaction_tax,
            'transaction_total_bill' => (int) $row->transaction_total_bill,
            'transaction_saved_time_value' => $row->transaction_saved_time_value !== null ? (int) $row->transaction_saved_time_value : null,
            'transaction_table' => (int) $row->transaction_table,
            'transaction_status' => $row->transaction_status,
            'transaction_type' => $row->transaction_type,
            'transaction_note' => $row->transaction_note,
            'created_by' => $row->created_by,
            'paid_by' => (int) $row->paid_by,
            'transaction_payment_edited_by' => $row->transaction_payment_edited_by,
            'transaction_payment_edited_at' => $row->transaction_payment_edited_at,
        );
    }

    // ditandai 'Y' setelah push ke gameon (laporan online) berhasil - lihat
    // Billing::_sync_transaction_to_gameon() & Sync::retry(). Selamanya 'N' kalau belum pernah berhasil.
    public function mark_transaction_uploaded($transaction_id) {
        $this->db->where('transaction_id', $transaction_id);
        $this->db->update('transaction', array('transaction_upload_status' => 'Y'));
    }

    // ganti metode pembayaran transaksi yang sudah selesai. Ditolak kalau transaksi tidak ditemukan,
    // sudah dibatalkan, metode baru tidak ada, atau metode LAMA/BARU adalah "Potong Saldo" - karena
    // saldo customer sudah divalidasi & dipotong berdasarkan metode ASLI saat transaksi dibuat
    // (lihat Billing::payment()), dan tidak ada logika koreksi saldo untuk kasus edit setelah itu.
    // return true kalau berhasil, atau salah satu dari 'NOT_FOUND'/'CANCELLED'/'PAYMENT_NOT_FOUND'/'SALDO_NOT_ALLOWED'.
    public function edit_payment_transaction($transaction_id, $payment_id, $created_by) {
        $this->db->where('transaction_id', $transaction_id);
        $transaction = $this->db->get('transaction')->row();
        if (!$transaction) return 'NOT_FOUND';
        if (strtolower($transaction->transaction_status) === 'cancel') return 'CANCELLED';

        $old_payment = $transaction->transaction_payment_id
            ? $this->db->where('payment_id', $transaction->transaction_payment_id)->get('ms_payment')->row()
            : null;
        $new_payment = $this->db->where('payment_id', $payment_id)->get('ms_payment')->row();
        if (!$new_payment) return 'PAYMENT_NOT_FOUND';

        $old_is_saldo = $old_payment && $old_payment->payment_name === 'Potong Saldo';
        $new_is_saldo = $new_payment->payment_name === 'Potong Saldo';
        if ($old_is_saldo || $new_is_saldo) return 'SALDO_NOT_ALLOWED';

        $this->db->trans_start();
        $this->db->where('transaction_id', $transaction_id);
        $this->db->update('transaction', array(
            'transaction_payment_id' => $payment_id,
            'transaction_payment_edited_by' => $created_by,
            'transaction_payment_edited_at' => date('Y-m-d H:i:s'),
        ));
        $this->db->trans_complete();
        if (!$this->db->trans_status()) return false;

        return true;
    }

    // batalkan transaksi billing yang SUDAH "Done" (beda dari cancel_table() yang membatalkan MEJA
    // yang masih aktif dalam 6 menit pertama - ini untuk transaksi yang sudah tercatat di riwayat).
    // Ditolak untuk metode "Potong Saldo" atau transaction_type "Gunakan Timer" (dibayar pakai waktu
    // tersimpan) - saldo/waktu customer sudah benar-benar terpotong di gameon saat payment() dan
    // deduction_ref-nya tidak disimpan ke tabel transaction, jadi tidak ada cara aman merefund-nya
    // otomatis di sini (sama seperti guard SALDO_NOT_ALLOWED di edit_payment_transaction() di atas).
    // Poin member juga TIDAK direfund (tidak ada endpoint pengurangan poin di gameon) - konsisten
    // dengan keputusan yang sudah dipakai di Cafe_model::cancel_transaction_cafe() (saldo juga tidak
    // direfund di sana). Status meja TIDAK disentuh - meja mungkin sudah lama dipakai sesi lain sejak
    // transaksi ini dibuat, beda dari cancel_table() yang mejanya masih dalam sesi yang sama.
    public function cancel_transaction($transaction_id, $created_by) {
        $this->db->where('transaction_id', $transaction_id);
        $transaction = $this->db->get('transaction')->row();
        if (!$transaction) return 'NOT_FOUND';
        if (strtolower($transaction->transaction_status) === 'cancel') return 'ALREADY_CANCELLED';

        $payment = $transaction->transaction_payment_id
            ? $this->db->where('payment_id', $transaction->transaction_payment_id)->get('ms_payment')->row()
            : null;
        if ($payment && $payment->payment_name === 'Potong Saldo') return 'SALDO_NOT_ALLOWED';
        if (strtolower((string) $transaction->transaction_type) === strtolower('Gunakan Timer')) {
            return 'SAVED_TIME_NOT_ALLOWED';
        }

        $this->db->trans_start();

        // UPDATE bersyarat (status != Cancel) atomik, sama pola dengan claim_table_for_payment() &
        // Cafe_model::cancel_transaction_cafe() - jaga-jaga kalau tombol Cancel diklik dua kali
        // nyaris bersamaan, cuma satu yang berhasil memproses.
        $this->db->where('transaction_id', $transaction_id);
        $this->db->where('transaction_status !=', 'Cancel');
        $this->db->update('transaction', array(
            'transaction_status' => 'Cancel',
            'transaction_cancelled_by' => $created_by,
            'transaction_cancelled_at' => date('Y-m-d H:i:s'),
        ));

        if ($this->db->affected_rows() <= 0) {
            $this->db->trans_complete();
            return 'ALREADY_CANCELLED';
        }

        $this->db->trans_complete();
        return $this->db->trans_status() ? true : false;
    }

    private function map_transaction_saldo($row) {
        return array(
            'id' => (int) $row->transaksi_saldo_id,
            'inv' => $row->transaksi_saldo_inv,
            'customer_id' => (int) $row->customer_id,
            'customer_name' => isset($row->customer_name) ? $row->customer_name : null,
            'ms_saldo_id' => (int) $row->transaksi_saldo_amount,
            'nominal' => isset($row->ms_saldo_nominal) ? (int) $row->ms_saldo_nominal : null,
            'pay' => (int) $row->transaction_saldo_pay,
            'discount_pay' => (int) $row->transaction_discount_pay,
            'payment_id' => (int) $row->transaksi_saldo_payment_id,
            'payment_name' => isset($row->payment_name) ? $row->payment_name : null,
            'status' => $row->transaksi_saldo_status,
            'created_by' => $row->created_by,
            'created_at' => $row->created_at,
            'paid_by' => (int) $row->paid_by,
        );
    }

    public function get_transaction_saldo_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $total_items = (int) $this->db->count_all_results('transaksi_saldo');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->select('ts.*, c.customer_name, p.payment_name, ms.ms_saldo_nominal', false);
        $this->db->from('transaksi_saldo ts');
        $this->db->join('ms_customer c', 'c.customer_id = ts.customer_id', 'left');
        $this->db->join('ms_payment p', 'p.payment_id = ts.transaksi_saldo_payment_id', 'left');
        $this->db->join('ms_saldo ms', 'ms.ms_saldo_id = ts.transaksi_saldo_amount', 'left');
        $this->db->order_by('ts.transaksi_saldo_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_transaction_saldo($row);
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

    private function get_business_day_range() {
        // hari operasional = tanggal kalender hari ini saja, jam 06:00:00 s/d 23:59:59.
        // tidak ada pergeseran dini hari: transaksi jam 00:00-05:59 tidak masuk hitungan hari mana pun.
        $business_date = date('Y-m-d');

        $start = $business_date . ' 06:00:00';
        $end = $business_date . ' 23:59:59';

        return array('business_date' => $business_date, 'start' => $start, 'end' => $end);
    }

    public function get_total_transaction_per_cashier($user_id) {
        $range = $this->get_business_day_range();

        $this->db->select('t.paid_by, u.username, COUNT(t.transaction_id) AS total_transaction, SUM(t.transaction_total_bill) AS total_amount', false);
        $this->db->from('transaction t');
        $this->db->join('ms_user u', 'u.user_id = t.paid_by', 'left');
        $this->db->where('t.created_at >=', $range['start']);
        $this->db->where('t.created_at <', $range['end']);
        $this->db->where('t.transaction_status !=', 'cancel');
        $this->db->group_by('t.paid_by');
        $this->db->order_by('total_amount', 'DESC');
        $query = $this->db->get();

        $cashiers = array();
        $current_cashier = null;
        foreach ($query->result() as $row) {
            $item = array(
                'user_id' => (int) $row->paid_by,
                'username' => $row->username,
                'total_transaction' => (int) $row->total_transaction,
                'total_amount' => (int) $row->total_amount,
            );
            $cashiers[] = $item;
            if ((int) $row->paid_by === (int) $user_id) {
                $current_cashier = $item;
            }
        }

        if ($current_cashier === null) {
            $user = $this->db->where('user_id', $user_id)->get('ms_user')->row();
            $current_cashier = array(
                'user_id' => (int) $user_id,
                'username' => $user ? $user->username : null,
                'total_transaction' => 0,
                'total_amount' => 0,
            );
        }

        return array(
            'business_date' => $range['business_date'],
            'cashiers' => $cashiers,
            'current_cashier' => $current_cashier,
        );
    }

    public function get_promo_detail($promo_id) {
        if (empty($promo_id)) return null;
        $this->db->where('ms_promo_id', $promo_id);
        return $this->db->get('ms_promo')->row();
    }

    // Promo Fix "beli X jam gratis Y jam" (ms_promo.hour = X, ms_promo.free_hour = Y) - HANYA berefek
    // saat booking pakai waktu tersimpan (saldo waktu di gameon): kalau durasi main > X jam, bagian
    // gratisnya (maks Y jam, tidak proporsional/berkelipatan - lihat contoh di bawah) TIDAK dipotong
    // dari saldo waktu customer. Kalau durasi <= X jam, promo tidak berlaku (potong penuh seperti biasa).
    //
    // contoh X=2, Y=1: main 2 jam -> potong 2 jam (promo belum berlaku, durasi belum lewat 2 jam).
    //                   main 2.5 jam -> potong 2 jam (0.5 jam kelebihan masih di dalam jatah gratis 1 jam).
    //                   main 3 jam   -> potong 2 jam (persis jatah gratis 1 jam).
    //                   main 4 jam   -> potong 3 jam (jatah gratis mentok di 1 jam, 1 jam sisanya potong normal).
    //
    // return detik yang harus dipotong dari saldo waktu tersimpan (<= $duration_seconds).
    public function apply_promo_free_hour($duration_seconds, $promo) {
        if (!$promo || $promo->ms_promo_tipe !== 'Fix' || empty($promo->hour) || empty($promo->free_hour)) {
            return $duration_seconds;
        }

        $threshold_seconds = (int) $promo->hour * 3600;
        if ($duration_seconds <= $threshold_seconds) return $duration_seconds;

        $free_seconds = (int) $promo->free_hour * 3600;
        $excess_seconds = $duration_seconds - $threshold_seconds;
        $free_applied = min($excess_seconds, $free_seconds);

        return $duration_seconds - $free_applied;
    }

    public function get_customer_detail($customer_id) {
        if (empty($customer_id)) return null;
        $this->db->where('customer_id', $customer_id);
        return $this->db->get('ms_customer')->row();
    }

    // Validasi & resolve daftar pemain mahjong (maks 4, harus member terdaftar) dari
    // $player_ids_raw ("12,34,56", dikirim client) - dipakai book_table(). Return array
    // ['ids' => 'id1,id2,...'|null, 'names' => 'Nama1, Nama2, ...'|null] kalau valid, atau
    // string pesan error kalau tidak (dedup, lebih dari 4, member tidak ada, atau member
    // itu sedang aktif di meja lain - "1 member 1 meja" berlaku lintas billiard & mahjong).
    public function resolve_players($player_ids_raw, $exclude_table_id) {
        $ids = array();
        foreach (explode(',', (string) $player_ids_raw) as $raw) {
            $id = (int) trim($raw);
            if ($id > 0) $ids[] = $id;
        }
        if (empty($ids)) return array('ids' => null, 'names' => null);

        if (count($ids) > 4) {
            return 'Maksimal 4 pemain per meja';
        }
        if (count($ids) !== count(array_unique($ids))) {
            return 'Tidak boleh memilih member yang sama lebih dari sekali di meja ini';
        }

        $names = array();
        foreach ($ids as $id) {
            $customer = $this->get_customer_detail($id);
            if (!$customer) return 'Salah satu pemain tidak ditemukan sebagai member';
            $names[] = $customer->customer_name;
        }

        // "1 member 1 meja": cek member yang dipilih tidak sedang aktif (table_active=1) di meja
        // lain - baik sebagai member utama (table_customer_id) maupun sebagai salah satu pemain
        // mahjong (table_player_ids) meja lain. table_player_ids disimpan sebagai teks dipisah
        // koma, jadi dicocokkan di PHP (bukan SQL IN) setelah baris aktifnya diambil.
        $this->db->select('table_id, table_number, table_customer_id, table_player_ids');
        $this->db->where('table_active', 1);
        $this->db->where('table_id !=', $exclude_table_id);
        $active_tables = $this->db->get('table_active')->result();

        foreach ($active_tables as $t) {
            $occupied = array();
            if (!empty($t->table_customer_id)) $occupied[] = (int) $t->table_customer_id;
            if (!empty($t->table_player_ids)) {
                foreach (explode(',', $t->table_player_ids) as $raw) {
                    $id = (int) trim($raw);
                    if ($id > 0) $occupied[] = $id;
                }
            }
            $clash = array_intersect($ids, $occupied);
            if (!empty($clash)) {
                $clash_id = reset($clash);
                $clash_index = array_search($clash_id, $ids, true);
                $clash_name = $names[$clash_index];
                return $clash_name . ' sedang aktif di meja ' . $t->table_number . ' - 1 member cuma bisa main di 1 meja';
            }
        }

        return array('ids' => implode(',', $ids), 'names' => implode(', ', $names));
    }

    public function book_table($table_id, $data) {
        if (!$this->get_table_by_id($table_id)) return false;
        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', $data);
    }

    public function edit_table_active($table_id, $active) {
        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', array('table_active' => $active));
    }

    public function get_setting_table() {
        $this->db->select('table_id, relay_number, table_number, table_point, table_category, table_active, table_type, category_meja_name');
        $this->db->join('category_meja', 'category_meja.category_meja_id = table_active.table_category', 'left');
        $this->db->where('is_active', 1);
        $this->db->order_by('table_id', 'ASC');
        $query = $this->db->get('table_active');

        $data = array();
        foreach ($query->result() as $row) {
            $category_id = ($row->table_category !== null && (int) $row->table_category > 0)
                ? (int) $row->table_category
                : null;
            $data[] = array(
                'table_id' => (int) $row->table_id,
                'table_relay' => (int) $row->relay_number,
                'table_number' => $row->table_number,
                'table_point' => (int) $row->table_point,
                // id kategori: dikirim dalam beberapa nama key supaya cocok dengan
                // klien (app baca table_category_id/name; key lama tetap ada).
                'table_category' => $row->table_category,
                'table_category_id' => $category_id,
                'table_category_name' => $category_id !== null ? $row->category_meja_name : null,
                'table_active' => $row->table_active,
                'table_type' => $row->table_type === 'mahjong' ? 'mahjong' : 'billiard',
            );
        }
        return $data;
    }

    // table_point sengaja tidak bisa diubah lewat fungsi ini
    public function update_setting_table($table_id, $data) {
        $update = array();
        if (array_key_exists('table_relay', $data)) $update['relay_number'] = (int) $data['table_relay'];
        if (array_key_exists('table_number', $data)) $update['table_number'] = $data['table_number'];
        // category_meja_id selalu >= 1, jadi 0/kosong dianggap "tidak diisi" (bukan permintaan mengosongkan category) untuk mencegah ke-reset tidak sengaja
        if (!empty($data['table_category'])) {
            $update['table_category'] = (int) $data['table_category'];
            // table_type ikut disinkronkan ke jenis kategori barunya, supaya kolom cepat ini
            // (dipakai get_table_list()) tidak pernah nyasar beda dari category_meja_type-nya
            $category = $this->db->where('category_meja_id', $update['table_category'])->get('category_meja')->row();
            if ($category) $update['table_type'] = $category->category_meja_type;
        }

        if (empty($update)) return false;

        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', $update);
    }

    private function time_to_seconds($time) {
        sscanf($time, '%d:%d:%d', $h, $m, $s);
        return ((int) $h * 3600) + ((int) $m * 60) + (int) $s;
    }

    private function seconds_to_time($seconds) {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    public function add_duration($table_id, $additional_duration) {
        $table = $this->get_table_by_id($table_id);
        if (!$table) return false;

        $additional_seconds = $this->time_to_seconds($additional_duration);
        $new_duration_seconds = $this->time_to_seconds($table->table_duration) + $additional_seconds;
        $new_end_time = date('Y-m-d H:i:s', strtotime($table->table_end_time) + $additional_seconds);

        $data = array(
            'table_end_time' => $new_end_time,
            'table_duration' => $this->seconds_to_time($new_duration_seconds),
        );

        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', $data);
    }

    private function default_table_state() {
        return array(
            'table_customer_id' => null,
            'table_customer_name' => null,
            'table_players' => null,
            'table_player_ids' => null,
            // table_promo_id NOT NULL di database, jadi direset ke 0
            'table_promo_id' => 0,
            'table_mode' => null,
            'table_start_time' => null,
            'table_end_time' => null,
            'table_duration' => null,
            'use_saved_time' => 'N',
            'saved_time_deducted' => null,
            'prepaid_saldo' => 'N',
            'saldo_prepaid_amount' => 0,
            'saldo_prepaid_ref' => null,
            'table_bill' => 0,
            'table_active' => 0,
        );
    }

    public function reset_table() {
        return $this->db->update('table_active', $this->default_table_state());
    }

    // "klaim" meja untuk dibayar: UPDATE bersyarat table_active=1 -> 0 dalam satu query atomik.
    // Kalau 2 permintaan payment nyaris bersamaan untuk meja yang sama, MySQL mengunci baris ini
    // selama UPDATE berjalan sehingga hanya SATU yang bisa mengubah table_active dari 1 ke 0 -
    // permintaan kedua akan melihat affected_rows()==0 dan gagal, mencegah pembayaran ganda pada satu sesi.
    public function claim_table_for_payment($table_id) {
        $this->db->where('table_id', $table_id);
        $this->db->where('table_active', 1);
        $this->db->update('table_active', array('table_active' => 0));
        return $this->db->affected_rows() > 0;
    }

    // kembalikan table_active ke 1 kalau add_transaction() gagal setelah klaim berhasil, supaya meja
    // tidak nyangkut nonaktif tanpa ada transaksi yang tercatat
    public function unclaim_table($table_id) {
        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', array('table_active' => 1));
    }

    // kebalikan dari claim_table_for_payment(): UPDATE bersyarat table_active=0 -> 1, dipakai untuk
    // "mengunci" meja TUJUAN pada move_table() supaya dua perpindahan yang nyaris bersamaan ke meja
    // kosong yang sama tidak bisa berdua-duanya berhasil
    public function claim_free_table($table_id) {
        $this->db->where('table_id', $table_id);
        $this->db->where('table_active', 0);
        $this->db->update('table_active', array('table_active' => 1));
        return $this->db->affected_rows() > 0;
    }

    // kembalikan table_active ke 0 kalau move_table() gagal setelah meja tujuan terlanjur diklaim
    public function mark_table_free($table_id) {
        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', array('table_active' => 0));
    }

    public function clear_table($table_id) {
        $this->db->where('table_id', $table_id);
        return $this->db->update('table_active', $this->default_table_state());
    }

    public function move_table($from_table_id, $to_table_id) {
        $this->db->trans_start();

        $this->db->where('table_id', $from_table_id);
        $source = $this->db->get('table_active')->row();

        if ($source) {
            $data = array(
                'table_customer_id' => $source->table_customer_id,
                'table_customer_name' => $source->table_customer_name,
                'table_promo_id' => $source->table_promo_id,
                'table_mode' => $source->table_mode,
                'table_start_time' => $source->table_start_time,
                'table_end_time' => $source->table_end_time,
                'table_duration' => $source->table_duration,
                'use_saved_time' => $source->use_saved_time,
                'saved_time_deducted' => $source->saved_time_deducted,
                'prepaid_saldo' => $source->prepaid_saldo,
                'saldo_prepaid_amount' => $source->saldo_prepaid_amount,
                'saldo_prepaid_ref' => $source->saldo_prepaid_ref,
                'table_bill' => $source->table_bill,
                // table_active TIDAK disalin di sini - meja asal & tujuan sudah benar diklaim/dibebaskan
                // secara atomik oleh claim_table_for_payment()/claim_free_table() di controller sebelum
                // fungsi ini dipanggil (lihat move_table() di Billing.php)
            );

            $this->db->where('table_id', $to_table_id);
            $this->db->update('table_active', $data);

            $this->clear_table($from_table_id);
        }

        $this->db->trans_complete();
        return $source && $this->db->trans_status();
    }

    private function generate_transaction_inv() {
        // format: INV/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('transaction');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'INV/' . date('d') . '/' . date('m') . $seq;
    }

    private function calculate_discount($promo_id, $total_bill) {
        if (empty($promo_id)) return 0;

        $promo = $this->db->where('ms_promo_id', $promo_id)->get('ms_promo')->row();
        if (!$promo) return 0;

        if ($promo->ms_promo_tipe === 'Diskon') {
            return (int) round($total_bill * $promo->ms_promo_value / 100);
        }
        if ($promo->ms_promo_tipe === 'Fix') {
            return (int) $promo->ms_promo_value;
        }
        return 0;
    }

    public function add_transaction($data) {
        $this->db->trans_start();

        $insert = array(
            'transaction_inv' => $this->generate_transaction_inv(),
            'transaction_date' => date('Y-m-d'),
            'transaction_mode' => $data['mode'],
            'transaction_customer_id' => $data['customer_id'],
            'transaction_players' => isset($data['players']) ? $data['players'] : null,
            'transaction_player_ids' => isset($data['player_ids']) ? $data['player_ids'] : null,
            'transaction_payment_id' => $data['payment_id'],
            'transaction_promo_id' => $data['promo_id'],
            'transaction_start_time' => $data['start_time'],
            'transaction_end_time' => $data['end_time'],
            'transaction_duration' => $data['duration'],
            'transaction_sub_total' => $data['sub_total'],
            // nominal diskon riil - dihitung sekali di calculate_price() (lihat total_promo di
            // sana, termasuk promo Fix yang sekarang dihitung dari selisih harga normal vs harga
            // paket) dan diteruskan payment() ke sini, bukan dihitung ulang - calculate_discount()
            // di bawah cuma fallback kalau caller lain (tidak ada saat ini) belum menghitungnya.
            'transaction_discount' => isset($data['total_promo'])
                ? (int) $data['total_promo']
                : $this->calculate_discount($data['promo_id'], $data['total_bill']),
            'transaction_tax' => $data['tax'],
            'transaction_total_bill' => $data['total_bill'],
            'transaction_saved_time_value' => isset($data['saved_time_value']) ? $data['saved_time_value'] : null,
            'transaction_table' => $data['table'],
            // snapshot jenis kategori meja SAAT transaksi terjadi (bukan lookup langsung ke
            // table_active.table_category saat laporan dibuat) - supaya laporan historis tetap
            // akurat walau kategori meja itu diubah/dipindah belakangan.
            'transaction_category_type' => !empty($data['category_type']) ? $data['category_type'] : 'billiard',
            'transaction_status' => 'Done',
            'transaction_type' => !empty($data['payment_type']) ? $data['payment_type'] : 'Normal',
            'created_by' => $data['created_by'],
            'paid_by' => $data['paid_by'],
            'transaction_upload_status' => 'N',
            'branch' => !empty($data['branch']) ? (int) $data['branch'] : 1,
        );
        $this->db->insert('transaction', $insert);
        $transaction_id = $this->db->insert_id();

        $this->db->trans_complete();
        return $this->db->trans_status() ? $transaction_id : false;
    }

    public function cancel_table($table_id, $created_by, $paid_by, $branch = 1) {
        $table = $this->get_table_by_id($table_id);
        if (!$table) return false;

        $this->db->trans_start();

        $insert = array(
            'transaction_inv' => $this->generate_transaction_inv(),
            'transaction_date' => date('Y-m-d'),
            'transaction_mode' => $table->table_mode,
            'transaction_customer_id' => $table->table_customer_id,
            'transaction_payment_id' => 0,
            'transaction_promo_id' => $table->table_promo_id,
            'transaction_start_time' => $table->table_start_time,
            'transaction_end_time' => date('Y-m-d H:i:s'),
            'transaction_duration' => $table->table_duration,
            'transaction_sub_total' => 0,
            'transaction_discount' => 0,
            'transaction_tax' => 0,
            'transaction_total_bill' => 0,
            'transaction_table' => $table_id,
            'transaction_status' => 'cancel',
            'created_by' => $created_by,
            'paid_by' => $paid_by,
            'transaction_upload_status' => 'N',
            'branch' => $branch,
        );
        $this->db->insert('transaction', $insert);
        $transaction_id = $this->db->insert_id();

        $this->clear_table($table_id);

        $this->db->trans_complete();
        return $this->db->trans_status() ? $transaction_id : false;
    }

    // category_meja_price (1-5) menentukan kolom harga yang dipakai (1 = master_price_price, dst
    // sampai 5 = master_price_price_5), dan category_meja_type menentukan TABEL-nya: billiard
    // baca/tulis ms_master_price, mahjong baca/tulis ms_master_price_mahjong (tabel terpisah -
    // 5 slot tier billiard sudah terpakai semua oleh kategori billiard yang ada). Return array
    // ['tier' => 1-5, 'type' => 'billiard'|'mahjong'] supaya kedua nilai selalu diambil bersamaan
    // dari baris category_meja yang sama (hindari 2 query terpisah yang bisa saja tidak konsisten).
    public function get_category_meja_price_tier($category_meja_id) {
        if (empty($category_meja_id)) return array('tier' => 1, 'type' => 'billiard');

        $this->db->where('category_meja_id', $category_meja_id);
        $row = $this->db->get('category_meja')->row();
        if (!$row) return array('tier' => 1, 'type' => 'billiard');

        $tier = !empty($row->category_meja_price) ? (int) $row->category_meja_price : 1;
        $tier = in_array($tier, array(1, 2, 3, 4, 5)) ? $tier : 1;
        $type = $row->category_meja_type === 'mahjong' ? 'mahjong' : 'billiard';

        return array('tier' => $tier, 'type' => $type);
    }

    private function get_price_per_hour($day, $hour, $price_tier = 1, $category_type = 'billiard') {
        $price_column = $price_tier > 1 ? 'master_price_price_' . $price_tier : 'master_price_price';
        $table = $category_type === 'mahjong' ? 'ms_master_price_mahjong' : 'ms_master_price';

        $this->db->select($price_column, false);
        $this->db->where('master_price_days', $day);
        $this->db->where('master_price_time', $hour);
        $row = $this->db->get($table)->row();
        return $row ? (int) $row->$price_column : 0;
    }

    // minute_setting_time (1 atau 6) menentukan kelipatan pembulatan durasi (menit) sebelum dihitung harganya
    private function get_minute_step() {
        $row = $this->db->get('minute_setting')->row();
        if (!$row) return 1;

        $step = (int) $row->minute_setting_time;
        return in_array($step, array(1, 6)) ? $step : 1;
    }

    private function calculate_billing($start_time, $end_time, $price_tier = 1, $category_type = 'billiard') {
        $start_ts = strtotime($start_time);
        $end_ts = strtotime($end_time);
        $total_minutes = (int) floor(($end_ts - $start_ts) / 60);

        // durasi dibulatkan ke atas ke kelipatan minute_step, mis. step=6 & durasi 62 menit -> dianggap 66 menit.
        // sesi dengan durasi positif (termasuk di bawah 1 menit) tetap ditagih minimal satu minute_step,
        // supaya checkout sesaat setelah booking tidak jadi gratis (total_minutes=0).
        $minute_step = $this->get_minute_step();
        if ($end_ts > $start_ts) {
            $total_minutes = max($minute_step, (int) (ceil($total_minutes / $minute_step) * $minute_step));
        } else {
            $total_minutes = 0;
        }

        $total = 0;
        $cursor_ts = $start_ts;
        $minutes_left = $total_minutes;
        while ($minutes_left > 0) {
            $hour_start = mktime((int) date('G', $cursor_ts), 0, 0, (int) date('n', $cursor_ts), (int) date('j', $cursor_ts), (int) date('Y', $cursor_ts));
            $minute_into_hour = (int) floor(($cursor_ts - $hour_start) / 60);
            $minutes_in_hour = min(60 - $minute_into_hour, $minutes_left);

            $price_per_hour = $this->get_price_per_hour(date('l', $cursor_ts), (int) date('G', $cursor_ts), $price_tier, $category_type);
            $total += ($price_per_hour / 60) * $minutes_in_hour;

            $cursor_ts += $minutes_in_hour * 60;
            $minutes_left -= $minutes_in_hour;
        }

        return (int) round($total);
    }

    // Hari (1=Senin..7=Minggu, sesuai date('N')) dan/atau jendela jam (valid_time_start/valid_time_end,
    // jam 0-24) yang membatasi kapan sebuah promo boleh dipakai - lihat kolom terkait di ms_promo dan
    // validasinya di Master::_parse_promo_schedule(). Promo dengan jendela jam HANYA boleh dipakai untuk
    // mode Timer (durasinya pasti diketahui di muka - mode Reguler open-ended jadi tidak bisa dijamin
    // selesai dalam jendela). Return null kalau boleh, atau pesan penolakan.
    public function validate_promo_schedule($promo, $start_time, $end_time, $mode) {
        if (empty($promo->valid_days) && $promo->valid_time_start === null && $promo->valid_time_end === null) {
            return null;
        }

        if (!empty($promo->valid_days)) {
            $allowed_days = array_map('intval', explode(',', $promo->valid_days));
            $day_of_week = (int) date('N', strtotime($start_time));
            if (!in_array($day_of_week, $allowed_days, true)) {
                return 'Promo "' . $promo->ms_promo_name . '" tidak berlaku pada hari ini';
            }
        }

        if ($promo->valid_time_start !== null && $promo->valid_time_end !== null) {
            if (strtoupper((string) $mode) !== 'TIMER') {
                return 'Promo "' . $promo->ms_promo_name . '" hanya berlaku untuk mode Timer (ada batas jam berlaku)';
            }

            $hour_of_day = function ($datetime) {
                $ts = strtotime($datetime);
                return ((int) date('H', $ts)) + ((int) date('i', $ts)) / 60 + ((int) date('s', $ts)) / 3600;
            };
            $start_hod = $hour_of_day($start_time);
            // end_hod dihitung sebagai start_hod + durasi (jam), BUKAN hour-of-day terpisah dari
            // end_time - kalau dihitung terpisah, sesi yang melewati tengah malam (mis. mulai 23:00
            // durasi 1 jam -> selesai 00:00 keesokan harinya) akan "membungkus" jadi jam kecil (0.0) dan
            // lolos dari pengecekan batas atas, padahal jelas sudah lewat jendela berlaku promo.
            $end_hod = $end_time !== null
                ? $start_hod + ((strtotime($end_time) - strtotime($start_time)) / 3600)
                : null;

            // valid_time_start bisa lebih besar dari valid_time_end - itu artinya jendela melewati
            // tengah malam (mis. 22 s/d 4 = berlaku 22:00 malam - 04:00 keesokan paginya). Supaya
            // jendela normal (9-12) DAN jendela lintas-tengah-malam (22-4) bisa dicek dengan rumus
            // yang sama, semuanya digeser relatif ke valid_time_start (jadi start jendela = 0) lalu
            // dibungkus modulo 24 jam - window_length adalah panjang jendela dalam jam.
            $window_length = fmod((float) $promo->valid_time_end - (float) $promo->valid_time_start + 24, 24);
            $shifted_start = fmod($start_hod - (float) $promo->valid_time_start + 24, 24);
            $shifted_end = $end_hod !== null ? $shifted_start + ($end_hod - $start_hod) : null;

            if ($shifted_start > $window_length) {
                return 'Promo "' . $promo->ms_promo_name . '" hanya berlaku antara jam ' . $promo->valid_time_start . ':00 - ' . $promo->valid_time_end . ':00';
            }
            if ($shifted_end !== null && $shifted_end > $window_length) {
                return 'Promo "' . $promo->ms_promo_name . '" hanya berlaku sampai jam ' . $promo->valid_time_end . ':00';
            }
        }

        return null;
    }

    public function calculate_price($start_time, $end_time, $promo_id, $price_tier = 1, $mode = null, $category_type = 'billiard') {
        $promo = !empty($promo_id) ? $this->db->where('ms_promo_id', $promo_id)->get('ms_promo')->row() : null;

        $promo_rejected_reason = null;

        // promo dengan batas jam (ms_promo.hour, mis. "Promo 2 Jam") tidak boleh dipakai kalau waktu
        // main yang SEBENARNYA sudah melebihi batas itu - main lebih pendek dari batas tetap boleh
        // (wajar, mis. beli paket 2 jam tapi baru main 1.5 jam). Kalau melebihi, promo diabaikan.
        // Divalidasi di sini (bukan cuma di FE) supaya tidak bisa dilewati dengan memanggil
        // Billing/payment langsung.
        if ($promo && !empty($promo->hour)) {
            $elapsed_hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
            if ($elapsed_hours > (float) $promo->hour) {
                $promo_rejected_reason = 'Promo ini hanya berlaku untuk maksimal ' . $promo->hour . ' jam - waktu main sudah melebihi batas tersebut';
                $promo = null;
                $promo_id = null;
            }
        }

        // hari/jendela jam berlaku promo (lihat validate_promo_schedule) - dicek ulang di sini dengan
        // waktu main SEBENARNYA (bisa berubah dari rencana awal kalau ada tambah durasi/checkout lebih
        // awal), bukan cuma sekali saat booking
        if ($promo) {
            $schedule_error = $this->validate_promo_schedule($promo, $start_time, $end_time, $mode);
            if ($schedule_error !== null) {
                $promo_rejected_reason = $schedule_error;
                $promo = null;
                $promo_id = null;
            }
        }

        if ($promo && $promo->ms_promo_tipe === 'Fix') {
            // promo Fix: yang ditagih SELALU ms_promo_value (tidak dihitung ulang per jam) - dibatasi
            // minimal 0 supaya promo dengan nilai negatif tidak membuat tagihan minus (sistem
            // "membayar" customer). Tapi buat "Diskon" yang tampil di nota, hitung juga harga NORMAL
            // (seandainya tanpa promo) supaya selisihnya (harga normal - harga paket) berarti sebagai
            // nominal diskon - sebelum ini total_promo selalu 0 untuk promo Fix, jadi nota tidak pernah
            // menunjukkan berapa yang sebenarnya dihemat customer, cuma nama promonya saja.
            $total_transaksi = max(0, (int) $promo->ms_promo_value);
            $normal_price = $this->calculate_billing($start_time, $end_time, $price_tier, $category_type);
            $total_promo = max(0, $normal_price - $total_transaksi);
            $total_billing = $normal_price;

            return array(
                'total_billing' => $total_billing,
                'total_promo' => $total_promo,
                'total_tax' => 0,
                'total_pembulatan' => 0,
                'total_transaksi' => $total_transaksi,
                'promo_rejected_reason' => $promo_rejected_reason,
            );
        }

        $total_billing = $this->calculate_billing($start_time, $end_time, $price_tier, $category_type);
        $total_promo = $this->calculate_discount($promo_id, $total_billing);
        $total_tax = 0;

        // dibatasi minimal 0 supaya diskon yang lebih besar dari tagihannya (mis. promo di atas 100%) tidak
        // membuat total tagihan minus
        $raw_total = max(0, $total_billing - $total_promo + $total_tax);
        // dibulatkan ke atas ke kelipatan 500 (sisa 1-499 -> 500, sisa 501-999 -> 1000), selisihnya dikembalikan sebagai total_pembulatan
        $total_transaksi = (int) (ceil($raw_total / 500) * 500);
        $total_pembulatan = $total_transaksi - $raw_total;

        return array(
            'total_billing' => $total_billing,
            'total_promo' => $total_promo,
            'total_tax' => $total_tax,
            'total_pembulatan' => $total_pembulatan,
            'total_transaksi' => $total_transaksi,
            'promo_rejected_reason' => $promo_rejected_reason,
        );
    }
}

?>