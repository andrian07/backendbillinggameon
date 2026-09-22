<?php

class Report_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // $date (Y-m-d) opsional - kalau diisi, business_date-nya LANGSUNG tanggal itu (bukan dihitung
    // dari jam sekarang), dipakai saat kasir memilih tanggal lain lewat date picker di Tutup Kas
    private function get_cashier_day_range($date = null) {
        // hari kasir = tanggal kalender itu saja, jam 06:00:00 s/d 23:59:59.
        // tidak ada pergeseran dini hari: transaksi jam 00:00-05:59 tidak masuk hari mana pun.
        $business_date = !empty($date) ? $date : date('Y-m-d');

        $start = $business_date . ' 06:00:00';
        $end = $business_date . ' 23:59:59';

        return array('business_date' => $business_date, 'start' => $start, 'end' => $end);
    }

    private function get_today_range() {
        // hari ini = tanggal kalender hari ini saja, jam 06:00:00 s/d 23:59:59
        $business_date = date('Y-m-d');

        $start = $business_date . ' 06:00:00';
        $end = $business_date . ' 23:59:59';

        return array('business_date' => $business_date, 'start' => $start, 'end' => $end);
    }

    // rentang datetime untuk laporan periode date_from s/d date_to, per hari dihitung 06:00:00 s/d 23:59:59
    // pada tanggal itu sendiri (transaksi jam 00:00-05:59 tidak ikut terhitung)
    private function get_business_range($date_from, $date_to) {
        $start = $date_from . ' 06:00:00';
        $end = $date_to . ' 23:59:59';
        return array('start' => $start, 'end' => $end);
    }

    private function map_payment_rows($rows) {
        $by_payment = array();
        $total = 0;
        $nota = 0;
        foreach ($rows as $row) {
            $amount = $row->total_amount !== null ? (int) $row->total_amount : 0;
            $count = (int) $row->total_nota;
            $by_payment[] = array(
                'payment_id' => (int) $row->payment_id,
                'payment_name' => $row->payment_name,
                'total_transaksi' => $amount,
                'jumlah_nota' => $count,
            );
            $total += $amount;
            $nota += $count;
        }
        return array('total_transaksi' => $total, 'jumlah_nota' => $nota, 'by_payment' => $by_payment);
    }

    // billing & cafe dalam rentang tanggal tertentu, masing-masing dipecah per jenis pembayaran (payment_id)
    private function get_payment_breakdown($user_id, $range) {
        $this->db->select('t.transaction_payment_id AS payment_id, p.payment_name, SUM(t.transaction_total_bill) AS total_amount, COUNT(t.transaction_id) AS total_nota', false);
        $this->db->from('transaction t');
        $this->db->join('ms_payment p', 'p.payment_id = t.transaction_payment_id', 'left');
        $this->db->where('t.paid_by', $user_id);
        $this->db->where('t.transaction_status', 'Done');
        $this->db->where('t.created_at >=', $range['start']);
        $this->db->where('t.created_at <=', $range['end']);
        $this->db->group_by('t.transaction_payment_id');
        $billing_rows = $this->db->get()->result();

        $this->db->select('c.transaction_cafe_payment_id AS payment_id, p.payment_name, SUM(c.transaction_cafe_total_bill) AS total_amount, COUNT(c.transaction_cafe_id) AS total_nota', false);
        $this->db->from('transaction_cafe c');
        $this->db->join('ms_payment p', 'p.payment_id = c.transaction_cafe_payment_id', 'left');
        $this->db->where('c.paid_by', $user_id);
        $this->db->where('c.transaction_cafe_status', 'Done');
        $this->db->where('c.created_at >=', $range['start']);
        $this->db->where('c.created_at <=', $range['end']);
        $this->db->group_by('c.transaction_cafe_payment_id');
        $cafe_rows = $this->db->get()->result();

        $this->db->select('s.transaksi_saldo_payment_id AS payment_id, p.payment_name, SUM(s.transaction_saldo_pay) AS total_amount, COUNT(s.transaksi_saldo_id) AS total_nota', false);
        $this->db->from('transaksi_saldo s');
        $this->db->join('ms_payment p', 'p.payment_id = s.transaksi_saldo_payment_id', 'left');
        $this->db->where('s.paid_by', $user_id);
        $this->db->where('s.transaksi_saldo_status', 'Done');
        $this->db->where('s.created_at >=', $range['start']);
        $this->db->where('s.created_at <=', $range['end']);
        $this->db->group_by('s.transaksi_saldo_payment_id');
        $saldo_rows = $this->db->get()->result();

        return array(
            'billing' => $this->map_payment_rows($billing_rows),
            'cafe' => $this->map_payment_rows($cafe_rows),
            'saldo' => $this->map_payment_rows($saldo_rows),
        );
    }

    // sama seperti get_transaction_today_by_cashier, tapi pakai rentang hari 06:00-23:59:59
    public function total_transaction_today_by_cashier($user_id) {
        $range = $this->get_today_range();
        $breakdown = $this->get_payment_breakdown($user_id, $range);

        return array(
            'business_date' => $range['business_date'],
            'user_id' => (int) $user_id,
            'billing' => $breakdown['billing'],
            'cafe' => $breakdown['cafe'],
            'saldo' => $breakdown['saldo'],
        );
    }

    public function get_transaction_today_by_cashier($user_id, $date = null) {
        $range = $this->get_cashier_day_range($date);
        $breakdown = $this->get_payment_breakdown($user_id, $range);
        $expenses = $this->get_cash_expenses($user_id, $range['business_date']);

        return array(
            'business_date' => $range['business_date'],
            'user_id' => (int) $user_id,
            'billing' => $breakdown['billing'],
            'cafe' => $breakdown['cafe'],
            'saldo' => $breakdown['saldo'],
            'cafe_items' => $this->get_cafe_items_sold($user_id, $range),
            // pengeluaran kas kasir ini di hari yang sama - dikurangkan dari
            // TUNAI per channel saat Tutup Kas (lihat tutup_kas_dialog.dart)
            'expenses' => $expenses['data'],
            'expense_total' => $expenses['total'],
            'expense_total_billing' => $expenses['total_billing'],
            'expense_total_cafe' => $expenses['total_cafe'],
        );
    }

    // === Pengeluaran kas (cash_expense) - dicatat kasir dari tombol "Pengeluaran"
    // di header. Saat Tutup Kas, totalnya dikurangkan dari pembayaran TUNAI pada
    // channel yang dipilih (billing / cafe). Omzet/Grand Total tidak berubah. ===

    // $channel: 'billing' | 'cafe' (default 'billing' kalau tidak valid).
    public function add_cash_expense($user_id, $keterangan, $nominal, $channel = 'billing', $date = null) {
        $business_date = !empty($date) ? $date : date('Y-m-d');
        $channel = ($channel === 'cafe') ? 'cafe' : 'billing';
        $user = $this->db->where('user_id', (int) $user_id)->get('ms_user')->row();

        $this->db->insert('cash_expense', array(
            'keterangan'      => $keterangan,
            'nominal'         => (int) $nominal,
            'channel'         => $channel,
            'business_date'   => $business_date,
            'created_by'      => (int) $user_id,
            'created_by_name' => $user ? $user->username : '',
            'branch'          => $user ? (int) $user->user_branch : 1,
        ));
        return (int) $this->db->insert_id();
    }

    // pengeluaran kas 1 kasir untuk 1 hari + total keseluruhan & per channel.
    public function get_cash_expenses($user_id, $date = null) {
        $business_date = !empty($date) ? $date : date('Y-m-d');

        $this->db->where('created_by', (int) $user_id);
        $this->db->where('business_date', $business_date);
        $this->db->order_by('cash_expense_id', 'ASC');
        $rows = $this->db->get('cash_expense')->result();

        $data = array();
        $total = 0;
        $total_billing = 0;
        $total_cafe = 0;
        foreach ($rows as $r) {
            $nominal = (int) $r->nominal;
            $channel = ($r->channel === 'cafe') ? 'cafe' : 'billing';
            $data[] = array(
                'id'         => (int) $r->cash_expense_id,
                'keterangan' => $r->keterangan,
                'nominal'    => $nominal,
                'channel'    => $channel,
                'created_at' => $r->created_at,
            );
            $total += $nominal;
            if ($channel === 'cafe') $total_cafe += $nominal;
            else $total_billing += $nominal;
        }
        return array(
            'data' => $data,
            'total' => $total,
            'total_billing' => $total_billing,
            'total_cafe' => $total_cafe,
        );
    }

    // hapus 1 pengeluaran - hanya entri milik user itu sendiri & business_date hari ini.
    public function delete_cash_expense($id, $user_id) {
        $this->db->where('cash_expense_id', (int) $id);
        $this->db->where('created_by', (int) $user_id);
        $this->db->where('business_date', date('Y-m-d'));
        $this->db->delete('cash_expense');
        return $this->db->affected_rows() > 0;
    }

    // produk cafe yang terjual (nama + total qty) untuk kasir & rentang hari tertentu, dipakai tombol
    // "Cetak Cafe" di Tutup Kas - hanya transaksi Done (dibatalkan tidak dihitung, sama seperti summary)
    private function get_cafe_items_sold($user_id, $range) {
        $this->db->select('d.product_name, SUM(d.qty) AS total_qty', false);
        $this->db->from('transaction_cafe_detail d');
        $this->db->join('transaction_cafe c', 'c.transaction_cafe_id = d.transaction_cafe_id');
        $this->db->where('c.paid_by', $user_id);
        $this->db->where('c.transaction_cafe_status', 'Done');
        $this->db->where('c.created_at >=', $range['start']);
        $this->db->where('c.created_at <=', $range['end']);
        $this->db->group_by('d.product_name');
        $this->db->order_by('d.product_name', 'ASC');
        $rows = $this->db->get()->result();

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'product_name' => $row->product_name,
                'qty' => (int) $row->total_qty,
            );
        }
        return $items;
    }

    // laporan transaksi billing, filter tanggal (wajib), member & kasir (opsional)
    // 1 hari dihitung dari jam 06:00:00 s/d 23:59:59 pada tanggal itu sendiri (berdasarkan created_at)
    public function get_billing_report($filter) {
        $range = $this->get_business_range($filter['date_from'], $filter['date_to']);

        $this->db->select('t.transaction_id, t.transaction_inv, t.transaction_date, t.transaction_start_time, t.transaction_end_time, t.transaction_sub_total, t.transaction_discount, t.transaction_tax, t.transaction_total_bill, t.transaction_saved_time_value, t.transaction_type, t.transaction_status, p.ms_promo_name, p.ms_promo_tipe, p.ms_promo_value, c.customer_name, u.username, pay.payment_name', false);
        $this->db->from('transaction t');
        $this->db->join('ms_customer c', 'c.customer_id = t.transaction_customer_id', 'left');
        $this->db->join('ms_user u', 'u.user_id = t.paid_by', 'left');
        $this->db->join('ms_payment pay', 'pay.payment_id = t.transaction_payment_id', 'left');
        $this->db->join('ms_promo p', 'p.ms_promo_id = t.transaction_promo_id', 'left');
        $this->db->where('t.created_at >=', $range['start']);
        $this->db->where('t.created_at <=', $range['end']);
        if (!empty($filter['customer_id'])) $this->db->where('t.transaction_customer_id', $filter['customer_id']);
        if (!empty($filter['paid_by'])) $this->db->where('t.paid_by', $filter['paid_by']);
        $this->db->order_by('t.created_at', 'ASC');
        $query = $this->db->get();

        $data = array();
        $summary = array('jumlah_nota' => 0, 'total_sub_total' => 0, 'total_discount' => 0, 'total_tax' => 0, 'total_bill' => 0);
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->transaction_id,
                'inv' => $row->transaction_inv,
                'date' => $row->transaction_date,
                'start_time' => $row->transaction_start_time,
                'end_time' => $row->transaction_end_time,
                'member_name' => $row->customer_name,
                'kasir_name' => $row->username,
                'payment_name' => $row->payment_name,
                'promo_name' => $row->ms_promo_name,
                'promo_tipe' => $row->ms_promo_tipe,
                'promo_value' => $row->ms_promo_value !== null ? (int) $row->ms_promo_value : 0,
                'sub_total' => (int) $row->transaction_sub_total,
                'discount' => (int) $row->transaction_discount,
                'tax' => (int) $row->transaction_tax,
                'total_bill' => (int) $row->transaction_total_bill,
                // transaksi yang dibayar pakai sisa waktu tersimpan -> total_bill = 0, tapi
                // saved_time_value menyimpan nilai tagihan yang seharusnya (buat kolom Keterangan).
                // nota lama (sebelum kolom ini ada) tetap terdeteksi dari transaction_type-nya,
                // hanya saja tidak punya nominalnya.
                'used_saved_time' => $row->transaction_saved_time_value !== null || $row->transaction_type === 'Gunakan Timer',
                'saved_time_value' => $row->transaction_saved_time_value !== null ? (int) $row->transaction_saved_time_value : null,
                'status' => $row->transaction_status,
            );
            $summary['jumlah_nota']++;
            $summary['total_sub_total'] += (int) $row->transaction_sub_total;
            $summary['total_discount'] += (int) $row->transaction_discount;
            $summary['total_tax'] += (int) $row->transaction_tax;
            $summary['total_bill'] += (int) $row->transaction_total_bill;
        }

        return array('filter' => $filter, 'data' => $data, 'summary' => $summary);
    }

    // laporan transaksi cafe, filter tanggal (wajib), member & kasir (opsional)
    // 1 hari dihitung dari jam 06:00:00 s/d 23:59:59 pada tanggal itu sendiri (berdasarkan created_at)
    public function get_cafe_report($filter) {
        $range = $this->get_business_range($filter['date_from'], $filter['date_to']);

        $this->db->select('tc.transaction_cafe_id, tc.transaction_cafe_inv, tc.transaction_cafe_date, tc.created_at, tc.transaction_cafe_sub_total, tc.transaction_cafe_discount, tc.transaction_cafe_tax, tc.transaction_cafe_total_bill, tc.transaction_cafe_status, tc.transaction_cafe_promo_note, cp.ms_cafe_promo_name, c.customer_name, u.username, pay.payment_name', false);
        $this->db->from('transaction_cafe tc');
        $this->db->join('ms_customer c', 'c.customer_id = tc.transaction_cafe_customer_id', 'left');
        $this->db->join('ms_user u', 'u.user_id = tc.paid_by', 'left');
        $this->db->join('ms_payment pay', 'pay.payment_id = tc.transaction_cafe_payment_id', 'left');
        $this->db->join('ms_cafe_promo cp', 'cp.ms_cafe_promo_id = tc.transaction_cafe_promo_id', 'left');
        $this->db->where('tc.created_at >=', $range['start']);
        $this->db->where('tc.created_at <=', $range['end']);
        if (!empty($filter['customer_id'])) $this->db->where('tc.transaction_cafe_customer_id', $filter['customer_id']);
        if (!empty($filter['paid_by'])) $this->db->where('tc.paid_by', $filter['paid_by']);
        $this->db->order_by('tc.created_at', 'ASC');
        $query = $this->db->get();
        $trx_rows = $query->result();

        $ids = array();
        foreach ($trx_rows as $row) $ids[] = (int) $row->transaction_cafe_id;

        $items_by_transaction = array();
        if (!empty($ids)) {
            $this->db->select('transaction_cafe_id, product_name, qty, product_price, sub_total, note');
            $this->db->where_in('transaction_cafe_id', $ids);
            $this->db->order_by('transaction_cafe_detail_id', 'ASC');
            $detail_query = $this->db->get('transaction_cafe_detail');
            foreach ($detail_query->result() as $d) {
                $items_by_transaction[(int) $d->transaction_cafe_id][] = array(
                    'product_name' => $d->product_name,
                    'qty' => (int) $d->qty,
                    'price' => (int) $d->product_price,
                    'sub_total' => (int) $d->sub_total,
                    'note' => $d->note,
                );
            }
        }

        $data = array();
        $summary = array('jumlah_nota' => 0, 'total_sub_total' => 0, 'total_discount' => 0, 'total_tax' => 0, 'total_bill' => 0);
        foreach ($trx_rows as $row) {
            $id = (int) $row->transaction_cafe_id;
            $data[] = array(
                'id' => $id,
                'inv' => $row->transaction_cafe_inv,
                'date' => $row->transaction_cafe_date,
                'time' => $row->created_at,
                'member_name' => $row->customer_name,
                'kasir_name' => $row->username,
                'payment_name' => $row->payment_name,
                'promo_name' => $row->ms_cafe_promo_name,
                'promo_note' => $row->transaction_cafe_promo_note,
                'sub_total' => (int) $row->transaction_cafe_sub_total,
                'discount' => (int) $row->transaction_cafe_discount,
                'tax' => (int) $row->transaction_cafe_tax,
                'total_bill' => (int) $row->transaction_cafe_total_bill,
                'status' => $row->transaction_cafe_status,
                'items' => isset($items_by_transaction[$id]) ? $items_by_transaction[$id] : array(),
            );
            $summary['jumlah_nota']++;
            $summary['total_sub_total'] += (int) $row->transaction_cafe_sub_total;
            $summary['total_discount'] += (int) $row->transaction_cafe_discount;
            $summary['total_tax'] += (int) $row->transaction_cafe_tax;
            $summary['total_bill'] += (int) $row->transaction_cafe_total_bill;
        }

        return array('filter' => $filter, 'data' => $data, 'summary' => $summary);
    }

    // laporan transaksi saldo (top up), filter tanggal (wajib), member & kasir (opsional)
    // 1 hari dihitung dari jam 06:00:00 s/d 23:59:59 pada tanggal itu sendiri (berdasarkan created_at)
    public function get_saldo_report($filter) {
        $range = $this->get_business_range($filter['date_from'], $filter['date_to']);

        // nominal diambil dari transaksi_saldo.transaksi_saldo_nominal (snapshot yang dicatat SAAT
        // transaksi terjadi di add_customer_saldo()), bukan dari join ke ms_saldo.ms_saldo_nominal yang
        // aktif SEKARANG - supaya laporan transaksi lama tidak ikut berubah kalau harga/nominal paket
        // saldo diedit belakangan
        $this->db->select('s.transaksi_saldo_id, s.transaksi_saldo_inv, s.created_at, s.transaksi_saldo_nominal, s.transaction_saldo_pay, s.transaction_discount_pay, s.transaksi_saldo_status, c.customer_name, u.username, pay.payment_name', false);
        $this->db->from('transaksi_saldo s');
        $this->db->join('ms_customer c', 'c.customer_id = s.customer_id', 'left');
        $this->db->join('ms_user u', 'u.user_id = s.paid_by', 'left');
        $this->db->join('ms_payment pay', 'pay.payment_id = s.transaksi_saldo_payment_id', 'left');
        $this->db->where('s.created_at >=', $range['start']);
        $this->db->where('s.created_at <=', $range['end']);
        if (!empty($filter['customer_id'])) $this->db->where('s.customer_id', $filter['customer_id']);
        if (!empty($filter['paid_by'])) $this->db->where('s.paid_by', $filter['paid_by']);
        $this->db->order_by('s.created_at', 'ASC');
        $query = $this->db->get();

        $data = array();
        $summary = array('jumlah_nota' => 0, 'total_nominal' => 0, 'total_discount_pay' => 0, 'total_pay' => 0);
        foreach ($query->result() as $row) {
            $nominal = (int) $row->transaksi_saldo_nominal;
            $data[] = array(
                'id' => (int) $row->transaksi_saldo_id,
                'inv' => $row->transaksi_saldo_inv,
                'time' => $row->created_at,
                'member_name' => $row->customer_name,
                'kasir_name' => $row->username,
                'payment_name' => $row->payment_name,
                'nominal' => $nominal,
                'discount_pay' => (int) $row->transaction_discount_pay,
                'pay' => (int) $row->transaction_saldo_pay,
                'status' => $row->transaksi_saldo_status,
            );
            $summary['jumlah_nota']++;
            $summary['total_nominal'] += $nominal;
            $summary['total_discount_pay'] += (int) $row->transaction_discount_pay;
            $summary['total_pay'] += (int) $row->transaction_saldo_pay;
        }

        return array('filter' => $filter, 'data' => $data, 'summary' => $summary);
    }

    // laporan stok produk, hanya produk yang stoknya masih ada (> 0)
    public function get_stock_report() {
        $this->db->select('product_code, product_name, product_stock');
        $this->db->where('product_stock >', 0);
        $this->db->order_by('product_name', 'ASC');
        $query = $this->db->get('ms_product');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'total_stock' => (int) $row->product_stock,
            );
        }
        return $data;
    }

    // laporan pembelian stok. filter: date_from, date_to (wajib, tanggal kalender biasa - purchase_date
    // adalah DATE, tidak dihitung dengan cutoff jam bisnis seperti billing/cafe), supplier (opsional,
    // exact match nama supplier - lihat get_purchase_suppliers() untuk daftar pilihannya)
    public function get_purchase_report($filter) {
        $this->db->select('purchase_id, purchase_inv, purchase_date, purchase_supplier, purchase_supplier_invoice, purchase_sub_total, purchase_discount, purchase_total, purchase_status, created_by, cancelled_by, cancelled_at', false);
        $this->db->from('purchase');
        $this->db->where('purchase_date >=', $filter['date_from']);
        $this->db->where('purchase_date <=', $filter['date_to']);
        if (!empty($filter['supplier'])) $this->db->where('purchase_supplier', $filter['supplier']);
        $this->db->order_by('purchase_id', 'ASC');
        $query = $this->db->get();

        $data = array();
        $summary = array('jumlah_nota' => 0, 'total_sub_total' => 0, 'total_discount' => 0, 'total_bill' => 0);
        foreach ($query->result() as $row) {
            $data[] = array(
                'id' => (int) $row->purchase_id,
                'inv' => $row->purchase_inv,
                'date' => $row->purchase_date,
                'supplier' => $row->purchase_supplier,
                'supplier_invoice' => $row->purchase_supplier_invoice,
                'sub_total' => (int) $row->purchase_sub_total,
                'discount' => (int) $row->purchase_discount,
                'total' => (int) $row->purchase_total,
                'status' => $row->purchase_status,
                'created_by' => $row->created_by,
                'cancelled_by' => $row->cancelled_by,
                'cancelled_at' => $row->cancelled_at,
            );
            // baris yang dibatalkan tetap ditampilkan (jejak audit) tapi tidak dihitung ke summary/total
            if (strtolower($row->purchase_status) === 'cancel') continue;

            $summary['jumlah_nota']++;
            $summary['total_sub_total'] += (int) $row->purchase_sub_total;
            $summary['total_discount'] += (int) $row->purchase_discount;
            $summary['total_bill'] += (int) $row->purchase_total;
        }

        return array('data' => $data, 'summary' => $summary);
    }

    // daftar nama supplier unik yang pernah dipakai di pembelian - untuk dropdown filter laporan
    public function get_purchase_suppliers() {
        $this->db->select('DISTINCT purchase_supplier', false);
        $this->db->where('purchase_supplier IS NOT NULL', null, false);
        $this->db->where('purchase_supplier !=', '');
        $this->db->order_by('purchase_supplier', 'ASC');
        $query = $this->db->get('purchase');

        $suppliers = array();
        foreach ($query->result() as $row) {
            $suppliers[] = $row->purchase_supplier;
        }
        return $suppliers;
    }

}

?>