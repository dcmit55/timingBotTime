<?php
require_once __DIR__ . '/_export_helpers.php';

$date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
$rows   = getInputTimingRows($pdo, $date);
$header = inputTimingHeader();

$fname = "input_timing_".date('Ymd', strtotime($date)).".csv";
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$fname\"");

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM
fwrite($out, "sep=,\r\n");      // force Excel to use comma

fputcsv($out, $header, ',');
foreach ($rows as $r) {
  fputcsv($out, [
    $r['date'],
    $r['project'],
    $r['department'],
    $r['step'],
    $r['part'],
    $r['employee'],
    $r['start_time'],
    $r['end_time'],
    $r['qty'],
    $r['status'],
    $r['remarks']
  ], ',');
}
fclose($out);
exit;