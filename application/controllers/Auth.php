<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

class Auth extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->model('auth_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}

	public function login()
	{
		$data = (array) json_decode(file_get_contents('php://input'), true);
		$username = isset($data['username']) ? $data['username'] : '';
		$password = isset($data['password']) ? $data['password'] : '';

		if ($username === '' || $password === '') {
			echo json_encode(['code' => 0, 'result' => 'Username dan password wajib diisi']);
			return;
		}

		$get_user_login = $this->auth_model->login($username, md5($password));
		if($get_user_login != null)
		{
			$result = (array) $get_user_login;

			echo json_encode(['code'=>200, 'result'=>$result,]);
		}else{
			$msg = 'Username Atau Password Salah';
			echo json_encode(['code'=>0, 'result'=>$msg]);
		}
	}

	public function change_password()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		$old_password = isset($body['old_password']) ? (string) $body['old_password'] : '';
		$new_password = isset($body['new_password']) ? (string) $body['new_password'] : '';

		if ($user_id <= 0 || $old_password === '' || $new_password === '') {
			echo json_encode(['code' => 0, 'result' => 'user_id, old_password, dan new_password wajib diisi']);
			return;
		}
		if (strlen($new_password) < 5) {
			echo json_encode(['code' => 0, 'result' => 'Password baru minimal 5 karakter']);
			return;
		}

		$result = $this->auth_model->change_password($user_id, md5($old_password), md5($new_password));

		if ($result === 'USER_NOT_FOUND') {
			echo json_encode(['code' => 0, 'result' => 'User tidak ditemukan']);
		} else if ($result === 'WRONG_PASSWORD') {
			echo json_encode(['code' => 0, 'result' => 'Password lama salah']);
		} else if ($result) {
			echo json_encode(['code' => 200, 'result' => 'Password berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah password']);
		}
	}

	/**
	 * Absensi (attendance) karyawan
	 */

	// input absensi.
	// - GET  /auth/add_absensi/{user_id}  -> dipakai untuk scan QR code: toggle otomatis check-in/check-out hari ini
	// - POST (JSON body)                  -> input manual (mis. isi absensi lengkap oleh admin)
	public function add_absensi($qr_user_id = null)
	{
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
			$user_id = $qr_user_id !== null ? (int) $qr_user_id : (isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0);
			if ($user_id <= 0) {
				echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
				return;
			}

			$user = $this->auth_model->get_user_by_id($user_id);
			if (!$user) {
				echo json_encode(['code' => 0, 'result' => 'User tidak ditemukan']);
				return;
			}

			$result = $this->auth_model->scan_absensi($user_id, $user->username);
			$label = $result['action'] === 'check_in' ? 'Check-in' : 'Check-out';
			echo json_encode([
				'code' => 200,
				'result' => $label . ' berhasil untuk ' . $user->username . ' (' . $result['time'] . ')',
				'action' => $result['action'],
				'absensi_id' => $result['absensi_id'],
			]);
			return;
		}

		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		$date = !empty($body['absensi_date']) ? $body['absensi_date'] : date('Y-m-d');
		$time_in = !empty($body['absensi_time_in']) ? $body['absensi_time_in'] : date('H:i:s');
		$time_out = !empty($body['absensi_time_out']) ? $body['absensi_time_out'] : null;
		$note = !empty($body['absensi_note']) ? trim($body['absensi_note']) : null;
		$created_by = isset($body['created_by']) ? trim($body['created_by']) : '';

		if ($user_id <= 0 || $created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'user_id dan created_by wajib diisi']);
			return;
		}

		if (!$this->auth_model->get_user_by_id($user_id)) {
			echo json_encode(['code' => 0, 'result' => 'User tidak ditemukan']);
			return;
		}

		$absensi_id = $this->auth_model->add_absensi($user_id, $date, $time_in, $time_out, $note, $created_by);
		if ($absensi_id) {
			echo json_encode(['code' => 200, 'result' => 'Absensi berhasil disimpan', 'absensi_id' => $absensi_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan absensi']);
		}
	}

	// update absensi (mis. isi jam keluar / koreksi jam masuk)
	public function edit_absensi()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$absensi_id = isset($body['absensi_id']) ? (int) $body['absensi_id'] : 0;
		if ($absensi_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'absensi_id wajib diisi']);
			return;
		}

		if (!$this->auth_model->get_absensi_by_id($absensi_id)) {
			echo json_encode(['code' => 0, 'result' => 'Absensi tidak ditemukan']);
			return;
		}

		$data = array();
		if (!empty($body['absensi_date'])) $data['absensi_date'] = $body['absensi_date'];
		if (!empty($body['absensi_time_in'])) $data['absensi_time_in'] = $body['absensi_time_in'];
		if (!empty($body['absensi_time_out'])) $data['absensi_time_out'] = $body['absensi_time_out'];
		if (isset($body['absensi_note'])) $data['absensi_note'] = trim($body['absensi_note']);

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$success = $this->auth_model->edit_absensi($absensi_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Absensi berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah absensi']);
		}
	}

	// hapus absensi (soft delete)
	public function delete_absensi()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$absensi_id = isset($body['absensi_id']) ? (int) $body['absensi_id'] : 0;
		if ($absensi_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'absensi_id wajib diisi']);
			return;
		}

		if (!$this->auth_model->get_absensi_by_id($absensi_id)) {
			echo json_encode(['code' => 0, 'result' => 'Absensi tidak ditemukan']);
			return;
		}

		$success = $this->auth_model->delete_absensi($absensi_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Absensi berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus absensi']);
		}
	}

	// laporan absensi. filter: date_from, date_to (wajib), user_id (opsional)
	public function absensi_report()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$date_from = isset($body['date_from']) ? trim($body['date_from']) : '';
		$date_to = isset($body['date_to']) ? trim($body['date_to']) : '';
		if ($date_from === '' || $date_to === '') {
			echo json_encode(['code' => 0, 'result' => 'date_from dan date_to wajib diisi']);
			return;
		}

		$filter = array(
			'date_from' => $date_from,
			'date_to' => $date_to,
			'user_id' => !empty($body['user_id']) ? (int) $body['user_id'] : 0,
		);

		$data = $this->auth_model->get_absensi_report($filter);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	/**
	 * End Absensi
	 */

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

