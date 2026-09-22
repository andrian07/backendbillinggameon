<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Halaman "Sinkron Online": transaction / transaction_cafe / transaksi_saldo / purchase /
// table_status_event semua di-curl ke gameon (laporan online terpusat) fire-and-forget saat
// dibuat - kalau gameon down/tidak terjangkau saat itu, data lokal TETAP SAH tapi tidak pernah
// otomatis diulang. pending() menampilkan baris yang *_upload_status-nya masih 'N' (lihat
// mark_*_uploaded() di masing-masing model), retry() mengulang push-nya pakai data yang sudah
// tersimpan lokal (tidak perlu data baru dari client).
class Sync extends CI_Controller {

	private $valid_types = array('transaction', 'transaction_cafe', 'transaksi_saldo', 'purchase', 'table_status_event', 'table_snapshot', 'product_stock');

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('sync_model');
		$this->load->model('billing_model');
		$this->load->model('cafe_model');
		$this->load->model('master_model');
		$this->load->model('purchase_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}

	// daftar data yang belum berhasil ke-push ke gameon, dipaging (terbaru duluan, lintas ke-4 sumber).
	// body: page, per_page (opsional, default 1/20)
	public function pending()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

		$data = $this->sync_model->get_pending($page, $per_page);
		$data['counts'] = $this->sync_model->count_pending();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// versi ringan dari pending() - cuma 4 COUNT(*), tanpa menarik/mengurutkan barisnya. Dipakai
	// SyncWatcher (Flutter) yang polling tiap beberapa detik di semua halaman, supaya tick yang
	// (hampir selalu) tidak nemu apa-apa tetap murah.
	public function pending_count()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$counts = $this->sync_model->count_pending();
		$counts['total'] = array_sum($counts);
		echo json_encode(['code' => 200, 'result' => $counts]);
	}

	// upload ulang 1 baris - payload dibangun ulang dari data yang sudah tersimpan lokal (idempoten
	// di sisi gameon lewat kunci branch+inv, jadi retry berkali-kali aman).
	// body: {type: transaction|transaction_cafe|transaksi_saldo|purchase, id}
	public function retry()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$type = isset($body['type']) ? trim($body['type']) : '';
		$id = isset($body['id']) ? (int) $body['id'] : 0;
		if ($id <= 0 || !in_array($type, $this->valid_types, true)) {
			echo json_encode(['code' => 0, 'result' => 'type (' . implode('|', $this->valid_types) . ') dan id wajib diisi']);
			return;
		}

		$push = null;

		switch ($type) {
			case 'transaction':
				$payload = $this->billing_model->get_transaction_for_sync($id);
				if (!$payload) {
					echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
					return;
				}
				$push = $this->gameon->push_transaction($payload);
				if ($push['success']) $this->billing_model->mark_transaction_uploaded($id);
				break;

			case 'transaction_cafe':
				$payload = $this->cafe_model->get_transaction_cafe_for_sync($id);
				if (!$payload) {
					echo json_encode(['code' => 0, 'result' => 'Transaksi cafe tidak ditemukan']);
					return;
				}
				$push = $this->gameon->push_transaction_cafe($payload);
				if ($push['success']) $this->cafe_model->mark_transaction_cafe_uploaded($id);
				break;

			case 'transaksi_saldo':
				$payload = $this->master_model->get_saldo_transaction_for_sync($id);
				if (!$payload) {
					echo json_encode(['code' => 0, 'result' => 'Transaksi saldo tidak ditemukan']);
					return;
				}
				$push = $this->gameon->update_online($payload);
				if ($push['success']) $this->master_model->mark_saldo_uploaded($id);
				break;

			case 'purchase':
				$p = $this->purchase_model->get_purchase_list($id);
				if (!$p) {
					echo json_encode(['code' => 0, 'result' => 'Pembelian tidak ditemukan']);
					return;
				}
				// payload sama persis dengan Purchase::_sync_purchase_to_gameon()
				$payload = array(
					'branch' => isset($p['branch']) ? (int) $p['branch'] : 1,
					'purchase_inv' => $p['inv'],
					'purchase_date' => $p['date'],
					'purchase_created_at' => $p['created_at'],
					'purchase_supplier' => $p['supplier'],
					'purchase_supplier_invoice' => $p['supplier_invoice'],
					'purchase_sub_total' => (int) $p['sub_total'],
					'purchase_discount' => (int) $p['discount'],
					'purchase_total' => (int) $p['total'],
					'purchase_status' => $p['status'],
					'created_by' => $p['created_by'],
					'cancelled_by' => $p['cancelled_by'],
					'cancelled_at' => $p['cancelled_at'],
					'items' => isset($p['items']) ? $p['items'] : array(),
				);
				$push = $this->gameon->push_purchase($payload);
				if ($push['success']) $this->purchase_model->mark_purchase_uploaded($id);
				break;

			case 'table_status_event':
				$payload = $this->billing_model->get_table_event_for_sync($id);
				if (!$payload) {
					echo json_encode(['code' => 0, 'result' => 'Event status meja tidak ditemukan']);
					return;
				}
				$push = $this->gameon->push_table_status($payload);
				if ($push['success']) $this->billing_model->mark_table_event_uploaded($id);
				break;

			case 'table_snapshot':
				// "papan meja live" tidak dikunci per baris - id di sini = branch. Snapshot dibangun
				// ulang dari STATE MEJA SEKARANG (bukan histori), lalu di-push ulang. Idempoten di
				// gameon (upsert per branch+table_id, tolak snapshot yang lebih lama).
				$payload = $this->billing_model->get_table_snapshot_for_sync($id);
				$push = $this->gameon->push_table_snapshot($payload);
				$this->billing_model->mark_table_snapshot_synced(
					$id,
					$push['success'],
					$payload['snapshot_at'],
					$push['success'] ? null : $push['error']
				);
				break;

			case 'product_stock':
				// stok/HPP produk tidak dikunci per baris - id di sini = branch. Snapshot dibangun
				// ulang dari KATALOG PRODUK SEKARANG (bukan histori), lalu di-push ulang. Idempoten
				// di gameon (upsert per branch+product_id, tolak snapshot yang lebih lama).
				$payload = $this->master_model->get_product_stock_snapshot_for_sync($id);
				$push = $this->gameon->push_product_stock($payload);
				$this->master_model->mark_product_stock_synced(
					$id,
					$push['success'],
					$payload['snapshot_at'],
					$push['success'] ? null : $push['error']
				);
				break;
		}

		if ($push['success']) {
			echo json_encode(['code' => 200, 'result' => 'Berhasil diupload ulang']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal upload ulang: ' . $push['error']]);
		}
	}

	private function _post_body()
	{
		if ($this->input->method() !== 'post') {
			http_response_code(405);
			echo json_encode(['code' => 405, 'result' => 'Method Not Allowed, gunakan POST']);
			return null;
		}
		return (array) json_decode(file_get_contents('php://input'), true);
	}
}
