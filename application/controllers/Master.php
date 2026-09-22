<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

class Master extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('master_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}


	/**
	 * master user
	 */
	public function user_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->master_model->get_user_list($page, $per_page);
		echo json_encode($data);
	}

	public function add_user()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$username = isset($body['username']) ? trim($body['username']) : '';
		$password = isset($body['password']) ? $body['password'] : '';
		$userrole = isset($body['role']) ? $body['role'] : '';

		if ($username === '' || $password === '' || $userrole === '') {
			echo json_encode(['code' => 0, 'result' => 'username, password, role wajib diisi']);
			return;
		}
		// dibandingkan sebagai angka (bukan string exact-match) supaya varian seperti "01", " 1",
		// "1.0", "+1" tidak lolos dari pengecekan cuma karena tidak persis sama dengan string "1" -
		// MySQL tetap akan menyimpannya sebagai integer 1 di kolom userrole yang bertipe int
		if ((int) $userrole === 1) {
			echo json_encode(['code' => 0, 'result' => 'Role superadmin tidak dapat diberikan lewat API']);
			return;
		}
		if ($this->master_model->is_username_exists($username)) {
			echo json_encode(['code' => 0, 'result' => 'Username sudah digunakan']);
			return;
		}

		$user_id = $this->master_model->add_user($username, $password, $userrole);
		if ($user_id) {
			echo json_encode(['code' => 200, 'result' => 'User berhasil ditambahkan', 'user_id' => $user_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan user']);
		}
	}

	public function edit_user()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$target = $this->master_model->get_user_by_id($user_id);
		if ($target && (string) $target->userrole === '1') {
			echo json_encode(['code' => 0, 'result' => 'Akun superadmin tidak dapat diubah']);
			return;
		}

		// lihat catatan di add_user() - dibandingkan sebagai angka, bukan string exact-match
		if (!empty($body['role']) && (int) $body['role'] === 1) {
			echo json_encode(['code' => 0, 'result' => 'Role superadmin tidak dapat diberikan lewat API']);
			return;
		}

		$data = array();
		if (!empty($body['username'])) $data['username'] = trim($body['username']);
		if (!empty($body['password'])) $data['password'] = md5($body['password']);
		if (!empty($body['role'])) $data['userrole'] = $body['role'];

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['username']) && $this->master_model->is_username_exists($data['username'], $user_id)) {
			echo json_encode(['code' => 0, 'result' => 'Username sudah digunakan']);
			return;
		}

		$success = $this->master_model->edit_user($user_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'User berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah user']);
		}
	}

	public function delete_user()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$target = $this->master_model->get_user_by_id($user_id);
		if ($target && (string) $target->userrole === '1') {
			echo json_encode(['code' => 0, 'result' => 'Akun superadmin tidak dapat dihapus']);
			return;
		}

		$success = $this->master_model->delete_user($user_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'User berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus user']);
		}
	}

	public function reset_pass_user()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$target = $this->master_model->get_user_by_id($user_id);
		if ($target && (string) $target->userrole === '1') {
			echo json_encode(['code' => 0, 'result' => 'Password akun superadmin tidak dapat direset']);
			return;
		}

		$success = $this->master_model->reset_pass_user($user_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Password berhasil direset']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mereset password']);
		}
	}

	// QR code karyawan (user) untuk di-print. Isinya URL GET absensi (Auth::add_absensi), sekali di-scan = otomatis check-in/check-out.
	// GET /master/user_qrcode/{user_id} -> langsung menampilkan gambar QR code (image/png), tinggal dibuka di browser lalu di-print
	public function user_qrcode($user_id = null)
	{
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			http_response_code(400);
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$user = $this->master_model->get_user_by_id($user_id);
		if (!$user) {
			http_response_code(404);
			echo json_encode(['code' => 0, 'result' => 'User tidak ditemukan']);
			return;
		}

		$absen_url = base_url('index.php/auth/add_absensi/' . $user_id);

		$options = new \chillerlan\QRCode\QROptions([
			'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
			'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
			'scale' => 8,
			'imageBase64' => false,
		]);

		$qrcode = new \chillerlan\QRCode\QRCode($options);
		$png = $qrcode->render($absen_url);

		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: image/png');
		header('Content-Disposition: inline; filename="qrcode_' . $user->username . '.png"');
		echo $png;
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

	// trim + gabungkan spasi ganda di tengah nama jadi satu spasi, supaya pengecekan nama
	// duplikat (is_*_name_exists) tidak bisa dilewati cuma dengan menyisipkan spasi ekstra
	private function _normalize_name($name)
	{
		return trim(preg_replace('/\s+/', ' ', (string) $name));
	}

	// cabang milik user yang melakukan aksi: dicari dari ms_user.user_branch, coba lewat paid_by
	// (user_id) dulu, kalau tidak ada coba lewat created_by (username), default 1 kalau keduanya gagal
	private function _resolve_branch($paid_by = null, $created_by = null)
	{
		if (!empty($paid_by)) {
			$user = $this->db->where('user_id', (int) $paid_by)->get('ms_user')->row();
			if ($user) return (int) $user->user_branch;
		}
		if (!empty($created_by)) {
			$user = $this->db->where('username', $created_by)->get('ms_user')->row();
			if ($user) return (int) $user->user_branch;
		}
		return 1;
	}

	/**
	 * End master user
	 */

	/**
	 * master customer
	 */

	// data Member (termasuk saldo & point) diambil LIVE dari gameon - gameon adalah source of truth
	// untuk customer/saldo/point, billing_api tidak lagi menampilkan salinan lokalnya. Kalau gameon
	// tidak bisa dihubungi, fallback ke salinan lokal ms_customer supaya kasir tidak terblokir total.
	public function customer_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$search = isset($body['search']) ? trim((string) $body['search']) : '';

		$gameon = $this->gameon->customer_list($page, $per_page, $search);
		if ($gameon['success'] && is_array($gameon['result'])) {
			echo json_encode($gameon['result']);
			return;
		}

		$data = $this->master_model->get_customer_list($page, $per_page);
		$data['source'] = 'local_fallback';
		echo json_encode($data);
	}

	public function customer_list_no_pagging()
	{
		$gameon = $this->gameon->latest_customer(100000);
		if ($gameon['success'] && is_array($gameon['result'])) {
			echo json_encode(['code' => 200, 'result' => $gameon['result']]);
			return;
		}

		$data = $this->master_model->get_customer_list_no_pagging();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// tarik customer baru dari gameon (server pusat) yang belum ada di DB lokal, lalu insert. Dipanggil
	// manual dari tombol "Sync Customer" di aplikasi. Pakai HTTP API (bukan akses DB langsung) karena
	// gameon akan dipindah ke hosting - alamatnya cukup diganti lewat config/gameon.php
	public function sync_customer()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$gameon_result = $this->gameon->latest_customer(100000);
		if (!$gameon_result['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengambil data dari gameon: ' . $gameon_result['error']]);
			return;
		}

		$rows = is_array($gameon_result['result']) ? $gameon_result['result'] : array();
		if (empty($rows)) {
			echo json_encode(['code' => 200, 'result' => 'Tidak ada customer baru untuk disinkronkan', 'synced_count' => 0]);
			return;
		}

		$ids = array_map(function ($row) { return (int) $row['id']; }, $rows);
		$existing_ids = $this->master_model->get_existing_customer_ids($ids);

		$synced_count = 0;
		foreach ($rows as $row) {
			if (in_array((int) $row['id'], $existing_ids, true)) continue;
			if ($this->master_model->insert_customer_from_gameon($row)) {
				$synced_count++;
			}
		}

		echo json_encode([
			'code' => 200,
			'result' => $synced_count > 0
				? "$synced_count customer baru berhasil disinkronkan"
				: 'Tidak ada customer baru untuk disinkronkan',
			'synced_count' => $synced_count,
		]);
	}

	public function delete_customer()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
		if ($customer_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
			return;
		}

		$success = $this->master_model->delete_customer($customer_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Customer berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus customer']);
		}
	}

	public function reset_pass_customer()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
		if ($customer_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
			return;
		}

		$success = $this->master_model->reset_pass_customer($customer_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Password berhasil direset']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mereset password']);
		}
	}

	// tambah saldo customer (top up) dari pilihan ms_saldo. billing_api hanya mencatat transaksi_saldo -
	// saldo & histori penuh (update ms_customer.customer_saldo + history_saldo) jadi tanggung jawab
	// gameon, di-push lewat gameon->update_online() di bawah
	public function add_customer_saldo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
		$ms_saldo_id = isset($body['ms_saldo_id']) ? (int) $body['ms_saldo_id'] : 0;
		$payment_id = isset($body['payment_id']) ? (int) $body['payment_id'] : 0;
		$created_by = isset($body['created_by']) ? trim($body['created_by']) : '';
		$paid_by = isset($body['paid_by']) ? (int) $body['paid_by'] : 0;

		if ($customer_id <= 0 || $ms_saldo_id <= 0 || $payment_id <= 0 || $created_by === '' || $paid_by <= 0) {
			echo json_encode(['code' => 0, 'result' => 'customer_id, ms_saldo_id, payment_id, created_by, paid_by wajib diisi']);
			return;
		}

		if (!$this->master_model->get_customer_by_id($customer_id)) {
			echo json_encode(['code' => 0, 'result' => 'Customer tidak ditemukan']);
			return;
		}

		// paket saldo (nominal/harga/diskon) diambil dari gameon - katalog ms_saldo sudah tidak lagi
		// dikelola lokal. billing_api tetap mencatat transaksi_saldo lokal (rekap uang masuk kasir).
		$pkg = $this->gameon->saldo_get($ms_saldo_id);
		if (!$pkg['success'] || !is_array($pkg['result'])) {
			echo json_encode(['code' => 0, 'result' => $pkg['http_code'] === 404 ? 'Pilihan saldo tidak ditemukan' : ('Gagal mengambil paket saldo dari gameon: ' . $pkg['error'])]);
			return;
		}
		$nominal = (int) $pkg['result']['nominal'];
		$pay = (int) $pkg['result']['price'] - (int) $pkg['result']['discount'];
		$discount_pay = (int) $pkg['result']['discount'];

		$branch = $this->_resolve_branch($paid_by, $created_by);
		$result = $this->master_model->add_customer_saldo($customer_id, $ms_saldo_id, $nominal, $pay, $discount_pay, $payment_id, $created_by, $paid_by, $branch);
		if ($result === 'SALDO_OPTION_NOT_FOUND') {
			echo json_encode(['code' => 0, 'result' => 'Pilihan saldo tidak ditemukan']);
		} else if ($result) {
			// push ke gameon selagi cabang online, supaya saldo di gameon ikut ter-update. fire-and-forget -
			// kalau gameon gagal/tidak bisa dihubungi, transaksi lokal TETAP dianggap berhasil (tidak di-rollback)
			$push = $this->gameon->update_online(array(
				'type' => 'saldo',
				'transaksi_saldo_id' => $result['transaksi_saldo_id'],
				'transaksi_saldo_inv' => $result['transaksi_saldo_inv'],
				'customer_id' => $customer_id,
				'ms_saldo_id' => $ms_saldo_id,
				'nominal' => $result['nominal'],
				'pay' => $result['pay'],
				'discount_pay' => $result['discount_pay'],
				'payment_id' => $payment_id,
				'created_by' => $created_by,
				'paid_by' => $paid_by,
				'branch' => $branch,
			));
			if ($push['success']) {
				$this->master_model->mark_saldo_uploaded($result['transaksi_saldo_id']);
			} else {
				log_message('error', 'Gagal push saldo ke gameon (transaksi_saldo_id=' . $result['transaksi_saldo_id'] . '): ' . $push['error']);
			}

			echo json_encode(['code' => 200, 'result' => 'Saldo customer berhasil ditambahkan', 'transaksi_saldo_id' => $result['transaksi_saldo_id']]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan saldo customer']);
		}
	}

	// daftar permintaan top up dari aplikasi member (self-service) yang masih menunggu approve kasir -
	// dipanggil billinggameon secara berkala (polling), tinggal tarik langsung dari gameon (source
	// kebenarannya, billing_api tidak menyimpan salinan lokal).
	public function pending_topups()
	{
		$pull = $this->gameon->pending_topups();
		if (!$pull['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghubungi gameon: ' . $pull['error']]);
			return;
		}

		foreach ((array) $pull['result'] as $row) {
			// auto-sync: customer yang belum ada di database lokal (mis. daftar langsung dari app, belum
			// pernah datang ke cabang) di-input dulu di sini pakai data dari gameon, supaya nanti waktu
			// di-approve customer-nya sudah pasti ada lokal (lihat approve_topup()).
			if (!$this->master_model->get_customer_by_id($row['customer_id'])) {
				$customer = $this->gameon->get_customer($row['customer_id']);
				if ($customer['success']) {
					$this->master_model->insert_customer_from_gameon($customer['result']);
				} else {
					log_message('error', 'Gagal sync customer dari gameon (customer_id=' . $row['customer_id'] . '): ' . $customer['error']);
				}
			}

			// catat notifikasi kalau ini permintaan yang belum pernah kelihatan sebelumnya (idempoten -
			// topup_request_id UNIQUE, aman dipanggil tiap polling). billinggameon yang menentukan mana
			// yang "baru" (buat notifikasi OS) berdasarkan baris yang benar-benar baru ditambahkan di sini.
			$this->master_model->log_topup_notification($row['topup_request_id'], $row['customer_id'], $row['customer_name'], $row['amount'], $row['payment_method']);
		}

		echo json_encode(['code' => 200, 'result' => $pull['result']]);
	}

	// daftar notifikasi topup tersimpan lokal, terbaru duluan - dipakai billinggameon buat layar/bell
	// notifikasi (independen dari pending_topups(), tetap ada historinya meski request sudah di-approve).
	// query/body opsional: unread_only=1 buat cuma ambil yang belum dibaca.
	public function topup_notifications()
	{
		$unread_only = $this->input->get_post('unread_only') == '1';
		$data = $this->master_model->get_topup_notifications($unread_only);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// tandai notifikasi topup sudah dibaca. body: topup_notification_id (opsional - kosong = tandai SEMUA
	// sudah dibaca sekaligus, dipakai tombol "tandai semua dibaca").
	public function mark_topup_notification_read()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = !empty($body['topup_notification_id']) ? (int) $body['topup_notification_id'] : null;
		$this->master_model->mark_topup_notification_read($id);
		echo json_encode(['code' => 200, 'result' => 'Notifikasi ditandai sudah dibaca']);
	}

	// === Booking room (dari aplikasi member) - pola sama dengan pending_topups() di atas, tapi TANPA
	// aksi approve: saldo sudah dipotong di gameon waktu booking dibuat, billinggameon cuma menampilkan ===

	// daftar booking room dari aplikasi member - dipanggil billinggameon secara berkala (polling),
	// tarik langsung dari gameon. query/body: branch (1/2, WAJIB - tiap billinggameon cuma ambil
	// booking cabangnya sendiri), since_id (opsional, polling incremental).
	public function bookings()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$since_id = isset($body['since_id']) ? (int) $body['since_id'] : (int) $this->input->get('since_id');
		$branch = isset($body['branch']) ? (int) $body['branch'] : (int) $this->input->get('branch');
		if ($branch <= 0) {
			echo json_encode(['code' => 0, 'result' => 'Parameter branch (1/2) wajib diisi']);
			return;
		}

		// tarik SEMUA booking aktif cabang ini (limit besar) supaya mirror lokal lengkap, bukan
		// cuma yang baru (since_id) - mirror dipakai untuk cek bentrok saat buka meja.
		$pull = $this->gameon->bookings(0, 500, $branch);
		if (!$pull['success']) {
			// gameon tidak terjangkau - catat kegagalan, kembalikan state supaya FE tahu datanya basi
			$this->master_model->mark_booking_sync_failure($pull['error']);
			echo json_encode([
				'code' => 0,
				'result' => 'Gagal menghubungi gameon: ' . $pull['error'],
				'sync' => $this->master_model->get_booking_sync_state(),
			]);
			return;
		}

		// refresh mirror lokal (isi ulang total) + tandai sinkron berhasil
		$this->master_model->replace_local_bookings($pull['result'], $branch);
		$this->master_model->mark_booking_sync_success(count((array) $pull['result']));

		foreach ((array) $pull['result'] as $row) {
			// auto-sync customer yang belum ada di database lokal - sama seperti pending_topups().
			if (!$this->master_model->get_customer_by_id($row['customer_id'])) {
				$customer = $this->gameon->get_customer($row['customer_id']);
				if ($customer['success']) {
					$this->master_model->insert_customer_from_gameon($customer['result']);
				} else {
					log_message('error', 'Gagal sync customer dari gameon (customer_id=' . $row['customer_id'] . '): ' . $customer['error']);
				}
			}

			// catat notifikasi kalau booking ini belum pernah kelihatan (idempoten - booking_request_id
			// UNIQUE). billinggameon yang menentukan mana yang "baru" buat notifikasi OS.
			$this->master_model->log_booking_notification(
				$row['booking_request_id'], $row['customer_id'], $row['customer_name'], $row['category_name'],
				$row['booking_date'], $row['booking_time'], $row['duration_hours'], $row['price'],
				isset($row['branch']) ? (int) $row['branch'] : $branch
			);
		}

		echo json_encode([
			'code' => 200,
			'result' => $pull['result'],
			'sync' => $this->master_model->get_booking_sync_state(),
		]);
	}

	// daftar notifikasi booking tersimpan lokal, terbaru duluan. query/body: branch (1/2, WAJIB),
	// unread_only=1 (opsional).
	public function booking_notifications()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$unread_only = (isset($body['unread_only']) ? $body['unread_only'] : $this->input->get('unread_only')) == '1';
		$branch = isset($body['branch']) ? (int) $body['branch'] : (int) $this->input->get('branch');
		if ($branch <= 0) {
			echo json_encode(['code' => 0, 'result' => 'Parameter branch (1/2) wajib diisi']);
			return;
		}
		$data = $this->master_model->get_booking_notifications($unread_only, $branch);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// tandai notifikasi booking sudah dibaca. body: booking_notification_id (opsional - kosong = SEMUA).
	public function mark_booking_notification_read()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = !empty($body['booking_notification_id']) ? (int) $body['booking_notification_id'] : null;
		$this->master_model->mark_booking_notification_read($id);
		echo json_encode(['code' => 200, 'result' => 'Notifikasi ditandai sudah dibaca']);
	}

	// dipanggil billinggameon SETELAH kasir berhasil buka meja (Billing/book_table) dari 1 booking,
	// supaya booking itu ditandai selesai di gameon dan tidak lagi muncul di daftar booking aktif.
	public function confirm_booking()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$booking_request_id = isset($body['booking_request_id']) ? (int) $body['booking_request_id'] : 0;
		if ($booking_request_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'booking_request_id wajib diisi']);
			return;
		}

		$result = $this->gameon->confirm_booking($booking_request_id);
		if (!$result['success']) {
			echo json_encode(['code' => 0, 'result' => $result['error']]);
			return;
		}

		// meja sudah dibuka dari booking ini -> notifikasi lokalnya ikut ditandai sudah dibaca
		// supaya tidak muncul lagi (badge & notifikasi OS di billinggameon)
		$this->master_model->mark_booking_notification_read_by_request($booking_request_id);

		echo json_encode(['code' => 200, 'result' => 'Booking ditandai selesai']);
	}

	// cashier app polling status konfirmasi PIN member saat menunggu di layar pembayaran. body: {ref}
	public function member_approval_status()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$ref = isset($body['ref']) ? trim((string) $body['ref']) : '';
		if ($ref === '') {
			echo json_encode(['code' => 0, 'result' => 'ref wajib diisi']);
			return;
		}
		$res = $this->gameon->member_approval_status($ref);
		if (!$res['success']) {
			echo json_encode(['code' => 0, 'result' => $res['error'] ?: 'Gagal cek status ke gameon']);
			return;
		}
		echo json_encode(['code' => 200, 'result' => $res['result']]);
	}

	// kasir batal menunggu (ganti metode bayar / tutup dialog). body: {ref}
	public function member_approval_cancel()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$ref = isset($body['ref']) ? trim((string) $body['ref']) : '';
		if ($ref === '') {
			echo json_encode(['code' => 0, 'result' => 'ref wajib diisi']);
			return;
		}
		$this->gameon->cancel_member_approval($ref);
		echo json_encode(['code' => 200, 'result' => 'Dibatalkan']);
	}

	// kasir approve 1 permintaan top up dari billinggameon. customer_id & amount SENGAJA diambil ulang
	// dari gameon (bukan dari body client) supaya nominal yang dicatat lokal selalu sama dengan yang
	// sebenarnya disetujui gameon - client cuma perlu kirim topup_request_id.
	public function approve_topup()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$topup_request_id = isset($body['topup_request_id']) ? (int) $body['topup_request_id'] : 0;
		$payment_id = isset($body['payment_id']) ? (int) $body['payment_id'] : 0;
		$created_by = isset($body['created_by']) ? trim($body['created_by']) : '';
		$paid_by = isset($body['paid_by']) ? (int) $body['paid_by'] : 0;

		if ($topup_request_id <= 0 || $payment_id <= 0 || $created_by === '' || $paid_by <= 0) {
			echo json_encode(['code' => 0, 'result' => 'topup_request_id, payment_id, created_by, paid_by wajib diisi']);
			return;
		}

		$pull = $this->gameon->pending_topups();
		if (!$pull['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghubungi gameon: ' . $pull['error']]);
			return;
		}

		$request = null;
		foreach ((array) $pull['result'] as $row) {
			if ((int) $row['topup_request_id'] === $topup_request_id) {
				$request = $row;
				break;
			}
		}
		if ($request === null) {
			echo json_encode(['code' => 0, 'result' => 'Permintaan top up tidak ditemukan atau sudah diproses']);
			return;
		}

		// customer HARUS sudah tersinkron ke database lokal cabang dulu (lihat receive_customer) - kalau
		// belum, transaksi_saldo lokal bisa jadi referensi customer_id yang tidak ada di ms_customer lokal
		if (!$this->master_model->get_customer_by_id($request['customer_id'])) {
			echo json_encode(['code' => 0, 'result' => 'Customer belum tersinkron ke sistem lokal, tidak bisa di-approve dulu']);
			return;
		}

		$branch = $this->_resolve_branch($paid_by, $created_by);
		$local = $this->master_model->record_topup_transaction($request['customer_id'], $request['amount'], $payment_id, $created_by, $paid_by, $branch);
		if (!$local) {
			echo json_encode(['code' => 0, 'result' => 'Gagal mencatat transaksi top up lokal']);
			return;
		}

		$push = $this->gameon->confirm_topup(array(
			'topup_request_id' => $topup_request_id,
			'transaksi_saldo_id' => $local['transaksi_saldo_id'],
			'transaksi_saldo_inv' => $local['transaksi_saldo_inv'],
			'payment_id' => $payment_id,
			'created_by' => $created_by,
			'paid_by' => $paid_by,
			'branch' => $branch,
		));
		if (!$push['success']) {
			// transaksi lokal TETAP tercatat (upload_status='N') meski gameon gagal dihubungi - saldo member
			// belum ter-update di gameon sampai retry berhasil. Sama seperti resiko fire-and-forget di
			// add_customer_saldo() di atas.
			log_message('error', 'Gagal konfirmasi top up ke gameon (topup_request_id=' . $topup_request_id . '): ' . $push['error']);
			echo json_encode(['code' => 0, 'result' => 'Tercatat lokal, tapi gagal konfirmasi ke gameon: ' . $push['error']]);
			return;
		}

		echo json_encode(['code' => 200, 'result' => 'Top up berhasil di-approve', 'transaksi_saldo_id' => $local['transaksi_saldo_id']]);
	}

	// cari member dari QR yang di-scan kasir (scanner USB/HID - sama seperti pola absensi karyawan,
	// lihat AbsensiScanDialog di billinggameon). QR-nya berupa token TERENKRIPSI & berubah tiap kali
	// member buka layar QR-nya (perlu PIN dulu di sisi member - lihat Apimobile::verify_pin_for_qr() di
	// gameon), jadi screenshot lama tidak bisa dipakai ulang. Auto-sync customer ke lokal kalau belum
	// ada, sama seperti pending_topups().
	public function find_member_by_qr()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$qr_token = isset($body['qr_token']) ? trim($body['qr_token']) : '';
		if ($qr_token === '') {
			echo json_encode(['code' => 0, 'result' => 'qr_token wajib diisi']);
			return;
		}

		$this->load->helper('qr_token');
		$payload = qr_token_decrypt($qr_token);
		if ($payload === null || empty($payload['customer_id'])) {
			echo json_encode(['code' => 0, 'result' => 'QR tidak valid atau sudah kadaluarsa, minta member buka ulang QR-nya']);
			return;
		}
		$customer_id = (int) $payload['customer_id'];

		// selalu tarik data terbaru dari gameon (source kebenaran) - dipakai buat auto-sync KALAU belum
		// ada lokal, dan buat tampilan saldo/poin di kasir supaya akurat (bukan angka lokal yang basi)
		$remote = $this->gameon->get_customer($customer_id);
		if (!$remote['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengambil data member dari gameon: ' . $remote['error']]);
			return;
		}

		if (!$this->master_model->get_customer_by_id($customer_id)) {
			$this->master_model->insert_customer_from_gameon($remote['result']);
		}

		echo json_encode(['code' => 200, 'result' => array(
			'customer_id' => $customer_id,
			'name' => $remote['result']['name'],
			'phone' => $remote['result']['phone'],
			'saldo' => $remote['result']['saldo'],
			'point' => $remote['result']['point'],
		)]);
	}

	/**
	 * End master customer
	 */

	/**
	 * master price
	 */

	public function price_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$data = $this->master_model->get_price_list();
		echo json_encode(['data' => $data]);
	}

	public function edit_price()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$master_price_id = isset($body['master_price_id']) ? (int) $body['master_price_id'] : 0;
		if ($master_price_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'master_price_id wajib diisi']);
			return;
		}

		$editable_fields = array(
			'price' => 'master_price_price',
			'price_2' => 'master_price_price_2',
			'price_3' => 'master_price_price_3',
			'price_4' => 'master_price_price_4',
			'price_5' => 'master_price_price_5',
		);

		$data = array();
		foreach ($editable_fields as $field => $column) {
			if (isset($body[$field])) $data[$column] = (int) $body[$field];
		}

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$success = $this->master_model->edit_price($master_price_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Harga berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah harga']);
		}
	}

	/**
	 * End master price
	 */

	/**
	 * Master Payment
	 */

	public function get_payment_list()
	{
		$data = $this->master_model->get_payment_list();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	/**
	 * End master Payment
	 */

	/**
	 * Master Promo
	 */

	public function promo_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->master_model->get_promo_list($page, $per_page);
		echo json_encode($data);
	}

	public function promo_list_no_pagging()
	{
		$data = $this->master_model->get_promo_list_no_pagging();
		echo json_encode($data);
	}

	// mem-parse & memvalidasi valid_days ("1,3" atau [1,3], 1=Senin..7=Minggu sesuai date('N')) dan
	// valid_time_start/valid_time_end (jam 0-24, wajib diisi berdua atau dikosongkan berdua) yang dikirim
	// body add_promo/edit_promo. Dipakai bersama supaya aturannya konsisten di kedua endpoint.
	// Return array('error' => string|null, 'valid_days' => string|null, 'valid_time_start' => int|null, 'valid_time_end' => int|null)
	private function _parse_promo_schedule($body) {
		$valid_days = null;
		if (isset($body['valid_days']) && $body['valid_days'] !== '' && $body['valid_days'] !== null) {
			$raw_days = is_array($body['valid_days']) ? $body['valid_days'] : explode(',', $body['valid_days']);
			$days = array();
			foreach ($raw_days as $d) {
				$d = (int) trim($d);
				if ($d < 1 || $d > 7) {
					return array('error' => 'valid_days harus angka 1-7 (1=Senin, 7=Minggu)');
				}
				if (!in_array($d, $days, true)) $days[] = $d;
			}
			if (!empty($days)) {
				sort($days);
				$valid_days = implode(',', $days);
			}
		}

		$has_start = isset($body['valid_time_start']) && $body['valid_time_start'] !== '';
		$has_end = isset($body['valid_time_end']) && $body['valid_time_end'] !== '';
		$valid_time_start = null;
		$valid_time_end = null;
		if ($has_start || $has_end) {
			if (!$has_start || !$has_end) {
				return array('error' => 'valid_time_start dan valid_time_end wajib diisi bersamaan');
			}
			$valid_time_start = (int) $body['valid_time_start'];
			$valid_time_end = (int) $body['valid_time_end'];
			if ($valid_time_start < 0 || $valid_time_start > 24 || $valid_time_end < 0 || $valid_time_end > 24) {
				return array('error' => 'valid_time_start dan valid_time_end harus antara 0-24');
			}
			// valid_time_start boleh LEBIH BESAR dari valid_time_end - itu artinya jendela melewati
			// tengah malam (mis. 22 s/d 4 = berlaku jam 22:00 malam sampai jam 04:00 keesokan
			// paginya). Hanya start === end (durasi nol, ambigu) yang ditolak - lihat cara jendela
			// ini dibaca di Billing_model::validate_promo_schedule().
			if ($valid_time_start === $valid_time_end) {
				return array('error' => 'valid_time_start tidak boleh sama dengan valid_time_end');
			}
		}

		return array('error' => null, 'valid_days' => $valid_days, 'valid_time_start' => $valid_time_start, 'valid_time_end' => $valid_time_end);
	}

	public function add_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['ms_promo_name']) ? $this->_normalize_name($body['ms_promo_name']) : '';
		$tipe = isset($body['ms_promo_tipe']) ? $body['ms_promo_tipe'] : '';
		$value = isset($body['ms_promo_value']) ? $body['ms_promo_value'] : '';
		$hour = isset($body['hour']) ? $body['hour'] : '';
		// free_hour: jam gratis tambahan (mis. promo "2 Jam Gratis 1 Jam" -> hour=2, free_hour=1).
		// Murni keterangan untuk booking normal (harga Fix tetap dari ms_promo_value) - baru berefek
		// nyata saat booking pakai waktu tersimpan (lihat Billing_model::apply_promo_free_hour()).
		$free_hour = isset($body['free_hour']) && $body['free_hour'] !== '' ? (int) $body['free_hour'] : null;
		// category_ids: daftar category_meja_id yang promo ini boleh dipakai. KOSONG / tidak dikirim =
		// berlaku untuk SEMUA kategori meja (perilaku lama, kompatibel).
		$category_ids = (isset($body['category_ids']) && is_array($body['category_ids'])) ? $body['category_ids'] : array();

		if ($name === '' || $tipe === '' || $value === '') {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_name, ms_promo_tipe, ms_promo_value wajib diisi']);
			return;
		}
		if (!in_array($tipe, array('Diskon', 'Fix'))) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_tipe harus Diskon atau Fix']);
			return;
		}
		if ($tipe === 'Diskon' && ((int) $value < 0 || (int) $value > 100)) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_value untuk tipe Diskon harus antara 0-100']);
			return;
		}
		if ($tipe === 'Fix' && (int) $value < 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_value untuk tipe Fix tidak boleh negatif']);
			return;
		}
		// promo tipe Fix: jam yang didapat wajib diisi - dipakai untuk auto-isi & mengunci durasi timer
		// saat open billing dengan promo ini dipilih (lihat Billing::book_table())
		if ($tipe === 'Fix' && ((int) $hour) <= 0) {
			echo json_encode(['code' => 0, 'result' => 'hour wajib diisi (lebih dari 0) untuk tipe Fix']);
			return;
		}
		if ($this->master_model->is_promo_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama promo sudah digunakan']);
			return;
		}

		$schedule = $this->_parse_promo_schedule($body);
		if ($schedule['error'] !== null) {
			echo json_encode(['code' => 0, 'result' => $schedule['error']]);
			return;
		}

		$promo_id = $this->master_model->add_promo(
			$name, $tipe, (int) $value, $tipe === 'Fix' ? (int) $hour : null,
			$schedule['valid_days'], $schedule['valid_time_start'], $schedule['valid_time_end'],
			$tipe === 'Fix' ? $free_hour : null,
			$category_ids
		);
		if ($promo_id) {
			echo json_encode(['code' => 200, 'result' => 'Promo berhasil ditambahkan', 'promo_id' => $promo_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan promo']);
		}
	}

	public function edit_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$promo_id = isset($body['ms_promo_id']) ? (int) $body['ms_promo_id'] : 0;
		if ($promo_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_id wajib diisi']);
			return;
		}

		if (isset($body['ms_promo_tipe']) && !in_array($body['ms_promo_tipe'], array('Diskon', 'Fix'))) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_tipe harus Diskon atau Fix']);
			return;
		}

		$data = array();
		if (!empty($body['ms_promo_name'])) $data['ms_promo_name'] = $this->_normalize_name($body['ms_promo_name']);
		if (!empty($body['ms_promo_tipe'])) $data['ms_promo_tipe'] = $body['ms_promo_tipe'];
		if (isset($body['ms_promo_value'])) $data['ms_promo_value'] = (int) $body['ms_promo_value'];
		if (isset($body['hour'])) $data['hour'] = $body['hour'] !== '' ? (int) $body['hour'] : null;
		if (isset($body['free_hour'])) $data['free_hour'] = $body['free_hour'] !== '' ? (int) $body['free_hour'] : null;

		// category_ids: full replace (sama pola dengan valid_days). Dikirim = ganti daftar kategori,
		// tidak dikirim = biarkan apa adanya. Array kosong = hapus batasan (berlaku semua kategori).
		$category_ids = (isset($body['category_ids']) && is_array($body['category_ids'])) ? $body['category_ids'] : null;

		// valid_days/valid_time_start/valid_time_end selalu dikirim bersamaan dari form (full replace),
		// sama seperti pola 'hour' di atas - kosong berarti hapus batasan tersebut
		if (isset($body['valid_days']) || isset($body['valid_time_start']) || isset($body['valid_time_end'])) {
			$schedule = $this->_parse_promo_schedule($body);
			if ($schedule['error'] !== null) {
				echo json_encode(['code' => 0, 'result' => $schedule['error']]);
				return;
			}
			$data['valid_days'] = $schedule['valid_days'];
			$data['valid_time_start'] = $schedule['valid_time_start'];
			$data['valid_time_end'] = $schedule['valid_time_end'];
		}

		if (empty($data) && $category_ids === null) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$existing_promo = null;
		if (isset($data['ms_promo_value']) || array_key_exists('hour', $data) || isset($data['ms_promo_tipe'])) {
			$effective_tipe = isset($data['ms_promo_tipe']) ? $data['ms_promo_tipe'] : null;
			if ($effective_tipe === null) {
				$existing_promo = $this->master_model->get_promo_by_id($promo_id);
				$effective_tipe = $existing_promo ? $existing_promo->ms_promo_tipe : null;
			}
			if (isset($data['ms_promo_value'])) {
				if ($effective_tipe === 'Diskon' && ($data['ms_promo_value'] < 0 || $data['ms_promo_value'] > 100)) {
					echo json_encode(['code' => 0, 'result' => 'ms_promo_value untuk tipe Diskon harus antara 0-100']);
					return;
				}
				if ($effective_tipe === 'Fix' && $data['ms_promo_value'] < 0) {
					echo json_encode(['code' => 0, 'result' => 'ms_promo_value untuk tipe Fix tidak boleh negatif']);
					return;
				}
			}
			// promo tipe Fix: jam yang didapat wajib diisi (baik dari payload ini, atau yang sudah
			// tersimpan sebelumnya) - dipakai untuk auto-isi & mengunci durasi timer saat open billing
			if ($effective_tipe === 'Fix') {
				$effective_hour = array_key_exists('hour', $data) ? $data['hour'] : null;
				if ($effective_hour === null) {
					if ($existing_promo === null) $existing_promo = $this->master_model->get_promo_by_id($promo_id);
					$effective_hour = $existing_promo ? $existing_promo->hour : null;
				}
				if (empty($effective_hour) || $effective_hour <= 0) {
					echo json_encode(['code' => 0, 'result' => 'hour wajib diisi (lebih dari 0) untuk tipe Fix']);
					return;
				}
			}
		}
		if (isset($data['ms_promo_name']) && $this->master_model->is_promo_name_exists($data['ms_promo_name'], $promo_id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama promo sudah digunakan']);
			return;
		}

		$success = $this->master_model->edit_promo($promo_id, $data, $category_ids);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Promo berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah promo']);
		}
	}

	public function delete_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$promo_id = isset($body['ms_promo_id']) ? (int) $body['ms_promo_id'] : 0;
		if ($promo_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_promo_id wajib diisi']);
			return;
		}

		if ($this->master_model->is_promo_in_use($promo_id)) {
			echo json_encode(['code' => 0, 'result' => 'Promo masih digunakan di meja aktif, tidak bisa dihapus']);
			return;
		}

		$success = $this->master_model->delete_promo($promo_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Promo berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus promo']);
		}
	}

	/**
	 * End Master Promo
	 */

	/**
	 * Master Promo Cafe - promo khusus transaksi cafe/POS. Beda dari ms_promo (billing):
	 * punya HARGA PROMO tetap + daftar produk yang dicakup. Saat dipakai di POS, subtotal
	 * gabungan produk-produk itu di keranjang di-reprice jadi ms_cafe_promo_price.
	 */

	public function cafe_promo_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		echo json_encode($this->master_model->get_cafe_promo_list($page, $per_page));
	}

	public function cafe_promo_list_no_pagging()
	{
		echo json_encode(['code' => 200, 'result' => $this->master_model->get_cafe_promo_list_no_pagging()]);
	}

	public function add_cafe_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['name']) ? $this->_normalize_name($body['name']) : '';
		$price = isset($body['price']) ? (int) $body['price'] : -1;
		$product_ids = isset($body['product_ids']) && is_array($body['product_ids']) ? $body['product_ids'] : array();

		if ($name === '') {
			echo json_encode(['code' => 0, 'result' => 'Nama promo wajib diisi']);
			return;
		}
		if ($price < 0) {
			echo json_encode(['code' => 0, 'result' => 'Harga promo wajib diisi (tidak boleh negatif)']);
			return;
		}
		$product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids), function ($v) { return $v > 0; })));
		if (empty($product_ids)) {
			echo json_encode(['code' => 0, 'result' => 'Pilih minimal 1 produk untuk promo ini']);
			return;
		}
		if ($this->master_model->is_cafe_promo_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama promo cafe sudah digunakan']);
			return;
		}

		$id = $this->master_model->add_cafe_promo($name, $price, $product_ids);
		echo json_encode($id
			? ['code' => 200, 'result' => 'Promo cafe berhasil ditambahkan', 'ms_cafe_promo_id' => $id]
			: ['code' => 0, 'result' => 'Gagal menambahkan promo cafe']);
	}

	public function edit_cafe_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = isset($body['ms_cafe_promo_id']) ? (int) $body['ms_cafe_promo_id'] : 0;
		if ($id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_cafe_promo_id wajib diisi']);
			return;
		}

		$active = isset($body['active']) ? $body['active'] : null;
		if (!empty($active) && !in_array($active, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'active harus Y atau N']);
			return;
		}

		$data = array();
		if (isset($body['name']) && $this->_normalize_name($body['name']) !== '') $data['ms_cafe_promo_name'] = $this->_normalize_name($body['name']);
		if (isset($body['price']) && (int) $body['price'] >= 0) $data['ms_cafe_promo_price'] = (int) $body['price'];
		if (!empty($active)) $data['ms_cafe_promo_active'] = $active;

		$product_ids = null;
		if (isset($body['product_ids']) && is_array($body['product_ids'])) {
			$product_ids = array_values(array_unique(array_filter(array_map('intval', $body['product_ids']), function ($v) { return $v > 0; })));
			if (empty($product_ids)) {
				echo json_encode(['code' => 0, 'result' => 'Pilih minimal 1 produk untuk promo ini']);
				return;
			}
		}

		if (empty($data) && $product_ids === null) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['ms_cafe_promo_name']) && $this->master_model->is_cafe_promo_name_exists($data['ms_cafe_promo_name'], $id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama promo cafe sudah digunakan']);
			return;
		}

		$ok = $this->master_model->edit_cafe_promo($id, $data, $product_ids);
		echo json_encode($ok
			? ['code' => 200, 'result' => 'Promo cafe berhasil diubah']
			: ['code' => 0, 'result' => 'Gagal mengubah promo cafe']);
	}

	public function delete_cafe_promo()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = isset($body['ms_cafe_promo_id']) ? (int) $body['ms_cafe_promo_id'] : 0;
		if ($id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'ms_cafe_promo_id wajib diisi']);
			return;
		}
		$ok = $this->master_model->delete_cafe_promo($id);
		echo json_encode($ok
			? ['code' => 200, 'result' => 'Promo cafe berhasil dihapus']
			: ['code' => 0, 'result' => 'Gagal menghapus promo cafe']);
	}

	/**
	 * End Master Promo Cafe
	 */

	/**
	 * Master Category
	 */

	public function category_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->master_model->get_category_list($page, $per_page);
		echo json_encode($data);
	}

	public function category_list_no_pagging()
	{
		$data = $this->master_model->get_category_list_no_pagging();
		echo json_encode($data);
	}

	public function add_category()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['category_name']) ? $this->_normalize_name($body['category_name']) : '';

		if ($name === '') {
			echo json_encode(['code' => 0, 'result' => 'category_name wajib diisi']);
			return;
		}
		if ($this->master_model->is_category_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama category sudah digunakan']);
			return;
		}

		$category_id = $this->master_model->add_category($name);
		if ($category_id) {
			echo json_encode(['code' => 200, 'result' => 'Category berhasil ditambahkan', 'category_id' => $category_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan category']);
		}
	}

	public function edit_category()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$category_id = isset($body['category_id']) ? (int) $body['category_id'] : 0;
		if ($category_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'category_id wajib diisi']);
			return;
		}

		$data = array();
		if (!empty($body['category_name'])) $data['category_name'] = $this->_normalize_name($body['category_name']);

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['category_name']) && $this->master_model->is_category_name_exists($data['category_name'], $category_id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama category sudah digunakan']);
			return;
		}

		$success = $this->master_model->edit_category($category_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Category berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah category']);
		}
	}

	public function delete_category()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$category_id = isset($body['category_id']) ? (int) $body['category_id'] : 0;
		if ($category_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'category_id wajib diisi']);
			return;
		}

		if ($this->master_model->is_category_in_use($category_id)) {
			echo json_encode(['code' => 0, 'result' => 'Category masih digunakan di product aktif, tidak bisa dihapus']);
			return;
		}

		$success = $this->master_model->delete_category($category_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Category berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus category']);
		}
	}

	/**
	 * End Master Category
	 */

	/**
	 * Master Unit
	 */

	public function unit_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->master_model->get_unit_list($page, $per_page);
		echo json_encode($data);
	}

	public function unit_list_no_pagging()
	{
		$data = $this->master_model->get_unit_list_no_pagging();
		echo json_encode($data);
	}

	public function add_unit()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$name = isset($body['unit_name']) ? $this->_normalize_name($body['unit_name']) : '';

		if ($name === '') {
			echo json_encode(['code' => 0, 'result' => 'unit_name wajib diisi']);
			return;
		}
		if ($this->master_model->is_unit_name_exists($name)) {
			echo json_encode(['code' => 0, 'result' => 'Nama unit sudah digunakan']);
			return;
		}

		$unit_id = $this->master_model->add_unit($name);
		if ($unit_id) {
			echo json_encode(['code' => 200, 'result' => 'Unit berhasil ditambahkan', 'unit_id' => $unit_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan unit']);
		}
	}

	public function edit_unit()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$unit_id = isset($body['unit_id']) ? (int) $body['unit_id'] : 0;
		if ($unit_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'unit_id wajib diisi']);
			return;
		}

		$data = array();
		if (!empty($body['unit_name'])) $data['unit_name'] = $this->_normalize_name($body['unit_name']);

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}
		if (isset($data['unit_name']) && $this->master_model->is_unit_name_exists($data['unit_name'], $unit_id)) {
			echo json_encode(['code' => 0, 'result' => 'Nama unit sudah digunakan']);
			return;
		}

		$success = $this->master_model->edit_unit($unit_id, $data);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Unit berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah unit']);
		}
	}

	public function delete_unit()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$unit_id = isset($body['unit_id']) ? (int) $body['unit_id'] : 0;
		if ($unit_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'unit_id wajib diisi']);
			return;
		}

		if ($this->master_model->is_unit_in_use($unit_id)) {
			echo json_encode(['code' => 0, 'result' => 'Unit masih digunakan di product aktif, tidak bisa dihapus']);
			return;
		}

		$success = $this->master_model->delete_unit($unit_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Unit berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus unit']);
		}
	}

	/**
	 * End Master Unit
	 */

	/**
	 * Master Product
	 */

	public function product_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;
		$data = $this->master_model->get_product_list($page, $per_page);
		echo json_encode($data);
	}

	/**
	 * add_product & edit_product menerima multipart/form-data (bukan JSON) karena upload file gambar
	 * membutuhkan encoding multipart. Field product_image bersifat opsional, dikirim sebagai file.
	 */
	private function _upload_product_image()
	{
		if (empty($_FILES['product_image']) || $_FILES['product_image']['error'] === UPLOAD_ERR_NO_FILE) {
			return array('uploaded' => false);
		}

		$file = $_FILES['product_image'];
		if ($file['error'] !== UPLOAD_ERR_OK) {
			return array('uploaded' => false, 'error' => 'Upload gambar gagal');
		}

		$allowed_ext = array('jpg', 'jpeg', 'png', 'gif');
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, $allowed_ext)) {
			return array('uploaded' => false, 'error' => 'Format gambar harus jpg, jpeg, png, atau gif');
		}

		$max_size = 2 * 1024 * 1024; // 2MB
		if ($file['size'] > $max_size) {
			return array('uploaded' => false, 'error' => 'Ukuran gambar maksimal 2MB');
		}

		$upload_dir = FCPATH . 'uploads/products/';
		if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

		$filename = 'product_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
		if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
			return array('uploaded' => false, 'error' => 'Gagal menyimpan gambar');
		}

		return array('uploaded' => true, 'filename' => $filename);
	}

	public function add_product()
	{
		if ($this->input->method() !== 'post') {
			http_response_code(405);
			echo json_encode(['code' => 405, 'result' => 'Method Not Allowed, gunakan POST']);
			return;
		}

		$category_id = (int) $this->input->post('category_id');
		$unit_id = (int) $this->input->post('unit_id');
		$name = trim((string) $this->input->post('product_name'));
		$price = $this->input->post('product_price');
		$cogs = (int) $this->input->post('product_cogs');
		$stock = (int) $this->input->post('product_stock');
		$reduce_stock = $this->input->post('reduce_stock') ? $this->input->post('reduce_stock') : 'Y';

		if ($category_id <= 0 || $unit_id <= 0 || $name === '' || $price === '' || $price === null) {
			echo json_encode(['code' => 0, 'result' => 'category_id, unit_id, product_name, product_price wajib diisi']);
			return;
		}
		if (!in_array($reduce_stock, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'reduce_stock harus Y atau N']);
			return;
		}
		if ((int) $price < 0 || $cogs < 0 || $stock < 0) {
			echo json_encode(['code' => 0, 'result' => 'product_price, product_cogs, product_stock tidak boleh negatif']);
			return;
		}
		if (!$this->master_model->get_category_by_id($category_id)) {
			echo json_encode(['code' => 0, 'result' => 'Category tidak ditemukan']);
			return;
		}
		if (!$this->master_model->get_unit_by_id($unit_id)) {
			echo json_encode(['code' => 0, 'result' => 'Unit tidak ditemukan']);
			return;
		}

		$upload = $this->_upload_product_image();
		if (!empty($upload['error'])) {
			echo json_encode(['code' => 0, 'result' => $upload['error']]);
			return;
		}
		$image = $upload['uploaded'] ? $upload['filename'] : 'default.jpg';

		$product_id = $this->master_model->add_product($category_id, $unit_id, $name, (int) $price, $cogs, $image, $stock, $reduce_stock);
		if ($product_id) {
			echo json_encode(['code' => 200, 'result' => 'Product berhasil ditambahkan', 'product_id' => $product_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan product']);
		}
	}

	public function edit_product()
	{
		if ($this->input->method() !== 'post') {
			http_response_code(405);
			echo json_encode(['code' => 405, 'result' => 'Method Not Allowed, gunakan POST']);
			return;
		}

		$product_id = (int) $this->input->post('product_id');
		if ($product_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'product_id wajib diisi']);
			return;
		}

		if (!$this->master_model->get_product_by_id($product_id)) {
			echo json_encode(['code' => 0, 'result' => 'Product tidak ditemukan']);
			return;
		}

		$reduce_stock = $this->input->post('reduce_stock');
		if (!empty($reduce_stock) && !in_array($reduce_stock, array('Y', 'N'))) {
			echo json_encode(['code' => 0, 'result' => 'reduce_stock harus Y atau N']);
			return;
		}

		$upload = $this->_upload_product_image();
		if (!empty($upload['error'])) {
			echo json_encode(['code' => 0, 'result' => $upload['error']]);
			return;
		}

		$data = array();
		if ($this->input->post('category_id')) $data['category_id'] = (int) $this->input->post('category_id');
		if ($this->input->post('unit_id')) $data['unit_id'] = (int) $this->input->post('unit_id');
		if ($this->input->post('product_name')) $data['product_name'] = trim($this->input->post('product_name'));
		if ($this->input->post('product_cogs') !== null && $this->input->post('product_cogs') !== '') $data['product_cogs'] = (int) $this->input->post('product_cogs');
		if ($this->input->post('product_price') !== null && $this->input->post('product_price') !== '') $data['product_price'] = (int) $this->input->post('product_price');
		if ($this->input->post('product_stock') !== null && $this->input->post('product_stock') !== '') $data['product_stock'] = (int) $this->input->post('product_stock');
		if (!empty($reduce_stock)) $data['reduce_stock'] = $reduce_stock;

		if (isset($data['product_price']) && $data['product_price'] < 0) {
			echo json_encode(['code' => 0, 'result' => 'product_price tidak boleh negatif']);
			return;
		}
		if (isset($data['product_cogs']) && $data['product_cogs'] < 0) {
			echo json_encode(['code' => 0, 'result' => 'product_cogs tidak boleh negatif']);
			return;
		}
		if (isset($data['product_stock']) && $data['product_stock'] < 0) {
			echo json_encode(['code' => 0, 'result' => 'product_stock tidak boleh negatif']);
			return;
		}
		if (isset($data['category_id']) && !$this->master_model->get_category_by_id($data['category_id'])) {
			echo json_encode(['code' => 0, 'result' => 'Category tidak ditemukan']);
			return;
		}
		if (isset($data['unit_id']) && !$this->master_model->get_unit_by_id($data['unit_id'])) {
			echo json_encode(['code' => 0, 'result' => 'Unit tidak ditemukan']);
			return;
		}

		$old_image = null;
		if ($upload['uploaded']) {
			$data['product_image'] = $upload['filename'];
			$product = $this->master_model->get_product_by_id($product_id);
			$old_image = $product ? $product->product_image : null;
		}

		if (empty($data)) {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
			return;
		}

		$success = $this->master_model->edit_product($product_id, $data);
		if ($success) {
			if ($old_image && $old_image !== 'default.jpg') {
				$old_path = FCPATH . 'uploads/products/' . $old_image;
				if (file_exists($old_path)) unlink($old_path);
			}
			echo json_encode(['code' => 200, 'result' => 'Product berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah product']);
		}
	}

	public function delete_product()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$product_id = isset($body['product_id']) ? (int) $body['product_id'] : 0;
		if ($product_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'product_id wajib diisi']);
			return;
		}

		$success = $this->master_model->delete_product($product_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Product berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menghapus product']);
		}
	}

	// push SNAPSHOT PENUH katalog produk cabang (stok & HPP/COGS terkini) ke laporan online
	// gameon. Fire & forget: gagal cuma dicatat di log + ditandai dirty di product_stock_sync
	// supaya bisa diulang dari halaman Sinkron Online. Dipanggil tiap 30 menit oleh
	// ProductStockSyncWatcher (billinggameon) - branch dikirim client (SessionStorage) karena
	// tidak ada user yang sedang login melakukan aksi seperti pada aksi transaksi lainnya.
	public function sync_product_stock()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$branch = isset($body['branch']) ? (int) $body['branch'] : 0;
		if ($branch <= 0) {
			echo json_encode(['code' => 0, 'result' => 'branch wajib diisi']);
			return;
		}

		$payload = $this->master_model->get_product_stock_snapshot_for_sync($branch);
		$push = $this->gameon->push_product_stock($payload);
		$this->master_model->mark_product_stock_synced(
			$branch,
			$push['success'],
			$payload['snapshot_at'],
			$push['success'] ? null : $push['error']
		);

		if ($push['success']) {
			echo json_encode(['code' => 200, 'result' => 'Stok produk tersinkron (' . count($payload['products']) . ' produk)']);
		} else {
			log_message('error', 'Gagal push stok produk ke gameon (branch=' . $branch . '): ' . $push['error']);
			echo json_encode(['code' => 0, 'result' => 'Gagal sinkron stok produk: ' . $push['error']]);
		}
	}

	/**
	 * End Master Product
	 */

	/**
	 * Customer Time (waktu yang disimpan customer saat billing timer belum selesai, per category meja)
	 */

	// timer/waktu tersimpan milik 1 customer beserta nama keterangan mejanya (category meja). customer_id
	// wajib diisi. Saldo waktu asli sekarang cuma valid di gameon (source kebenaran) - di sini proxy ke
	// gameon lalu dilengkapi nama customer/category dari data lokal.
	public function get_time_per_customer()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
		if ($customer_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
			return;
		}

		$time_result = $this->gameon->get_customer_time($customer_id);
		if (!$time_result['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengambil waktu tersimpan dari gameon: ' . $time_result['error']]);
			return;
		}

		$customer = $this->master_model->get_customer_by_id($customer_id);
		$rows = is_array($time_result['result']) ? $time_result['result'] : array();
		$data = array();
		foreach ($rows as $row) {
			$category = $this->master_model->get_category_meja_by_id($row['category_meja_id']);
			$data[] = array(
				'id' => $row['id'],
				'customer_id' => $row['customer_id'],
				'customer_name' => $customer ? $customer->customer_name : null,
				'category_meja_id' => $row['category_meja_id'],
				'category_meja_name' => $category ? $category->category_meja_name : null,
				'time_remaining' => $row['time_remaining'],
				'updated_at' => $row['updated_at'],
			);
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// sisa waktu tersimpan customer untuk category meja dari meja yang dipilih, beserta nama customernya.
	// category_meja_id di-resolve dari table_id secara lokal, baru ditanyakan ke gameon (source kebenaran)
	public function get_save_customer_time()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
		$table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;

		if ($customer_id <= 0 || $table_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'customer_id dan table_id wajib diisi']);
			return;
		}

		$category_meja_id = $this->master_model->get_table_category_meja_id($table_id);
		if ($category_meja_id === null) {
			echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan atau category meja belum di set']);
			return;
		}

		$time_result = $this->gameon->get_customer_time($customer_id, $category_meja_id);
		if (!$time_result['success']) {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengambil waktu tersimpan dari gameon: ' . $time_result['error']]);
			return;
		}

		$customer = $this->master_model->get_customer_by_id($customer_id);
		$category = $this->master_model->get_category_meja_by_id($category_meja_id);
		$rows = is_array($time_result['result']) ? $time_result['result'] : array();
		$time_remaining = !empty($rows[0]['time_remaining']) ? $rows[0]['time_remaining'] : '00:00:00';

		echo json_encode(['code' => 200, 'result' => array(
			'customer_id' => $customer_id,
			'customer_name' => $customer ? $customer->customer_name : null,
			'table_id' => $table_id,
			'category_meja_id' => $category_meja_id,
			'category_meja_name' => $category ? $category->category_meja_name : null,
			'time_remaining' => $time_remaining,
		)]);
	}

	// setting global apakah fitur simpan waktu billing timer aktif (dibaca dari save_time_setting), dipakai FE untuk tampil/sembunyi fitur
	public function check_time_save()
	{
		$data = $this->master_model->check_time_save();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	/**
	 * End Customer Time
	 */

}

