<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Billing extends CI_Controller {

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->library('gameon');
		$this->load->model('billing_model');
		$this->load->model('master_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}

    public function get_table_list()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        // table_type opsional: 'billiard'/'mahjong' buat filter langsung di query (dipakai
        // halaman Billing & Mahjong yang masing-masing cuma butuh separuh daftar meja),
        // dikosongkan/nilai lain = semua meja (dipakai Sync & tempat lain yang butuh semuanya)
        $table_type = isset($body['table_type']) ? $body['table_type'] : null;
        if (!in_array($table_type, array('billiard', 'mahjong'), true)) $table_type = null;

        $data = $this->billing_model->get_table_list($table_type);
        echo json_encode($data);
    }

    public function book_table()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        $customer_id = isset($body['table_customer_id']) ? (int) $body['table_customer_id'] : 0;
        $promo_id = isset($body['table_promo_id']) ? (int) $body['table_promo_id'] : 0;
        $mode = isset($body['table_mode']) ? $body['table_mode'] : '';
        $start_time = isset($body['table_start_time']) ? $body['table_start_time'] : '';
        $created_by = isset($body['created_by']) ? $body['created_by'] : 'system';
        // use_saved_time = Y: table_duration dipotong dari saldo waktu tersimpan customer (customer_time) saat buka meja
        $used_save_time = isset($body['use_saved_time']) ? strtoupper($body['use_saved_time']) : 'N';
        // prepaid_saldo = Y: bayar di muka dari SALDO customer (harga durasi penuh, Timer saja) saat buka meja
        $prepaid_saldo = isset($body['prepaid_saldo']) ? strtoupper($body['prepaid_saldo']) : 'N';

        if ($table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'Nomor Meja Wajib Diisi']);
            return;
        }
        if ($mode === '') {
            echo json_encode(['code' => 0, 'result' => 'Mode Wajib Diisi']);
            return;
        }
        if ($start_time === '') {
            echo json_encode(['code' => 0, 'result' => 'Jam Mulai Wajib Diisi']);
            return;
        }
        if (!in_array($mode, array('Reguler', 'Timer'))) {
            echo json_encode(['code' => 0, 'result' => 'table_mode harus Reguler atau Timer']);
            return;
        }
        if (!in_array($used_save_time, array('Y', 'N'))) {
            echo json_encode(['code' => 0, 'result' => 'use_saved_time harus Y atau N']);
            return;
        }
        if (!in_array($prepaid_saldo, array('Y', 'N'))) {
            echo json_encode(['code' => 0, 'result' => 'prepaid_saldo harus Y atau N']);
            return;
        }
        if ($prepaid_saldo === 'Y' && $used_save_time === 'Y') {
            echo json_encode(['code' => 0, 'result' => 'Tidak bisa potong saldo di awal sekaligus pakai waktu tersimpan']);
            return;
        }

        // pemain mahjong (maks 4, harus member terdaftar) - id dikirim client, nama di-resolve
        // & divalidasi (dedup + tidak sedang aktif di meja lain) di sini, lihat
        // Billing_model::resolve_players()
        $player_ids_raw = isset($body['table_player_ids']) ? trim($body['table_player_ids']) : '';
        $players_result = $this->billing_model->resolve_players($player_ids_raw, $table_id);
        if (is_string($players_result)) {
            echo json_encode(['code' => 0, 'result' => $players_result]);
            return;
        }

        $data = array(
            'table_customer_id' => $customer_id,
            'table_players' => $players_result['names'],
            'table_player_ids' => $players_result['ids'],
            'table_promo_id' => $promo_id,
            'table_mode' => $mode,
            'table_start_time' => $start_time,
            'use_saved_time' => $used_save_time,
            // dikosongkan dulu; diisi 'Y' + amount + ref di bawah kalau prepaid_saldo=Y & potong sukses.
            // ditulis eksplisit supaya nilai sisa dari sesi meja sebelumnya (kalau clear_table terlewat) bersih.
            'prepaid_saldo' => 'N',
            'saldo_prepaid_amount' => 0,
            'saldo_prepaid_ref' => null,
            'table_active' => 1,
        );

        if ($mode === 'Timer') {
            if (empty($body['table_end_time'])) {
                echo json_encode(['code' => 0, 'result' => 'table_end_time wajib diisi untuk mode Timer']);
                return;
            }
            if (empty($body['table_duration']) || $body['table_duration'] === '00:00:00') {
                echo json_encode(['code' => 0, 'result' => 'Durasi Wajib Diisi']);
                return;
            }
            $data['table_end_time'] = $body['table_end_time'];
            $data['table_duration'] = $body['table_duration'];
        } else {
            $data['table_end_time'] = null;
            $data['table_duration'] = null;
        }

        $table_row = $this->billing_model->get_table_by_id($table_id);

        // --- PERINGATAN booking (bisa dilewati kasir dengan ignore_booking_warning=Y) ---
        // 1) data mirror booking lokal sudah basi (gameon lama tidak me-reply -> kemungkinan masalah jaringan)
        // 2) ada booking member untuk kategori meja ini yang jam-nya beririsan dengan sesi yang mau dibuka
        $ignore_booking_warning = isset($body['ignore_booking_warning']) && strtoupper($body['ignore_booking_warning']) === 'Y';
        if (!$ignore_booking_warning) {
            $booking_warnings = array();

            $sync = $this->master_model->get_booking_sync_state();
            $stale_after = 20; // detik - dgn polling 3 detik, > ~7x gagal berturut baru dianggap basi
            $secs = $sync['seconds_since_success'];
            if ($secs === null) {
                $booking_warnings[] = 'Data booking belum pernah tersinkron dari server pusat (gameon). Kemungkinan ada masalah jaringan - daftar booking bisa jadi tidak lengkap.';
            } elseif ($secs > $stale_after) {
                $booking_warnings[] = 'Data booking terakhir tersinkron ' . $secs . ' detik lalu (server pusat mungkin sedang tidak terhubung). Daftar booking bisa jadi tidak terbaru.';
            }

            if ($mode === 'Timer' && !empty($data['table_end_time']) && $table_row && !empty($table_row->table_category)) {
                $overlaps = $this->master_model->get_overlapping_bookings($table_row->table_category, $start_time, $data['table_end_time']);
                foreach ($overlaps as $o) {
                    $unit = $o['unit_no'] !== null ? (' unit ' . $o['unit_no'] . ($o['area'] ? ' (' . $o['area'] . ')' : '')) : '';
                    $booking_warnings[] = 'Kategori meja ini sudah ada booking member "' . ($o['customer_name'] ?: '-') . '"' . $unit
                        . ' pada ' . $o['booking_date'] . ' jam ' . substr((string) $o['booking_time'], 0, 5)
                        . ' selama ' . $o['duration_hours'] . ' jam - beririsan dengan sesi ini.';
                }
            }

            if (!empty($booking_warnings)) {
                // code 200 supaya tidak dianggap error oleh client; result penanda khusus
                echo json_encode(['code' => 200, 'result' => 'BOOKING_WARNING', 'warnings' => $booking_warnings]);
                return;
            }
        }

        // hari/jendela jam berlaku promo (lihat Billing_model::validate_promo_schedule) - dicek di sini
        // juga (bukan cuma nanti pas bayar) supaya penolakan langsung terlihat saat booking, dengan
        // rencana durasi yang sudah diketahui di muka untuk mode Timer
        if (!empty($promo_id)) {
            $promo = $this->master_model->get_promo_by_id($promo_id);
            if ($promo) {
                $schedule_error = $this->billing_model->validate_promo_schedule($promo, $start_time, $data['table_end_time'], $mode);
                if ($schedule_error !== null) {
                    echo json_encode(['code' => 0, 'result' => $schedule_error]);
                    return;
                }
                // promo tipe Fix = paket harga+durasi tetap (lihat Billing_model::is_fix_promo() &
                // calculate_price()) - hanya masuk akal untuk mode Timer (ada table_end_time yang
                // pasti). Mode Reguler tidak punya durasi tetap, jadi tidak boleh dipasangkan dengan
                // promo Fix (dulu lolos tanpa dicek sama sekali kalau promo Fix-nya tidak punya
                // jendela jam - lihat validate_promo_schedule() di atas yang hanya menolak promo
                // berjendela jam).
                if ($promo->ms_promo_tipe === 'Fix' && $mode !== 'Timer') {
                    echo json_encode(['code' => 0, 'result' => 'Promo Hanya Untuk Timer saja']);
                    return;
                }
            }
            // promo dibatasi ke kategori meja tertentu (ms_promo_category) - tolak kalau kategori meja
            // ini tidak termasuk, supaya promo tidak salah kepakai (lihat Master_model::promo_category_ok)
            $table_category = $table_row ? $table_row->table_category : null;
            if (!$this->master_model->promo_category_ok($promo_id, $table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Promo ini tidak berlaku untuk kategori meja ini']);
                return;
            }
        }

        // use_saved_time: potong table_duration (atau kurang, kalau promo Fix "beli X jam gratis Y jam"
        // dipakai - lihat Billing_model::apply_promo_free_hour()) dari saldo waktu tersimpan customer
        // (customer_time), hanya berlaku mode Timer
        $save_time_deduct_duration = $data['table_duration'];
        if ($used_save_time === 'Y') {
            if ($mode !== 'Timer') {
                echo json_encode(['code' => 0, 'result' => 'use_saved_time hanya berlaku untuk mode Timer']);
                return;
            }
            $setting = $this->master_model->check_time_save();
            if (empty($setting['check_time_save']) || $setting['check_time_save'] !== 'Y') {
                echo json_encode(['code' => 0, 'result' => 'Fitur simpan waktu sedang tidak aktif, tidak bisa memotong waktu customer']);
                return;
            }
            if ($customer_id <= 0) {
                echo json_encode(['code' => 0, 'result' => 'table_customer_id wajib diisi untuk memotong waktu (use_saved_time)']);
                return;
            }
            if (!$table_row || empty($table_row->table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Category meja tidak ditemukan, tidak bisa memotong waktu (use_saved_time)']);
                return;
            }

            // sisa waktu tersimpan divalidasi ke gameon (source kebenaran) - billing_api tidak lagi
            // menyimpan saldo waktu sendiri
            $time_check = $this->gameon->get_customer_time($customer_id, (int) $table_row->table_category);
            if (!$time_check['success']) {
                echo json_encode(['code' => 0, 'result' => 'Gagal memvalidasi waktu ke gameon: ' . $time_check['error']]);
                return;
            }
            $balance_seconds = !empty($time_check['result'][0]['time_remaining']) ? $this->_time_to_seconds($time_check['result'][0]['time_remaining']) : 0;

            $duration_seconds = $this->_time_to_seconds($data['table_duration']);
            $deduct_seconds = $this->billing_model->apply_promo_free_hour($duration_seconds, isset($promo) ? $promo : null);
            $save_time_deduct_duration = $this->_seconds_to_time($deduct_seconds);

            if ($deduct_seconds > $balance_seconds) {
                echo json_encode(['code' => 0, 'result' => 'Saldo waktu customer tidak mencukupi']);
                return;
            }

            // simpan berapa yang benar-benar dipotong dari saldo waktu (bisa < table_duration
            // kalau promo Fix "beli X gratis Y" dipakai) - dipakai cancel_table() untuk
            // mengembalikan persis sejumlah ini kalau meja dibatalkan.
            $data['saved_time_deducted'] = $save_time_deduct_duration;
        }

        // buka meja dengan MEMBER: data member (termasuk namanya) diambil LANGSUNG dari gameon (source
        // of truth) - bukan dari salinan lokal. Konsekuensinya, kalau gameon sedang tidak bisa dihubungi
        // maka buka meja pakai member DITOLAK (tidak ada fallback lokal) - member hanya jalan kalau
        // server pusat sehat. Nama member disimpan sebagai snapshot di table_active.table_customer_name.
        $data['table_customer_name'] = null;
        if ($customer_id > 0) {
            $cust = $this->gameon->get_customer($customer_id);
            if (!$cust['success']) {
                $reason = ($cust['http_code'] === 404)
                    ? 'Member tidak ditemukan di server pusat (gameon)'
                    : 'Server pusat (gameon) sedang tidak dapat dihubungi - buka meja dengan member tidak bisa diproses. Coba lagi, atau buka meja tanpa member.';
                echo json_encode(['code' => 0, 'result' => $reason]);
                return;
            }
            $data['table_customer_name'] = isset($cust['result']['name']) ? $cust['result']['name'] : null;
        }

        // --- KONFIRMASI PIN MEMBER untuk buka meja dengan "pakai waktu tersimpan" ---
        // Saldo waktu member dipotong DI SINI (saat buka meja), bukan saat tutup meja - jadi PIN
        // member (aplikasi GAMEON) diminta di sini juga. Kasir kirim member_approval_ref (1 token
        // per buka-meja). Selama belum "approved" -> return NEED_MEMBER_APPROVAL: meja BELUM kebuka
        // & waktu BELUM dipotong. Idempoten via ref (retry setelah disetujui aman).
        if ($used_save_time === 'Y' && $customer_id > 0) {
            $appr_ref = isset($body['member_approval_ref']) ? trim($body['member_approval_ref']) : '';
            if ($appr_ref === '') {
                echo json_encode(['code' => 0, 'result' => 'member_approval_ref wajib untuk buka meja dengan potong waktu tersimpan']);
                return;
            }
            $appr = $this->gameon->request_member_approval(array(
                'customer_id' => $customer_id,
                'branch' => $this->_resolve_branch(null, $created_by),
                'type' => 'billing_time',
                'ref' => $appr_ref,
                'amount' => 0,
                'detail' => array(
                    'Untuk' => 'Buka meja (potong waktu tersimpan)',
                    'Waktu' => $save_time_deduct_duration,
                ),
            ));
            if (!$appr['success']) {
                echo json_encode(['code' => 0, 'result' => $appr['error']]);
                return;
            }
            $appr_data = is_array($appr['result']) ? $appr['result'] : array();
            if ((isset($appr_data['status']) ? $appr_data['status'] : '') !== 'approved') {
                echo json_encode(['code' => 200, 'result' => 'NEED_MEMBER_APPROVAL', 'approval' => $appr_data]);
                return;
            }
        }

        $branch = $this->_resolve_branch(null, $created_by);
        $meja_label = $table_row ? $table_row->table_number : ('Meja ' . $table_id);

        // --- POTONG SALDO DI AWAL (prepaid_saldo = Y) ---
        // Bayar di muka dari SALDO customer sejumlah harga durasi PENUH yang dibooking (Timer saja).
        // PIN member dulu (aplikasi GAMEON), lalu saldo langsung dipotong (blocking). Saat tutup meja
        // TIDAK dipotong lagi: kalau tagihan aktual < prepaid -> selisih dikembalikan; kalau > prepaid
        // (overtime / tambah durasi) -> kekurangannya ditagih saat checkout pakai metode bayar lain.
        // amount + ref disimpan di table_active supaya payment()/cancel_table() bisa rekonsiliasi.
        if ($prepaid_saldo === 'Y') {
            if ($mode !== 'Timer') {
                echo json_encode(['code' => 0, 'result' => 'Potong saldo di awal hanya berlaku untuk mode Timer']);
                return;
            }
            if ($customer_id <= 0) {
                echo json_encode(['code' => 0, 'result' => 'table_customer_id wajib diisi untuk potong saldo di awal']);
                return;
            }
            if (!$table_row || empty($table_row->table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Category meja tidak ditemukan, tidak bisa menghitung harga di awal']);
                return;
            }

            $tier_info = $this->billing_model->get_category_meja_price_tier($table_row->table_category);
            $price = $this->billing_model->calculate_price($start_time, $data['table_end_time'], $promo_id, $tier_info['tier'], 'Timer', $tier_info['type']);
            if (!empty($price['promo_rejected_reason'])) {
                echo json_encode(['code' => 0, 'result' => $price['promo_rejected_reason']]);
                return;
            }
            $prepaid_amount = (int) $price['total_transaksi'];
            if ($prepaid_amount <= 0) {
                echo json_encode(['code' => 0, 'result' => 'Harga sesi Rp0 - tidak perlu potong saldo di awal']);
                return;
            }

            $appr_ref = isset($body['member_approval_ref']) ? trim($body['member_approval_ref']) : '';
            if ($appr_ref === '') {
                echo json_encode(['code' => 0, 'result' => 'member_approval_ref wajib untuk potong saldo di awal']);
                return;
            }
            $appr = $this->gameon->request_member_approval(array(
                'customer_id' => $customer_id,
                'branch' => $branch,
                'type' => 'billing_saldo',
                'ref' => $appr_ref,
                'amount' => $prepaid_amount,
                'detail' => array(
                    'Untuk' => 'Buka meja (bayar di muka)',
                    'Jumlah' => 'Rp' . number_format($prepaid_amount, 0, ',', '.'),
                ),
            ));
            if (!$appr['success']) {
                echo json_encode(['code' => 0, 'result' => $appr['error']]);
                return;
            }
            $appr_data = is_array($appr['result']) ? $appr['result'] : array();
            if ((isset($appr_data['status']) ? $appr_data['status'] : '') !== 'approved') {
                echo json_encode(['code' => 200, 'result' => 'NEED_MEMBER_APPROVAL', 'approval' => $appr_data]);
                return;
            }

            // disetujui -> potong saldo sekarang (atomik + idempoten by ref di sisi gameon).
            // Gagal (mis. saldo kurang / gameon down) -> meja TIDAK dibuka.
            $prepaid_ref = 'branch' . $branch . '-' . uniqid('prepaid-', true);
            $saldo_push = $this->gameon->deduct_saldo(array(
                'customer_id' => $customer_id,
                'amount' => $prepaid_amount,
                'deduction_ref' => $prepaid_ref,
                'created_by' => $created_by,
                'branch' => $branch,
            ));
            if (!$saldo_push['success']) {
                echo json_encode(['code' => 0, 'result' => $saldo_push['error'] ?: 'Gagal memotong saldo di gameon']);
                return;
            }

            $data['prepaid_saldo'] = 'Y';
            $data['saldo_prepaid_amount'] = $prepaid_amount;
            $data['saldo_prepaid_ref'] = $prepaid_ref;
        }

        // Potong saldo WAKTU tersimpan member DI SINI, BLOCKING - kalau gagal, meja tidak jadi
        // dibuka. Dulu ini fire-and-forget SETELAH meja kebuka: kalau push ke gameon gagal
        // (mis. waktu tidak cukup / gameon down), meja tetap kebuka & member dapat sesi gratis
        // karena saldo waktunya tidak pernah kepotong. Sekarang divalidasi & dipotong dulu.
        if ($used_save_time === 'Y') {
            $time_push = $this->gameon->use_customer_time(array(
                'customer_id' => $customer_id,
                'category_meja_id' => (int) $table_row->table_category,
                // durasi yang DIPOTONG dari saldo waktu, bisa lebih kecil dari table_duration kalau
                // promo Fix "beli X jam gratis Y jam" dipakai (lihat apply_promo_free_hour() di atas)
                'time_used' => $save_time_deduct_duration,
                // keterangan buat "kartu stok" waktu di gameon (customer_time_history.transaction_ref)
                'transaction_ref' => 'Buka ' . $meja_label,
                'created_by' => $created_by,
                'branch' => $branch,
            ));
            if (!$time_push['success']) {
                $msg = ((int) $time_push['http_code'] === 422)
                    ? 'Saldo waktu customer tidak mencukupi'
                    : 'Gagal memotong waktu tersimpan di gameon: ' . $time_push['error'];
                echo json_encode(['code' => 0, 'result' => $msg]);
                return;
            }
        }

        $success = $this->billing_model->book_table($table_id, $data);
        if ($success) {
            // nyalakan lampu meja - sebelumnya cuma disinkronkan lewat tombol manual "Reset Lampu"
            // atau (untuk off) saat timer habis; sekarang real-time tiap meja dibuka.
            if ($table_row) $this->_write_relay_signal($table_row->relay_number, 'on');

            // status meja (buka) ke laporan online - fire & forget, sama pola dengan transaksi/pembelian
            $event_id = $this->billing_model->record_table_event(array(
                'table_id' => $table_id,
                'table_number' => $meja_label,
                'event' => 'open',
                'mode' => $mode,
                'customer_id' => $customer_id > 0 ? $customer_id : null,
                'customer_name' => $data['table_customer_name'],
                'start_time' => $start_time,
                'end_time' => isset($data['table_end_time']) ? $data['table_end_time'] : null,
                'branch' => $branch,
                'created_by' => $created_by,
            ));
            $this->_sync_table_event_to_gameon($event_id);
            $this->_sync_table_snapshot_to_gameon($branch);

            // CATATAN: poin TIDAK lagi diberikan saat booking/buka meja (dulu mode Timer dapat poin di
            // sini). Semua poin sekarang diberikan saat payment() - untuk mode Timer maupun Reguler.

            echo json_encode(['code' => 200, 'result' => 'Meja berhasil dibooking']);
        } else {
            // meja gagal dibuka SETELAH saldo waktu terlanjur dipotong -> kembalikan supaya customer
            // tidak kehilangan waktu untuk sesi yang tidak pernah jadi
            if ($used_save_time === 'Y') {
                $refund = $this->gameon->save_customer_time(array(
                    'customer_id' => $customer_id,
                    'category_meja_id' => (int) $table_row->table_category,
                    'time_remaining' => $save_time_deduct_duration,
                    'transaction_ref' => 'Refund gagal buka ' . $meja_label,
                    'created_by' => $created_by,
                    'branch' => $branch,
                ));
                if (!$refund['success']) {
                    log_message('error', 'Gagal refund waktu tersimpan setelah book_table gagal (customer_id=' . $customer_id . '): ' . $refund['error']);
                }
            }
            // idem untuk potong saldo di awal yang terlanjur ke-deduct
            if (!empty($data['saldo_prepaid_ref'])) {
                $refund_saldo = $this->gameon->refund_saldo(array(
                    'deduction_ref' => $data['saldo_prepaid_ref'],
                    'created_by' => $created_by,
                ));
                if (!$refund_saldo['success']) {
                    log_message('error', 'Gagal refund prepaid saldo setelah book_table gagal (ref=' . $data['saldo_prepaid_ref'] . '): ' . $refund_saldo['error']);
                }
            }
            echo json_encode(['code' => 0, 'result' => 'Gagal booking meja']);
        }
    }

    public function edit_table_active()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        if ($table_id <= 0 || !isset($body['table_active'])) {
            echo json_encode(['code' => 0, 'result' => 'table_id dan table_active wajib diisi']);
            return;
        }
        $active = (int) $body['table_active'] ? 1 : 0;

        $success = $this->billing_model->edit_table_active($table_id, $active);
        if ($success) {
            $this->_sync_table_snapshot_to_gameon();
            echo json_encode(['code' => 200, 'result' => 'table_active berhasil diubah']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal mengubah table_active']);
        }
    }

    public function setting_table()
    {
        $data = $this->billing_model->get_setting_table();
        echo json_encode(['code' => 200, 'result' => $data]);
    }

    public function update_setting_table()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        if ($table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'table_id wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }

        // table_point sengaja tidak bisa diubah lewat endpoint ini
        $update = array();
        if (isset($body['table_relay'])) $update['table_relay'] = $body['table_relay'];
        if (isset($body['table_number'])) $update['table_number'] = $body['table_number'];
        // category_meja_id selalu >= 1, jadi 0/kosong dianggap "tidak diisi" (bukan permintaan mengosongkan category) untuk mencegah ke-reset tidak sengaja
        if (!empty($body['table_category'])) $update['table_category'] = (int) $body['table_category'];

        if (empty($update)) {
            echo json_encode(['code' => 0, 'result' => 'Tidak ada data yang diubah']);
            return;
        }

        $success = $this->billing_model->update_setting_table($table_id, $update);
        if ($success) {
            echo json_encode(['code' => 200, 'result' => 'Setting meja berhasil diubah']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal mengubah setting meja']);
        }
    }

    public function reset_table()
    {

        $success = $this->billing_model->reset_table();
        if ($success) {
            $this->_sync_table_snapshot_to_gameon();
            echo json_encode(['code' => 200, 'result' => 'Semua meja berhasil direset']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal mereset meja']);
        }
    }

    public function reset_lampu()
    {
        $tables = $this->billing_model->get_table_list_ordered();

        $last_index = count($tables) - 1;
        foreach ($tables as $index => $table) {
            $status = ((int) $table->table_active) === 1 ? 'on' : 'off';
            $this->_write_relay_signal($table->relay_number, $status);

            if ($index < $last_index) sleep(2);
        }

        echo json_encode(['code' => 200, 'result' => 'Reset lampu selesai']);
    }

    // dipanggil dari FE saat countdown meja mode Timer mencapai 0 - hanya
    // matikan lampu (sinyal relay off), sesi/table_active TIDAK disentuh
    // sama sekali, jadi meja tetap "Belum Dibayar" menunggu staff proses
    // pembayaran/cancel seperti biasa.
    public function timer_expired()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        if ($table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'table_id wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }

        $this->_write_relay_signal($table->relay_number, 'off');
        echo json_encode(['code' => 200, 'result' => 'Sinyal lampu off terkirim']);
    }

    public function transaction_list()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $page = !empty($body['page']) ? (int) $body['page'] : 1;
        $per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

        $data = $this->billing_model->get_transaction_list($page, $per_page);
        echo json_encode(['code' => 200, 'result' => $data]);
    }

    public function transaction_saldo_list()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $page = !empty($body['page']) ? (int) $body['page'] : 1;
        $per_page = !empty($body['per_page']) ? (int) $body['per_page'] : 20;

        $data = $this->billing_model->get_transaction_saldo_list($page, $per_page);
        echo json_encode(['code' => 200, 'result' => $data]);
    }

    public function total_transaction_per_cashier()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        if ($user_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
            return;
        }

        $data = $this->billing_model->get_total_transaction_per_cashier($user_id);
        echo json_encode(['code' => 200, 'result' => $data]);
    }

    public function transaction_detail()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $transaction_id = isset($body['transaction_id']) ? (int) $body['transaction_id'] : 0;
        if ($transaction_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'transaction_id wajib diisi']);
            return;
        }

        $data = $this->billing_model->get_transaction_detail($transaction_id);
        if (!$data) {
            echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
            return;
        }

        $customer = $this->billing_model->get_customer_detail($data['customer_id']);
        $data['member_name'] = $customer ? $customer->customer_name : null;

        $promo = $this->billing_model->get_promo_detail($data['promo_id']);
        $data['promo'] = $promo ? array(
            'id' => (int) $promo->ms_promo_id,
            'name' => $promo->ms_promo_name,
            'tipe' => $promo->ms_promo_tipe,
            'value' => (int) $promo->ms_promo_value,
        ) : null;

        echo json_encode(['code' => 200, 'result' => $data]);
    }

    // ubah metode pembayaran transaksi billing yang sudah selesai - ditolak kalau metode lama ATAU
    // baru adalah "Potong Saldo" (lihat Billing_model::edit_payment_transaction()) supaya saldo
    // customer tidak jadi tidak sinkron (belum ada logika koreksi saldo untuk kasus edit setelah
    // transaksi jadi)
    public function edit_payment_transaction()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $transaction_id = isset($body['transaction_id']) ? (int) $body['transaction_id'] : 0;
        $payment_id = isset($body['payment_id']) ? (int) $body['payment_id'] : 0;
        $created_by = isset($body['created_by']) ? trim($body['created_by']) : '';

        if ($transaction_id <= 0 || $payment_id <= 0 || $created_by === '') {
            echo json_encode(['code' => 0, 'result' => 'transaction_id, payment_id, dan created_by wajib diisi']);
            return;
        }

        $result = $this->billing_model->edit_payment_transaction($transaction_id, $payment_id, $created_by);

        if ($result === 'NOT_FOUND') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
        } else if ($result === 'CANCELLED') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi yang sudah dibatalkan tidak bisa diubah metode pembayarannya']);
        } else if ($result === 'PAYMENT_NOT_FOUND') {
            echo json_encode(['code' => 0, 'result' => 'Metode pembayaran tidak ditemukan']);
        } else if ($result === 'SALDO_NOT_ALLOWED') {
            echo json_encode(['code' => 0, 'result' => 'Metode pembayaran Potong Saldo tidak bisa diubah lewat sini - batalkan transaksi lalu buat ulang']);
        } else if ($result) {
            $this->_sync_transaction_to_gameon($transaction_id);
            echo json_encode(['code' => 200, 'result' => 'Metode pembayaran berhasil diubah']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal mengubah metode pembayaran']);
        }
    }

    // batalkan transaksi billing yang SUDAH "Done" (bukan cancel_table() di bawah, yang untuk meja
    // yang masih aktif) - ditolak untuk "Potong Saldo"/"Gunakan Timer" (lihat komentar lengkap di
    // Billing_model::cancel_transaction())
    public function cancel_transaction()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $transaction_id = isset($body['transaction_id']) ? (int) $body['transaction_id'] : 0;
        $created_by = isset($body['created_by']) ? trim($body['created_by']) : '';
        if ($transaction_id <= 0 || $created_by === '') {
            echo json_encode(['code' => 0, 'result' => 'transaction_id dan created_by wajib diisi']);
            return;
        }

        $result = $this->billing_model->cancel_transaction($transaction_id, $created_by);

        if ($result === 'NOT_FOUND') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi tidak ditemukan']);
        } else if ($result === 'ALREADY_CANCELLED') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi sudah dibatalkan sebelumnya']);
        } else if ($result === 'SALDO_NOT_ALLOWED') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi dengan metode Potong Saldo tidak bisa dibatalkan lewat sini - saldo yang sudah terpotong tidak bisa dikembalikan otomatis']);
        } else if ($result === 'SAVED_TIME_NOT_ALLOWED') {
            echo json_encode(['code' => 0, 'result' => 'Transaksi yang dibayar pakai waktu tersimpan tidak bisa dibatalkan lewat sini - waktu yang sudah terpotong tidak bisa dikembalikan otomatis']);
        } else if ($result) {
            $this->_sync_transaction_to_gameon($transaction_id);
            echo json_encode(['code' => 200, 'result' => 'Transaksi berhasil dibatalkan']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal membatalkan transaksi']);
        }
    }

    public function cancel_table()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        $created_by = isset($body['created_by']) ? $body['created_by'] : '';
        $paid_by = isset($body['paid_by']) ? (int) $body['paid_by'] : 0;

        if ($table_id <= 0 || $created_by === '') {
            echo json_encode(['code' => 0, 'result' => 'table_id dan created_by wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }
        if (!$table->table_active || empty($table->table_start_time)) {
            echo json_encode(['code' => 0, 'result' => 'Meja belum dibooking']);
            return;
        }

        // batal meja hanya boleh dalam 6 menit pertama sejak sesi dimulai - mencegah meja yang
        // sudah dipakai lama dibatalkan begitu saja tanpa tercatat sebagai transaksi/pembayaran
        $elapsed_minutes = (time() - strtotime($table->table_start_time)) / 60;
        if ($elapsed_minutes > 6) {
            echo json_encode(['code' => 0, 'result' => 'Meja hanya bisa dibatalkan dalam 6 menit pertama sejak sesi dimulai']);
            return;
        }

        // klaim meja secara atomik sebelum membatalkan, supaya beberapa permintaan cancel yang datang
        // nyaris bersamaan untuk meja yang sama tidak bisa semuanya berhasil (lihat claim_table_for_payment,
        // dipakai juga oleh payment() untuk masalah yang persis sama)
        if (!$this->billing_model->claim_table_for_payment($table_id)) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak sedang aktif atau sudah dibatalkan']);
            return;
        }

        $branch = $this->_resolve_branch($paid_by, $created_by);
        $transaction_id = $this->billing_model->cancel_table($table_id, $created_by, $paid_by, $branch);
        if ($transaction_id) {
            // matikan lampu meja - sebelumnya cuma disinkronkan lewat tombol manual "Reset Lampu";
            // sekarang real-time begitu meja dibatalkan.
            $this->_write_relay_signal($table->relay_number, 'off');

            // status meja (tutup, dibatalkan) ke laporan online - fire & forget
            $close_event_id = $this->billing_model->record_table_event(array(
                'table_id' => $table_id,
                'table_number' => $table->table_number,
                'event' => 'close',
                'mode' => $table->table_mode,
                'customer_id' => !empty($table->table_customer_id) ? $table->table_customer_id : null,
                'customer_name' => $table->table_customer_name,
                'start_time' => $table->table_start_time,
                'end_time' => date('Y-m-d H:i:s'),
                'reason' => 'cancel',
                'branch' => $branch,
                'created_by' => $created_by,
            ));
            $this->_sync_table_event_to_gameon($close_event_id);

            // Meja dibuka pakai waktu tersimpan lalu dibatalkan (dalam 6 menit) -> waktunya
            // belum terpakai, jadi dikembalikan persis sejumlah yang dulu dipotong.
            // Fire & forget (sama seperti potongannya saat book_table): gagalnya hanya dicatat.
            if (strtoupper((string) $table->use_saved_time) === 'Y'
                && !empty($table->table_customer_id)
                && !empty($table->saved_time_deducted)
                && $table->saved_time_deducted !== '00:00:00') {
                $refund_time = $this->gameon->save_customer_time(array(
                    'customer_id' => (int) $table->table_customer_id,
                    'category_meja_id' => (int) $table->table_category,
                    'time_remaining' => $table->saved_time_deducted,
                    'transaction_ref' => 'Refund batal ' . $table->table_number,
                    'created_by' => $created_by,
                    'branch' => $branch,
                ));
                if (!$refund_time['success']) {
                    log_message('error', 'Gagal mengembalikan waktu tersimpan saat cancel meja (customer_id=' . $table->table_customer_id . ', table_id=' . $table_id . '): ' . $refund_time['error']);
                }
            }

            // Meja dibuka dengan "Potong Saldo di awal" lalu dibatalkan -> kembalikan penuh saldo
            // yang tadi dipotong (idempoten by deduction_ref di sisi gameon).
            if (strtoupper((string) $table->prepaid_saldo) === 'Y' && !empty($table->saldo_prepaid_ref)) {
                $refund_saldo = $this->gameon->refund_saldo(array(
                    'deduction_ref' => $table->saldo_prepaid_ref,
                    'created_by' => $created_by,
                ));
                if (!$refund_saldo['success']) {
                    log_message('error', 'Gagal refund prepaid saldo saat cancel meja (ref=' . $table->saldo_prepaid_ref . ', table_id=' . $table_id . '): ' . $refund_saldo['error']);
                }
            }

            $this->_sync_transaction_to_gameon($transaction_id);
            $this->_sync_table_snapshot_to_gameon($branch);
            echo json_encode(['code' => 200, 'result' => 'Meja berhasil dibatalkan', 'transaction_id' => $transaction_id]);
        } else {
            $this->billing_model->unclaim_table($table_id);
            echo json_encode(['code' => 0, 'result' => 'Gagal membatalkan meja']);
        }
    }

    public function add_duration()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        $additional_duration = isset($body['additional_duration']) ? $body['additional_duration'] : '';

        if ($table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'table_id wajib diisi']);
            return;
        }
        if (empty($additional_duration) || $additional_duration === '00:00:00') {
            echo json_encode(['code' => 0, 'result' => 'additional_duration wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }
        if (!$table->table_active) {
            echo json_encode(['code' => 0, 'result' => 'Meja belum dibooking']);
            return;
        }
        if ($table->table_mode !== 'Timer') {
            echo json_encode(['code' => 0, 'result' => 'Tambah durasi hanya berlaku untuk mode Timer']);
            return;
        }
        if ($this->billing_model->is_fix_promo($table->table_promo_id)) {
            echo json_encode(['code' => 0, 'result' => 'Meja dengan promo paket (Fix) tidak bisa ditambah durasi']);
            return;
        }

        $success = $this->billing_model->add_duration($table_id, $additional_duration);
        if ($success) {
            $this->_sync_table_snapshot_to_gameon();
            echo json_encode(['code' => 200, 'result' => 'Durasi berhasil ditambahkan']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal menambahkan durasi']);
        }
    }

    // "genapkan waktu" - konversi meja mode Reguler (hitung naik, open-ended) jadi Timer dengan target
    // durasi total dari table_start_time (mis. sudah jalan 01:35:00, digenapkan ke 02:00:00 -> jadi
    // Timer dengan sisa hitung mundur 00:25:00). target_duration wajib lebih besar dari waktu yang
    // sudah berjalan, supaya sisa waktunya tidak negatif/langsung habis.
    public function round_up_duration()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        $target_duration = isset($body['target_duration']) ? $body['target_duration'] : '';

        if ($table_id <= 0 || empty($target_duration)) {
            echo json_encode(['code' => 0, 'result' => 'table_id dan target_duration wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }
        if (!$table->table_active) {
            echo json_encode(['code' => 0, 'result' => 'Meja belum dibooking']);
            return;
        }
        if ($table->table_mode !== 'Reguler') {
            echo json_encode(['code' => 0, 'result' => 'Genapkan waktu hanya berlaku untuk mode Reguler']);
            return;
        }
        if (empty($table->table_start_time)) {
            echo json_encode(['code' => 0, 'result' => 'Meja belum memiliki waktu mulai']);
            return;
        }

        $target_seconds = $this->_time_to_seconds($target_duration);
        if ($target_seconds <= 0) {
            echo json_encode(['code' => 0, 'result' => 'target_duration tidak valid']);
            return;
        }

        $start_ts = strtotime($table->table_start_time);
        $elapsed_seconds = max(0, time() - $start_ts);

        if ($target_seconds <= $elapsed_seconds) {
            echo json_encode(['code' => 0, 'result' => 'Waktu genap harus lebih besar dari waktu yang sudah berjalan']);
            return;
        }

        $success = $this->billing_model->book_table($table_id, array(
            'table_mode' => 'Timer',
            'table_end_time' => date('Y-m-d H:i:s', $start_ts + $target_seconds),
            'table_duration' => gmdate('H:i:s', $target_seconds),
        ));

        if ($success) {
            $this->_sync_table_snapshot_to_gameon();
            echo json_encode(['code' => 200, 'result' => 'Waktu berhasil digenapkan']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'Gagal menggenapkan waktu']);
        }
    }

    public function move_table()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $from_table_id = isset($body['from_table_id']) ? (int) $body['from_table_id'] : 0;
        $to_table_id = isset($body['to_table_id']) ? (int) $body['to_table_id'] : 0;

        if ($from_table_id <= 0 || $to_table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'from_table_id dan to_table_id wajib diisi']);
            return;
        }
        if ($from_table_id === $to_table_id) {
            echo json_encode(['code' => 0, 'result' => 'Meja asal dan tujuan tidak boleh sama']);
            return;
        }

        $from_table = $this->billing_model->get_table_by_id($from_table_id);
        if (!$from_table) {
            echo json_encode(['code' => 0, 'result' => 'Meja asal tidak ditemukan']);
            return;
        }
        if (!$from_table->table_active) {
            echo json_encode(['code' => 0, 'result' => 'Meja asal belum dibooking']);
            return;
        }

        $to_table = $this->billing_model->get_table_by_id($to_table_id);
        if (!$to_table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tujuan tidak ditemukan']);
            return;
        }
        if ($to_table->table_active) {
            echo json_encode(['code' => 0, 'result' => 'Meja tujuan sedang terpakai']);
            return;
        }
        if ((int) $from_table->table_category !== (int) $to_table->table_category) {
            echo json_encode(['code' => 0, 'result' => 'Meja tujuan harus category yang sama dengan meja asal']);
            return;
        }

        // klaim kedua meja secara atomik sebelum memindahkan, supaya beberapa permintaan move yang datang
        // nyaris bersamaan (mis. 2 sumber berebut tujuan yang sama, atau meja asal yang sama dipindah dua
        // kali) tidak bisa semuanya berhasil
        if (!$this->billing_model->claim_table_for_payment($from_table_id)) {
            echo json_encode(['code' => 0, 'result' => 'Meja asal tidak sedang aktif']);
            return;
        }
        if (!$this->billing_model->claim_free_table($to_table_id)) {
            // gagal klaim tujuan - kembalikan klaim meja asal supaya tidak nyangkut nonaktif
            $this->billing_model->unclaim_table($from_table_id);
            echo json_encode(['code' => 0, 'result' => 'Meja tujuan sedang dipakai proses lain']);
            return;
        }

        $success = $this->billing_model->move_table($from_table_id, $to_table_id);
        if ($success) {
            $this->_sync_table_snapshot_to_gameon();
            echo json_encode(['code' => 200, 'result' => 'Meja berhasil dipindahkan']);
        } else {
            // gagal memindahkan setelah kedua meja terlanjur diklaim - kembalikan keduanya ke kondisi semula
            $this->billing_model->unclaim_table($from_table_id);
            $this->billing_model->mark_table_free($to_table_id);
            echo json_encode(['code' => 0, 'result' => 'Gagal memindahkan meja']);
        }
    }


    public function payment()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $mode = isset($body['transaction_mode']) ? $body['transaction_mode'] : null;
        $customer_id = !empty($body['transaction_customer_id']) ? (int) $body['transaction_customer_id'] : null;
        // transaction_payment_id & transaction_promo_id NOT NULL di database, default 0 apabila tidak dikirim
        $payment_id = !empty($body['transaction_payment_id']) ? (int) $body['transaction_payment_id'] : 0;
        $promo_id = !empty($body['transaction_promo_id']) ? (int) $body['transaction_promo_id'] : 0;
        $start_time = isset($body['transaction_start_time']) ? $body['transaction_start_time'] : '';
        $end_time = !empty($body['transaction_end_time']) ? $body['transaction_end_time'] : null;
        $duration = !empty($body['transaction_duration']) ? $body['transaction_duration'] : null;
        $sub_total = isset($body['transaction_sub_total']) ? (int) $body['transaction_sub_total'] : 0;
        $tax = isset($body['transaction_tax']) ? (int) $body['transaction_tax'] : 0;
        $total_bill = isset($body['transaction_total_bill']) ? (int) $body['transaction_total_bill'] : 0;
        $table = isset($body['transaction_table']) ? (int) $body['transaction_table'] : 0;
        $created_by = isset($body['created_by']) ? $body['created_by'] : '';
        $paid_by = isset($body['paid_by']) ? (int) $body['paid_by'] : 0;
        // save_time = Y: sisa waktu (table_end_time - jam saat ini) disimpan sebagai saldo waktu customer (customer_time), bukan langsung "hangus"
        $save_time = isset($body['save_time']) ? strtoupper($body['save_time']) : 'N';
        // used_save_time = Y: transaction_duration dipotong dari saldo waktu tersimpan customer (customer_time), dicatat sebagai OUT
        $used_save_time = isset($body['used_save_time']) ? strtoupper($body['used_save_time']) : 'N';

        if ($start_time === '' || $table <= 0 || $created_by === '' || $paid_by <= 0) {
            echo json_encode(['code' => 0, 'result' => 'transaction_start_time, transaction_table, created_by, paid_by wajib diisi']);
            return;
        }
        if (!in_array($save_time, array('Y', 'N'))) {
            echo json_encode(['code' => 0, 'result' => 'save_time harus Y atau N']);
            return;
        }
        if (!in_array($used_save_time, array('Y', 'N'))) {
            echo json_encode(['code' => 0, 'result' => 'used_save_time harus Y atau N']);
            return;
        }

        // used_save_time = Y (bayar pakai waktu tersimpan): saldo waktu sudah dipotong saat buka meja
        // (book_table) + sudah lewat PIN member di sana. Transaksi di sini nilainya Rp0 dan metode
        // bayarnya SELALU "Potong Waktu" - apa pun payment_id yang dikirim kasir di-override, supaya
        // laporan konsisten dan tidak bisa nyasar ke "Potong Saldo".
        if ($used_save_time === 'Y') {
            $potong_waktu = $this->billing_model->get_payment_by_name('Potong Waktu');
            if ($potong_waktu) $payment_id = (int) $potong_waktu->payment_id;
        }

        // pembayaran dengan transaction_payment_id yang payment_name-nya "Potong Saldo" otomatis memotong
        // saldo customer (ms_customer.customer_saldo) - bukan dari flag terpisah di payload
        $payment_row = $payment_id > 0 ? $this->billing_model->get_payment_by_id($payment_id) : null;
        $use_saldo = ($payment_row && $payment_row->payment_name === 'Potong Saldo') ? 'Y' : 'N';
        if ($use_saldo === 'Y' && empty($customer_id)) {
            echo json_encode(['code' => 0, 'result' => 'Silahkan isi nama customer terlebih dahulu']);
            return;
        }

        $table_row = $this->billing_model->get_table_by_id($table);

        // save_time: category meja diambil dari table_active (state meja saat ini), member diambil dari payload
        // (transaction_customer_id) karena member bisa saja baru diisi saat payment, bukan saat open billing
        $remaining_time = null;
        if ($save_time === 'Y') {
            if (!$table_row) {
                echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan, tidak bisa menyimpan waktu (save_time)']);
                return;
            }
            if (empty($customer_id)) {
                echo json_encode(['code' => 0, 'result' => 'Member wajib diisi untuk menyimpan waktu (save_time)']);
                return;
            }
            if (empty($table_row->table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Category meja belum di set, tidak bisa menyimpan waktu (save_time)']);
                return;
            }
            if (empty($table_row->table_end_time)) {
                echo json_encode(['code' => 0, 'result' => 'Meja tidak memiliki jam selesai (table_end_time), tidak bisa menyimpan waktu (save_time)']);
                return;
            }

            // detik dibuang (floor ke menit penuh), dan sisa waktu wajib LEBIH DARI 3 menit baru boleh
            // disimpan - sisa waktu 3 menit atau kurang dianggap 0 (mis. 00:03:00 tetap ditolak)
            $remaining_minutes = (int) floor((strtotime($table_row->table_end_time) - time()) / 60);
            if ($remaining_minutes <= 3) {
                echo json_encode(['code' => 0, 'result' => 'Waktu meja sudah habis, tidak ada sisa waktu untuk disimpan (save_time)']);
                return;
            }
            $remaining_time = sprintf('%02d:%02d:00', (int) floor($remaining_minutes / 60), $remaining_minutes % 60);
        }

        // nominal tagihan SELALU dihitung ulang oleh backend (tidak trust payload), untuk kedua mode.
        // mode diambil dari table_row->table_mode (state tersimpan di server), bukan dari transaction_mode
        // yang dikirim client, supaya client tidak bisa menghindari perhitungan ulang dengan mengaku mode lain.
        // Timer, save_time = Y -> ditagih penuh sesuai durasi booking (table_end_time), karena sisa waktunya disimpan untuk dipakai lagi nanti.
        // Timer, save_time = N -> ditagih sesuai waktu yang benar-benar terpakai (start s/d sekarang, dibatasi table_end_time kalau overtime).
        // cth: booking 2 jam tapi baru jalan 10 menit -> disimpan: tagih 2 jam, tidak disimpan: tagih 10 menit
        // Reguler -> tidak ada table_end_time, selalu ditagih dari start_time sampai sekarang (checkout)
        //
        // $billed_duration = durasi yang BENAR-BENAR DIBAYAR customer (start_time s/d billing_end_time).
        // Ini yang jadi patokan poin - BUKAN durasi main yang dikirim client. Untuk save_time = Y
        // billing_end_time = table_end_time penuh, jadi customer langsung dapat poin sesuai jam yang
        // dibayar penuh (mis. bayar 25 jam -> poin 25 jam). used_save_time = Y tetap 0 poin (guard di bawah).
        $billed_duration = $duration;
        // jenis kategori meja (billiard/mahjong) snapshot buat transaction_category_type - default
        // billiard kalau blok di bawah ini ter-skip (table_row kosong/belum pernah start_time).
        $category_type = 'billiard';
        // nominal diskon promo (buat transaction_discount & nota) - default 0 kalau blok di bawah
        // ter-skip atau tidak pakai promo sama sekali.
        $total_promo = 0;
        if ($table_row && !empty($table_row->table_start_time)) {
            $effective_mode = !empty($table_row->table_mode) ? $table_row->table_mode : $mode;

            if ($effective_mode === 'Timer') {
                if ($save_time === 'Y' && !empty($table_row->table_end_time)) {
                    $billing_end_time = $table_row->table_end_time;
                } else {
                    $now_ts = time();
                    $end_ts = !empty($table_row->table_end_time) ? min(strtotime($table_row->table_end_time), $now_ts) : $now_ts;
                    $billing_end_time = date('Y-m-d H:i:s', $end_ts);
                }
            } else {
                $billing_end_time = date('Y-m-d H:i:s', time());
            }

            // promo dibatasi kategori meja (ms_promo_category) - tolak kalau kategori meja ini tidak
            // termasuk (dicek juga di book_table, tapi promo bisa saja baru dipilih/diganti saat bayar)
            if (!empty($promo_id) && !$this->master_model->promo_category_ok($promo_id, $table_row->table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Promo ini tidak berlaku untuk kategori meja ini']);
                return;
            }
            // promo tipe Fix hanya untuk mode Timer (sama seperti guard di book_table) - jaring
            // pengaman kalau ada table_active lama yang lolos sebelum guard itu ada, atau promo
            // diganti saat bayar
            if (!empty($promo_id)) {
                $promo_check = $this->billing_model->get_promo_detail($promo_id);
                if ($promo_check && $promo_check->ms_promo_tipe === 'Fix' && $effective_mode !== 'Timer') {
                    echo json_encode(['code' => 0, 'result' => 'Promo Hanya Untuk Timer saja']);
                    return;
                }
            }

            $tier_info = $this->billing_model->get_category_meja_price_tier($table_row->table_category);
            $category_type = $tier_info['type'];
            $price = $this->billing_model->calculate_price($table_row->table_start_time, $billing_end_time, $promo_id, $tier_info['tier'], $effective_mode, $category_type);
            if (!empty($price['promo_rejected_reason'])) {
                echo json_encode(['code' => 0, 'result' => $price['promo_rejected_reason']]);
                return;
            }
            $sub_total = $price['total_billing'];
            $tax = $price['total_tax'];
            $total_bill = $price['total_transaksi'];
            $total_promo = $price['total_promo'];

            $billed_seconds = max(0, strtotime($billing_end_time) - strtotime($table_row->table_start_time));
            $billed_duration = $this->_seconds_to_time($billed_seconds);
        }

        // jumlah yang BENAR-BENAR ditagih/dipotong di checkout ini. Normalnya = total_bill.
        // Sesi "Potong Saldo di awal" mengubah ini (lihat blok rekonsiliasi di bawah).
        $charge_amount = (int) $total_bill;

        // --- REKONSILIASI "Potong Saldo di awal" (prepaid_saldo = Y) ---
        // Saldo customer sudah dipotong sejumlah saldo_prepaid_amount saat BUKA meja. Di sini:
        //  - tagihan aktual <= prepaid : refund penuh prepaid lalu potong sejumlah aktual (net =
        //    aktual). Metode transaksi dikunci "Potong Saldo", tanpa PIN lagi (masih di dalam
        //    jumlah yang sudah disetujui member di awal).
        //  - tagihan aktual  > prepaid : prepaid tetap, kekurangannya ($diff) ditagih SEKARANG
        //    lewat metode bayar pilihan kasir (blok approval + deduct_saldo di bawah pakai $charge_amount).
        $prepaid_settled = false;
        $prepaid_amount = 0;
        if ($table_row && strtoupper((string) $table_row->prepaid_saldo) === 'Y') {
            $prepaid_amount = (int) $table_row->saldo_prepaid_amount;
            $prepaid_ref = $table_row->saldo_prepaid_ref;
            $recon_branch = $this->_resolve_branch($paid_by, $created_by);

            if ((int) $total_bill <= $prepaid_amount) {
                if (!empty($prepaid_ref)) {
                    $rf = $this->gameon->refund_saldo(array('deduction_ref' => $prepaid_ref, 'created_by' => $created_by));
                    if (!$rf['success']) {
                        log_message('error', 'Gagal refund prepaid saldo saat rekonsiliasi (ref=' . $prepaid_ref . '): ' . $rf['error']);
                    }
                }
                if ((int) $total_bill > 0) {
                    $recon_ref = 'branch' . $recon_branch . '-' . uniqid('recon-', true);
                    $dd = $this->gameon->deduct_saldo(array(
                        'customer_id' => $customer_id,
                        'amount' => (int) $total_bill,
                        'deduction_ref' => $recon_ref,
                        'created_by' => $created_by,
                        'branch' => $recon_branch,
                    ));
                    if (!$dd['success']) {
                        echo json_encode(['code' => 0, 'result' => $dd['error'] ?: 'Gagal menyesuaikan potongan saldo di gameon']);
                        return;
                    }
                }
                $ps_row = $this->billing_model->get_payment_by_name('Potong Saldo');
                if ($ps_row) $payment_id = (int) $ps_row->payment_id;
                $saved_time_value = $prepaid_amount;
                $charge_amount = 0;
                $prepaid_settled = true;
            } else {
                $charge_amount = (int) $total_bill - $prepaid_amount;
                $saved_time_value = $prepaid_amount;
            }
        }

        // --- KONFIRMASI PIN MEMBER untuk pembayaran Potong Saldo ---
        // Kasir kirim member_approval_ref (1 token per checkout). Kalau belum "approved",
        // kembalikan NEED_MEMBER_APPROVAL supaya cashier app menampilkan "menunggu PIN" + polling.
        // Tidak ada yang dipotong/dicatat sampai member menyetujui.
        //
        // used_save_time = Y (bayar pakai waktu tersimpan): total_bill nanti dipaksa 0 di blok
        // used_save_time di bawah, jadi TIDAK ada saldo yang dipotong sama sekali. Jangan minta PIN
        // member di sini kalau begitu - dulu popup PIN tetap muncul (pakai total_bill penuh yang
        // belum di-nol-kan), member menyetujui "pembayaran RpX", tapi ujungnya saldo tidak berkurang
        // - menyesatkan. Sekarang: minta approval hanya kalau saldo benar-benar akan dipotong,
        // yaitu syarat yang sama persis dengan deduct_saldo() di bawah.
        if ($use_saldo === 'Y' && $used_save_time !== 'Y' && !$prepaid_settled && !empty($customer_id) && $charge_amount > 0) {
            $appr_ref = isset($body['member_approval_ref']) ? trim($body['member_approval_ref']) : '';
            if ($appr_ref === '') {
                echo json_encode(['code' => 0, 'result' => 'member_approval_ref wajib untuk pembayaran Potong Saldo dengan member']);
                return;
            }
            $appr = $this->gameon->request_member_approval(array(
                'customer_id' => $customer_id,
                'branch' => $this->_resolve_branch($paid_by, $created_by),
                'type' => 'billing_saldo',
                'ref' => $appr_ref,
                'amount' => (int) $charge_amount,
                'detail' => array(
                    'Untuk' => $prepaid_amount > 0 ? 'Sewa meja (kekurangan)' : 'Sewa meja',
                    'Jumlah' => 'Rp' . number_format((int) $charge_amount, 0, ',', '.'),
                ),
            ));
            if (!$appr['success']) {
                echo json_encode(['code' => 0, 'result' => $appr['error']]);
                return;
            }
            $appr_data = is_array($appr['result']) ? $appr['result'] : array();
            $appr_status = isset($appr_data['status']) ? $appr_data['status'] : '';
            if ($appr_status !== 'approved') {
                echo json_encode(['code' => 200, 'result' => 'NEED_MEMBER_APPROVAL', 'approval' => $appr_data]);
                return;
            }
        }

        // validasi customer ada di gameon SEBELUM potongan waktu/saldo/transaksi diproses, kalau payment
        // ini akan memberi poin (member terisi + bukan pakai waktu tersimpan; berlaku untuk Timer &
        // Reguler karena poin sekarang SELALU diberikan saat payment, bukan saat booking) - supaya tidak
        // ada apa pun yang keburu tercatat/terpotong kalau customer_id ternyata tidak ada/belum ke-sync
        // di gameon, dan error-nya kelihatan di frontend
        if ($used_save_time !== 'Y' && !empty($customer_id) && $table_row) {
            if ($this->_duration_point((int) $table_row->table_point, $billed_duration) > 0) {
                $customer_check = $this->gameon->check_customer($customer_id);
                if (!$customer_check['success'] || empty($customer_check['result']['exists'])) {
                    echo json_encode(['code' => 0, 'result' => 'Customer tidak ditemukan di gameon, gagal menambah poin transaksi']);
                    return;
                }
            }
        }

        // used_save_time: potong transaction_duration dari saldo waktu tersimpan customer (customer_time).
        // hanya boleh kalau fitur simpan waktu (save_time_setting) sedang aktif
        $used_save_time_deducted = false;
        if ($used_save_time === 'Y') {
            $setting = $this->master_model->check_time_save();
            if (empty($setting['check_time_save']) || $setting['check_time_save'] !== 'Y') {
                echo json_encode(['code' => 0, 'result' => 'Fitur simpan waktu sedang tidak aktif, tidak bisa memotong waktu customer']);
                return;
            }
            if (empty($customer_id)) {
                echo json_encode(['code' => 0, 'result' => 'transaction_customer_id wajib diisi untuk memotong waktu (used_save_time)']);
                return;
            }
            if (!$table_row || empty($table_row->table_category)) {
                echo json_encode(['code' => 0, 'result' => 'Category meja tidak ditemukan, tidak bisa memotong waktu (used_save_time)']);
                return;
            }

            // kalau meja ini sudah "dibayar di muka" pakai waktu tersimpan SAAT BOOKING
            // (table_row->use_saved_time == 'Y', lihat book_table()), potongannya sudah terjadi waktu
            // itu - jangan dipotong lagi di sini (dulu ini yang bikin dobel potong sejak get_table_list
            // mulai mengembalikan use_saved_time), cukup ditutup sebagai transaksi Rp0. Kalau BELUM
            // dipotong sama sekali (booking biasa, tapi kasir baru memilih bayar pakai waktu tersimpan
            // saat checkout), potongan baru dilakukan di sini seperti biasa.
            $already_prepaid_at_booking = !empty($table_row->use_saved_time) && strtoupper($table_row->use_saved_time) === 'Y';

            if (!$already_prepaid_at_booking) {
                if (empty($duration) || !preg_match('/^([0-9]{1,3}):([0-5][0-9]):([0-5][0-9])$/', $duration) || $duration === '00:00:00') {
                    echo json_encode(['code' => 0, 'result' => 'transaction_duration wajib diisi format HH:MM:SS untuk memotong waktu (used_save_time)']);
                    return;
                }

                // sisa waktu tersimpan dipotong DI SINI juga (bukan setelah transaksi dibuat), supaya kalau
                // ternyata kurang, tidak ada transaksi Rp0 yang keburu tercatat sama sekali. Kecukupan &
                // pemotongan dilakukan atomik di sisi gameon (source kebenaran sisa waktu customer -
                // billing_api tidak lagi menyimpan saldo waktu sendiri).
                //
                // promo Fix "beli X jam gratis Y jam" (kalau dipilih) mengurangi durasi yang benar-benar
                // dipotong - lihat Billing_model::apply_promo_free_hour()
                $promo_for_saved_time = $promo_id > 0 ? $this->billing_model->get_promo_detail($promo_id) : null;
                $save_time_deduct_duration = $this->_seconds_to_time(
                    $this->billing_model->apply_promo_free_hour($this->_time_to_seconds($duration), $promo_for_saved_time)
                );

                $branch_for_time = $this->_resolve_branch($paid_by, $created_by);
                $time_result = $this->gameon->use_customer_time(array(
                    'customer_id' => $customer_id,
                    'category_meja_id' => (int) $table_row->table_category,
                    'time_used' => $save_time_deduct_duration,
                    // keterangan buat "kartu stok" waktu di gameon (customer_time_history.transaction_ref)
                    'transaction_ref' => 'Bayar ' . ($table_row ? $table_row->table_number : ('Meja ' . $table)),
                    'created_by' => $created_by,
                    'branch' => $branch_for_time,
                ));
                if (!$time_result['success']) {
                    $message = $time_result['http_code'] === 422
                        ? 'Saldo waktu customer tidak mencukupi'
                        : 'Gagal memotong waktu ke gameon: ' . $time_result['error'];
                    echo json_encode(['code' => 0, 'result' => $message]);
                    return;
                }
                $used_save_time_deducted = true;
            }

            // dibayar pakai sisa waktu tersimpan -> tidak ada tagihan baru (sudah dibayar/dihitung poinnya saat waktu itu disimpan).
            // nilai tagihan yang SEHARUSNYA (kalau tidak pakai sisa waktu) disimpan terpisah untuk keterangan di laporan.
            $saved_time_value = (int) $total_bill;
            $sub_total = 0;
            $tax = 0;
            $total_bill = 0;
        }

        // use_saldo (Potong Saldo): saldo customer sepenuhnya milik gameon - billing_api TIDAK lagi
        // menyimpan/memotong saldo lokal. Potongan dilakukan LANGSUNG ke gameon lewat deduct_saldo()
        // yang sudah atomik (cek kecukupan + potong sekaligus) dan idempoten (pakai deduction_ref).
        // Kalau gameon menolak / tidak bisa dihubungi -> pembayaran dibatalkan di sini juga, tidak ada
        // transaksi yang keburu tercatat. Kalau transaksinya nanti gagal dibuat, potongan di-refund
        // balik ke gameon pakai deduction_ref yang sama (lihat blok refund di bawah).
        $saldo_deducted = false;
        $saldo_deduction_ref = null;
        if ($use_saldo === 'Y' && !$prepaid_settled && $charge_amount > 0) {
            if (empty($customer_id)) {
                echo json_encode(['code' => 0, 'result' => 'Customer wajib diisi untuk pembayaran Potong Saldo']);
                return;
            }

            $branch_for_saldo = $this->_resolve_branch($paid_by, $created_by);
            $saldo_deduction_ref = 'branch' . $branch_for_saldo . '-' . uniqid('saldo-', true);
            $saldo_push = $this->gameon->deduct_saldo(array(
                'customer_id' => $customer_id,
                'amount' => $charge_amount,
                'deduction_ref' => $saldo_deduction_ref,
                'created_by' => $created_by,
                'branch' => $branch_for_saldo,
            ));
            if (!$saldo_push['success']) {
                // gameon menolak (mis. HTTP 422 saldo kurang) atau tidak bisa dihubungi - error string
                // dari gameon (mis. "Saldo customer tidak mencukupi") disurahkan apa adanya
                echo json_encode(['code' => 0, 'result' => $saldo_push['error'] ?: 'Gagal memotong saldo di gameon']);
                return;
            }
            $saldo_deducted = true;
        }

        // klaim meja secara atomik sebelum membuat transaksi, supaya 2 permintaan payment yang datang
        // nyaris bersamaan untuk meja yang sama tidak bisa dua-duanya berhasil (lihat claim_table_for_payment)
        if (!$this->billing_model->claim_table_for_payment($table)) {
            // saldo/waktu tersimpan mungkin sudah terlanjur dipotong di blok validasi used_save_time/use_saldo
            // di atas (dipotong duluan supaya race condition-nya sendiri tertutup) - kalau klaim meja
            // ternyata gagal di sini, potongan itu harus dikembalikan supaya customer tidak dirugikan
            // untuk pembayaran yang sebenarnya tidak pernah tercatat
            if ($used_save_time_deducted) {
                // refund = simpan lagi jumlah yang sama ke gameon (tidak ada lagi tabel lokal untuk direfund)
                $refund_time = $this->gameon->save_customer_time(array(
                    'customer_id' => $customer_id,
                    'category_meja_id' => (int) $table_row->table_category,
                    // refund persis sejumlah yang tadi dipotong (bisa lebih kecil dari $duration kalau
                    // promo Fix "beli X jam gratis Y jam" dipakai)
                    'time_remaining' => $save_time_deduct_duration,
                    'transaction_ref' => 'Refund gagal bayar ' . ($table_row ? $table_row->table_number : ('Meja ' . $table)),
                    'created_by' => $created_by,
                    'branch' => $branch_for_time,
                ));
                if (!$refund_time['success']) {
                    log_message('error', 'Gagal refund waktu tersimpan ke gameon (customer_id=' . $customer_id . '): ' . $refund_time['error']);
                }
            }
            if ($saldo_deducted) {
                // refund balik ke gameon pakai ref yang sama persis dengan deduct-nya tadi (idempoten),
                // supaya gameon tidak "terjebak" nganggap saldo itu masih kepakai padahal transaksinya batal.
                $refund_saldo = $this->gameon->refund_saldo(array(
                    'deduction_ref' => $saldo_deduction_ref,
                    'created_by' => $created_by,
                ));
                if (!$refund_saldo['success']) {
                    log_message('error', 'Gagal refund potongan saldo ke gameon (ref=' . $saldo_deduction_ref . '): ' . $refund_saldo['error']);
                }
            }
            echo json_encode(['code' => 0, 'result' => 'Meja tidak sedang aktif atau sudah dibayar']);
            return;
        }

        $branch = $this->_resolve_branch($paid_by, $created_by);

        $transaction_id = $this->billing_model->add_transaction(array(
            'mode' => $mode,
            'customer_id' => $customer_id,
            'payment_id' => $payment_id,
            'promo_id' => $promo_id,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration' => $duration,
            'sub_total' => $sub_total,
            'total_promo' => $total_promo,
            'tax' => $tax,
            'total_bill' => $total_bill,
            'table' => $table,
            'category_type' => $category_type,
            'players' => $table_row ? $table_row->table_players : null,
            'player_ids' => $table_row ? $table_row->table_player_ids : null,
            'payment_type' => $used_save_time === 'Y' ? 'Gunakan Timer' : 'Normal',
            'saved_time_value' => isset($saved_time_value) ? $saved_time_value : null,
            'created_by' => $created_by,
            'paid_by' => $paid_by,
            'branch' => $branch,
        ));

        if ($transaction_id) {
            $this->billing_model->clear_table($table);

            // matikan lampu meja - sebelumnya cuma disinkronkan lewat tombol manual "Reset Lampu";
            // sekarang real-time begitu pembayaran tercatat & meja dikosongkan.
            if ($table_row) $this->_write_relay_signal($table_row->relay_number, 'off');

            // status meja (tutup, dibayar) ke laporan online - fire & forget
            $close_event_id = $this->billing_model->record_table_event(array(
                'table_id' => $table,
                'table_number' => $table_row ? $table_row->table_number : ('Meja ' . $table),
                'event' => 'close',
                'mode' => $mode,
                'customer_id' => !empty($customer_id) ? $customer_id : null,
                'customer_name' => $table_row ? $table_row->table_customer_name : null,
                'start_time' => $start_time,
                'end_time' => date('Y-m-d H:i:s'),
                'reason' => 'payment',
                'branch' => $branch,
                'created_by' => $created_by,
            ));
            $this->_sync_table_event_to_gameon($close_event_id);

            if ($save_time === 'Y') {
                $transaction = $this->billing_model->get_transaction_detail($transaction_id);
                $transaction_ref = $transaction ? $transaction['inv'] : (string) $transaction_id;

                // fire-and-forget ke gameon (transaksi sudah tercatat duluan, tidak digagalkan kalau ini gagal)
                $time_push = $this->gameon->save_customer_time(array(
                    'customer_id' => $customer_id,
                    'category_meja_id' => (int) $table_row->table_category,
                    'time_remaining' => $remaining_time,
                    'transaction_ref' => $transaction_ref,
                    'created_by' => $created_by,
                    'branch' => $branch,
                ));
                if (!$time_push['success']) {
                    log_message('error', 'Gagal simpan waktu tersimpan ke gameon (customer_id=' . $customer_id . '): ' . $time_push['error']);
                }
            }

            // poin SELALU dihitung saat payment (baik Timer maupun Reguler) - tidak lagi diberikan
            // saat booking/buka meja. Patokannya JAM YANG DIBAYAR ($billed_duration), bukan durasi
            // main yang dikirim client -> save_time = Y (bayar penuh) langsung dapat poin penuh.
            // used_save_time = Y: tidak dapat poin lagi, karena sudah dapat poin full saat pembayaran awal.
            // fire-and-forget ke gameon - gameon yang menambahkan poin & catat histori
            // (customer_point_history), billing_api tidak lagi menyimpan poin sendiri.
            if ($used_save_time !== 'Y' && !empty($customer_id) && $table_row) {
                $point = $this->_duration_point((int) $table_row->table_point, $billed_duration);
                if ($point > 0) {
                    $point_push = $this->gameon->add_customer_point(array(
                        'customer_id' => $customer_id,
                        'point' => $point,
                        'ref_type' => 'Transaction',
                        'ref_id' => $transaction_id,
                        'description' => 'Poin dari transaksi',
                        'created_by' => $created_by,
                        'branch' => $branch,
                    ));
                    if (!$point_push['success']) {
                        log_message('error', 'Gagal menambah poin ke gameon saat payment (customer_id=' . $customer_id . '): ' . $point_push['error']);
                    }
                }
            }

            // salinan transaksi ke gameon buat laporan terpusat - fire & forget, kegagalan tidak
            // menggagalkan pembayaran (transaksi sudah tercatat lokal)
            $this->_sync_transaction_to_gameon($transaction_id);
            $this->_sync_table_snapshot_to_gameon($branch);

            echo json_encode(['code' => 200, 'result' => 'Pembayaran berhasil disimpan', 'transaction_id' => $transaction_id]);
        } else {
            // gagal menyimpan transaksi setelah meja terlanjur diklaim - kembalikan table_active supaya
            // meja tidak nyangkut nonaktif tanpa ada transaksi yang tercatat
            $this->billing_model->unclaim_table($table);
            // kalau saldo/waktu tersimpan sudah terlanjur dipotong juga, kembalikan supaya customer
            // tidak kehilangan saldo/waktu untuk pembayaran yang sebenarnya gagal
            if ($used_save_time_deducted) {
                $refund_time = $this->gameon->save_customer_time(array(
                    'customer_id' => $customer_id,
                    'category_meja_id' => (int) $table_row->table_category,
                    // refund persis sejumlah yang tadi dipotong (bisa lebih kecil dari $duration kalau
                    // promo Fix "beli X jam gratis Y jam" dipakai)
                    'time_remaining' => $save_time_deduct_duration,
                    'transaction_ref' => 'Refund gagal bayar ' . ($table_row ? $table_row->table_number : ('Meja ' . $table)),
                    'created_by' => $created_by,
                    'branch' => $branch_for_time,
                ));
                if (!$refund_time['success']) {
                    log_message('error', 'Gagal refund waktu tersimpan ke gameon (customer_id=' . $customer_id . '): ' . $refund_time['error']);
                }
            }
            if ($saldo_deducted) {
                // potongan saldo di gameon dibatalkan pakai deduction_ref yang sama (idempoten)
                $refund_saldo = $this->gameon->refund_saldo(array(
                    'deduction_ref' => $saldo_deduction_ref,
                    'created_by' => $created_by,
                ));
                if (!$refund_saldo['success']) {
                    log_message('error', 'Gagal refund potongan saldo ke gameon (ref=' . $saldo_deduction_ref . '): ' . $refund_saldo['error']);
                }
            }
            echo json_encode(['code' => 0, 'result' => 'Gagal menyimpan pembayaran']);
        }
    }

    // kirim salinan 1 transaksi billing ke gameon (buat laporan terpusat). Fire & forget:
    // kalau gameon tidak terjangkau / menolak, cuma dicatat di log - transaksi lokal tetap sah.
    // Dipanggil setelah payment() sukses, setelah cancel_table(), dan setelah edit metode bayar.
    private function _sync_transaction_to_gameon($transaction_id)
    {
        if (empty($transaction_id)) return;
        $payload = $this->billing_model->get_transaction_for_sync($transaction_id);
        if (!$payload) return;
        $push = $this->gameon->push_transaction($payload);
        if ($push['success']) {
            $this->billing_model->mark_transaction_uploaded($transaction_id);
        } else {
            log_message('error', 'Gagal push transaksi billing ke gameon (id=' . $transaction_id . '): ' . $push['error']);
        }
    }

    // kirim 1 event status meja (buka/tutup) ke laporan online gameon. Fire & forget: gagal cuma
    // dicatat di log (upload_status tetap 'N') - muncul di halaman Sinkron Online untuk diulang.
    private function _sync_table_event_to_gameon($table_status_event_id)
    {
        if (empty($table_status_event_id)) return;
        $payload = $this->billing_model->get_table_event_for_sync($table_status_event_id);
        if (!$payload) return;
        $push = $this->gameon->push_table_status($payload);
        if ($push['success']) {
            $this->billing_model->mark_table_event_uploaded($table_status_event_id);
        } else {
            log_message('error', 'Gagal push status meja ke gameon (id=' . $table_status_event_id . '): ' . $push['error']);
        }
    }

    // push SNAPSHOT state semua meja cabang ke laporan online gameon ("papan meja live": meja mana
    // yang dipakai/tidak, mode Timer/Reguler, pakai promo, jam mulai - jam selesai, target habis
    // Timer). Fire & forget: gagal cuma dicatat di log + ditandai dirty di table_state_sync supaya
    // bisa diulang dari halaman Sinkron Online. Dipanggil tiap kali table_active berubah (buka /
    // bayar / batal / pindah / tambah durasi / genapkan waktu / aktif-nonaktif / reset).
    private function _sync_table_snapshot_to_gameon($branch = null)
    {
        if ($branch === null) $branch = $this->_resolve_branch();
        $payload = $this->billing_model->get_table_snapshot_for_sync($branch);
        $push = $this->gameon->push_table_snapshot($payload);
        $this->billing_model->mark_table_snapshot_synced(
            $branch,
            $push['success'],
            $payload['snapshot_at'],
            $push['success'] ? null : $push['error']
        );
        if (!$push['success']) {
            log_message('error', 'Gagal push snapshot meja ke gameon (branch=' . $branch . '): ' . $push['error']);
        }
    }

    private function _time_to_seconds($time) {
        sscanf($time, '%d:%d:%d', $h, $m, $s);
        return ((int) $h * 3600) + ((int) $m * 60) + (int) $s;
    }

    private function _seconds_to_time($seconds) {
        $seconds = max(0, (int) $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    // poin dari durasi pemakaian meja: table_point (poin/jam, dari table_active) x jumlah jam penuh
    // pemakaian (kelipatan 1 jam, sisa menit di bawah 1 jam tidak dihitung). cth: durasi 2,5 jam tetap
    // dihitung 2 jam
    private function _duration_point($table_point, $duration) {
        if ($table_point <= 0 || empty($duration)) return 0;
        $hours = (int) floor($this->_time_to_seconds($duration) / 3600);
        return (int) $table_point * $hours;
    }

    // proxy "kartu stok" waktu tersimpan member ke gameon (source kebenaran). Dipakai kasir
    // untuk melihat riwayat mutasi waktu (IN disimpan / OUT dipakai buka meja) 1 member.
    // body: {customer_id, category_meja_id?, page?, per_page?}
    public function customer_time_history()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
        if ($customer_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
            return;
        }

        $payload = array('customer_id' => $customer_id);
        if (!empty($body['category_meja_id'])) $payload['category_meja_id'] = (int) $body['category_meja_id'];
        if (!empty($body['page'])) $payload['page'] = (int) $body['page'];
        if (!empty($body['per_page'])) $payload['per_page'] = (int) $body['per_page'];

        $res = $this->gameon->customer_time_history($payload);
        if (!$res['success']) {
            echo json_encode(['code' => 0, 'result' => $res['error'] ?: 'Gagal mengambil riwayat waktu dari gameon']);
            return;
        }
        echo json_encode(['code' => 200, 'result' => $res['result']]);
    }

    // proxy riwayat POTONGAN saldo 1 member ke gameon (history_saldo, OUT saja), dipaging.
    // Dipakai dialog "Detail Member" di billinggameon. body: {customer_id, page?, per_page?}
    public function customer_saldo_history()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
        if ($customer_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
            return;
        }

        $payload = array('customer_id' => $customer_id);
        if (!empty($body['page'])) $payload['page'] = (int) $body['page'];
        if (!empty($body['per_page'])) $payload['per_page'] = (int) $body['per_page'];

        $res = $this->gameon->customer_saldo_history($payload);
        if (!$res['success']) {
            echo json_encode(['code' => 0, 'result' => $res['error'] ?: 'Gagal mengambil riwayat potongan saldo dari gameon']);
            return;
        }
        echo json_encode(['code' => 200, 'result' => $res['result']]);
    }

    // proxy riwayat POTONGAN poin 1 member ke gameon (customer_point_history, OUT saja), dipaging.
    // Dipakai dialog "Detail Member" di billinggameon. body: {customer_id, page?, per_page?}
    public function customer_point_history()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $customer_id = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
        if ($customer_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'customer_id wajib diisi']);
            return;
        }

        $payload = array('customer_id' => $customer_id);
        if (!empty($body['page'])) $payload['page'] = (int) $body['page'];
        if (!empty($body['per_page'])) $payload['per_page'] = (int) $body['per_page'];

        $res = $this->gameon->customer_point_history($payload);
        if (!$res['success']) {
            echo json_encode(['code' => 0, 'result' => $res['error'] ?: 'Gagal mengambil riwayat potongan poin dari gameon']);
            return;
        }
        echo json_encode(['code' => 200, 'result' => $res['result']]);
    }

    public function calculation_price()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $table_id = isset($body['table_id']) ? (int) $body['table_id'] : 0;
        if ($table_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'table_id wajib diisi']);
            return;
        }

        $table = $this->billing_model->get_table_by_id($table_id);
        if (!$table) {
            echo json_encode(['code' => 0, 'result' => 'Meja tidak ditemukan']);
            return;
        }
        if (empty($table->table_start_time)) {
            echo json_encode(['code' => 0, 'result' => 'Meja belum dibooking']);
            return;
        }

        // save_time = Y: preview harus samakan dengan payment() - ditagih penuh sesuai durasi booking
        // (table_end_time), karena sisa waktunya rencananya akan disimpan untuk dipakai lagi nanti.
        $save_time = isset($body['save_time']) ? strtoupper($body['save_time']) : 'N';

        $start_time = $table->table_start_time;
        $now_ts = time();
        if (!empty($table->table_end_time)) {
            if ($save_time === 'Y') {
                // mode Timer, rencana simpan waktu: tagih penuh sampai table_end_time, tidak dipotong walau checkout lebih awal
                $end_ts = strtotime($table->table_end_time);
            } else {
                // mode Timer, tidak disimpan: kalau masih ada sisa durasi (table_end_time > sekarang), dihitung sampai sekarang (checkout lebih awal).
                // kalau table_end_time sudah lewat, tetap pakai table_end_time sebagai patokan (tidak dikenakan biaya overtime)
                $end_ts = min(strtotime($table->table_end_time), $now_ts);
            }
        } else {
            // mode Reguler: tidak ada end_time, selalu dihitung sampai sekarang (checkout)
            $end_ts = $now_ts;
        }
        $end_time = date('Y-m-d H:i:s', $end_ts);
        $promo_id = (int) $table->table_promo_id;
        $tier_info = $this->billing_model->get_category_meja_price_tier($table->table_category);

        if (strtoupper((string) $table->use_saved_time) === 'Y') {
            // meja ini sudah "dibayar di muka" pakai sisa waktu tersimpan customer saat buka meja
            // (book_table() use_saved_time=Y) - tidak ada tagihan baru. payment() juga menutupnya
            // sebagai Rp0 (guard $already_prepaid_at_booking), jadi preview harus 0 supaya konsisten.
            $data = array(
                'total_billing' => 0,
                'total_promo' => 0,
                'total_tax' => 0,
                'total_pembulatan' => 0,
                'total_transaksi' => 0,
                'promo_rejected_reason' => null,
                'prepaid_with_saved_time' => true,
            );
        } else {
            $data = $this->billing_model->calculate_price($start_time, $end_time, $promo_id, $tier_info['tier'], $table->table_mode, $tier_info['type']);
            $data['prepaid_with_saved_time'] = false;
        }
        $data['table_id'] = (int) $table->table_id;
        $data['table_customer_id'] = $table->table_customer_id !== null ? (int) $table->table_customer_id : null;
        $data['table_promo_id'] = $promo_id;
        $data['table_mode'] = $table->table_mode;
        $data['start_time'] = $start_time;
        $data['end_time'] = $end_time;

        $promo = $this->billing_model->get_promo_detail($promo_id);
        $data['promo'] = $promo ? array(
            'id' => (int) $promo->ms_promo_id,
            'name' => $promo->ms_promo_name,
            'tipe' => $promo->ms_promo_tipe,
            'value' => (int) $promo->ms_promo_value,
        ) : null;

        $customer = $this->billing_model->get_customer_detail($table->table_customer_id);
        $data['member_name'] = $customer ? $customer->customer_name : null;

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

    private function _write_relay_signal($relay_number, $status)
    {
        $relay_number = (int) $relay_number;
        if ($relay_number <= 0) return;

        $dir = FCPATH . 'reader/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $file = $dir . $relay_number . $status . '.txt';

        // file lama bisa saja dibuat oleh user OS lain (mis. sebelum server dipindah/di-clone) dan
        // jadi tidak writable oleh user yang menjalankan PHP sekarang - file_put_contents() ke file
        // begitu gagal dengan Warning "Permission denied" walau direktorinya sendiri writable.
        // Hapus dulu (izin hapus ditentukan oleh direktori, bukan pemilik file) baru tulis ulang -
        // filenya jadi otomatis dimiliki user yang sedang jalan.
        if (file_exists($file)) {
            @unlink($file);
        }

        file_put_contents($file, '');
    }

}

