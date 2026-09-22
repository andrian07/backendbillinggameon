<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Purchase_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    private function generate_purchase_inv() {
        // format: INV/PUR/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('purchase');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'INV/PUR/' . date('d') . '/' . date('m') . $seq;
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

    // HPP rata-rata bergerak (moving average):
    //   (stok_lama * hpp_lama + qty_masuk * harga_beli) / (stok_lama + qty_masuk)
    // Kalau stok lama <= 0 (tidak ada basis untuk dirata-ratakan) HPP = harga beli terbaru.
    private function moving_average_cogs($stock_before, $cogs_before, $qty_in, $buy_price) {
        $stock_before = (int) $stock_before;
        $qty_in = (int) $qty_in;
        $new_stock = $stock_before + $qty_in;
        if ($stock_before <= 0 || $new_stock <= 0) return (int) $buy_price;
        return (int) round(($stock_before * (int) $cogs_before + $qty_in * (int) $buy_price) / $new_stock);
    }

    // catat 1 perubahan HPP produk (dipakai untuk hitung keuntungan: harga jual - HPP saat itu)
    private function record_cogs_history($row) {
        $this->db->insert('product_cogs_history', array(
            'product_id' => (int) $row['product_id'],
            'product_name' => $row['product_name'],
            'cogs_before' => (int) $row['cogs_before'],
            'cogs_after' => (int) $row['cogs_after'],
            'stock_before' => (int) $row['stock_before'],
            'stock_after' => (int) $row['stock_after'],
            'qty' => (int) $row['qty'],
            'buy_price' => (int) $row['buy_price'],
            'ref_type' => $row['ref_type'],
            'ref_id' => isset($row['ref_id']) ? (int) $row['ref_id'] : null,
            'branch' => isset($row['branch']) ? (int) $row['branch'] : 1,
            'created_by' => $row['created_by'],
        ));
    }

    // daftar product dengan reduce_stock == Y, tanpa pagination, dipakai untuk pilih item di form purchase_add
    public function get_product_list_for_purchase() {
        $this->db->select('p.product_id, p.product_code, p.product_name, p.product_cogs, p.product_price, p.product_stock, p.unit_id, u.unit_name, p.category_id, c.category_name');
        $this->db->from('ms_product p');
        $this->db->join('unit u', 'u.unit_id = p.unit_id', 'left');
        $this->db->join('category c', 'c.category_id = p.category_id', 'left');
        $this->db->where('p.reduce_stock', 'Y');
        $this->db->where('p.is_active', 'Y');
        $this->db->order_by('p.product_name', 'ASC');
        $query = $this->db->get();

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = array(
                'product_id' => (int) $row->product_id,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'product_cogs' => (int) $row->product_cogs,
                'product_price' => (int) $row->product_price,
                'product_stock' => (int) $row->product_stock,
                'unit_id' => (int) $row->unit_id,
                'unit_name' => $row->unit_name,
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
            );
        }
        return $data;
    }

    public function add_purchase($data) {
        $items = $data['items'];

        $this->db->trans_start();

        $sub_total = 0;
        $detail_rows = array();
        foreach ($items as $item) {
            $product = $this->db->where('product_id', $item['product_id'])->get('ms_product')->row();
            if (!$product) continue;

            $qty = (int) $item['qty'];
            $price = isset($item['price']) ? (int) $item['price'] : (int) $product->product_cogs;
            $item_sub_total = $price * $qty;
            $sub_total += $item_sub_total;

            $detail_rows[] = array(
                'product_id' => (int) $product->product_id,
                'product_name' => $product->product_name,
                'price' => $price,
                'qty' => $qty,
                'sub_total' => $item_sub_total,
                'stock_before' => (int) $product->product_stock,
                'cogs_before' => (int) $product->product_cogs,
            );
        }

        if (empty($detail_rows)) {
            $this->db->trans_complete();
            return false;
        }

        $discount = isset($data['discount']) ? (int) $data['discount'] : 0;
        // dibatasi minimal 0 supaya diskon yang lebih besar dari sub_total tidak membuat total pembelian minus
        $total = max(0, $sub_total - $discount);

        $header = array(
            'purchase_inv' => $this->generate_purchase_inv(),
            'purchase_date' => date('Y-m-d'),
            'purchase_supplier' => !empty($data['supplier']) ? $data['supplier'] : null,
            // no. invoice/nota YANG DIBERIKAN SUPPLIER - beda dari purchase_inv (nomor internal kita
            // sendiri, di-generate otomatis) - dicatat sebagai referensi/pencocokan dengan nota fisik
            'purchase_supplier_invoice' => !empty($data['supplier_invoice']) ? trim($data['supplier_invoice']) : null,
            'purchase_sub_total' => $sub_total,
            'purchase_discount' => $discount,
            'purchase_total' => $total,
            'purchase_status' => 'Done',
            'created_by' => $data['created_by'],
            'branch' => isset($data['branch']) ? (int) $data['branch'] : 1,
        );
        $this->db->insert('purchase', $header);
        $purchase_id = $this->db->insert_id();

        $branch = isset($data['branch']) ? (int) $data['branch'] : 1;

        foreach ($detail_rows as $row) {
            $stock_after = $row['stock_before'] + $row['qty'];
            // HPP baru = rata-rata bergerak stok lama vs qty yang baru masuk di harga beli ini
            $cogs_after = $this->moving_average_cogs(
                $row['stock_before'],
                $row['cogs_before'],
                $row['qty'],
                $row['price']
            );

            $this->db->insert('purchase_detail', array(
                'purchase_id' => $purchase_id,
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'price' => $row['price'],
                'cogs_before' => $row['cogs_before'],
                'cogs_after' => $cogs_after,
                'qty' => $row['qty'],
                'sub_total' => $row['sub_total'],
            ));

            $this->db->where('product_id', $row['product_id']);
            $this->db->set('product_stock', 'product_stock + ' . $row['qty'], false);
            $this->db->set('product_cogs', $cogs_after);
            $this->db->update('ms_product');

            $this->record_movement_stock(
                $row['product_id'],
                $row['product_name'],
                'IN',
                $row['qty'],
                $row['stock_before'],
                $stock_after,
                'Purchase',
                $purchase_id,
                $data['created_by'],
                'HPP ' . number_format($row['cogs_before'], 0, ',', '.') . ' -> ' . number_format($cogs_after, 0, ',', '.')
            );

            if ($cogs_after !== (int) $row['cogs_before']) {
                $this->record_cogs_history(array(
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'cogs_before' => $row['cogs_before'],
                    'cogs_after' => $cogs_after,
                    'stock_before' => $row['stock_before'],
                    'stock_after' => $stock_after,
                    'qty' => $row['qty'],
                    'buy_price' => $row['price'],
                    'ref_type' => 'Purchase',
                    'ref_id' => $purchase_id,
                    'branch' => $branch,
                    'created_by' => $data['created_by'],
                ));
            }
        }

        $this->db->trans_complete();
        return $this->db->trans_status() ? $purchase_id : false;
    }

    private function map_purchase($row) {
        return array(
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
            'created_at' => $row->created_at,
            'cancelled_by' => $row->cancelled_by,
            'cancelled_at' => $row->cancelled_at,
            'branch' => (int) $row->branch,
            'upload_status' => $row->purchase_upload_status,
        );
    }

    // ditandai 'Y' setelah push ke gameon (laporan online) berhasil - lihat
    // Purchase::_sync_purchase_to_gameon() & Sync::retry(). Selamanya 'N' kalau belum pernah berhasil.
    public function mark_purchase_uploaded($purchase_id) {
        $this->db->where('purchase_id', $purchase_id);
        $this->db->update('purchase', array('purchase_upload_status' => 'Y'));
    }

    private function get_purchase_items($purchase_id) {
        $this->db->where('purchase_id', $purchase_id);
        $query = $this->db->get('purchase_detail');

        $items = array();
        foreach ($query->result() as $row) {
            $items[] = array(
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'price' => (int) $row->price,
                'cogs_before' => (int) $row->cogs_before,
                'cogs_after' => (int) $row->cogs_after,
                'qty' => (int) $row->qty,
                'sub_total' => (int) $row->sub_total,
            );
        }
        return $items;
    }

    // tanpa purchase_id: daftar purchase dengan pagination
    // dengan purchase_id: detail satu purchase beserta item-nya
    public function get_purchase_list($purchase_id = null, $page = 1, $per_page = 20) {
        if (!empty($purchase_id)) {
            $this->db->where('purchase_id', $purchase_id);
            $row = $this->db->get('purchase')->row();
            if (!$row) return null;

            $data = $this->map_purchase($row);
            $data['items'] = $this->get_purchase_items($data['id']);
            return $data;
        }

        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $total_items = (int) $this->db->count_all_results('purchase');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->order_by('purchase_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('purchase');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_purchase($row);
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

    public function cancel_purchase($purchase_id, $cancelled_by, $branch = 1) {
        $this->db->where('purchase_id', $purchase_id);
        $purchase = $this->db->get('purchase')->row();
        if (!$purchase) return false;
        if ($purchase->purchase_status === 'Cancel') return false;

        $this->db->trans_start();

        $this->db->where('purchase_id', $purchase_id);
        $items = $this->db->get('purchase_detail')->result();

        foreach ($items as $row) {
            $product = $this->db->where('product_id', $row->product_id)->get('ms_product')->row();
            $stock_before = $product ? (int) $product->product_stock : 0;
            $cogs_before = $product ? (int) $product->product_cogs : 0;
            $stock_after = $stock_before - (int) $row->qty;

            // UPDATE bersyarat (product_stock >= qty) dalam satu query atomik - sama seperti guard di
            // sisi penjualan (Cafe_model.php). Kalau stok yang mau dikembalikan sudah kepakai di tempat
            // lain sejak pembelian ini masuk, pembatalan ditolak daripada mendorong stok makin minus.
            $this->db->where('product_id', $row->product_id);
            $this->db->where('product_stock >=', (int) $row->qty);
            $this->db->set('product_stock', 'product_stock - ' . (int) $row->qty, false);
            $this->db->update('ms_product');

            if ($this->db->affected_rows() <= 0) {
                $this->db->trans_rollback();
                return 'INSUFFICIENT_STOCK:' . ($product ? $product->product_name : $row->product_name);
            }

            // Balik HPP: keluarkan qty pembelian ini dari rata-rata bergerak (perkiraan -
            // pembelian lain sesudahnya bisa sudah menggeser HPP). Kalau stok sisa <= 0 atau
            // hasilnya tidak masuk akal, HPP dibiarkan apa adanya.
            $cogs_after = $cogs_before;
            if ($stock_after > 0) {
                $reversed = (int) round(
                    ($stock_before * $cogs_before - (int) $row->qty * (int) $row->price) / $stock_after
                );
                if ($reversed > 0) $cogs_after = $reversed;
            }
            if ($cogs_after !== $cogs_before) {
                $this->db->where('product_id', $row->product_id);
                $this->db->set('product_cogs', $cogs_after);
                $this->db->update('ms_product');

                $this->record_cogs_history(array(
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name,
                    'cogs_before' => $cogs_before,
                    'cogs_after' => $cogs_after,
                    'stock_before' => $stock_before,
                    'stock_after' => $stock_after,
                    'qty' => (int) $row->qty,
                    'buy_price' => (int) $row->price,
                    'ref_type' => 'Purchase Cancel',
                    'ref_id' => $purchase_id,
                    'branch' => (int) $branch,
                    'created_by' => $cancelled_by,
                ));
            }

            $this->record_movement_stock(
                $row->product_id,
                $row->product_name,
                'OUT',
                (int) $row->qty,
                $stock_before,
                $stock_after,
                'Purchase Cancel',
                $purchase_id,
                $cancelled_by,
                'HPP ' . number_format($cogs_before, 0, ',', '.') . ' -> ' . number_format($cogs_after, 0, ',', '.')
            );
        }

        $this->db->where('purchase_id', $purchase_id);
        $this->db->update('purchase', array(
            'purchase_status' => 'Cancel',
            'cancelled_by' => $cancelled_by,
            'cancelled_at' => date('Y-m-d H:i:s'),
        ));

        $this->db->trans_complete();
        return $this->db->trans_status();
    }
}

?>
