<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Purchase extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('purchase_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}

	// cabang dari username (ms_user.user_branch). fallback 1.
	private function _resolve_branch($created_by = null)
	{
		if (!empty($created_by)) {
			$user = $this->db->where('username', $created_by)->get('ms_user')->row();
			if ($user) return (int) $user->user_branch;
		}
		return 1;
	}

	// kirim salinan 1 pembelian ke laporan online gameon (Internal/receive_purchase).
	// Fire & forget: gagal cuma dicatat di log, pembelian lokal tetap sah.
	private function _sync_purchase_to_gameon($purchase_id, $branch)
	{
		if (empty($purchase_id)) return;
		$p = $this->purchase_model->get_purchase_list($purchase_id);
		if (!$p) return;

		$payload = array(
			'branch' => (int) $branch,
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
		if ($push['success']) {
			$this->purchase_model->mark_purchase_uploaded($purchase_id);
		} else {
			log_message('error', 'Gagal push pembelian ke gameon (id=' . $purchase_id . '): ' . $push['error']);
		}
	}

	// tanpa purchase_id: daftar purchase dengan pagination. dengan purchase_id: detail satu purchase beserta item-nya
	public function purchase_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$purchase_id = !empty($body['purchase_id']) ? (int) $body['purchase_id'] : null;
		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

		$data = $this->purchase_model->get_purchase_list($purchase_id, $page, $per_page);

		if ($purchase_id !== null && !$data) {
			echo json_encode(['code' => 0, 'result' => 'Purchase tidak ditemukan']);
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// daftar product yang bisa dibelikan (reduce_stock == Y), tanpa pagination, dipakai untuk pilih item di form purchase_add
	public function list_product_purchase()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$data = $this->purchase_model->get_product_list_for_purchase();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function purchase_add()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$supplier = isset($body['supplier']) ? trim($body['supplier']) : '';
		$supplier_invoice = isset($body['supplier_invoice']) ? trim($body['supplier_invoice']) : '';
		$discount = isset($body['discount']) ? (int) $body['discount'] : 0;
		$created_by = isset($body['created_by']) ? $body['created_by'] : '';
		$items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();

		if ($created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'created_by wajib diisi']);
			return;
		}
		if (empty($items)) {
			echo json_encode(['code' => 0, 'result' => 'items wajib diisi']);
			return;
		}
		if ($discount < 0) {
			echo json_encode(['code' => 0, 'result' => 'discount tidak boleh negatif']);
			return;
		}
		foreach ($items as $item) {
			if (empty($item['product_id'])) {
				echo json_encode(['code' => 0, 'result' => 'Setiap item wajib memiliki product_id dan qty']);
				return;
			}
			// qty wajib bilangan bulat positif, supaya "purchase" tidak bisa dipakai untuk mengurangi stok
			// (qty negatif) atau lolos tanpa efek (qty non-angka yang di-cast jadi 0)
			if (!isset($item['qty']) || !is_numeric($item['qty']) || (int) $item['qty'] <= 0) {
				echo json_encode(['code' => 0, 'result' => 'qty setiap item harus berupa angka lebih dari 0']);
				return;
			}
			// price ikut divalidasi supaya total pembelian tidak bisa didorong negatif lewat harga
			// satuan yang negatif (sama seperti pengerasan qty di atas)
			if (isset($item['price']) && (int) $item['price'] < 0) {
				echo json_encode(['code' => 0, 'result' => 'price setiap item tidak boleh negatif']);
				return;
			}
		}

		$branch = $this->_resolve_branch($created_by);

		$purchase_id = $this->purchase_model->add_purchase(array(
			'supplier' => $supplier,
			'supplier_invoice' => $supplier_invoice,
			'discount' => $discount,
			'created_by' => $created_by,
			'branch' => $branch,
			'items' => $items,
		));

		if ($purchase_id) {
			$this->_sync_purchase_to_gameon($purchase_id, $branch);
			echo json_encode(['code' => 200, 'result' => 'Purchase berhasil disimpan', 'purchase_id' => $purchase_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan purchase']);
		}
	}

	public function purchase_cancel()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$purchase_id = !empty($body['purchase_id']) ? (int) $body['purchase_id'] : 0;
		$cancelled_by = isset($body['cancelled_by']) ? $body['cancelled_by'] : '';

		if ($purchase_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'purchase_id wajib diisi']);
			return;
		}
		if ($cancelled_by === '') {
			echo json_encode(['code' => 0, 'result' => 'cancelled_by wajib diisi']);
			return;
		}

		$branch = $this->_resolve_branch($cancelled_by);
		$success = $this->purchase_model->cancel_purchase($purchase_id, $cancelled_by, $branch);
		if (is_string($success) && strpos($success, 'INSUFFICIENT_STOCK:') === 0) {
			$product_name = substr($success, strlen('INSUFFICIENT_STOCK:'));
			echo json_encode(['code' => 0, 'result' => 'Stok produk "' . $product_name . '" sudah terpakai, tidak bisa membatalkan pembelian ini']);
		} else if ($success) {
			$this->_sync_purchase_to_gameon($purchase_id, $branch);
			echo json_encode(['code' => 200, 'result' => 'Purchase berhasil dibatalkan']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Purchase tidak ditemukan atau sudah dibatalkan']);
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