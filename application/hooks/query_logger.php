<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Query logger global: mencatat SEMUA query tulis (insert/update/delete/replace/truncate)
// yang dijalankan aplikasi ke table history_query, lengkap dengan table yang dieksekusi
// dan status sukses/gagal. SELECT sengaja tidak dicatat (jumlahnya jauh lebih banyak &
// bukan tujuan audit trail ini) - kalau mau ikut dicatat, hapus filter di _hql_query_type().
//
// dipasang lewat hook 'pre_system' (application/config/hooks.php) yang mendaftarkan
// register_shutdown_function() - BUKAN hook 'post_controller' - karena kalau db_debug aktif
// (non-production) dan ada query yang gagal, CodeIgniter langsung exit() di titik itu juga.
// shutdown function tetap dijalankan PHP walau exit()/fatal error terjadi di tengah request,
// sedangkan hook post_controller tidak akan pernah tercapai kalau itu terjadi.
function register_query_logger()
{
	register_shutdown_function('save_query_history');
}

function save_query_history()
{
	$CI =& get_instance();
	if (!isset($CI->db) || empty($CI->db->queries)) return;

	$queries = $CI->db->queries;
	$times = $CI->db->query_times;

	$rows = array();
	$last_failed_index = null;

	foreach ($queries as $i => $sql) {
		// history_query sendiri ditulis lewat $CI->db->insert() SETELAH loop ini selesai,
		// jadi tidak akan pernah muncul di $queries yang sedang di-loop (aman dari rekursi)
		$type = _hql_query_type($sql);
		if ($type === null) continue;

		$time = isset($times[$i]) ? (float) $times[$i] : 0;
		$success = $time > 0;
		if (!$success) $last_failed_index = count($rows);

		$rows[] = array(
			'query_text' => $sql,
			'query_type' => $type,
			'table_name' => _hql_query_table($sql),
			'status' => $success ? 'success' : 'gagal',
			'execution_time' => $success ? $time : null,
			// diisi setelah loop, khusus query gagal TERAKHIR (satu-satunya yang error
			// message-nya masih akurat diambil dari koneksi - lihat komentar di bawah)
			'error_message' => null,
		);
	}

	if (empty($rows)) return;

	// $CI->db->error() cuma menyimpan error dari query TERAKHIR yang gagal di koneksi ini.
	// db_debug aktif (non-production) -> request langsung exit begitu 1 query gagal, jadi
	// praktiknya cuma ada maksimal 1 query gagal per request & ini selalu akurat.
	// kalau nanti db_debug dimatikan (production) dan ada beberapa yang gagal berurutan,
	// hanya baris gagal TERAKHIR yang dapat pesan error asli, sisanya generik.
	if ($last_failed_index !== null) {
		$error = $CI->db->error();
		$rows[$last_failed_index]['error_message'] = !empty($error['message'])
			? substr($error['message'], 0, 500)
			: 'Query gagal dieksekusi';
	}
	foreach ($rows as &$row) {
		if ($row['status'] === 'gagal' && $row['error_message'] === null) {
			$row['error_message'] = 'Query gagal dieksekusi';
		}
	}
	unset($row);

	foreach ($rows as $row) {
		$CI->db->insert('history_query', $row);
	}
}

function _hql_query_type($sql)
{
	if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $sql, $m)) {
		return strtoupper($m[1]);
	}
	return null;
}

function _hql_query_table($sql)
{
	if (preg_match('/^\s*(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE\s+TABLE)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
		return $m[1];
	}
	return null;
}
