<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Stock opname (penyesuaian stok fisik) - HANYA akun owner (ms_user.userrole == 1) yang boleh
// menjalankan opname_add(), diverifikasi lewat user_id yang dikirim di body (lihat
// Opname_model::is_owner()). Ini pengecekan role pertama di codebase ini yang menolak PEMANGGIL
// berdasarkan role-nya sendiri, bukan cuma memvalidasi target yang sedang diedit (lihat Master.php).
class Opname extends CI_Controller {

    public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->model('opname_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}

	// daftar product yang bisa di-opname (reduce_stock == Y), tanpa pagination
	public function product_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$data = $this->opname_model->get_product_list_for_opname();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// tanpa opname_id: daftar opname dengan pagination. dengan opname_id: detail satu opname beserta item-nya
	public function opname_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$stock_opname_id = !empty($body['opname_id']) ? (int) $body['opname_id'] : null;
		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

		$data = $this->opname_model->get_opname_list($stock_opname_id, $page, $per_page);

		if ($stock_opname_id !== null && !$data) {
			echo json_encode(['code' => 0, 'result' => 'Opname tidak ditemukan']);
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function opname_add()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		$note = isset($body['note']) ? trim($body['note']) : '';
		$created_by = isset($body['created_by']) ? $body['created_by'] : '';
		$items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();

		if ($user_id <= 0 || $created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'user_id dan created_by wajib diisi']);
			return;
		}
		if (empty($items)) {
			echo json_encode(['code' => 0, 'result' => 'items wajib diisi']);
			return;
		}
		foreach ($items as $item) {
			if (empty($item['product_id'])) {
				echo json_encode(['code' => 0, 'result' => 'Setiap item wajib memiliki product_id dan physical_stock']);
				return;
			}
			// physical_stock wajib bilangan bulat >= 0 (hasil hitung fisik, boleh nol tapi tidak boleh negatif)
			if (!isset($item['physical_stock']) || !is_numeric($item['physical_stock']) || (int) $item['physical_stock'] < 0) {
				echo json_encode(['code' => 0, 'result' => 'physical_stock setiap item harus berupa angka 0 atau lebih']);
				return;
			}
		}

		if (!$this->opname_model->is_owner($user_id)) {
			echo json_encode(['code' => 0, 'result' => 'Hanya akun owner yang dapat melakukan stock opname']);
			return;
		}

		$result = $this->opname_model->add_opname(array(
			'note' => $note,
			'created_by' => $created_by,
			'items' => $items,
		));

		if ($result === 'NO_VALID_ITEMS') {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada produk valid untuk di-opname']);
		} else if ($result) {
			echo json_encode(['code' => 200, 'result' => 'Stock opname berhasil disimpan', 'opname_id' => $result]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan stock opname']);
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
