<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Base URL server gameon (server pusat) - dipakai untuk memanggil endpoint gameon (mis. latest_customer).
// billing_api memanggil: <gameon_base_url>/index.php/api/<endpoint>
//$config['gameon_base_url'] = 'https://staging.gameonstation.com/';
//$config['gameon_base_url'] = 'https://gameonstation.com/';
//$config['gameon_base_url'] = 'http://localhost/gameon/';
//$config['gameon_base_url'] = 'http://localhost/billiardmahjongonline/';

// API key wajib disertakan (header X-API-KEY) di setiap request ke Api.php gameon - lihat Gameon.php
// (request()) dan Api::_require_api_key() di sisi gameon. Nilainya HARUS SAMA PERSIS dengan api_key
// di application/config/api_security.php punya gameon.
$config['gameon_api_key'] = 'ec1f3c52f6bde3e7a3cac9329f0d9d771ebcfee611ba1d7e01e232fc34bdcd75';
