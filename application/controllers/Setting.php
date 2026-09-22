<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Setting extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('setting_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}

	/**
	 * Point Exchange - riwayat penukaran point member (read-only)
	 */

	// Menu "Tukar Point" di billinggameon sekarang read-only: menampilkan riwayat penukaran
	// point member (tabel reward_redemption di gameon), bukan lagi CRUD katalog hadiah.
	// billing_api hanya proxy ke gameon (source of truth). body: {page?, per_page?, search?}.
	public function point_exchange_history()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$search = isset($body['search']) ? trim((string) $body['search']) : null;

		$gameon = $this->gameon->reward_redemption_list($page, $per_page, $search);
		if (!$gameon['success']) {
			echo json_encode(['code' => 0, 'result' => 'Server pusat (gameon) sedang tidak dapat dihubungi - riwayat penukaran point tidak bisa dibuka. Coba lagi setelah koneksi pulih.']);
			return;
		}
		echo json_encode(['code' => 200, 'result' => $gameon['result']]);
	}

	// cabang petugas (dari ms_user.user_branch by username), default 1 kalau tidak ketemu.
	private function _branch_by_username($username)
	{
		$username = trim((string) $username);
		if ($username === '') return 1;
		$user = $this->db->where('username', $username)->get('ms_user')->row();
		return ($user && (int) $user->user_branch > 0) ? (int) $user->user_branch : 1;
	}

	// Tandai kupon penukaran point TERPAKAI (Redeemed -> Claimed). Proxy ke gameon (source of truth).
	// body: {id? | redeem_code?, created_by}. cabang di-resolve dari user, bukan dikirim FE.
	public function point_exchange_claim()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = isset($body['id']) ? (int) $body['id'] : 0;
		$code = isset($body['redeem_code']) ? trim((string) $body['redeem_code']) : '';
		$created_by = isset($body['created_by']) ? trim((string) $body['created_by']) : '';
		if ($id <= 0 && $code === '') {
			echo json_encode(['code' => 0, 'result' => 'id atau redeem_code wajib diisi']);
			return;
		}
		if ($created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'created_by wajib diisi']);
			return;
		}

		$gameon = $this->gameon->reward_redemption_claim(array(
			'reward_redemption_id' => $id,
			'redeem_code' => $code,
			'branch' => $this->_branch_by_username($created_by),
			'claimed_by' => $created_by,
		));
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Kupon berhasil ditandai terpakai']
			: ['code' => 0, 'result' => $gameon['error'] ?: 'Gagal menandai kupon - server pusat (gameon) tidak dapat dihubungi.']);
	}

	// Batalkan klaim kupon (Claimed -> Redeemed). body: {id? | redeem_code?}
	public function point_exchange_unclaim()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = isset($body['id']) ? (int) $body['id'] : 0;
		$code = isset($body['redeem_code']) ? trim((string) $body['redeem_code']) : '';
		if ($id <= 0 && $code === '') {
			echo json_encode(['code' => 0, 'result' => 'id atau redeem_code wajib diisi']);
			return;
		}

		$gameon = $this->gameon->reward_redemption_unclaim(array(
			'reward_redemption_id' => $id,
			'redeem_code' => $code,
		));
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Klaim kupon berhasil dibatalkan']
			: ['code' => 0, 'result' => $gameon['error'] ?: 'Gagal membatalkan klaim - server pusat (gameon) tidak dapat dihubungi.']);
	}

	/**
	 * End Point Exchange
	 */

	/**
	 * Game (Setting > Game)
	 * Katalog game 100% dikelola di gameon (source of truth), billing_api hanya proxy.
	 * File gambar disimpan lokal di uploads/game/ (gameon simpan nama filenya saja).
	 * ms_game_room_ids = daftar category_meja_id (CSV) tempat game tersedia (multi-ruangan).
	 */
	public function game()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$branch = !empty($body['branch']) ? (int) $body['branch'] : null;

		$gameon = $this->gameon->game_list($page, $per_page, $branch);
		if (!$gameon['success'] || !is_array($gameon['result'])) {
			echo json_encode(['code' => 0, 'result' => 'Server pusat (gameon) sedang tidak dapat dihubungi - menu Game tidak bisa dibuka. Coba lagi setelah koneksi pulih.']);
			return;
		}

		$result = $gameon['result'];
		if (isset($result['data']) && is_array($result['data'])) {
			foreach ($result['data'] as &$row) {
				$img = !empty($row['image']) ? $row['image'] : 'default.jpg';
				$row['image_url'] = base_url('uploads/game/' . $img);
			}
			unset($row);
		}
		echo json_encode($result);
	}

	// add_game / edit_game menerima multipart/form-data (upload gambar). Field ms_game_image opsional.
	private function _upload_game_image()
	{
		if (empty($_FILES['ms_game_image']) || $_FILES['ms_game_image']['error'] === UPLOAD_ERR_NO_FILE) {
			return array('uploaded' => false);
		}
		$file = $_FILES['ms_game_image'];
		if ($file['error'] !== UPLOAD_ERR_OK) {
			return array('uploaded' => false, 'error' => 'Upload gambar gagal');
		}
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif'))) {
			return array('uploaded' => false, 'error' => 'Format gambar harus jpg, jpeg, png, atau gif');
		}
		if ($file['size'] > 2 * 1024 * 1024) {
			return array('uploaded' => false, 'error' => 'Ukuran gambar maksimal 2MB');
		}

		$upload_dir = FCPATH . 'uploads/game/';
		if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

		$filename = 'game_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
		if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
			return array('uploaded' => false, 'error' => 'Gagal menyimpan gambar');
		}
		return array('uploaded' => true, 'filename' => $filename);
	}

	public function add_game()
	{
		if ($this->input->method() !== 'post') {
			http_response_code(405);
			echo json_encode(['code' => 405, 'result' => 'Method Not Allowed, gunakan POST']);
			return;
		}

		$name = trim((string) $this->input->post('ms_game_name'));
		// branch, console, room_ids dikirim FE sebagai CSV (multi-pilihan). gameon yang membersihkan.
		$branch = trim((string) $this->input->post('ms_game_branch'));
		$console = $this->input->post('ms_game_console');
		$room_ids = $this->input->post('ms_game_room_ids');
		$desc = $this->input->post('ms_game_desc');

		if ($name === '' || $branch === '') {
			echo json_encode(['code' => 0, 'result' => 'ms_game_name dan ms_game_branch wajib diisi']);
			return;
		}

		$upload = $this->_upload_game_image();
		if (!empty($upload['error'])) {
			echo json_encode(['code' => 0, 'result' => $upload['error']]);
			return;
		}
		$image = $upload['uploaded'] ? $upload['filename'] : 'default.jpg';

		$gameon = $this->gameon->game_add(array(
			'name' => $name,
			'branch' => $branch,
			'console' => $console !== null ? trim($console) : null,
			'room_ids' => $room_ids !== null ? trim($room_ids) : null,
			'desc' => $desc !== null ? trim($desc) : null,
			'image' => $image,
		));
		if (!$gameon['success']) {
			if ($upload['uploaded'] && $image !== 'default.jpg') {
				$p = FCPATH . 'uploads/game/' . $image;
				if (file_exists($p)) unlink($p);
			}
			echo json_encode(['code' => 0, 'result' => $gameon['error']]);
			return;
		}
		echo json_encode(['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Game berhasil ditambahkan']);
	}

	public function edit_game()
	{
		if ($this->input->method() !== 'post') {
			http_response_code(405);
			echo json_encode(['code' => 405, 'result' => 'Method Not Allowed, gunakan POST']);
			return;
		}

		$ms_game_id = (int) $this->input->post('ms_game_id');
		if ($ms_game_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_game_id wajib diisi']);
			return;
		}

		$active = $this->input->post('ms_game_active');
		if (!empty($active) && !in_array($active, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'ms_game_active harus Y atau N']);
			return;
		}

		$upload = $this->_upload_game_image();
		if (!empty($upload['error'])) {
			echo json_encode(['code' => 0, 'result' => $upload['error']]);
			return;
		}

		$payload = array('ms_game_id' => $ms_game_id);
		if ($this->input->post('ms_game_name')) $payload['name'] = trim($this->input->post('ms_game_name'));
		// branch/console/room_ids = CSV (multi); dikirim FE hanya kalau ada perubahan
		if ($this->input->post('ms_game_branch') !== null) $payload['branch'] = trim((string) $this->input->post('ms_game_branch'));
		if ($this->input->post('ms_game_console') !== null) $payload['console'] = trim((string) $this->input->post('ms_game_console'));
		if ($this->input->post('ms_game_room_ids') !== null) $payload['room_ids'] = trim((string) $this->input->post('ms_game_room_ids'));
		if ($this->input->post('ms_game_desc') !== null) $payload['desc'] = trim((string) $this->input->post('ms_game_desc'));
		if (!empty($active)) $payload['ms_game_active'] = $active;

		$old_image = null;
		if ($upload['uploaded']) {
			$payload['image'] = $upload['filename'];
			$existing = $this->gameon->game_get($ms_game_id);
			if ($existing['success'] && is_array($existing['result'])) {
				$old_image = isset($existing['result']['image']) ? $existing['result']['image'] : null;
			}
		}

		if (count($payload) === 1) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$gameon = $this->gameon->game_edit($payload);
		if (!$gameon['success']) {
			if ($upload['uploaded'] && $upload['filename'] !== 'default.jpg') {
				$p = FCPATH . 'uploads/game/' . $upload['filename'];
				if (file_exists($p)) unlink($p);
			}
			echo json_encode(['code' => 0, 'result' => $gameon['error']]);
			return;
		}
		if ($old_image && $old_image !== 'default.jpg') {
			$old_path = FCPATH . 'uploads/game/' . $old_image;
			if (file_exists($old_path)) unlink($old_path);
		}
		echo json_encode(['code' => 200, 'result' => 'Game berhasil diubah']);
	}

	public function delete_game()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$ms_game_id = isset($body['ms_game_id']) ? (int) $body['ms_game_id'] : 0;
		if ($ms_game_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_game_id wajib diisi']);
			return;
		}

		$gameon = $this->gameon->game_delete(array('ms_game_id' => $ms_game_id));
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => 'Game berhasil dihapus']
			: ['code' => 0, 'result' => $gameon['error']]);
	}

	/**
	 * End Game
	 */

	/**
	 * Saldo (pilihan nominal top up saldo customer)
	 */

	// Katalog paket saldo 100% dikelola di gameon (source of truth). billing_api hanya proxy - TIDAK
	// ada fallback lokal: kalau gameon tidak bisa dihubungi, daftar paket saldo tidak bisa dibuka
	// (konsisten dengan customer/saldo/point yang lain - hanya jalan kalau server pusat sehat).
	public function saldo_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

		$gameon = $this->gameon->saldo_list($page, $per_page);
		if (!$gameon['success']) {
			echo json_encode(['code' => 0, 'result' => 'Server pusat (gameon) sedang tidak dapat dihubungi - daftar paket saldo tidak bisa dibuka. Coba lagi setelah koneksi pulih.']);
			return;
		}
		echo json_encode(['code' => 200, 'result' => $gameon['result']]);
	}

	public function saldo_list_no_pagging()
	{
		$gameon = $this->gameon->saldo_list_active();
		if (!$gameon['success']) {
			echo json_encode(['code' => 0, 'result' => 'Server pusat (gameon) sedang tidak dapat dihubungi - daftar paket saldo tidak bisa dimuat. Coba lagi setelah koneksi pulih.']);
			return;
		}
		echo json_encode(['code' => 200, 'result' => $gameon['result']]);
	}

	public function add_saldo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$gameon = $this->gameon->saldo_add(array(
			'ms_saldo_nominal' => isset($body['ms_saldo_nominal']) ? (int) $body['ms_saldo_nominal'] : 0,
			'ms_saldo_price' => isset($body['ms_saldo_price']) ? (int) $body['ms_saldo_price'] : 0,
			'ms_saldo_discount' => isset($body['ms_saldo_discount']) ? (int) $body['ms_saldo_discount'] : 0,
		));
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Pilihan saldo berhasil ditambahkan']
			: ['code' => 0, 'result' => $gameon['error']]);
	}

	public function edit_saldo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$payload = array('ms_saldo_id' => isset($body['ms_saldo_id']) ? (int) $body['ms_saldo_id'] : 0);
		foreach (array('ms_saldo_nominal', 'ms_saldo_price', 'ms_saldo_discount', 'ms_saldo_active') as $f) {
			if (isset($body[$f])) $payload[$f] = $body[$f];
		}
		$gameon = $this->gameon->saldo_edit($payload);
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Pilihan saldo berhasil diubah']
			: ['code' => 0, 'result' => $gameon['error']]);
	}

	public function delete_saldo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$gameon = $this->gameon->saldo_delete(array('ms_saldo_id' => isset($body['ms_saldo_id']) ? (int) $body['ms_saldo_id'] : 0));
		echo json_encode($gameon['success']
			? ['code' => 200, 'result' => is_string($gameon['result']) ? $gameon['result'] : 'Pilihan saldo berhasil dihapus']
			: ['code' => 0, 'result' => $gameon['error']]);
	}

	/**
	 * End Saldo
	 */

	/**
	 * Category Meja
	 */

	public function category_meja()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->setting_model->get_category_meja_list($page, $per_page);
		echo json_encode($data);
	}

	public function add_category_meja()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['category_meja_name']) ? trim($body['category_meja_name']) : '';
		if ($name === '') {
			echo json_encode(['code' => 0, 'result' => 'category_meja_name wajib diisi']);
			return;
		}

		$price = isset($body['category_meja_price']) ? (int) $body['category_meja_price'] : 1;
		if (!in_array($price, array(1, 2, 3, 4, 5))) {
			echo json_encode(['code' => 0, 'result' => 'category_meja_price harus 1, 2, 3, 4, atau 5']);
			return;
		}
		if ($this->setting_model->is_category_meja_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama kategori meja sudah digunakan']);
			return;
		}

		$category_meja_id = $this->setting_model->add_category_meja($name, $price);
		if ($category_meja_id) {
			echo json_encode(['code' => 200, 'result' => 'Kategori meja berhasil ditambahkan', 'category_meja_id' => $category_meja_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan kategori meja']);
		}
	}

	public function edit_category_meja()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$category_meja_id = isset($body['category_meja_id']) ? (int) $body['category_meja_id'] : 0;
		if ($category_meja_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'category_meja_id wajib diisi']);
			return;
		}

		$active = isset($body['category_meja_active']) ? $body['category_meja_active'] : null;
		if (!empty($active) && !in_array($active, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'category_meja_active harus Y atau N']);
			return;
		}

		$price = isset($body['category_meja_price']) ? (int) $body['category_meja_price'] : null;
		if ($price !== null && !in_array($price, array(1, 2, 3, 4, 5))) {
			echo json_encode(['code' => 0, 'result' => 'category_meja_price harus 1, 2, 3, 4, atau 5']);
			return;
		}

		$data = array();
		if (!empty($body['category_meja_name'])) $data['category_meja_name'] = trim($body['category_meja_name']);
		if ($price !== null) $data['category_meja_price'] = $price;
		if (!empty($active)) $data['category_meja_active'] = $active;

		if (isset($data['category_meja_name']) && $this->setting_model->is_category_meja_name_exists($data['category_meja_name'], $category_meja_id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama kategori meja sudah digunakan']);
			return;
		}

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$success = $this->setting_model->edit_category_meja($category_meja_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Kategori meja berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah kategori meja']);
		}
	}

	public function delete_category_meja()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$category_meja_id = isset($body['category_meja_id']) ? (int) $body['category_meja_id'] : 0;
		if ($category_meja_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'category_meja_id wajib diisi']);
			return;
		}

		$success = $this->setting_model->delete_category_meja($category_meja_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Kategori meja berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus kategori meja']);
		}
	}

	/**
	 * End Category Meja
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

?>