<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// TODO: notify/curl integration ke server pusat (gameonstation.com) sedang ditata ulang,
// akan dibangun kembali per-endpoint.
class Api {

    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    public function notify($module, $action, $function_name, $data = array(), $table = null, $id_field = null, $id_value = null)
    {
        // no-op sementara
    }
}
