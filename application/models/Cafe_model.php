<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class cafe_model extends CI_Model {
    public function __construct() {
        parent::__construct();
        $this->load->library('gameon');
        $this->load->model('master_model');
    }

    private function generate_transaction_cafe_inv() {
        // format: INV/CFE/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('transaction_cafe');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'INV/CFE/' . date('d') . '/' . date('m') . $seq;
    }

    private function map_transaction_cafe($row) {
        return array(
            'id' => (int) $row->transaction_cafe_id,
            'inv' => $row->transaction_cafe_inv,
            'date' => $row->transaction_cafe_date,
            'table' => $row->transaction_cafe_table !== null ? (int) $row->transaction_cafe_table : null,
            'customer_id' => $row->transaction_cafe_customer_id !== null ? (int) $row->transaction_cafe_customer_id : null,
            'customer_name' => $row->transaction_cafe_customer_name,
            'payment_id' => (int) $row->transaction_cafe_payment_id,
            'promo_id' => (int) $row->transaction_cafe_promo_id,
            'promo_price' => (int) $row->transaction_cafe_promo_price,
            'promo_note' => $row->transaction_cafe_promo_note,
            'sub_total' => (int) $row->transaction_cafe_sub_total,
            'discount' => (int) $row->transaction_cafe_discount,
            'tax' => (int) $row->transaction_cafe_tax,
            'total_bill' => (int) $row->transaction_cafe_total_bill,
            'status' => $row->transaction_cafe_status,
            'created_by' => $row->created_by,
            'created_at' => $row->created_at,
            'paid_by' => (int) $row->paid_by,
            'cancelled_by' => $row->transaction_cafe_cancelled_by,
            'cancelled_at' => $row->transaction_cafe_cancelled_at,
            'payment_edited_by' => $row->transaction_cafe_payment_edited_by,
            'payment_edited_at' => $row->transaction_cafe_payment_edited_at,
        );
    }

    public function get_transaction_cafe_detail($transaction_cafe_id) {
        if (empty($transaction_cafe_id)) return null;

        $this->db->select('tc.*, pay.payment_name', false);
        $this->db->from('transaction_cafe tc');
        $this->db->join('ms_payment pay', 'pay.payment_id = tc.transaction_cafe_payment_id', 'left');
        $this->db->where('tc.transaction_cafe_id', $transaction_cafe_id);
        $row = $this->db->get()->row();
        if (!$row) return null;

        $data = $this->map_transaction_cafe($row);
        $data['payment_name'] = isset($row->payment_name) ? $row->payment_name : null;

        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $this->db->order_by('transaction_cafe_detail_id', 'ASC');
        $items = $this->db->get('transaction_cafe_detail')->result();

        $data['items'] = array();
        foreach ($items as $item) {
            $data['items'][] = array(
                'product_name' => $item->product_name,
                'qty' => (int) $item->qty,
                'price' => (int) $item->product_price,
                'sub_total' => (int) $item->sub_total,
                'note' => $item->note,
                'addons' => $this->get_transaction_cafe_detail_addons($item->transaction_cafe_detail_id),
            );
        }

        return $data;
    }

    // payload untuk push ke gameon (Gameon::push_transaction_cafe). id transaksi cabang TIDAK
    // disertakan; nama payment/promo ikut dibawa karena id-nya beda per cabang. items = rincian
    // item + addon-nya.
    public function get_transaction_cafe_for_sync($transaction_cafe_id) {
        if (empty($transaction_cafe_id)) return null;

        $this->db->select('tc.*, pay.payment_name, cp.ms_cafe_promo_name', false);
        $this->db->from('transaction_cafe tc');
        $this->db->join('ms_payment pay', 'pay.payment_id = tc.transaction_cafe_payment_id', 'left');
        $this->db->join('ms_cafe_promo cp', 'cp.ms_cafe_promo_id = tc.transaction_cafe_promo_id', 'left');
        $this->db->where('tc.transaction_cafe_id', $transaction_cafe_id);
        $row = $this->db->get()->row();
        if (!$row) return null;

        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $this->db->order_by('transaction_cafe_detail_id', 'ASC');
        $details = $this->db->get('transaction_cafe_detail')->result();

        $items = array();
        foreach ($details as $d) {
            $items[] = array(
                'product_id' => (int) $d->product_id,
                'product_name' => $d->product_name,
                'product_price' => (int) $d->product_price,
                'qty' => (int) $d->qty,
                'sub_total' => (int) $d->sub_total,
                'note' => $d->note,
            );
        }

        return array(
            'branch' => (int) $row->branch,
            'transaction_cafe_inv' => $row->transaction_cafe_inv,
            'transaction_cafe_date' => $row->transaction_cafe_date,
            'transaction_cafe_created_at' => $row->created_at,
            'transaction_cafe_table' => $row->transaction_cafe_table !== null ? (int) $row->transaction_cafe_table : null,
            'transaction_cafe_customer_id' => $row->transaction_cafe_customer_id !== null ? (int) $row->transaction_cafe_customer_id : null,
            'transaction_cafe_customer_name' => $row->transaction_cafe_customer_name,
            'transaction_cafe_payment_id' => (int) $row->transaction_cafe_payment_id,
            'transaction_cafe_payment_name' => $row->payment_name,
            'transaction_cafe_promo_id' => (int) $row->transaction_cafe_promo_id,
            'transaction_cafe_promo_name' => $row->ms_cafe_promo_name,
            'transaction_cafe_promo_price' => (int) $row->transaction_cafe_promo_price,
            'transaction_cafe_promo_note' => $row->transaction_cafe_promo_note,
            'transaction_cafe_sub_total' => (int) $row->transaction_cafe_sub_total,
            'transaction_cafe_discount' => (int) $row->transaction_cafe_discount,
            'transaction_cafe_tax' => (int) $row->transaction_cafe_tax,
            'transaction_cafe_total_bill' => (int) $row->transaction_cafe_total_bill,
            'transaction_cafe_status' => $row->transaction_cafe_status,
            'created_by' => $row->created_by,
            'paid_by' => (int) $row->paid_by,
            'transaction_cafe_payment_edited_by' => $row->transaction_cafe_payment_edited_by,
            'transaction_cafe_payment_edited_at' => $row->transaction_cafe_payment_edited_at,
            'transaction_cafe_cancelled_by' => $row->transaction_cafe_cancelled_by,
            'transaction_cafe_cancelled_at' => $row->transaction_cafe_cancelled_at,
            'items' => $items,
        );
    }

    // ditandai 'Y' setelah push ke gameon (laporan online) berhasil - lihat
    // Cafe::_sync_transaction_cafe_to_gameon() & Sync::retry(). Selamanya 'N' kalau belum pernah berhasil.
    public function mark_transaction_cafe_uploaded($transaction_cafe_id) {
        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $this->db->update('transaction_cafe', array('transaction_cafe_upload_status' => 'Y'));
    }

    // additional item (mis. Telur ditambahkan ke Indomie) yang menempel di satu baris transaction_cafe_detail
    private function get_transaction_cafe_detail_addons($transaction_cafe_detail_id) {
        $this->db->where('transaction_cafe_detail_id', $transaction_cafe_detail_id);
        $this->db->order_by('transaction_cafe_detail_addon_id', 'ASC');
        $rows = $this->db->get('transaction_cafe_detail_addon')->result();

        $addons = array();
        foreach ($rows as $row) {
            $addons[] = array(
                'product_name' => $row->product_name,
                'qty' => (int) $row->qty,
                'price' => (int) $row->product_price,
                'sub_total' => (int) $row->sub_total,
            );
        }
        return $addons;
    }

    // ubah status jadi Cancel + kembalikan stok item (reduce_stock = 'Y') yang sempat dipotong saat
    // transaksi dibuat, dicatat ke movement_stock sebagai IN. Saldo customer TIDAK direfund (belum ada
    // keputusan bisnis untuk itu). return true kalau berhasil, 'ALREADY_CANCELLED' kalau transaksi ini
    // sudah pernah dibatalkan sebelumnya (guard atomik, supaya stok tidak dobel dikembalikan kalau
    // endpoint ini terpanggil dua kali nyaris bersamaan), false kalau transaksi tidak ditemukan.
    public function cancel_transaction_cafe($transaction_cafe_id, $created_by) {
        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $transaction = $this->db->get('transaction_cafe')->row();
        if (!$transaction) return false;

        $this->db->trans_start();

        // UPDATE bersyarat (status != Cancel) dalam satu query atomik - lihat claim_table_for_payment
        // di Billing_model untuk pola serupa. cancelled_by/at dicatat di sini (mirip purchase.cancelled_by)
        // supaya laporan bisa menunjukkan user mana yang melakukan pembatalan.
        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $this->db->where('transaction_cafe_status !=', 'Cancel');
        $this->db->update('transaction_cafe', array(
            'transaction_cafe_status' => 'Cancel',
            'transaction_cafe_cancelled_by' => $created_by,
            'transaction_cafe_cancelled_at' => date('Y-m-d H:i:s'),
        ));

        if ($this->db->affected_rows() <= 0) {
            $this->db->trans_complete();
            return 'ALREADY_CANCELLED';
        }

        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $details = $this->db->get('transaction_cafe_detail')->result();

        foreach ($details as $detail) {
            $product = $this->db->where('product_id', $detail->product_id)->get('ms_product')->row();
            if ($product && $product->reduce_stock === 'Y') {
                $stock_before = (int) $product->product_stock;
                $this->db->where('product_id', $detail->product_id);
                $this->db->set('product_stock', 'product_stock + ' . (int) $detail->qty, false);
                $this->db->update('ms_product');

                $this->record_movement_stock(
                    $detail->product_id,
                    $detail->product_name,
                    'IN',
                    (int) $detail->qty,
                    $stock_before,
                    $stock_before + (int) $detail->qty,
                    'Cafe',
                    $transaction_cafe_id,
                    $created_by,
                    'Pembatalan transaksi cafe'
                );
            }

            // additional item milik baris ini juga dikembalikan stoknya, independen dari status
            // reduce_stock produk utamanya
            $this->db->where('transaction_cafe_detail_id', $detail->transaction_cafe_detail_id);
            $addons = $this->db->get('transaction_cafe_detail_addon')->result();
            foreach ($addons as $addon) {
                $addon_product = $this->db->where('product_id', $addon->product_id)->get('ms_product')->row();
                if (!$addon_product || $addon_product->reduce_stock !== 'Y') continue;

                $addon_stock_before = (int) $addon_product->product_stock;
                $this->db->where('product_id', $addon->product_id);
                $this->db->set('product_stock', 'product_stock + ' . (int) $addon->qty, false);
                $this->db->update('ms_product');

                $this->record_movement_stock(
                    $addon->product_id,
                    $addon->product_name,
                    'IN',
                    (int) $addon->qty,
                    $addon_stock_before,
                    $addon_stock_before + (int) $addon->qty,
                    'Cafe',
                    $transaction_cafe_id,
                    $created_by,
                    'Pembatalan transaksi cafe (additional item)'
                );
            }
        }

        $this->db->trans_complete();
        return $this->db->trans_status() ? true : false;
    }

    // ganti metode pembayaran transaksi cafe yang sudah selesai. Ditolak kalau transaksi tidak
    // ditemukan, sudah dibatalkan, metode baru tidak ada, atau metode LAMA/BARU adalah "Potong Saldo"
    // - karena saldo customer sudah divalidasi & dipotong di save_transaction_cafe() berdasarkan
    // metode ASLI saat transaksi dibuat, dan tidak ada logika koreksi saldo untuk kasus edit setelah itu.
    // return true kalau berhasil, atau salah satu dari 'NOT_FOUND'/'CANCELLED'/'PAYMENT_NOT_FOUND'/'SALDO_NOT_ALLOWED'.
    public function edit_payment_transaction_cafe($transaction_cafe_id, $payment_id, $created_by) {
        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $transaction = $this->db->get('transaction_cafe')->row();
        if (!$transaction) return 'NOT_FOUND';
        if ($transaction->transaction_cafe_status === 'Cancel') return 'CANCELLED';

        $old_payment = $transaction->transaction_cafe_payment_id
            ? $this->db->where('payment_id', $transaction->transaction_cafe_payment_id)->get('ms_payment')->row()
            : null;
        $new_payment = $this->db->where('payment_id', $payment_id)->get('ms_payment')->row();
        if (!$new_payment) return 'PAYMENT_NOT_FOUND';

        $old_is_saldo = $old_payment && $old_payment->payment_name === 'Potong Saldo';
        $new_is_saldo = $new_payment->payment_name === 'Potong Saldo';
        if ($old_is_saldo || $new_is_saldo) return 'SALDO_NOT_ALLOWED';

        $this->db->trans_start();
        $this->db->where('transaction_cafe_id', $transaction_cafe_id);
        $this->db->update('transaction_cafe', array(
            'transaction_cafe_payment_id' => $payment_id,
            'transaction_cafe_payment_edited_by' => $created_by,
            'transaction_cafe_payment_edited_at' => date('Y-m-d H:i:s'),
        ));
        $this->db->trans_complete();
        if (!$this->db->trans_status()) return false;

        return true;
    }

    public function get_transaction_cafe_list($page = 1, $per_page = 20) {
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $total_items = (int) $this->db->count_all_results('transaction_cafe');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->order_by('transaction_cafe_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('transaction_cafe');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_transaction_cafe($row);
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

    // mencatat pergerakan stok (masuk/keluar) untuk rekap, dipanggil setiap ada perubahan product_stock
    private function record_movement_stock($product_id, $product_name, $movement_type, $qty, $stock_before, $stock_after, $ref_type, $ref_id, $created_by, $description = null) {
        $this->db->insert('movement_stock', array(
            'product_id' => $product_id,
            'product_name' => $product_name,
            'movement_type' => $movement_type,
            'qty' => $qty,
            'stock_before' => $stock_before,
            'stock_after' => $stock_after,
            'ref_type' => $ref_type,
            'ref_id' => $ref_id,
            'description' => $description,
            'created_by' => $created_by,
        ));
    }

    private function calculate_discount($promo_id, $amount) {
        if (empty($promo_id)) return 0;

        $promo = $this->db->where('ms_promo_id', $promo_id)->get('ms_promo')->row();
        if (!$promo) return 0;

        if ($promo->ms_promo_tipe === 'Diskon') {
            return (int) round($amount * $promo->ms_promo_value / 100);
        }
        if ($promo->ms_promo_tipe === 'Fix') {
            return (int) $promo->ms_promo_value;
        }
        return 0;
    }

    public function save_transaction_cafe($data) {
        $items = $data['items'];

        $this->db->trans_start();

        // qty digabung per product_id dulu SEBELUM dicek ke stok, supaya keranjang yang menulis produk
        // yang sama di beberapa baris (mis. 2 baris @30 untuk produk stok 46) tidak lolos cuma karena
        // tiap barisnya dicek satu-satu terhadap angka stok yang sama. Qty additional item (mis. Telur
        // yang ditambahkan ke beberapa Indomie) ikut digabung ke produk yang sama di sini juga.
        $qty_by_product = array();
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $qty_by_product[$pid] = ($qty_by_product[$pid] ?? 0) + (int) $item['qty'];

            if (!empty($item['addons']) && is_array($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    $apid = (int) $addon['product_id'];
                    $qty_by_product[$apid] = ($qty_by_product[$apid] ?? 0) + (int) $addon['qty'];
                }
            }
        }
        foreach ($qty_by_product as $pid => $total_qty) {
            $product = $this->db->where('product_id', $pid)->get('ms_product')->row();
            if (!$product) continue;
            if ($product->reduce_stock === 'Y' && $total_qty > (int) $product->product_stock) {
                $this->db->trans_complete();
                return 'INSUFFICIENT_STOCK:' . $product->product_name;
            }
        }

        $sub_total = 0;
        $detail_rows = array();
        foreach ($items as $item) {
            $product = $this->db->where('product_id', $item['product_id'])->get('ms_product')->row();
            if (!$product) continue;

            $qty = (int) $item['qty'];
            $item_sub_total = (int) $product->product_price * $qty;
            $sub_total += $item_sub_total;

            // additional item (mis. Telur di Indomie) - produk lain yang ditempelkan ke baris ini,
            // dengan qty sendiri (tidak dikali qty item utama). Harganya ikut menambah sub_total
            // transaksi, dan stoknya (kalau reduce_stock) dipotong sama seperti item biasa.
            $addon_rows = array();
            if (!empty($item['addons']) && is_array($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    if (empty($addon['product_id'])) continue;
                    $addon_product = $this->db->where('product_id', $addon['product_id'])->get('ms_product')->row();
                    if (!$addon_product) continue;

                    $addon_qty = (int) $addon['qty'];
                    if ($addon_qty <= 0) continue;

                    $addon_sub_total = (int) $addon_product->product_price * $addon_qty;
                    $sub_total += $addon_sub_total;

                    $addon_rows[] = array(
                        'product_id' => (int) $addon_product->product_id,
                        'product_name' => $addon_product->product_name,
                        'product_price' => (int) $addon_product->product_price,
                        'qty' => $addon_qty,
                        'sub_total' => $addon_sub_total,
                        'reduce_stock' => $addon_product->reduce_stock,
                        'stock_before' => (int) $addon_product->product_stock,
                    );
                }
            }

            $detail_rows[] = array(
                'product_id' => (int) $product->product_id,
                'product_name' => $product->product_name,
                'product_price' => (int) $product->product_price,
                'qty' => $qty,
                'sub_total' => $item_sub_total,
                'note' => !empty($item['note']) ? trim($item['note']) : null,
                'reduce_stock' => $product->reduce_stock,
                'stock_before' => (int) $product->product_stock,
                'addons' => $addon_rows,
            );
        }

        if (empty($detail_rows)) {
            $this->db->trans_complete();
            return 'NO_VALID_ITEMS';
        }

        // === Promo cafe ===
        // Kalau promo_id (ms_cafe_promo) dikirim: subtotal GABUNGAN semua baris (item + addon) yang
        // product_id-nya termasuk cakupan promo di-reprice jadi ms_cafe_promo_price (flat, berapapun
        // qty). Item lain di keranjang tidak terpengaruh. sub_total ikut berkurang, jadi total_bill,
        // pajak, dan laporan otomatis konsisten tanpa kolom tambahan. Kalau produk promo tidak ada di
        // keranjang, promo diabaikan.
        $cafe_promo_id = !empty($data['promo_id']) ? (int) $data['promo_id'] : 0;
        $cafe_promo_price = 0;
        $cafe_promo_note = !empty($data['promo_note']) ? trim($data['promo_note']) : null;
        if ($cafe_promo_id > 0) {
            $promo = $this->master_model->get_cafe_promo_for_apply($cafe_promo_id);
            $promo_pids = $promo ? $promo['product_ids'] : array();

            $eligible_subtotal = 0;
            $targets = array(); // [type('item'|'addon'), i, j]
            foreach ($detail_rows as $i => $dr) {
                if (in_array($dr['product_id'], $promo_pids, true)) {
                    $eligible_subtotal += $dr['sub_total'];
                    $targets[] = array('item', $i, null);
                }
                foreach ($dr['addons'] as $j => $ad) {
                    if (in_array($ad['product_id'], $promo_pids, true)) {
                        $eligible_subtotal += $ad['sub_total'];
                        $targets[] = array('addon', $i, $j);
                    }
                }
            }

            if ($promo && $eligible_subtotal > 0 && !empty($targets)) {
                $cafe_promo_price = (int) $promo['price'];
                $n = count($targets);
                $running = 0;
                foreach ($targets as $t => $loc) {
                    list($type, $i, $j) = $loc;
                    $cur = ($type === 'item') ? $detail_rows[$i]['sub_total'] : $detail_rows[$i]['addons'][$j]['sub_total'];
                    $new = ($t === $n - 1)
                        ? max(0, $cafe_promo_price - $running)
                        : (int) round($cur * $cafe_promo_price / $eligible_subtotal);
                    if ($t !== $n - 1) $running += $new;
                    if ($type === 'item') $detail_rows[$i]['sub_total'] = $new;
                    else $detail_rows[$i]['addons'][$j]['sub_total'] = $new;
                }

                $sub_total = 0;
                foreach ($detail_rows as $dr) {
                    $sub_total += $dr['sub_total'];
                    foreach ($dr['addons'] as $ad) $sub_total += $ad['sub_total'];
                }
            } else {
                // promo dipilih tapi tidak berlaku (nonaktif / produknya tidak ada di keranjang)
                $cafe_promo_id = 0;
                $cafe_promo_price = 0;
                $cafe_promo_note = null;
            }
        }

        // transaction_cafe_discount menyimpan PERSEN diskon (mis. 10 = 10%), bukan nominal - nominalnya
        // dihitung di sini hanya untuk total_bill, lalu dibuang (tidak disimpan terpisah)
        $discount_percent = isset($data['discount_percent']) ? (float) $data['discount_percent'] : 0;
        $discount_amount = (int) round($sub_total * $discount_percent / 100);
        $tax = (int) $data['tax'];
        $total_bill = $sub_total - $discount_amount + $tax;

        // payment_id yang payment_name-nya "Potong Saldo": total_bill dipotong dari saldo customer
        // LANGSUNG di gameon (source of truth). billing_api tidak cek/simpan saldo lokal sama sekali -
        // keberadaan customer & kecukupan saldo divalidasi oleh gameon deduct_saldo() (atomik + idempoten)
        // di bawah, setelah total_bill final & transaksi lokal siap di-commit.
        $payment = !empty($data['payment_id']) ? $this->db->where('payment_id', $data['payment_id'])->get('ms_payment')->row() : null;
        $use_saldo = $payment && $payment->payment_name === 'Potong Saldo';
        if ($use_saldo && $total_bill > 0 && empty($data['customer_id'])) {
            $this->db->trans_complete();
            return 'CUSTOMER_REQUIRED';
        }

        // KONFIRMASI PIN MEMBER untuk Potong Saldo: sampai member menyetujui lewat aplikasi,
        // tidak ada stok yang dipotong / transaksi yang dicatat. Idempoten via member_approval_ref.
        if ($use_saldo && $total_bill > 0 && !empty($data['customer_id'])) {
            $appr_ref = isset($data['member_approval_ref']) ? trim((string) $data['member_approval_ref']) : '';
            if ($appr_ref === '') {
                $this->db->trans_complete();
                return 'APPROVAL_REF_REQUIRED';
            }
            $this->load->library('gameon');
            $appr = $this->gameon->request_member_approval(array(
                'customer_id' => (int) $data['customer_id'],
                'branch' => !empty($data['branch']) ? (int) $data['branch'] : 1,
                'type' => 'cafe_saldo',
                'ref' => $appr_ref,
                'amount' => (int) $total_bill,
                'detail' => array('Untuk' => 'Pesanan cafe', 'Jumlah' => 'Rp' . number_format((int) $total_bill, 0, ',', '.')),
            ));
            if (!$appr['success']) {
                $this->db->trans_complete();
                return 'APPROVAL_FAILED:' . $appr['error'];
            }
            $appr_data = is_array($appr['result']) ? $appr['result'] : array();
            if ((isset($appr_data['status']) ? $appr_data['status'] : '') !== 'approved') {
                $this->db->trans_complete(); // belum ada yang ditulis
                return array('need_approval' => $appr_data);
            }
            // status 'approved' -> transaksi tetap terbuka, lanjut simpan seperti biasa
        }

        $branch = !empty($data['branch']) ? (int) $data['branch'] : 1;

        $header = array(
            'transaction_cafe_inv' => $this->generate_transaction_cafe_inv(),
            'transaction_cafe_date' => date('Y-m-d'),
            'transaction_cafe_table' => !empty($data['table']) ? (int) $data['table'] : null,
            'transaction_cafe_customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'transaction_cafe_customer_name' => !empty($data['customer_name']) ? $data['customer_name'] : null,
            'transaction_cafe_payment_id' => (int) $data['payment_id'],
            'transaction_cafe_promo_id' => $cafe_promo_id,
            'transaction_cafe_promo_price' => $cafe_promo_price,
            'transaction_cafe_promo_note' => $cafe_promo_note,
            'transaction_cafe_sub_total' => $sub_total,
            'transaction_cafe_discount' => $discount_percent,
            'transaction_cafe_tax' => $tax,
            'transaction_cafe_total_bill' => $total_bill,
            'transaction_cafe_status' => 'Done',
            'created_by' => $data['created_by'],
            'paid_by' => (int) $data['paid_by'],
            'branch' => $branch,
        );
        $this->db->insert('transaction_cafe', $header);
        $transaction_cafe_id = $this->db->insert_id();

        $transaction_cafe_detail_ids = array();
        foreach ($detail_rows as $row) {
            $this->db->insert('transaction_cafe_detail', array(
                'transaction_cafe_id' => $transaction_cafe_id,
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'product_price' => $row['product_price'],
                'qty' => $row['qty'],
                'sub_total' => $row['sub_total'],
                'note' => $row['note'],
            ));
            $transaction_cafe_detail_id = $this->db->insert_id();
            $transaction_cafe_detail_ids[] = $transaction_cafe_detail_id;

            if ($row['reduce_stock'] === 'Y') {
                // UPDATE bersyarat (product_stock >= qty) dalam satu query atomik - baris pre-check di atas
                // membuat pesan errornya menyebut nama produk untuk kasus umum, tapi guard yang benar-benar
                // mencegah oversell di bawah concurrency (2 request nyaris bersamaan) ada di sini: MySQL
                // mengunci baris ini selama UPDATE berjalan, jadi permintaan kedua akan melihat stok yang
                // sudah dikurangi permintaan pertama dan affected_rows()==0 kalau ternyata sudah tidak cukup.
                $this->db->where('product_id', $row['product_id']);
                $this->db->where('product_stock >=', $row['qty']);
                $this->db->set('product_stock', 'product_stock - ' . $row['qty'], false);
                $this->db->update('ms_product');

                if ($this->db->affected_rows() <= 0) {
                    $this->db->trans_rollback();
                    return 'INSUFFICIENT_STOCK:' . $row['product_name'];
                }

                $stock_after = $row['stock_before'] - $row['qty'];
                $this->record_movement_stock(
                    $row['product_id'],
                    $row['product_name'],
                    'OUT',
                    $row['qty'],
                    $row['stock_before'],
                    $stock_after,
                    'Cafe',
                    $transaction_cafe_id,
                    $data['created_by']
                );
            }

            // additional item milik baris ini - sama seperti item utama: dicatat di tabel sendiri
            // (ditautkan lewat transaction_cafe_detail_id), lalu stoknya dipotong kalau reduce_stock
            foreach ($row['addons'] as $addon_row) {
                $this->db->insert('transaction_cafe_detail_addon', array(
                    'transaction_cafe_detail_id' => $transaction_cafe_detail_id,
                    'product_id' => $addon_row['product_id'],
                    'product_name' => $addon_row['product_name'],
                    'product_price' => $addon_row['product_price'],
                    'qty' => $addon_row['qty'],
                    'sub_total' => $addon_row['sub_total'],
                ));

                if ($addon_row['reduce_stock'] === 'Y') {
                    $this->db->where('product_id', $addon_row['product_id']);
                    $this->db->where('product_stock >=', $addon_row['qty']);
                    $this->db->set('product_stock', 'product_stock - ' . $addon_row['qty'], false);
                    $this->db->update('ms_product');

                    if ($this->db->affected_rows() <= 0) {
                        $this->db->trans_rollback();
                        return 'INSUFFICIENT_STOCK:' . $addon_row['product_name'];
                    }

                    $addon_stock_after = $addon_row['stock_before'] - $addon_row['qty'];
                    $this->record_movement_stock(
                        $addon_row['product_id'],
                        $addon_row['product_name'],
                        'OUT',
                        $addon_row['qty'],
                        $addon_row['stock_before'],
                        $addon_stock_after,
                        'Cafe',
                        $transaction_cafe_id,
                        $data['created_by'],
                        'Additional item untuk ' . $row['product_name']
                    );
                }
            }
        }

        // Potong Saldo -> potong LANGSUNG di gameon (source kebenaran saldo). deduct_saldo() atomik
        // (cek kecukupan + potong sekaligus) & idempoten (deduction_ref). Kalau gameon menolak, seluruh
        // transaksi cafe lokal di-rollback - tidak ada yang tercatat.
        $saldo_deduction_ref = null;
        if ($use_saldo && $total_bill > 0) {
            $saldo_deduction_ref = 'branch' . $branch . '-cafe-' . uniqid('saldo-', true);
            $deduct = $this->gameon->deduct_saldo(array(
                'customer_id' => (int) $data['customer_id'],
                'amount' => (int) $total_bill,
                'deduction_ref' => $saldo_deduction_ref,
                'created_by' => $data['created_by'],
                'branch' => $branch,
            ));
            if (!$deduct['success']) {
                $this->db->trans_rollback();
                return ($deduct['http_code'] === 422) ? 'INSUFFICIENT' : ('SALDO_FAILED:' . $deduct['error']);
            }
        }

        $this->db->trans_complete();
        if ($this->db->trans_status()) {
            return array(
                'transaction_cafe_id' => (int) $transaction_cafe_id,
                'transaction_cafe_detail_ids' => $transaction_cafe_detail_ids,
            );
        }

        // transaksi lokal gagal commit padahal saldo sudah terlanjur dipotong di gameon - kembalikan
        if ($saldo_deduction_ref !== null) {
            $refund = $this->gameon->refund_saldo(array(
                'deduction_ref' => $saldo_deduction_ref,
                'created_by' => $data['created_by'],
            ));
            if (!$refund['success']) {
                log_message('error', 'Cafe: gagal refund potongan saldo ke gameon (ref=' . $saldo_deduction_ref . '): ' . $refund['error']);
            }
        }
        return false;
    }

    private function generate_keep_transaction_inv() {
        // format: KEEP/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('keep_transaction');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'KEEP/' . date('d') . '/' . date('m') . $seq;
    }

    // kalau keep_transaction_id dikirim & masih berstatus Keep, item baru digabung ke situ
    // (product_id yang sama tinggal ditambah qty-nya) alih-alih membuat keep baru
    public function keep_transaction($data) {
        $items = $data['items'];
        $keep_transaction_id = !empty($data['keep_transaction_id']) ? (int) $data['keep_transaction_id'] : null;

        $this->db->trans_start();

        if ($keep_transaction_id) {
            $this->db->where('keep_transaction_id', $keep_transaction_id);
            $this->db->where('keep_transaction_status', 'Keep');
            $exists = $this->db->get('keep_transaction')->row();
            if (!$exists) {
                $this->db->trans_complete();
                return false;
            }
        } else {
            $header = array(
                'keep_transaction_inv' => $this->generate_keep_transaction_inv(),
                'keep_transaction_name' => !empty($data['name']) ? $data['name'] : null,
                'keep_transaction_date' => date('Y-m-d'),
                'keep_transaction_table' => !empty($data['table']) ? (int) $data['table'] : null,
                'keep_transaction_customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                'keep_transaction_payment_id' => (int) $data['payment_id'],
                'keep_transaction_promo_id' => (int) $data['promo_id'],
                'keep_transaction_tax' => (int) $data['tax'],
                'keep_transaction_status' => 'Keep',
                'created_by' => $data['created_by'],
            );
            $this->db->insert('keep_transaction', $header);
            $keep_transaction_id = $this->db->insert_id();
        }

        foreach ($items as $item) {
            $product = $this->db->where('product_id', $item['product_id'])->get('ms_product')->row();
            if (!$product) continue;

            $qty = (int) $item['qty'];
            $note = !empty($item['note']) ? trim($item['note']) : null;

            $this->db->where('keep_transaction_id', $keep_transaction_id);
            $this->db->where('product_id', $product->product_id);
            $detail = $this->db->get('keep_transaction_detail')->row();

            // qty=0 hanya valid untuk baris yang SUDAH ada (mengirim ulang supaya note/additional
            // item ter-update tanpa menambah qty) - baris baru dengan qty 0 tidak ada gunanya
            if (!$detail && $qty <= 0) continue;

            if ($detail) {
                $new_qty = (int) $detail->qty + $qty;
                $update = array(
                    'qty' => $new_qty,
                    'sub_total' => (int) $product->product_price * $new_qty,
                );
                // note baru menimpa yang lama kalau dikirim, kalau tidak note lama tetap dipakai
                if ($note !== null) $update['note'] = $note;

                $this->db->where('keep_transaction_detail_id', $detail->keep_transaction_detail_id);
                $this->db->update('keep_transaction_detail', $update);
                $keep_transaction_detail_id = (int) $detail->keep_transaction_detail_id;
            } else {
                $this->db->insert('keep_transaction_detail', array(
                    'keep_transaction_id' => $keep_transaction_id,
                    'product_id' => (int) $product->product_id,
                    'product_name' => $product->product_name,
                    'product_price' => (int) $product->product_price,
                    'qty' => $qty,
                    'sub_total' => (int) $product->product_price * $qty,
                    'note' => $note,
                ));
                $keep_transaction_detail_id = $this->db->insert_id();
            }

            // additional item (mis. Telur di Indomie) yang ditahan bersama pesanan ini - belum memotong
            // stok di sini (baru dipotong saat benar-benar dibayar lewat save_transaction_cafe). Kalau
            // dikirim, daftar addon lama untuk baris ini diganti total dengan yang baru (sama seperti
            // note di atas) supaya edit ulang dari dialog "Item Tambahan" tercermin dengan benar.
            if (!empty($item['addons']) && is_array($item['addons'])) {
                $this->db->where('keep_transaction_detail_id', $keep_transaction_detail_id);
                $this->db->delete('keep_transaction_detail_addon');

                foreach ($item['addons'] as $addon) {
                    if (empty($addon['product_id'])) continue;
                    $addon_product = $this->db->where('product_id', $addon['product_id'])->get('ms_product')->row();
                    if (!$addon_product) continue;

                    $addon_qty = (int) $addon['qty'];
                    if ($addon_qty <= 0) continue;

                    $this->db->insert('keep_transaction_detail_addon', array(
                        'keep_transaction_detail_id' => $keep_transaction_detail_id,
                        'product_id' => (int) $addon_product->product_id,
                        'product_name' => $addon_product->product_name,
                        'product_price' => (int) $addon_product->product_price,
                        'qty' => $addon_qty,
                        'sub_total' => (int) $addon_product->product_price * $addon_qty,
                    ));
                }
            }
        }

        // total header dihitung ulang dari seluruh item yang tersimpan di keep ini
        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $header_row = $this->db->get('keep_transaction')->row();

        $this->db->select_sum('sub_total');
        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $sum = $this->db->get('keep_transaction_detail')->row();
        $sub_total = $sum->sub_total !== null ? (int) $sum->sub_total : 0;

        // harga additional item ikut menambah sub_total header, sama seperti di save_transaction_cafe
        $this->db->select_sum('a.sub_total', 'addon_total');
        $this->db->from('keep_transaction_detail_addon a');
        $this->db->join('keep_transaction_detail d', 'd.keep_transaction_detail_id = a.keep_transaction_detail_id');
        $this->db->where('d.keep_transaction_id', $keep_transaction_id);
        $addon_sum = $this->db->get()->row();
        $sub_total += $addon_sum->addon_total !== null ? (int) $addon_sum->addon_total : 0;

        $discount = $this->calculate_discount($header_row->keep_transaction_promo_id, $sub_total);
        $tax = (int) $header_row->keep_transaction_tax;

        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $this->db->update('keep_transaction', array(
            'keep_transaction_sub_total' => $sub_total,
            'keep_transaction_discount' => $discount,
            'keep_transaction_total_bill' => $sub_total - $discount + $tax,
        ));

        $this->db->trans_complete();
        // inv/name dikembalikan dari header_row (diambil sebelum update total di atas, tapi kedua
        // kolom itu tidak berubah oleh update itu) - dipakai FE untuk cetak struk dapur
        return $this->db->trans_status() ? $this->map_keep_transaction($header_row) : false;
    }

    private function map_keep_transaction($row) {
        return array(
            'id' => (int) $row->keep_transaction_id,
            'inv' => $row->keep_transaction_inv,
            'name' => $row->keep_transaction_name,
            'date' => $row->keep_transaction_date,
            'table' => $row->keep_transaction_table !== null ? (int) $row->keep_transaction_table : null,
            'customer_id' => $row->keep_transaction_customer_id !== null ? (int) $row->keep_transaction_customer_id : null,
            'payment_id' => (int) $row->keep_transaction_payment_id,
            'promo_id' => (int) $row->keep_transaction_promo_id,
            'sub_total' => (int) $row->keep_transaction_sub_total,
            'discount' => (int) $row->keep_transaction_discount,
            'tax' => (int) $row->keep_transaction_tax,
            'total_bill' => (int) $row->keep_transaction_total_bill,
            'status' => $row->keep_transaction_status,
            'created_by' => $row->created_by,
            'created_at' => $row->created_at,
        );
    }

    private function get_keep_transaction_items($keep_transaction_id) {
        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $query = $this->db->get('keep_transaction_detail');

        $items = array();
        foreach ($query->result() as $row) {
            $items[] = array(
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'product_price' => (int) $row->product_price,
                'qty' => (int) $row->qty,
                'sub_total' => (int) $row->sub_total,
                'note' => $row->note,
                'addons' => $this->get_keep_transaction_detail_addons($row->keep_transaction_detail_id),
            );
        }
        return $items;
    }

    // additional item yang ditahan bersama satu baris keep_transaction_detail
    private function get_keep_transaction_detail_addons($keep_transaction_detail_id) {
        $this->db->where('keep_transaction_detail_id', $keep_transaction_detail_id);
        $this->db->order_by('keep_transaction_detail_addon_id', 'ASC');
        $rows = $this->db->get('keep_transaction_detail_addon')->result();

        $addons = array();
        foreach ($rows as $row) {
            $addons[] = array(
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'product_price' => (int) $row->product_price,
                'qty' => (int) $row->qty,
                'sub_total' => (int) $row->sub_total,
            );
        }
        return $addons;
    }

    // tanpa keep_transaction_id: daftar semua transaksi yang masih disimpan (status Keep)
    // dengan keep_transaction_id: detail satu transaksi beserta item-nya, dipakai saat mau dibayar
    public function select_keep_transaction($keep_transaction_id = null) {
        if (!empty($keep_transaction_id)) {
            $this->db->where('keep_transaction_id', $keep_transaction_id);
            $row = $this->db->get('keep_transaction')->row();
            if (!$row) return null;

            $data = $this->map_keep_transaction($row);
            $data['items'] = $this->get_keep_transaction_items($data['id']);
            return $data;
        }

        $this->db->where('keep_transaction_status', 'Keep');
        $this->db->order_by('keep_transaction_id', 'DESC');
        $query = $this->db->get('keep_transaction');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_keep_transaction($row);
        }
        return $data;
    }

    // ganti nama pesanan yang ditahan (mis. Takeaway -> nama pelanggan asli) - hanya berlaku selama
    // masih berstatus Keep, name kosong ('') disimpan sebagai NULL (kembali ke tampilan invoice-only)
    public function rename_keep_transaction($keep_transaction_id, $name) {
        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $this->db->where('keep_transaction_status', 'Keep');
        $this->db->update('keep_transaction', array(
            'keep_transaction_name' => $name !== '' ? $name : null,
        ));
        return $this->db->affected_rows() > 0;
    }

    public function delete_keep_transaction($keep_transaction_id) {
        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $exists = $this->db->get('keep_transaction')->row();
        if (!$exists) return false;

        $this->db->trans_start();

        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $this->db->delete('keep_transaction_detail');

        $this->db->where('keep_transaction_id', $keep_transaction_id);
        $this->db->delete('keep_transaction');

        $this->db->trans_complete();
        return $this->db->trans_status();
    }
}

?>
