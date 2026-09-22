<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Access extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->model('access_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}

	/**
	 * Role
	 */

	public function role_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->access_model->get_role_list($page, $per_page);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function role_list_no_pagging()
	{
		$data = $this->access_model->get_role_list_no_pagging();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function add_role()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['role_name']) ? trim($body['role_name']) : '';
		if ($name === '') {
			echo json_encode(['code' => 0, 'result' => 'role_name wajib diisi']);
			return;
		}
		if ($this->access_model->is_role_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama role sudah digunakan']);
			return;
		}

		$role_id = $this->access_model->add_role($name);
		if ($role_id) {
			echo json_encode(['code' => 200, 'result' => 'Role berhasil ditambahkan', 'role_id' => $role_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan role']);
		}
	}

	public function edit_role()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$role_id = isset($body['role_id']) ? (int) $body['role_id'] : 0;
		if ($role_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'role_id wajib diisi']);
			return;
		}

		$active = isset($body['role_active']) ? $body['role_active'] : null;
		if (!empty($active) && !in_array($active, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'role_active harus Y atau N']);
			return;
		}

		$data = array();
		if (!empty($body['role_name'])) $data['role_name'] = trim($body['role_name']);
		if (!empty($active)) $data['role_active'] = $active;

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['role_name']) && $this->access_model->is_role_name_exists($data['role_name'], $role_id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama role sudah digunakan']);
			return;
		}

		$success = $this->access_model->edit_role($role_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Role berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah role']);
		}
	}

	public function delete_role()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$role_id = isset($body['role_id']) ? (int) $body['role_id'] : 0;
		if ($role_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'role_id wajib diisi']);
			return;
		}

		if ($this->access_model->is_role_in_use($role_id)) {
			echo json_encode(['code' => 0, 'result' => 'Role masih dipakai oleh user aktif, tidak bisa dihapus']);
			return;
		}

		$success = $this->access_model->delete_role($role_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Role berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus role']);
		}
	}

	/**
	 * End Role
	 */

	/**
	 * Menu
	 */

	public function menu_list_no_pagging()
	{
		$data = $this->access_model->get_menu_list_no_pagging();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function add_menu()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$code = isset($body['menu_code']) ? trim($body['menu_code']) : '';
		$name = isset($body['menu_name']) ? trim($body['menu_name']) : '';
		if ($code === '' || $name === '') {
			echo json_encode(['code' => 0, 'result' => 'menu_code dan menu_name wajib diisi']);
			return;
		}
		if ($this->access_model->is_menu_code_exists($code)) {
			echo json_encode(['code' => 0, 'result' => 'menu_code sudah dipakai']);
			return;
		}
		if ($this->access_model->is_menu_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'menu_name sudah dipakai']);
			return;
		}

		$menu_id = $this->access_model->add_menu($code, $name);
		if ($menu_id) {
			echo json_encode(['code' => 200, 'result' => 'Menu berhasil ditambahkan', 'menu_id' => $menu_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan menu']);
		}
	}

	public function edit_menu()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$menu_id = isset($body['menu_id']) ? (int) $body['menu_id'] : 0;
		if ($menu_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'menu_id wajib diisi']);
			return;
		}

		$active = isset($body['menu_active']) ? $body['menu_active'] : null;
		if (!empty($active) && !in_array($active, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'menu_active harus Y atau N']);
			return;
		}

		$data = array();
		if (!empty($body['menu_name'])) $data['menu_name'] = trim($body['menu_name']);
		if (!empty($active)) $data['menu_active'] = $active;

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['menu_name']) && $this->access_model->is_menu_name_exists($data['menu_name'], $menu_id)) {
			echo json_encode(['code' => 0, 'result' => 'menu_name sudah dipakai']);
			return;
		}

		$success = $this->access_model->edit_menu($menu_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Menu berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah menu']);
		}
	}

	public function delete_menu()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$menu_id = isset($body['menu_id']) ? (int) $body['menu_id'] : 0;
		if ($menu_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'menu_id wajib diisi']);
			return;
		}

		$success = $this->access_model->delete_menu($menu_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Menu berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus menu']);
		}
	}

	/**
	 * End Menu
	 */

	/**
	 * Role Access (hak akses per role, per menu: view/add/edit/delete)
	 */

	public function role_access()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$role_id = isset($body['role_id']) ? (int) $body['role_id'] : 0;
		if ($role_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'role_id wajib diisi']);
			return;
		}

		$role = $this->access_model->get_role_by_id($role_id);
		if (!$role || $role->role_active !== 'Y') {
			echo json_encode(['code' => 0, 'result' => 'Role tidak ditemukan']);
			return;
		}

		$data = $this->access_model->get_role_access($role_id);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// simpan hak akses 1 role untuk banyak menu sekaligus.
	// payload: { "role_id": 3, "access": [ { "menu_id": 1, "can_view": "Y", "can_add": "Y", "can_edit": "N", "can_delete": "N" }, ... ] }
	public function update_role_access()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$role_id = isset($body['role_id']) ? (int) $body['role_id'] : 0;
		$access_list = isset($body['access']) && is_array($body['access']) ? $body['access'] : array();

		if ($role_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'role_id wajib diisi']);
			return;
		}
		if (empty($access_list)) {
			echo json_encode(['code' => 0, 'result' => 'access wajib diisi']);
			return;
		}
		foreach ($access_list as $access) {
			if (empty($access['menu_id'])) {
				echo json_encode(['code' => 0, 'result' => 'Setiap access wajib memiliki menu_id']);
				return;
			}
		}

		$role = $this->access_model->get_role_by_id($role_id);
		if (!$role || $role->role_active !== 'Y') {
			echo json_encode(['code' => 0, 'result' => 'Role tidak ditemukan']);
			return;
		}

		$success = $this->access_model->update_role_access($role_id, $access_list);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Hak akses role berhasil disimpan']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan hak akses role']);
		}
	}

	/**
	 * End Role Access
	 */

	// hak akses milik 1 user yang login (dari userrole di ms_user). dipanggil setelah login untuk tahu menu/aksi apa saja yang boleh diakses
	public function get_user_access()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$data = $this->access_model->get_user_access($user_id);
		if (!$data) {
			echo json_encode(['code' => 0, 'result' => 'User tidak ditemukan']);
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
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
