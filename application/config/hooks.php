<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/userguide3/general/hooks.html
|
*/

// pasang query logger (application/hooks/query_logger.php) sedini mungkin (pre_system),
// supaya shutdown function-nya sempat terdaftar sebelum request mulai jalan
$hook['pre_system'][] = array(
	'class'    => '',
	'function' => 'register_query_logger',
	'filename' => 'query_logger.php',
	'filepath' => 'hooks',
);
