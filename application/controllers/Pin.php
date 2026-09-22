<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// PIN keamanan global untuk tindakan destruktif (batal meja, cancel transaksi cafe, hapus keep
// transaction). status() bisa diakses semua role (dipakai untuk tahu apakah harus munculkan
// dialog PIN). set_pin() dan set_active() HANYA owner (userrole == 1) - lihat Pin_model::is_owner(),
// sama seperti pola di Opname.php. verify() dipakai tiap kali user melakukan tindakan destruktif.
class Pin extends CI_Controller {

    public function __construct(){
        parent::__construct();
        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->model('pin_model');
        $this->load->helper(array('url', 'html'));
        date_default_timezone_set('Asia/Jakarta');
    }

    // { active, is_set } - tidak pernah mengembalikan pin_code
    public function status()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $data = $this->pin_model->get_status();
        echo json_encode(['code' => 200, 'result' => $data]);
    }

    public function set_pin()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        $pin_code = isset($body['pin_code']) ? trim((string) $body['pin_code']) : '';

        if ($user_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
            return;
        }
        if (!preg_match('/^\d{4,6}$/', $pin_code)) {
            echo json_encode(['code' => 0, 'result' => 'PIN harus berupa 4-6 digit angka']);
            return;
        }
        if (!$this->pin_model->is_owner($user_id)) {
            echo json_encode(['code' => 0, 'result' => 'Hanya akun owner yang dapat mengubah PIN']);
            return;
        }

        $this->pin_model->set_pin($pin_code);
        echo json_encode(['code' => 200, 'result' => 'PIN berhasil disimpan']);
    }

    public function set_active()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        $active = !empty($body['active']);

        if ($user_id <= 0) {
            echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
            return;
        }
        if (!$this->pin_model->is_owner($user_id)) {
            echo json_encode(['code' => 0, 'result' => 'Hanya akun owner yang dapat mengubah status PIN']);
            return;
        }

        $result = $this->pin_model->set_active($active);
        if ($result === 'PIN_NOT_SET') {
            echo json_encode(['code' => 0, 'result' => 'Atur PIN terlebih dahulu sebelum mengaktifkannya']);
            return;
        }
        echo json_encode(['code' => 200, 'result' => $active ? 'PIN diaktifkan' : 'PIN dinonaktifkan']);
    }

    // dipanggil sebelum tindakan destruktif. kalau PIN sedang tidak aktif, selalu lolos.
    public function verify()
    {
        $body = $this->_post_body();
        if ($body === null) return;

        $pin_code = isset($body['pin_code']) ? trim((string) $body['pin_code']) : '';

        if ($this->pin_model->verify($pin_code)) {
            echo json_encode(['code' => 200, 'result' => 'PIN benar']);
        } else {
            echo json_encode(['code' => 0, 'result' => 'PIN salah']);
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
