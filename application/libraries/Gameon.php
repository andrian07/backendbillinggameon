<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// client curl sederhana untuk memanggil API gameon (server pusat)
class Gameon {

    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('gameon', true);
    }

    private function base_url()
    {
        return rtrim($this->CI->config->item('gameon_base_url', 'gameon'), '/');
    }

    private function api_key()
    {
        return $this->CI->config->item('gameon_api_key', 'gameon');
    }

    // GET/POST ke {gameon_base_url}/index.php/api/{path}, $body null = GET, $body array = POST JSON
    // return array('success' => bool, 'http_code' => int, 'code' => int|null, 'result' => mixed, 'error' => string|null)
    private function request($path, $body = null)
    {
        $url = $this->base_url() . '/index.php/api/' . $path;

        // X-API-KEY wajib di SETIAP request (GET maupun POST) - lihat Api::_require_api_key() di gameon,
        // yang sekarang menolak semua request server-to-server tanpa key yang cocok.
        $headers = array('X-API-KEY: ' . $this->api_key());

        $ch = curl_init($url);
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            return array('success' => false, 'http_code' => 0, 'code' => null, 'result' => null, 'error' => $curl_error);
        }

        $decoded = json_decode((string) $response, true);
        $code = is_array($decoded) && isset($decoded['code']) ? (int) $decoded['code'] : null;
        $result = is_array($decoded) && array_key_exists('result', $decoded) ? $decoded['result'] : null;

        if ($code !== 200) {
            $message = is_string($result) ? $result : ('HTTP ' . $http_code . ' dari gameon');
            return array('success' => false, 'http_code' => $http_code, 'code' => $code, 'result' => $result, 'error' => $message);
        }

        return array('success' => true, 'http_code' => $http_code, 'code' => $code, 'result' => $result, 'error' => null);
    }

    // sama seperti request() tapi ke controller lain (mis. 'internal') alih-alih 'api'.
    private function request_c($controller, $path, $body = null)
    {
        $url = $this->base_url() . '/index.php/' . $controller . '/' . $path;
        $headers = array('X-API-KEY: ' . $this->api_key());

        $ch = curl_init($url);
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            return array('success' => false, 'http_code' => 0, 'code' => null, 'result' => null, 'error' => $curl_error);
        }

        $decoded = json_decode((string) $response, true);
        $code = is_array($decoded) && isset($decoded['code']) ? (int) $decoded['code'] : null;
        $result = is_array($decoded) && array_key_exists('result', $decoded) ? $decoded['result'] : null;

        if ($code !== 200) {
            $message = is_string($result) ? $result : ('HTTP ' . $http_code . ' dari gameon');
            return array('success' => false, 'http_code' => $http_code, 'code' => $code, 'result' => $result, 'error' => $message);
        }
        return array('success' => true, 'http_code' => $http_code, 'code' => $code, 'result' => $result, 'error' => null);
    }

    // Push salinan 1 PEMBELIAN STOK ke laporan online gameon (controller Internal).
    // Idempoten di sisi gameon lewat kunci (branch, purchase_inv).
    public function push_purchase($data)
    {
        return $this->request_c('internal', 'receive_purchase', $data);
    }

    // Push 1 event status meja (buka/tutup) ke laporan online gameon. Idempoten di sisi gameon
    // lewat kunci (branch, local_event_id) - lihat Billing::book_table/payment/cancel_table
    // (yang mencatat & memicu push ini) dan Sync (retry kalau gagal).
    public function push_table_status($data)
    {
        return $this->request_c('internal', 'receive_table_status', $data);
    }

    // Push SNAPSHOT state semua meja cabang ke laporan online gameon ("papan meja live":
    // meja mana yang dipakai/tidak, mode Timer/Reguler, pakai promo atau tidak, jam mulai -
    // jam selesai, target habis untuk Timer). Beda dari push_table_status() yang event-based:
    // ini state penuh, di-push tiap table_active berubah & menimpa baris gameon per
    // (branch, table_id) - jadi self-healing kalau ada push yang sempat gagal.
    public function push_table_snapshot($data)
    {
        return $this->request_c('internal', 'receive_table_snapshot', $data);
    }

    // Push SNAPSHOT PENUH katalog produk cabang (stok & HPP/COGS terkini) ke laporan online
    // gameon. Sama pola dengan push_table_snapshot(): state penuh, menimpa baris gameon per
    // (branch, product_id) - self-healing kalau ada push yang sempat gagal. Dipanggil tiap
    // 30 menit oleh ProductStockSyncWatcher (billinggameon) + manual dari Sinkron Online.
    public function push_product_stock($data)
    {
        return $this->request_c('internal', 'receive_product_stock', $data);
    }

    // Konfirmasi PIN member untuk pembayaran Potong Saldo. Idempoten via `ref`.
    public function request_member_approval($data)
    {
        return $this->request('request_member_approval', $data);
    }
    public function member_approval_status($ref)
    {
        return $this->request('member_approval_status', array('ref' => $ref));
    }
    public function cancel_member_approval($ref)
    {
        return $this->request('cancel_member_approval', array('ref' => $ref));
    }

    // GET {gameon_base_url}/index.php/api/latest_customer?limit=...
    public function latest_customer($limit = 1)
    {
        return $this->request('latest_customer?limit=' . (int) $limit);
    }

    // GET {gameon_base_url}/index.php/api/customer_list?page=..&per_page=..&search=.. - daftar customer
    // aktif (paginated) langsung dari gameon (source kebenaran data customer), dipakai menu Member.
    // result berbentuk array('data' => [...], 'pagination' => [...]).
    public function customer_list($page = 1, $per_page = 20, $search = '')
    {
        $query = 'page=' . (int) $page . '&per_page=' . (int) $per_page;
        if ($search !== '') $query .= '&search=' . rawurlencode($search);
        return $this->request('customer_list?' . $query);
    }

    // POST {gameon_base_url}/index.php/api/receive_customer - push customer baru ke gameon.
    // $data wajib berisi customer_id & customer_id_number yang SAMA dengan yang baru dibuat di lokal
    public function receive_customer($data)
    {
        return $this->request('receive_customer', $data);
    }

    // Service update_online - push transaksi yang baru terjadi di cabang ke gameon supaya data cabang &
    // gameon tetap sinkron selama cabang online. Untuk sekarang baru menangani top up saldo, lewat
    // endpoint gameon add_customer_saldo (nama service di sisi cabang & nama endpoint di gameon
    // sengaja beda: service ini dirancang jadi satu pintu untuk jenis update lain nantinya juga)
    public function update_online($data)
    {
        return $this->request('add_customer_saldo', $data);
    }

    // Push salinan 1 transaksi BILLING ke gameon (buat laporan terpusat). Dipanggil tiap ada
    // transaksi baru / dibatalkan / diedit metode bayarnya. Idempoten di sisi gameon lewat
    // kunci (branch, transaction_inv) - id transaksi cabang TIDAK dikirim.
    public function push_transaction($data)
    {
        return $this->request('receive_transaction', $data);
    }

    // Push salinan 1 transaksi CAFE (header + item + addon) ke gameon. Idempoten lewat
    // kunci (branch, transaction_cafe_inv).
    public function push_transaction_cafe($data)
    {
        return $this->request('receive_transaction_cafe', $data);
    }

    // POST {gameon_base_url}/index.php/api/check_saldo - tanya ke gameon apakah saldo customer cukup
    // buat dipotong sebesar $amount. Dipakai sebelum potong saldo saat payment - saldo asli sekarang
    // cuma valid di gameon, billing_api tidak lagi jadi source of truth untuk saldo.
    public function check_saldo($customer_id, $amount)
    {
        return $this->request('check_saldo', array('customer_id' => $customer_id, 'amount' => $amount));
    }

    // POST {gameon_base_url}/index.php/api/deduct_saldo - konfirmasi ke gameon SETELAH potongan saldo
    // lokal berhasil (Master_model::deduct_customer_saldo()), supaya gameon (source kebenaran saldo)
    // ikut ter-update - tanpa ini cabang lain yang check_saldo() untuk customer yang sama akan dapat
    // angka basi (lihat catatan di Billing::payment()).
    public function deduct_saldo($data)
    {
        return $this->request('deduct_saldo', $data);
    }

    // POST {gameon_base_url}/index.php/api/refund_saldo - batalkan 1 deduct_saldo() yang sudah berhasil
    // dikonfirmasi (dipanggil kalau transaksi lokal yang menyertainya gagal dibuat setelah saldo sempat
    // dipotong - lihat blok refund di Billing::payment()).
    public function refund_saldo($data)
    {
        return $this->request('refund_saldo', $data);
    }

    // POST {gameon_base_url}/index.php/api/get_customer_time - ambil sisa waktu tersimpan customer.
    // $category_meja_id null = semua category, diisi = 1 category saja. Saldo waktu asli sekarang cuma
    // valid di gameon, billing_api tidak lagi menyimpannya sendiri.
    public function get_customer_time($customer_id, $category_meja_id = null)
    {
        $body = array('customer_id' => $customer_id);
        if ($category_meja_id !== null) $body['category_meja_id'] = $category_meja_id;
        return $this->request('get_customer_time', $body);
    }

    // POST {gameon_base_url}/index.php/api/save_customer_time - simpan (akumulasi) sisa waktu customer
    // ke gameon. Juga dipakai sebagai refund kalau potongan waktu di cabang perlu dibatalkan.
    public function save_customer_time($data)
    {
        return $this->request('save_customer_time', $data);
    }

    // POST {gameon_base_url}/index.php/api/use_customer_time - potong sisa waktu tersimpan customer di
    // gameon (cek kecukupan & potong dilakukan atomik di sisi gameon).
    public function use_customer_time($data)
    {
        return $this->request('use_customer_time', $data);
    }

    // POST {gameon_base_url}/index.php/api/customer_time_history - "kartu stok" waktu tersimpan
    // customer (mutasi IN/OUT), dipaging. $data: customer_id (wajib), category_meja_id?, page?, per_page?.
    public function customer_time_history($data)
    {
        return $this->request('customer_time_history', $data);
    }

    // POST {gameon_base_url}/index.php/api/customer_saldo_history - potongan saldo 1 customer
    // (history_saldo, OUT saja), dipaging. $data: customer_id (wajib), page?, per_page?.
    public function customer_saldo_history($data)
    {
        return $this->request('customer_saldo_history', $data);
    }

    // POST {gameon_base_url}/index.php/api/customer_point_history - potongan poin 1 customer
    // (customer_point_history, OUT saja), dipaging. $data: customer_id (wajib), page?, per_page?.
    public function customer_point_history($data)
    {
        return $this->request('customer_point_history', $data);
    }

    // POST {gameon_base_url}/index.php/api/check_customer - cek apakah customer_id ada di gameon. Dipakai
    // sebagai validasi sebelum aksi yang butuh menyentuh data customer di gameon (mis. sebelum menambah
    // poin), supaya kalau customer belum/tidak ke-sync ke gameon, transaksi lokal tidak keburu tercatat.
    public function check_customer($customer_id)
    {
        return $this->request('check_customer', array('customer_id' => $customer_id));
    }

    // POST {gameon_base_url}/index.php/api/add_customer_point - tambah poin customer di gameon
    // (akumulasi) + catat histori (customer_point_history). billing_api hanya kirim nilai poin jadinya
    // (table_point x jam pemakaian) - source kebenaran poin & histori sepenuhnya di gameon, billing_api
    // tidak lagi menyimpan poin sendiri.
    public function add_customer_point($data)
    {
        return $this->request('add_customer_point', $data);
    }

    // POST {gameon_base_url}/index.php/api/get_customer - ambil data lengkap 1 customer by id, dipakai
    // buat auto-sync customer yang belum ada di database lokal cabang (lihat Master::pending_topups()).
    public function get_customer($customer_id)
    {
        return $this->request('get_customer', array('customer_id' => $customer_id));
    }

    // GET {gameon_base_url}/index.php/api/pending_topups - tarik daftar permintaan top up dari
    // aplikasi member (self-service) yang masih Pending, buat ditampilkan & di-approve kasir.
    // Dipanggil berkala (polling) dari billinggameon.
    public function pending_topups()
    {
        return $this->request('pending_topups');
    }

    // GET {gameon_base_url}/index.php/api/bookings - tarik daftar booking room dari aplikasi member.
    // Saldo sudah dipotong di gameon waktu booking dibuat, jadi tidak ada aksi approve - billinggameon
    // cuma menampilkan. $since_id buat polling incremental (cuma booking dengan id > since_id).
    public function bookings($since_id = 0, $limit = 50, $branch = null)
    {
        $path = 'bookings?since_id=' . (int) $since_id . '&limit=' . (int) $limit;
        if ($branch !== null && (int) $branch > 0) $path .= '&branch=' . (int) $branch;
        return $this->request($path);
    }

    // POST {gameon_base_url}/index.php/api/confirm_booking - dipanggil SETELAH kasir berhasil buka
    // meja dari 1 booking, supaya booking itu tidak lagi muncul di daftar booking aktif.
    public function confirm_booking($booking_request_id)
    {
        return $this->request('confirm_booking', array('booking_request_id' => (int) $booking_request_id));
    }

    // POST {gameon_base_url}/index.php/api/confirm_topup - kasir approve 1 permintaan top up.
    // transaksi_saldo_id/inv WAJIB sudah dibuat duluan di cabang (lihat Master::approve_topup())
    // supaya ID tetap identik dengan cabang, sama seperti pola update_online().
    public function confirm_topup($data)
    {
        return $this->request('confirm_topup', $data);
    }

    /* ===== Katalog Saldo & Tukar Point - sekarang dikelola di gameon (source of truth), billing_api
       hanya proxy. Lihat Setting::saldo_list() dkk. ===== */

    public function saldo_list($page = 1, $per_page = 20)
    {
        return $this->request('saldo_list?page=' . (int) $page . '&per_page=' . (int) $per_page);
    }

    public function saldo_list_active()
    {
        return $this->request('saldo_list?active_only=1');
    }

    public function saldo_get($ms_saldo_id)
    {
        return $this->request('saldo_get?ms_saldo_id=' . (int) $ms_saldo_id);
    }

    public function saldo_add($data)   { return $this->request('saldo_add', $data); }
    public function saldo_edit($data)  { return $this->request('saldo_edit', $data); }
    public function saldo_delete($data){ return $this->request('saldo_delete', $data); }

    // Riwayat penukaran point member (tabel reward_redemption di gameon) - read-only,
    // dipakai menu "Tukar Point" di billinggameon. $search opsional (nama member / kode).
    public function reward_redemption_list($page = 1, $per_page = 20, $search = null)
    {
        $q = 'reward_redemption_list?page=' . (int) $page . '&per_page=' . (int) $per_page;
        if ($search !== null && trim((string) $search) !== '') $q .= '&search=' . rawurlencode(trim((string) $search));
        return $this->request($q);
    }

    // Tandai kupon penukaran point terpakai / batalkan klaim. $data: {reward_redemption_id|redeem_code, branch?, claimed_by?}
    public function reward_redemption_claim($data)   { return $this->request('reward_redemption_claim', $data); }
    public function reward_redemption_unclaim($data) { return $this->request('reward_redemption_unclaim', $data); }

    // Katalog Game (Setting > Game) - dikelola terpusat di gameon.
    public function game_list($page = 1, $per_page = 20, $branch = null)
    {
        $q = 'game_list?page=' . (int) $page . '&per_page=' . (int) $per_page;
        if (!empty($branch)) $q .= '&branch=' . (int) $branch;
        return $this->request($q);
    }
    public function game_get($id)     { return $this->request('game_get?ms_game_id=' . (int) $id); }
    public function game_add($data)   { return $this->request('game_add', $data); }
    public function game_edit($data)  { return $this->request('game_edit', $data); }
    public function game_delete($data){ return $this->request('game_delete', $data); }

    public function point_setting_get()          { return $this->request('point_setting'); }
    public function point_setting_set($value)    { return $this->request('point_setting', array('save_point' => $value === 'Y' ? 'Y' : 'N')); }
}
