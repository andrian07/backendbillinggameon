<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Secret buat decrypt QR member yang di-scan kasir (lihat qr_token_helper.php, Master::find_member_by_qr()).
// HARUS SAMA PERSIS dengan qr_secret di application/config/qr_security.php punya gameon (yang nerbitkan).
$config['qr_secret'] = '318625b10e02efbe12e6fd03a428546989a349b53f706b020c928baf3d602b62';
