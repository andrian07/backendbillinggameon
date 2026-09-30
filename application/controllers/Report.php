<?php
defined('BASEPATH') OR exit('No direct script access allowed');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

class Report extends CI_Controller {

	// warna tema laporan excel & pdf
	const THEME_TITLE_BG = '2F5496';
	const THEME_HEADER_BG = '4472C4';
	const THEME_ZEBRA_BG = 'F2F2F2';
	const THEME_GROUP_BG = 'D9E2F3';
	const THEME_TOTAL_BG = 'FCE4D6';
	const THEME_TOTAL_BORDER = 'C0504D';

	public function __construct(){
		parent::__construct();
		if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
			http_response_code(200);
			exit();
		}
		$this->load->helper('url');
		$this->load->library('session');
		$this->load->model('report_model');
		$this->load->helper(array('url', 'html'));
		date_default_timezone_set('Asia/Jakarta');
	}
	public function index()
	{
		echo "API 1.0 Billing";die();
	}

	// date opsional (format Y-m-d) - kalau diisi, tutup kas dihitung untuk hari bisnis tanggal itu
	// (06:00:00 s/d 23:59:59 pada tanggal itu sendiri) alih-alih hari ini. Dipakai date picker di Tutup Kas.
	public function get_transaction_today_by_cashier()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}
		$date = !empty($body['date']) ? trim($body['date']) : null;

		$data = $this->report_model->get_transaction_today_by_cashier($user_id, $date);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// sama seperti get_transaction_today_by_cashier, tapi dipecah per jenis pembayaran (payment_id)
	public function total_transaction_today_by_cashier()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}

		$data = $this->report_model->total_transaction_today_by_cashier($user_id);
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// === Pengeluaran kas (cash_expense) - lihat Report_model & tutup_kas_dialog.dart ===

	// catat 1 pengeluaran kas milik kasir. body: {user_id, keterangan, nominal, channel (billing|cafe)}
	public function add_cash_expense()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		$keterangan = isset($body['keterangan']) ? trim($body['keterangan']) : '';
		$nominal = isset($body['nominal']) ? (int) $body['nominal'] : 0;
		$channel = isset($body['channel']) ? trim($body['channel']) : 'billing';

		if ($user_id <= 0 || $keterangan === '' || $nominal <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id, keterangan, dan nominal (> 0) wajib diisi']);
			return;
		}
		if (!in_array($channel, ['billing', 'mahjong', 'cafe'], true)) {
			echo json_encode(['code' => 0, 'result' => 'channel harus billing, mahjong, atau cafe']);
			return;
		}

		$id = $this->report_model->add_cash_expense($user_id, $keterangan, $nominal, $channel);
		echo json_encode(['code' => 200, 'result' => ['id' => $id]]);
	}

	// daftar pengeluaran kas kasir untuk 1 hari + totalnya. body: {user_id, date? (Y-m-d)}
	public function cash_expenses_today()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'user_id wajib diisi']);
			return;
		}
		$date = !empty($body['date']) ? trim($body['date']) : null;

		$res = $this->report_model->get_cash_expenses($user_id, $date);
		echo json_encode(['code' => 200, 'result' => $res]);
	}

	// hapus 1 pengeluaran kas (hanya entri sendiri & hari ini). body: {cash_expense_id, user_id}
	public function delete_cash_expense()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$id = isset($body['cash_expense_id']) ? (int) $body['cash_expense_id'] : 0;
		$user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
		if ($id <= 0 || $user_id <= 0) {
			echo json_encode(['code' => 0, 'result' => 'cash_expense_id dan user_id wajib diisi']);
			return;
		}

		$ok = $this->report_model->delete_cash_expense($id, $user_id);
		echo json_encode([
			'code' => $ok ? 200 : 0,
			'result' => $ok ? 'Pengeluaran dihapus' : 'Pengeluaran tidak ditemukan / bukan milik Anda / bukan hari ini',
		]);
	}

	// laporan transaksi billing. filter: date_from, date_to (wajib), customer_id, paid_by (opsional)
	// type: view (default, JSON) / excel / pdf
	public function billing_report()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$filter = $this->_parse_report_filter($body);
		if ($filter === null) {
			echo json_encode(['code' => 0, 'result' => 'date_from dan date_to wajib diisi']);
			return;
		}

		$report = $this->report_model->get_billing_report($filter);
		$type = isset($body['type']) ? strtolower($body['type']) : 'view';

		if ($type === 'excel' || $type === 'pdf') {
			$headers = array('No', 'No Nota', 'Tanggal', 'Jam Mulai', 'Jam Selesai', 'Member', 'Kasir', 'Pembayaran', 'Promo', 'Sub Total', 'Diskon', 'Pajak', 'Total', 'Status', 'Keterangan');
			$number_columns = array(9, 10, 11, 12);
			$rows = array();
			$no = 1;
			foreach ($report['data'] as $row) {
				$is_fix_promo = !empty($row['promo_name']) && $row['promo_tipe'] === 'Fix';
				// promo Fix (paket harga+durasi tetap): nominalnya sudah tercermin di Sub Total/Total,
				// tidak ada "diskon" yang bermakna -> kolom Diskon ditulis 0. Nominal paket ikut ditulis
				// di belakang nama promo supaya tetap kelihatan.
				$promo_display = empty($row['promo_name'])
					? '-'
					: ($is_fix_promo
						? $row['promo_name'] . ' (Rp' . number_format($row['promo_value'], 0, ',', '.') . ')'
						: $row['promo_name']);
				$discount_display = $is_fix_promo ? 0 : $row['discount'];

				// transaksi dibayar pakai sisa waktu tersimpan -> Total = 0, kolom Keterangan
				// menyebut nilai tagihan yang seharusnya kalau tidak pakai sisa waktu.
				if (empty($row['used_saved_time'])) {
					$keterangan = '-';
				} elseif ($row['saved_time_value'] !== null) {
					$keterangan = 'Bayar pakai sisa waktu (senilai Rp' . number_format((int) $row['saved_time_value'], 0, ',', '.') . ')';
				} else {
					$keterangan = 'Bayar pakai sisa waktu';
				}

				$rows[] = array(
					$no++,
					$row['inv'],
					$row['date'],
					$row['start_time'],
					$row['end_time'],
					$row['member_name'] ?: '-',
					$row['kasir_name'] ?: '-',
					$row['payment_name'] ?: '-',
					$promo_display,
					$row['sub_total'],
					$discount_display,
					$row['tax'],
					$row['total_bill'],
					$row['status'],
					$keterangan,
				);
			}

			$summary = $report['summary'];
			$footer = array(
				'label' => 'TOTAL (' . $summary['jumlah_nota'] . ' Nota)',
				'label_colspan' => 9,
				'values' => array(
					9 => $summary['total_sub_total'],
					10 => $summary['total_discount'],
					11 => $summary['total_tax'],
					12 => $summary['total_bill'],
				),
			);

			$title = 'Laporan Transaksi Billing (' . $filter['date_from'] . ' s/d ' . $filter['date_to'] . ')';
			$filename = 'Laporan_Billing_' . $filter['date_from'] . '_sd_' . $filter['date_to'];

			if ($type === 'excel') {
				$this->_export_excel($filename, $title, $headers, $rows, $footer, $number_columns);
			} else {
				$this->_export_pdf($filename, $title, $headers, $rows, $footer, $number_columns);
			}
			return;
		}

		echo json_encode(['code' => 200, 'result' => $report]);
	}

	// laporan transaksi cafe beserta detail item per nota. filter: date_from, date_to (wajib), customer_id, paid_by (opsional)
	// type: view (default, JSON) / excel / pdf
	public function cafe_report()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$filter = $this->_parse_report_filter($body);
		if ($filter === null) {
			echo json_encode(['code' => 0, 'result' => 'date_from dan date_to wajib diisi']);
			return;
		}

		$report = $this->report_model->get_cafe_report($filter);
		$type = isset($body['type']) ? strtolower($body['type']) : 'view';

		if ($type === 'excel' || $type === 'pdf') {
			$title = 'Laporan Transaksi Cafe (' . $filter['date_from'] . ' s/d ' . $filter['date_to'] . ')';
			$filename = 'Laporan_Cafe_' . $filter['date_from'] . '_sd_' . $filter['date_to'];

			if ($type === 'excel') {
				$this->_export_cafe_excel($filename, $title, $report);
			} else {
				$this->_export_cafe_pdf($filename, $title, $report);
			}
			return;
		}

		echo json_encode(['code' => 200, 'result' => $report]);
	}

	// laporan transaksi saldo (top up). filter: date_from, date_to (wajib), customer_id, paid_by (opsional)
	// type: view (default, JSON) / excel / pdf
	public function saldo_report()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$filter = $this->_parse_report_filter($body);
		if ($filter === null) {
			echo json_encode(['code' => 0, 'result' => 'date_from dan date_to wajib diisi']);
			return;
		}

		$report = $this->report_model->get_saldo_report($filter);
		$type = isset($body['type']) ? strtolower($body['type']) : 'view';

		if ($type === 'excel' || $type === 'pdf') {
			$headers = array('No', 'No Nota', 'Tanggal', 'Member', 'Kasir', 'Pembayaran', 'Nominal', 'Diskon', 'Bayar', 'Status');
			$number_columns = array(6, 7, 8);
			$rows = array();
			$no = 1;
			foreach ($report['data'] as $row) {
				$rows[] = array(
					$no++,
					$row['inv'],
					$row['time'],
					$row['member_name'] ?: '-',
					$row['kasir_name'] ?: '-',
					$row['payment_name'] ?: '-',
					$row['nominal'],
					$row['discount_pay'],
					$row['pay'],
					$row['status'],
				);
			}

			$summary = $report['summary'];
			$footer = array(
				'label' => 'TOTAL (' . $summary['jumlah_nota'] . ' Nota)',
				'label_colspan' => 6,
				'values' => array(
					6 => $summary['total_nominal'],
					7 => $summary['total_discount_pay'],
					8 => $summary['total_pay'],
				),
			);

			$title = 'Laporan Transaksi Saldo (' . $filter['date_from'] . ' s/d ' . $filter['date_to'] . ')';
			$filename = 'Laporan_Saldo_' . $filter['date_from'] . '_sd_' . $filter['date_to'];

			if ($type === 'excel') {
				$this->_export_excel($filename, $title, $headers, $rows, $footer, $number_columns);
			} else {
				$this->_export_pdf($filename, $title, $headers, $rows, $footer, $number_columns);
			}
			return;
		}

		echo json_encode(['code' => 200, 'result' => $report]);
	}

	// laporan stok produk yang stoknya masih ada. type: view (default, JSON) / excel / pdf
	public function stock()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$data = $this->report_model->get_stock_report();
		$type = isset($body['type']) ? strtolower($body['type']) : 'view';

		if ($type === 'excel' || $type === 'pdf') {
			$headers = array('No', 'Kode Produk', 'Nama Produk', 'Total Stok');
			$number_columns = array(3);
			$rows = array();
			$no = 1;
			foreach ($data as $row) {
				$rows[] = array($no++, $row['product_code'], $row['product_name'], $row['total_stock']);
			}

			$title = 'Laporan Stok Produk';
			$filename = 'Laporan_Stok_' . date('Y-m-d');

			if ($type === 'excel') {
				$this->_export_excel($filename, $title, $headers, $rows, null, $number_columns);
			} else {
				$this->_export_pdf($filename, $title, $headers, $rows, null, $number_columns);
			}
			return;
		}

		echo json_encode(['code' => 200, 'result' => $data]);
	}

	// laporan pembelian stok, dengan filter tanggal (wajib) dan supplier (opsional).
	// type: view (default, JSON) / excel / pdf
	public function purchase_report()
	{
		$body = $this->_post_body();
		if ($body === null) return;

		$filter = $this->_parse_report_filter($body);
		if ($filter === null) {
			echo json_encode(['code' => 0, 'result' => 'date_from dan date_to wajib diisi']);
			return;
		}
		$filter['supplier'] = isset($body['supplier']) ? trim($body['supplier']) : '';

		$report = $this->report_model->get_purchase_report($filter);
		$type = isset($body['type']) ? strtolower($body['type']) : 'view';

		if ($type === 'excel' || $type === 'pdf') {
			$headers = array('No', 'No Invoice', 'Tanggal', 'Supplier', 'No. Invoice Supplier', 'Sub Total', 'Diskon', 'Total', 'Status', 'Dibatalkan Oleh');
			$number_columns = array(5, 6, 7);
			$rows = array();
			$no = 1;
			foreach ($report['data'] as $row) {
				$rows[] = array(
					$no++,
					$row['inv'],
					$row['date'],
					$row['supplier'] ?: '-',
					$row['supplier_invoice'] ?: '-',
					$row['sub_total'],
					$row['discount'],
					$row['total'],
					$row['status'],
					$row['cancelled_by'] ?: '-',
				);
			}

			$summary = $report['summary'];
			$footer = array(
				'label' => 'TOTAL (' . $summary['jumlah_nota'] . ' Nota)',
				'label_colspan' => 5,
				'values' => array(
					5 => $summary['total_sub_total'],
					6 => $summary['total_discount'],
					7 => $summary['total_bill'],
				),
			);

			$title = 'Laporan Pembelian (' . $filter['date_from'] . ' s/d ' . $filter['date_to'] . ')'
				. (!empty($filter['supplier']) ? ' - Supplier: ' . $filter['supplier'] : '');
			$filename = 'Laporan_Pembelian_' . $filter['date_from'] . '_sd_' . $filter['date_to'];

			if ($type === 'excel') {
				$this->_export_excel($filename, $title, $headers, $rows, $footer, $number_columns);
			} else {
				$this->_export_pdf($filename, $title, $headers, $rows, $footer, $number_columns);
			}
			return;
		}

		echo json_encode(['code' => 200, 'result' => $report]);
	}

	// daftar nama supplier unik yang pernah dipakai di pembelian - untuk dropdown filter laporan
	public function purchase_suppliers()
	{
		$data = $this->report_model->get_purchase_suppliers();
		echo json_encode(['code' => 200, 'result' => $data]);
	}

	private function _parse_report_filter($body)
	{
		$date_from = isset($body['date_from']) ? trim($body['date_from']) : '';
		$date_to = isset($body['date_to']) ? trim($body['date_to']) : '';
		if ($date_from === '' || $date_to === '') return null;

		return array(
			'date_from' => $date_from,
			'date_to' => $date_to,
			'customer_id' => !empty($body['customer_id']) ? (int) $body['customer_id'] : 0,
			'paid_by' => !empty($body['paid_by']) ? (int) $body['paid_by'] : 0,
			// billiard/mahjong - hanya dipakai get_billing_report(), laporan lain abaikan key ini
			'category_type' => !empty($body['category_type']) ? $body['category_type'] : '',
		);
	}

	// laporan tabel flat (satu baris per data): title -> header kolom -> data (zebra) -> total (opsional)
	// $footer: array('label' => ..., 'label_colspan' => int, 'values' => array(col_index => value))
	// $number_columns: index kolom (0-based, sesuai urutan $headers) yang diformat sebagai angka ribuan
	private function _export_excel($filename, $title, $headers, $rows, $footer = null, $number_columns = array())
	{
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$col_count = count($headers);
		$last_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_count);

		$sheet->setCellValue('A1', $title);
		$sheet->mergeCells('A1:' . $last_col . '1');
		$sheet->getRowDimension(1)->setRowHeight(24);
		$title_style = $sheet->getStyle('A1');
		$title_style->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
		$title_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_TITLE_BG);
		$title_style->getAlignment()->setHorizontal('center')->setVertical('center');

		$header_row = 3;
		foreach ($headers as $i => $header) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
			$sheet->setCellValue($col . $header_row, $header);
		}
		$header_style = $sheet->getStyle('A' . $header_row . ':' . $last_col . $header_row);
		$header_style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
		$header_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_HEADER_BG);
		$header_style->getAlignment()->setHorizontal('center')->setVertical('center');
		$sheet->getRowDimension($header_row)->setRowHeight(20);

		$r = $header_row + 1;
		foreach ($rows as $row_index => $row) {
			foreach ($row as $i => $value) {
				$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
				$sheet->setCellValue($col . $r, $value);
			}
			if ($row_index % 2 === 1) {
				$sheet->getStyle('A' . $r . ':' . $last_col . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_ZEBRA_BG);
			}
			$r++;
		}
		$last_data_row = $r - 1;

		if (empty($rows)) {
			$sheet->setCellValue('A' . $r, 'Tidak ada data');
			$sheet->mergeCells('A' . $r . ':' . $last_col . $r);
			$sheet->getStyle('A' . $r)->getAlignment()->setHorizontal('center');
			$r++;
		}

		if ($footer !== null) {
			$label_colspan = isset($footer['label_colspan']) ? (int) $footer['label_colspan'] : 1;
			$label_last_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($label_colspan);

			$sheet->setCellValue('A' . $r, $footer['label']);
			if ($label_colspan > 1) {
				$sheet->mergeCells('A' . $r . ':' . $label_last_col . $r);
			}
			$sheet->getStyle('A' . $r)->getAlignment()->setHorizontal('right');

			foreach ($footer['values'] as $col_index => $value) {
				$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_index + 1);
				$sheet->setCellValue($col . $r, $value);
			}

			$footer_style = $sheet->getStyle('A' . $r . ':' . $last_col . $r);
			$footer_style->getFont()->setBold(true);
			$footer_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_TOTAL_BG);
			$footer_style->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::THEME_TOTAL_BORDER));
			$r++;
		}

		$last_row = $r - 1;
		if ($last_row >= $header_row) {
			$sheet->getStyle('A' . $header_row . ':' . $last_col . $last_row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BFBFBF'));
		}

		foreach ($number_columns as $col_index) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_index + 1);
			if ($last_data_row >= $header_row + 1) {
				$sheet->getStyle($col . ($header_row + 1) . ':' . $col . $last_row)->getNumberFormat()->setFormatCode('#,##0');
			}
		}

		for ($i = 1; $i <= $col_count; $i++) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
			$sheet->getColumnDimension($col)->setAutoSize(true);
		}

		$sheet->freezePane('A' . ($header_row + 1));

		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
		header('Cache-Control: max-age=0');

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
		$writer->save('php://output');
		exit();
	}

	private function _export_pdf($filename, $title, $headers, $rows, $footer = null, $number_columns = array())
	{
		$col_count = count($headers);

		$html = '<html><head><meta charset="utf-8"><style>' . $this->_pdf_base_style() . '</style></head><body>';
		$html .= '<h2>' . htmlspecialchars($title) . '</h2>';
		$html .= '<table><thead><tr>';
		foreach ($headers as $header) {
			$html .= '<th>' . htmlspecialchars($header) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		if (empty($rows)) {
			$html .= '<tr><td colspan="' . $col_count . '" class="center">Tidak ada data</td></tr>';
		}
		foreach ($rows as $row) {
			$html .= '<tr>';
			foreach ($row as $i => $value) {
				$html .= $this->_pdf_cell($value, in_array($i, $number_columns, true));
			}
			$html .= '</tr>';
		}
		$html .= '</tbody>';
		if ($footer !== null) {
			$label_colspan = isset($footer['label_colspan']) ? (int) $footer['label_colspan'] : 1;
			$html .= '<tfoot><tr class="total">';
			$html .= '<td colspan="' . $label_colspan . '" style="text-align:right;">' . htmlspecialchars($footer['label']) . '</td>';
			for ($i = $label_colspan; $i < $col_count; $i++) {
				$value = isset($footer['values'][$i]) ? $footer['values'][$i] : '';
				$html .= $this->_pdf_cell($value, in_array($i, $number_columns, true));
			}
			$html .= '</tr></tfoot>';
		}
		$html .= '</table></body></html>';

		$this->_stream_pdf($html, $filename, 'landscape');
	}

	// laporan transaksi cafe + detail item, header nota ditulis satu baris (tidak diulang per item)
	private function _export_cafe_excel($filename, $title, $report)
	{
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();

		// kolom header nota (No Nota s/d Status) di-merge vertikal sekali per nota, ditaruh berurutan di sebelah KIRI;
		// kolom detail item (No, Produk, Qty, Harga, Sub Total) satu baris per item, berurutan di sebelah KANAN header
		$headers = array('No Nota', 'Tanggal', 'Member', 'Kasir', 'Pembayaran', 'Sub Total', 'Diskon', 'Pajak', 'Total', 'Status', 'Promo', 'Keterangan Promo', 'No', 'Produk', 'Qty', 'Harga Satuan', 'Sub Total');
		$col_count = count($headers);
		$last_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_count);
		$header_last_col = 'L'; // No Nota..Keterangan Promo (12 kolom pertama)
		$detail_first_col = 'M'; // No..Sub Total (5 kolom terakhir)

		$sheet->setCellValue('A1', $title);
		$sheet->mergeCells('A1:' . $last_col . '1');
		$sheet->getRowDimension(1)->setRowHeight(24);
		$title_style = $sheet->getStyle('A1');
		$title_style->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
		$title_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_TITLE_BG);
		$title_style->getAlignment()->setHorizontal('center')->setVertical('center');

		$header_row = 3;
		foreach ($headers as $i => $header) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
			$sheet->setCellValue($col . $header_row, $header);
		}
		$header_style = $sheet->getStyle('A' . $header_row . ':' . $last_col . $header_row);
		$header_style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
		$header_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_HEADER_BG);
		$header_style->getAlignment()->setHorizontal('center')->setVertical('center');
		$sheet->getRowDimension($header_row)->setRowHeight(20);

		$r = $header_row + 1;
		$zebra = false;
		foreach ($report['data'] as $trx) {
			$items = $trx['items'];
			$span = max(1, count($items));
			$start_row = $r;
			$end_row = $r + $span - 1;

			// header nota: No Nota, Tanggal, Member, Kasir, Pembayaran, Sub Total, Diskon, Pajak, Total, Status
			// ditulis 1x (merge vertikal ke bawah sepanjang baris item), semua kolom berurutan di sebelah kiri
			$sheet->setCellValue('A' . $start_row, $trx['inv']);
			$sheet->setCellValue('B' . $start_row, $trx['date'] . ' ' . date('H:i', strtotime($trx['time'])));
			$sheet->setCellValue('C' . $start_row, $trx['member_name'] ?: '-');
			$sheet->setCellValue('D' . $start_row, $trx['kasir_name'] ?: '-');
			$sheet->setCellValue('E' . $start_row, $trx['payment_name'] ?: '-');
			$sheet->setCellValue('F' . $start_row, $trx['sub_total']);
			$sheet->setCellValue('G' . $start_row, $trx['discount']);
			$sheet->setCellValue('H' . $start_row, $trx['tax']);
			$sheet->setCellValue('I' . $start_row, $trx['total_bill']);
			$sheet->setCellValue('J' . $start_row, $trx['status']);
			$sheet->setCellValue('K' . $start_row, !empty($trx['promo_name']) ? $trx['promo_name'] : '-');
			$sheet->setCellValue('L' . $start_row, !empty($trx['promo_note']) ? $trx['promo_note'] : '-');

			if (empty($items)) {
				$sheet->setCellValue('N' . $start_row, '-');
				$sheet->mergeCells('N' . $start_row . ':Q' . $start_row);
				$sheet->getStyle('N' . $start_row)->getAlignment()->setHorizontal('center');
			} else {
				$row_cursor = $start_row;
				$no = 1;
				foreach ($items as $item) {
					$sheet->setCellValue('M' . $row_cursor, $no++);
					$sheet->setCellValue('N' . $row_cursor, $item['product_name']);
					$sheet->setCellValue('O' . $row_cursor, $item['qty']);
					$sheet->setCellValue('P' . $row_cursor, $item['price']);
					$sheet->setCellValue('Q' . $row_cursor, $item['sub_total']);
					$row_cursor++;
				}
			}

			if ($end_row > $start_row) {
				foreach (array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L') as $col) {
					$sheet->mergeCells($col . $start_row . ':' . $col . $end_row);
				}
			}

			$block_range = 'A' . $start_row . ':' . $last_col . $end_row;
			$sheet->getStyle($block_range)->getAlignment()->setVertical('center');
			if ($zebra) {
				$sheet->getStyle($detail_first_col . $start_row . ':' . $last_col . $end_row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_ZEBRA_BG);
			}
			$sheet->getStyle('A' . $start_row . ':' . $header_last_col . $end_row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_GROUP_BG);

			$zebra = !$zebra;
			$r = $end_row + 1;
		}

		if (empty($report['data'])) {
			$sheet->setCellValue('A' . $r, 'Tidak ada data');
			$sheet->mergeCells('A' . $r . ':' . $last_col . $r);
			$sheet->getStyle('A' . $r)->getAlignment()->setHorizontal('center');
			$r++;
		}

		$last_item_row = $r - 1;

		$summary = $report['summary'];
		$sheet->setCellValue('A' . $r, 'TOTAL KESELURUHAN (' . $summary['jumlah_nota'] . ' Nota)');
		$sheet->mergeCells('A' . $r . ':E' . $r);
		$sheet->setCellValue('F' . $r, $summary['total_sub_total']);
		$sheet->setCellValue('G' . $r, $summary['total_discount']);
		$sheet->setCellValue('H' . $r, $summary['total_tax']);
		$sheet->setCellValue('I' . $r, $summary['total_bill']);
		$sheet->mergeCells('J' . $r . ':L' . $r);
		$sheet->mergeCells('M' . $r . ':' . $last_col . $r);
		$footer_style = $sheet->getStyle('A' . $r . ':' . $last_col . $r);
		$footer_style->getFont()->setBold(true);
		$footer_style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::THEME_TOTAL_BG);
		$footer_style->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::THEME_TOTAL_BORDER));
		$sheet->getStyle('A' . $r)->getAlignment()->setHorizontal('right');

		$last_row = $r;
		$sheet->getStyle('A' . $header_row . ':' . $last_col . $last_row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BFBFBF'));

		$sheet->getStyle('F' . ($header_row + 1) . ':I' . $last_row)->getNumberFormat()->setFormatCode('#,##0');
		if ($last_item_row >= $header_row + 1) {
			$sheet->getStyle('O' . ($header_row + 1) . ':Q' . $last_item_row)->getNumberFormat()->setFormatCode('#,##0');
		}

		for ($i = 1; $i <= $col_count; $i++) {
			$col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
			$sheet->getColumnDimension($col)->setAutoSize(true);
		}
		$sheet->getColumnDimension('L')->setWidth(28); // Keterangan Promo
		$sheet->getColumnDimension('N')->setWidth(30); // Produk

		$sheet->freezePane('A' . ($header_row + 1));

		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
		header('Cache-Control: max-age=0');

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
		$writer->save('php://output');
		exit();
	}

	private function _export_cafe_pdf($filename, $title, $report)
	{
		// kolom header nota (No Nota s/d Status) pakai rowspan, ditulis 1x per nota, berurutan di sebelah KIRI;
		// kolom detail item (No, Produk, Qty, Harga, Sub Total) satu baris per item, berurutan di sebelah KANAN header
		$headers = array('No Nota', 'Tanggal', 'Member', 'Kasir', 'Pembayaran', 'Sub Total', 'Diskon', 'Pajak', 'Total', 'Status', 'Promo', 'Keterangan Promo', 'No', 'Produk', 'Qty', 'Harga Satuan', 'Sub Total');
		$col_count = count($headers);

		$html = '<html><head><meta charset="utf-8"><style>' . $this->_pdf_base_style() . '</style></head><body>';
		$html .= '<h2>' . htmlspecialchars($title) . '</h2>';
		$html .= '<table><thead><tr>';
		foreach ($headers as $header) {
			$html .= '<th>' . htmlspecialchars($header) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if (empty($report['data'])) {
			$html .= '<tr><td colspan="' . $col_count . '" class="center">Tidak ada data</td></tr>';
		}

		$zebra = false;
		foreach ($report['data'] as $trx) {
			$items = $trx['items'];
			$span = max(1, count($items));
			$row_class = $zebra ? 'zebra' : '';

			$header_cells = '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars($trx['inv']) . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars($trx['date'] . ' ' . date('H:i', strtotime($trx['time']))) . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars($trx['member_name'] ?: '-') . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars($trx['kasir_name'] ?: '-') . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars($trx['payment_name'] ?: '-') . '</td>'
				. '<td class="grp num" rowspan="' . $span . '">' . number_format($trx['sub_total'], 0, ',', '.') . '</td>'
				. '<td class="grp num" rowspan="' . $span . '">' . number_format($trx['discount'], 0, ',', '.') . '</td>'
				. '<td class="grp num" rowspan="' . $span . '">' . number_format($trx['tax'], 0, ',', '.') . '</td>'
				. '<td class="grp num" rowspan="' . $span . '">' . number_format($trx['total_bill'], 0, ',', '.') . '</td>'
				. '<td class="grp center" rowspan="' . $span . '">' . htmlspecialchars($trx['status']) . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars(!empty($trx['promo_name']) ? $trx['promo_name'] : '-') . '</td>'
				. '<td class="grp" rowspan="' . $span . '">' . htmlspecialchars(!empty($trx['promo_note']) ? $trx['promo_note'] : '-') . '</td>';

			if (empty($items)) {
				$html .= '<tr class="' . $row_class . '">' . $header_cells
					. '<td colspan="5" class="center">-</td></tr>';
			} else {
				$no = 1;
				foreach ($items as $idx => $item) {
					$html .= '<tr class="' . $row_class . '">';
					if ($idx === 0) $html .= $header_cells;
					$html .= '<td class="center">' . ($no++) . '</td>';
					$html .= '<td>' . htmlspecialchars($item['product_name']) . '</td>';
					$html .= '<td class="center">' . $item['qty'] . '</td>';
					$html .= '<td class="num">' . number_format($item['price'], 0, ',', '.') . '</td>';
					$html .= '<td class="num">' . number_format($item['sub_total'], 0, ',', '.') . '</td>';
					$html .= '</tr>';
				}
			}
			$zebra = !$zebra;
		}

		$summary = $report['summary'];
		$html .= '</tbody><tfoot><tr class="total">';
		$html .= '<td colspan="5" style="text-align:right;">TOTAL KESELURUHAN (' . $summary['jumlah_nota'] . ' Nota)</td>';
		$html .= '<td class="num">Rp' . number_format($summary['total_sub_total'], 0, ',', '.') . '</td>';
		$html .= '<td class="num">Rp' . number_format($summary['total_discount'], 0, ',', '.') . '</td>';
		$html .= '<td class="num">Rp' . number_format($summary['total_tax'], 0, ',', '.') . '</td>';
		$html .= '<td class="num">Rp' . number_format($summary['total_bill'], 0, ',', '.') . '</td>';
		$html .= '<td colspan="7"></td>';
		$html .= '</tr></tfoot>';
		$html .= '</table></body></html>';

		$this->_stream_pdf($html, $filename, 'landscape');
	}

	private function _pdf_base_style()
	{
		return '
			body { font-family: sans-serif; font-size: 10px; color: #222; }
			h2 { text-align: center; margin: 0 0 10px 0; color: #' . self::THEME_TITLE_BG . '; }
			table { width: 100%; border-collapse: collapse; }
			th { background: #' . self::THEME_HEADER_BG . '; color: #fff; padding: 6px 8px; text-align: left; border: 1px solid #' . self::THEME_TITLE_BG . '; }
			td { padding: 5px 8px; border: 1px solid #ccc; vertical-align: middle; }
			tbody tr:nth-child(even) td { background: #' . self::THEME_ZEBRA_BG . '; }
			tr.zebra td { background: #' . self::THEME_ZEBRA_BG . '; }
			td.grp { background: #' . self::THEME_GROUP_BG . ' !important; font-weight: bold; }
			tr.total td { background: #' . self::THEME_TOTAL_BG . '; font-weight: bold; border-top: 2px solid #' . self::THEME_TOTAL_BORDER . '; }
			td.num { text-align: right; }
			td.center { text-align: center; }
		';
	}

	private function _pdf_cell($value, $is_number)
	{
		if ($is_number && $value !== '' && $value !== null) {
			return '<td class="num">' . htmlspecialchars(number_format((float) $value, 0, ',', '.')) . '</td>';
		}
		return '<td>' . htmlspecialchars((string) $value) . '</td>';
	}

	private function _stream_pdf($html, $filename, $orientation = 'landscape')
	{
		while (ob_get_level() > 0) ob_end_clean();
		$dompdf = new \Dompdf\Dompdf();
		$dompdf->setPaper('A4', $orientation);
		$dompdf->loadHtml($html);
		$dompdf->render();
		$dompdf->stream($filename . '.pdf', array('Attachment' => true));
		exit();
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

?>
