<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Cafe extends CI_Controller {

    public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('cafe_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}

	// kirim salinan 1 transaksi cafe (header + item + addon) ke gameon buat laporan terpusat.
	// Fire & forget: kalau gameon tidak terjangkau / menolak, cuma dicatat di log - transaksi
	// lokal tetap sah. Dipanggil setelah simpan, batal, dan edit metode bayar transaksi cafe.
	private function _sync_transaction_cafe_to_gameon($transaction_cafe_id)
	{
		if (empty($transaction_cafe_id)) return;
		$payload = $this->cafe_model->get_transaction_cafe_for_sync($transaction_cafe_id);
		if (!$payload) return;
		$push = $this->gameon->push_transaction_cafe($payload);
		if ($push['success']) {
			$this->cafe_model->mark_transaction_cafe_uploaded($transaction_cafe_id);
		} else {
			log_message('error', 'Gagal push transaksi cafe ke gameon (id=' . $transaction_cafe_id . '): ' . $push['error']);
		}
	}

	public function transaction_cafe_list()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$page = !empty($body['page']) ? (int) $body['page'] : 1;
		$per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

		$data = $this->cafe_model->get_transaction_cafe_list($page, $per_page);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function save_transaction_cafe()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$customer_id = !empty($body['customer_id']) ? (int) $body['customer_id'] : 0;
		$customer_name = isset($body['customer_name']) ? trim($body['customer_name']) : '';
		$promo_id = !empty($body['promo_id']) ? (int) $body['promo_id'] : 0;
		// keterangan promo (free text) - WAJIB diisi kalau ada promo cafe dipilih
		$promo_note = isset($body['promo_note']) ? trim((string) $body['promo_note']) : '';
		$payment_id = !empty($body['payment_id']) ? (int) $body['payment_id'] : 0;
		$table = !empty($body['table']) ? (int) $body['table'] : 0;
		$tax = isset($body['tax']) ? (int) $body['tax'] : 0;
		$discount_percent = isset($body['discount_percent']) ? (float) $body['discount_percent'] : 0;
		$created_by = isset($body['created_by']) ? $body['created_by'] : '';
		$paid_by = isset($body['paid_by']) ? (int) $body['paid_by'] : 0;
		$items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();

		if ($created_by === '' || $paid_by <= 0) {
			echo json_encode(['code' => 0, 'result' => 'created_by dan paid_by wajib diisi']);
			return;
		}
		if (empty($items)) {
			echo json_encode(['code' => 0, 'result' => 'items wajib diisi']);
			return;
		}
		if ($tax < 0) {
			echo json_encode(['code' => 0, 'result' => 'tax tidak boleh negatif']);
			return;
		}
		if ($discount_percent < 0 || $discount_percent > 100) {
			echo json_encode(['code' => 0, 'result' => 'discount_percent harus di antara 0 dan 100']);
			return;
		}
		if ($promo_id > 0 && $promo_note === '') {
			echo json_encode(['code' => 0, 'result' => 'Keterangan promo wajib diisi']);
			return;
		}
		foreach ($items as $item) {
			if (empty($item['product_id'])) {
				echo json_encode(['code' => 0, 'result' => 'Setiap item wajib memiliki product_id dan qty']);
				return;
			}
			// qty wajib bilangan bulat positif - empty() tidak menangkap angka negatif atau string
			// bukan-angka seperti "abc" (yang lolos jadi 0 saat di-cast), jadi divalidasi eksplisit di sini
			if (!isset($item['qty']) || !is_numeric($item['qty']) || (int) $item['qty'] <= 0) {
				echo json_encode(['code' => 0, 'result' => 'qty setiap item harus berupa angka lebih dari 0']);
				return;
			}
			// additional item (mis. Telur ditambahkan ke Indomie) - opsional, tapi kalau dikirim
			// tiap barisnya wajib product_id + qty valid, sama seperti item utama
			if (!empty($item['addons'])) {
				if (!is_array($item['addons'])) {
					echo json_encode(['code' => 0, 'result' => 'addons harus berupa array']);
					return;
				}
				foreach ($item['addons'] as $addon) {
					if (empty($addon['product_id'])) {
						echo json_encode(['code' => 0, 'result' => 'Setiap additional item wajib memiliki product_id dan qty']);
						return;
					}
					if (!isset($addon['qty']) || !is_numeric($addon['qty']) || (int) $addon['qty'] <= 0) {
						echo json_encode(['code' => 0, 'result' => 'qty setiap additional item harus berupa angka lebih dari 0']);
						return;
					}
				}
			}
		}

		$branch = $this->_resolve_branch($paid_by, $created_by);
		$member_approval_ref = isset($body['member_approval_ref']) ? trim((string) $body['member_approval_ref']) : '';

		// payment_id yang payment_name-nya "Potong Saldo" otomatis memotong saldo customer, ditentukan di model (perlu total_bill dulu)
		$result = $this->cafe_model->save_transaction_cafe(array(
			'customer_id' => $customer_id,
			'customer_name' => $customer_name,
			'promo_id' => $promo_id,
			'promo_note' => $promo_note,
			'payment_id' => $payment_id,
			'table' => $table,
			'tax' => $tax,
			'discount_percent' => $discount_percent,
			'created_by' => $created_by,
			'paid_by' => $paid_by,
			'items' => $items,
			'branch' => $branch,
			'member_approval_ref' => $member_approval_ref,
		));

		// pembayaran Potong Saldo pakai member -> tunggu konfirmasi PIN member
		if (is_array($result) && isset($result['need_approval'])) {
			echo json_encode(['code' => 200, 'result' => 'NEED_MEMBER_APPROVAL', 'approval' => $result['need_approval']]);
			return;
		}
		if ($result === 'APPROVAL_REF_REQUIRED') {
			echo json_encode(['code' => 0, 'result' => 'member_approval_ref wajib untuk pembayaran Potong Saldo dengan member']);
			return;
		}
		if (is_string($result) && strpos($result, 'APPROVAL_FAILED:') === 0) {
			echo json_encode(['code' => 0, 'result' => substr($result, strlen('APPROVAL_FAILED:'))]);
			return;
		}

		if (is_string($result) && strpos($result, 'INSUFFICIENT_STOCK:') === 0) {
			$product_name = substr($result, strlen('INSUFFICIENT_STOCK:'));
			echo json_encode(['code' => 0, 'result' => 'Stok produk "' . $product_name . '" tidak mencukupi']);
		} else if ($result === 'NO_VALID_ITEMS') {
			echo json_encode(['code' => 0, 'result' => 'Tidak ada produk valid di keranjang']);
		} else if ($result === 'INSUFFICIENT') {
			echo json_encode(['code' => 0, 'result' => 'Saldo customer tidak mencukupi']);
		} else if (is_string($result) && strpos($result, 'SALDO_FAILED:') === 0) {
			echo json_encode(['code' => 0, 'result' => 'Gagal memotong saldo di gameon: ' . substr($result, strlen('SALDO_FAILED:'))]);
		} else if ($result === 'CUSTOMER_NOT_FOUND') {
			echo json_encode(['code' => 0, 'result' => 'Customer tidak ditemukan']);
		} else if ($result === 'CUSTOMER_REQUIRED') {
			echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi untuk memotong saldo (use_saldo)']);
		} else if ($result) {
			$transaction_cafe_id = $result['transaction_cafe_id'];
			$this->_sync_transaction_cafe_to_gameon($transaction_cafe_id);
			echo json_encode(['code' => 200, 'result' => 'Transaksi cafe berhasil disimpan', 'transaction_cafe_id' => $transaction_cafe_id]);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan transaksi cafe']);
		}
	}

	public function keep_transaction()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$keep_transaction_id_input = !empty($body['keep_transaction_id']) ? (int) $body['keep_transaction_id'] : 0;
		$name = isset($body['name']) ? trim($body['name']) : '';
		$customer_id = !empty($body['customer_id']) ? (int) $body['customer_id'] : 0;
		$promo_id = !empty($body['promo_id']) ? (int) $body['promo_id'] : 0;
		$payment_id = !empty($body['payment_id']) ? (int) $body['payment_id'] : 0;
		$table = !empty($body['table']) ? (int) $body['table'] : 0;
		$tax = isset($body['tax']) ? (int) $body['tax'] : 0;
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
		foreach ($items as $item) {
			if (empty($item['product_id'])) {
				echo json_encode(['code' => 0, 'result' => 'Setiap item wajib memiliki product_id dan qty']);
				return;
			}
			// qty boleh 0 di sini (beda dari save_transaction_cafe) - dipakai FE untuk mengirim ulang
			// item yang sudah ada di keep ini supaya catatan/additional item-nya ter-update, tanpa
			// menambah qty-nya (lihat Cafe_model::keep_transaction, qty di sini adalah TAMBAHAN,
			// bukan qty absolut). Negatif tetap ditolak - empty() tidak menangkap itu maupun string
			// bukan-angka seperti "abc" (yang lolos jadi 0 saat di-cast), jadi divalidasi eksplisit
			if (!isset($item['qty']) || !is_numeric($item['qty']) || (int) $item['qty'] < 0) {
				echo json_encode(['code' => 0, 'result' => 'qty setiap item harus berupa angka 0 atau lebih']);
				return;
			}
			if (!empty($item['addons'])) {
				if (!is_array($item['addons'])) {
					echo json_encode(['code' => 0, 'result' => 'addons harus berupa array']);
					return;
				}
				foreach ($item['addons'] as $addon) {
					if (empty($addon['product_id'])) {
						echo json_encode(['code' => 0, 'result' => 'Setiap additional item wajib memiliki product_id dan qty']);
						return;
					}
					if (!isset($addon['qty']) || !is_numeric($addon['qty']) || (int) $addon['qty'] <= 0) {
						echo json_encode(['code' => 0, 'result' => 'qty setiap additional item harus berupa angka lebih dari 0']);
						return;
					}
				}
			}
		}

		$keep_transaction = $this->cafe_model->keep_transaction(array(
			'keep_transaction_id' => $keep_transaction_id_input,
			'name' => $name,
			'customer_id' => $customer_id,
			'promo_id' => $promo_id,
			'payment_id' => $payment_id,
			'table' => $table,
			'tax' => $tax,
			'created_by' => $created_by,
			'items' => $items,
		));

		if ($keep_transaction) {
			echo json_encode([
				'code' => 200,
				'result' => 'Transaksi berhasil disimpan sementara',
				'keep_transaction_id' => $keep_transaction['id'],
				'keep_transaction_inv' => $keep_transaction['inv'],
				'keep_transaction_name' => $keep_transaction['name'],
			]);
		} else if ($keep_transaction_id_input) {
			echo json_encode(['code' => 0, 'result' => 'keep_transaction_id tidak ditemukan atau sudah tidak berstatus Keep']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan transaksi sementara']);
		}
	}

	public function select_keep_transaction()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$keep_transaction_id = !empty($body['keep_transaction_id']) ? (int) $body['keep_transaction_id'] : null;

		$data = $this->cafe_model->select_keep_transaction($keep_transaction_id);

		if ($keep_transaction_id !== null && !$data) {
			echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function delete_keep_transaction()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$keep_transaction_id = !empty($body['keep_transaction_id']) ? (int) $body['keep_transaction_id'] : 0;
		if ($keep_transaction_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'keep_transaction_id wajib diisi']);
			return;
		}

		$success = $this->cafe_model->delete_keep_transaction($keep_transaction_id);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Transaksi tersimpan berhasil dihapus']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
		}
	}

	public function rename_keep_transaction()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$keep_transaction_id = !empty($body['keep_transaction_id']) ? (int) $body['keep_transaction_id'] : 0;
		$name = isset($body['name']) ? trim($body['name']) : '';

		if ($keep_transaction_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'keep_transaction_id wajib diisi']);
			return;
		}

		$success = $this->cafe_model->rename_keep_transaction($keep_transaction_id, $name);
		if ($success) {
			echo json_encode(['code' => 200, 'result' => 'Nama pesanan berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Pesanan tidak ditemukan atau sudah tidak berstatus Keep']);
		}
	}

	public function transaction_detail()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$transaction_cafe_id = isset($body['transaction_cafe_id']) ? (int) $body['transaction_cafe_id'] : 0;
		if ($transaction_cafe_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'transaction_cafe_id wajib diisi']);
			return;
		}

		$data = $this->cafe_model->get_transaction_cafe_detail($transaction_cafe_id);
		if (!$data) {
			echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	public function cancel_transaction_cafe()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$transaction_cafe_id = isset($body['transaction_cafe_id']) ? (int) $body['transaction_cafe_id'] : 0;
		$created_by = isset($body['created_by']) ? trim($body['created_by']) : '';
		if ($transaction_cafe_id <= 0 || $created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'transaction_cafe_id dan created_by wajib diisi']);
			return;
		}

		$result = $this->cafe_model->cancel_transaction_cafe($transaction_cafe_id, $created_by);
		if ($result === 'ALREADY_CANCELLED') {
			echo json_encode(['code' => 0, 'result' => 'Transaksi sudah dibatalkan sebelumnya']);
		} else if ($result) {
			$this->_sync_transaction_cafe_to_gameon($transaction_cafe_id);
			echo json_encode(['code' => 200, 'result' => 'Transaksi cafe berhasil dibatalkan, stok item dikembalikan']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan atau gagal dibatalkan']);
		}
	}

	// ubah metode pembayaran transaksi cafe yang sudah selesai - ditolak kalau metode lama ATAU baru
	// adalah "Potong Saldo" (lihat Cafe_model::edit_payment_transaction_cafe) supaya saldo customer
	// tidak jadi tidak sinkron (belum ada logika koreksi saldo untuk kasus edit setelah transaksi jadi)
	public function edit_payment_transaction_cafe()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$transaction_cafe_id = isset($body['transaction_cafe_id']) ? (int) $body['transaction_cafe_id'] : 0;
		$payment_id = isset($body['payment_id']) ? (int) $body['payment_id'] : 0;
		$created_by = isset($body['created_by']) ? trim($body['created_by']) : '';

		if ($transaction_cafe_id <= 0 || $payment_id <= 0 || $created_by === '') {
			echo json_encode(['code' => 0, 'result' => 'transaction_cafe_id, payment_id, dan created_by wajib diisi']);
			return;
		}

		$result = $this->cafe_model->edit_payment_transaction_cafe($transaction_cafe_id, $payment_id, $created_by);

		if ($result === 'NOT_FOUND') {
			echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
		} else if ($result === 'CANCELLED') {
			echo json_encode(['code' => 0, 'result' => 'Transaksi yang sudah dibatalkan tidak bisa diubah metode pembayarannya']);
		} else if ($result === 'PAYMENT_NOT_FOUND') {
			echo json_encode(['code' => 0, 'result' => 'Metode pembayaran tidak ditemukan']);
		} else if ($result === 'SALDO_NOT_ALLOWED') {
			echo json_encode(['code' => 0, 'result' => 'Metode pembayaran Potong Saldo tidak bisa diubah lewat sini - batalkan transaksi lalu buat ulang']);
		} else if ($result) {
			$this->_sync_transaction_cafe_to_gameon($transaction_cafe_id);
			echo json_encode(['code' => 200, 'result' => 'Metode pembayaran berhasil diubah']);
		} else {
			echo json_encode(['code' => 0, 'result' => 'Gagal mengubah metode pembayaran']);
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
}