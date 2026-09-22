<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Sinkron Online" - daftar transaksi/pembelian yang GAGAL/BELUM ke-push ke laporan online (gameon)
 * dan tombol upload ulang manual (lihat Sync.php). Sumbernya 4 tabel yang di-curl ke gameon:
 * transaction, transaction_cafe, transaksi_saldo, purchase - masing-masing punya kolom
 * *_upload_status yang HANYA disetel 'Y' setelah push benar-benar sukses (lihat mark_*_uploaded()
 * di Billing_model/Cafe_model/Master_model/Purchase_model). Selamanya 'N' kalau belum pernah berhasil.
 */
class Sync_model extends CI_Model {
    public function __construct() {
        parent::__construct();
    }

    // batas aman ditarik per tabel sebelum digabung+diurutkan - kalau ada ratusan pending sekaligus
    // (gameon down lama), ini tetap dibatasi supaya query & sort di PHP tidak berat
    private $per_source_cap = 300;

    public function get_pending($page = 1, $per_page = 20) {
        $rows = array();

        $this->db->select('transaction_id AS id, transaction_inv AS inv, transaction_date AS date, transaction_total_bill AS total, branch, created_by, created_at, transaction_status AS status', false);
        $this->db->where('transaction_upload_status', 'N');
        $this->db->order_by('transaction_id', 'DESC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('transaction')->result_array() as $r) {
            $r['type'] = 'transaction';
            $rows[] = $r;
        }

        $this->db->select('transaction_cafe_id AS id, transaction_cafe_inv AS inv, transaction_cafe_date AS date, transaction_cafe_total_bill AS total, branch, created_by, created_at, transaction_cafe_status AS status', false);
        $this->db->where('transaction_cafe_upload_status', 'N');
        $this->db->order_by('transaction_cafe_id', 'DESC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('transaction_cafe')->result_array() as $r) {
            $r['type'] = 'transaction_cafe';
            $rows[] = $r;
        }

        // transaksi_saldo tidak punya kolom tanggal terpisah dari created_at, jadi dipakai untuk keduanya
        $this->db->select('transaksi_saldo_id AS id, transaksi_saldo_inv AS inv, created_at AS date, transaksi_saldo_nominal AS total, branch, created_by, created_at, transaksi_saldo_status AS status', false);
        $this->db->where('transaksi_saldo_upload_status', 'N');
        $this->db->order_by('transaksi_saldo_id', 'DESC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('transaksi_saldo')->result_array() as $r) {
            $r['type'] = 'transaksi_saldo';
            $rows[] = $r;
        }

        $this->db->select('purchase_id AS id, purchase_inv AS inv, purchase_date AS date, purchase_total AS total, branch, created_by, created_at, purchase_status AS status', false);
        $this->db->where('purchase_upload_status', 'N');
        $this->db->order_by('purchase_id', 'DESC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('purchase')->result_array() as $r) {
            $r['type'] = 'purchase';
            $rows[] = $r;
        }

        // status meja (buka/tutup) tidak punya nominal/no. invoice - "inv" disintesis dari
        // event+nama meja supaya baris ini tetap bisa ditampilkan di daftar yang sama
        $this->db->select("table_status_event_id AS id, CONCAT(event, ' - ', table_number) AS inv, created_at AS date, 0 AS total, branch, created_by, created_at, event AS status", false);
        $this->db->where('upload_status', 'N');
        $this->db->order_by('table_status_event_id', 'DESC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('table_status_event')->result_array() as $r) {
            $r['type'] = 'table_status_event';
            $rows[] = $r;
        }

        // snapshot "papan meja live" - 1 baris per cabang yang masih dirty (perubahan meja belum
        // sampai ke gameon). Tidak punya id baris/nominal: "id" = branch supaya Sync::retry bisa
        // membangun ulang snapshot state meja sekarang, "inv" disintesis untuk ditampilkan.
        $this->db->select("branch AS id, CONCAT('Papan meja - cabang ', branch) AS inv, last_snapshot_at AS date, 0 AS total, branch, 'system' AS created_by, updated_at AS created_at, 'dirty' AS status", false);
        $this->db->where('dirty', 'Y');
        $this->db->order_by('branch', 'ASC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('table_state_sync')->result_array() as $r) {
            $r['type'] = 'table_snapshot';
            $rows[] = $r;
        }

        // stok/HPP produk - 1 baris per cabang yang masih dirty (snapshot terakhir belum sampai
        // ke gameon). Sama pola dengan table_snapshot: "id" = branch, "inv" disintesis.
        $this->db->select("branch AS id, CONCAT('Stok produk - cabang ', branch) AS inv, last_snapshot_at AS date, 0 AS total, branch, 'system' AS created_by, updated_at AS created_at, 'dirty' AS status", false);
        $this->db->where('dirty', 'Y');
        $this->db->order_by('branch', 'ASC');
        $this->db->limit($this->per_source_cap);
        foreach ($this->db->get('product_stock_sync')->result_array() as $r) {
            $r['type'] = 'product_stock';
            $rows[] = $r;
        }

        // terbaru duluan lintas semua sumber, sebelum dipaging
        usort($rows, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        $total_items = count($rows);
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;
        $paged = array_slice($rows, $offset, $per_page);

        foreach ($paged as &$r) {
            $r['id'] = (int) $r['id'];
            $r['total'] = (int) $r['total'];
            $r['branch'] = (int) $r['branch'];
        }
        unset($r);

        return array(
            'data' => $paged,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_items > 0 ? (int) ceil($total_items / $per_page) : 0,
                'has_next_page' => ($offset + $per_page) < $total_items,
                'has_prev_page' => $page > 1,
            ),
        );
    }

    // jumlah pending per sumber - dipakai badge/ringkasan di halaman Sinkron Online
    public function count_pending() {
        return array(
            'transaction' => (int) $this->db->where('transaction_upload_status', 'N')->count_all_results('transaction'),
            'transaction_cafe' => (int) $this->db->where('transaction_cafe_upload_status', 'N')->count_all_results('transaction_cafe'),
            'transaksi_saldo' => (int) $this->db->where('transaksi_saldo_upload_status', 'N')->count_all_results('transaksi_saldo'),
            'purchase' => (int) $this->db->where('purchase_upload_status', 'N')->count_all_results('purchase'),
            'table_status_event' => (int) $this->db->where('upload_status', 'N')->count_all_results('table_status_event'),
            'table_snapshot' => (int) $this->db->where('dirty', 'Y')->count_all_results('table_state_sync'),
            'product_stock' => (int) $this->db->where('dirty', 'Y')->count_all_results('product_stock_sync'),
        );
    }
}
