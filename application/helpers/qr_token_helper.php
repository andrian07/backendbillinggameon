<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// QR member yang berubah-ubah (rotating) - isinya customer_id + waktu kadaluarsa, DIENKRIPSI (bukan
// cuma ditandatangani) pakai AES-256-CBC + secret yang HANYA diketahui gameon & billing_api (lihat
// application/config/qr_security.php). IV acak per token supaya token yang sama tidak pernah
// menghasilkan ciphertext yang sama dua kali (walau isinya identik/exp yang sama).
//
// PENTING: file ini SENGAJA ditulis IDENTIK di kedua project (gameon & billing_api) - bukan file yang
// benar-benar sama-sama diakses lewat symlink/composer package, cuma isinya sama persis, konsisten
// dengan cara jwt_helper.php di project ini juga hand-rolled sendiri tanpa dependency tambahan.
//
// format token: base64url(iv) . '.' . base64url(ciphertext)

if (!function_exists('qr_secret_key')) {
    function qr_secret_key() {
        $CI =& get_instance();
        $CI->config->load('qr_security', true);
        return $CI->config->item('qr_secret', 'qr_security');
    }
}

if (!function_exists('qr_b64url_encode')) {
    function qr_b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('qr_b64url_decode')) {
    function qr_b64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) $data .= str_repeat('=', 4 - $remainder);
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('qr_token_encrypt')) {
    // $payload: array, mis. ['customer_id' => 7, 'exp' => time() + 90]
    function qr_token_encrypt($payload, $secret = null) {
        $secret = $secret ?: qr_secret_key();
        $key = hash('sha256', $secret, true); // AES-256 butuh key 32 byte persis

        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt(json_encode($payload), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) return null;

        return qr_b64url_encode($iv) . '.' . qr_b64url_encode($ciphertext);
    }
}

if (!function_exists('qr_token_decrypt')) {
    // return payload (array) kalau token valid & belum kadaluarsa (field 'exp' <= sekarang), null kalau
    // tidak valid/rusak/kadaluarsa
    function qr_token_decrypt($token, $secret = null) {
        $secret = $secret ?: qr_secret_key();
        $key = hash('sha256', $secret, true);

        $parts = explode('.', (string) $token);
        if (count($parts) !== 2) return null;

        $iv = qr_b64url_decode($parts[0]);
        $ciphertext = qr_b64url_decode($parts[1]);
        if (strlen($iv) !== 16 || $ciphertext === '') return null;

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) return null;

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) return null;

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) return null;

        return $payload;
    }
}
