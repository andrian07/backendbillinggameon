<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Maintenance extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->model('maintenance_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}

	// reset SELURUH data transaksional (customer, product, transaction, absensi, purchase, dll)
	// ke kosong. HANYA menyisakan data master/konfigurasi: category, category_meja,
	// minute_setting, ms_master_price, ms_menu, ms_payment, ms_role, ms_saldo, ms_user,
	// role_access, save_time_setting, unit.
	// table_active TIDAK di-truncate, hanya kolom sesi bookingnya yang direset:
	// table_customer_id, table_promo_id, table_mode, table_start_time, table_end_time,
	// table_duration, table_bill, dan table_active (flag aktif meja) dikembalikan ke 0.
	//
	// AKSI INI PERMANEN DAN TIDAK BISA DIBATALKAN. wajib kirim confirm: "RESET" untuk mencegah trigger tidak sengaja.
	public function reset_data()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$confirm = isset($body['confirm']) ? $body['confirm'] : '';
		if ($confirm !== 'RESET') {
			echo json_encode(['code' => 0, 'result' => 'Aksi ini akan menghapus seluruh data transaksional secara permanen. Kirim confirm: "RESET" untuk melanjutkan.']);
			return;
		}

		$truncated = $this->maintenance_model->reset_all_data();

		echo json_encode(['code' => 200, 'result' => 'Seluruh data transaksional berhasil direset', 'truncated_tables' => $truncated]);
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

?>
