<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Opname_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    private function generate_opname_inv() {
        // format: INV/OPN/DD/MM00001, sequence 00001-99999 lalu diulang lagi dari 00001
        $total = $this->db->count_all_results('stock_opname');
        $seq = ($total % 99999) + 1;
        $seq = str_pad($seq, 5, '0', STR_PAD_LEFT);
        return 'INV/OPN/' . date('d') . '/' . date('m') . $seq;
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

    // hanya produk yang trackable stoknya (reduce_stock == Y) yang masuk akal untuk di-opname
    public function get_product_list_for_opname() {
        $this->db->select('p.product_id, p.product_code, p.product_name, p.product_stock, p.unit_id, u.unit_name, p.category_id, c.category_name');
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
                'product_stock' => (int) $row->product_stock,
                'unit_id' => (int) $row->unit_id,
                'unit_name' => $row->unit_name,
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
            );
        }
        return $data;
    }

    // hanya user dengan userrole 1 (owner) yang boleh melakukan opname - lihat komentar serupa di
    // Master.php soal role 1 = owner/superadmin, satu-satunya nilai role yang punya arti tetap
    public function is_owner($user_id) {
        $this->db->where('user_id', $user_id);
        $this->db->where('is_active', 'Y');
        $user = $this->db->get('ms_user')->row();
        return $user && (int) $user->userrole === 1;
    }

    // $items: array of {product_id, physical_stock}. Untuk tiap produk, product_stock di ms_product
    // langsung DISET ke physical_stock (bukan ditambah/dikurangi seperti purchase/penjualan), selisihnya
    // dicatat ke movement_stock (IN kalau fisik lebih banyak, OUT kalau lebih sedikit, tidak dicatat kalau
    // sama). Baris stock_opname_detail tetap disimpan untuk semua produk yang di-opname sebagai jejak
    // audit hasil hitung fisik, termasuk yang selisihnya 0.
    public function add_opname($data) {
        $items = $data['items'];

        $this->db->trans_start();

        $header = array(
            'stock_opname_inv' => $this->generate_opname_inv(),
            'stock_opname_date' => date('Y-m-d'),
            'note' => !empty($data['note']) ? trim($data['note']) : null,
            'created_by' => $data['created_by'],
        );
        $this->db->insert('stock_opname', $header);
        $stock_opname_id = $this->db->insert_id();

        $detail_rows = array();
        foreach ($items as $item) {
            $product = $this->db->where('product_id', $item['product_id'])->get('ms_product')->row();
            if (!$product) continue;

            $system_stock = (int) $product->product_stock;
            $physical_stock = (int) $item['physical_stock'];
            $difference = $physical_stock - $system_stock;

            $this->db->insert('stock_opname_detail', array(
                'stock_opname_id' => $stock_opname_id,
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'system_stock' => $system_stock,
                'physical_stock' => $physical_stock,
                'difference' => $difference,
            ));
            $detail_rows[] = array(
                'product_id' => (int) $product->product_id,
                'product_name' => $product->product_name,
                'system_stock' => $system_stock,
                'physical_stock' => $physical_stock,
                'difference' => $difference,
            );

            if ($difference === 0) continue;

            $this->db->where('product_id', $product->product_id);
            $this->db->update('ms_product', array('product_stock' => $physical_stock));

            $this->record_movement_stock(
                $product->product_id,
                $product->product_name,
                $difference > 0 ? 'IN' : 'OUT',
                abs($difference),
                $system_stock,
                $physical_stock,
                'Opname',
                $stock_opname_id,
                $data['created_by']
            );
        }

        if (empty($detail_rows)) {
            $this->db->trans_complete();
            return 'NO_VALID_ITEMS';
        }

        $this->db->trans_complete();
        return $this->db->trans_status() ? $stock_opname_id : false;
    }

    private function map_opname($row) {
        return array(
            'id' => (int) $row->stock_opname_id,
            'inv' => $row->stock_opname_inv,
            'date' => $row->stock_opname_date,
            'note' => $row->note,
            'created_by' => $row->created_by,
            'created_at' => $row->created_at,
        );
    }

    private function get_opname_items($stock_opname_id) {
        $this->db->where('stock_opname_id', $stock_opname_id);
        $this->db->order_by('stock_opname_detail_id', 'ASC');
        $query = $this->db->get('stock_opname_detail');

        $items = array();
        foreach ($query->result() as $row) {
            $items[] = array(
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'system_stock' => (int) $row->system_stock,
                'physical_stock' => (int) $row->physical_stock,
                'difference' => (int) $row->difference,
            );
        }
        return $items;
    }

    // tanpa stock_opname_id: daftar opname dengan pagination. dengan stock_opname_id: detail satu opname beserta item-nya
    public function get_opname_list($stock_opname_id = null, $page = 1, $per_page = 20) {
        if (!empty($stock_opname_id)) {
            $this->db->where('stock_opname_id', $stock_opname_id);
            $row = $this->db->get('stock_opname')->row();
            if (!$row) return null;

            $data = $this->map_opname($row);
            $data['items'] = $this->get_opname_items($data['id']);
            return $data;
        }

        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $total_items = (int) $this->db->count_all_results('stock_opname');
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

        $this->db->order_by('stock_opname_id', 'DESC');
        $this->db->limit($per_page, $offset);
        $query = $this->db->get('stock_opname');

        $data = array();
        foreach ($query->result() as $row) {
            $data[] = $this->map_opname($row);
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
}

?>
