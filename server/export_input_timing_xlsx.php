<?php
// XLSX export for per-operator work-log (multi-project/step/part within a day)
require_once __DIR__ . '/_export_helpers.php';

if (!class_exists('ZipArchive')) {
  http_response_code(500);
  echo "PHP ZipArchive extension is not enabled. Enable extension=zip in php.ini, then restart Apache.";
  exit;
}

$date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
$rows = getInputTimingRows($pdo, $date);
$header = inputTimingHeader();

// Build minimal sheet XML
function colName($n){ $s=''; while($n>0){ $m=($n-1)%26; $s=chr(65+$m).$s; $n=intdiv($n-1,26);} return $s; }

$data = [];
$data[] = $header;
foreach ($rows as $r) {
  $data[] = [
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
  ];
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
  '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
$r=1;
foreach($data as $row){
  $sheetXml.='<row r="'.$r.'">';
  $c=1;
  foreach($row as $v){
    $v = str_replace(['&','<','>'], ['&amp;','&lt;','&gt;'], (string)$v);
    $sheetXml .= '<c r="'.colName($c).$r.'" t="inlineStr"><is><t>'.$v.'</t></is></c>';
    $c++;
  }
  $sheetXml.='</row>'; $r++;
}
$sheetXml.='</sheetData></worksheet>';

// Prepare temp dir
$tmp = sys_get_temp_dir().'/worklog_'.uniqid();
@mkdir($tmp, 0777, true);
@mkdir($tmp.'/_rels', 0777, true);
@mkdir($tmp.'/xl/_rels', 0777, true);
@mkdir($tmp.'/xl/worksheets', 0777, true);

file_put_contents($tmp.'/_rels/.rels',
  '<?xml version="1.0" encoding="UTF-8"?>'.
  '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
  '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

file_put_contents($tmp.'/xl/_rels/workbook.xml.rels',
  '<?xml version="1.0" encoding="UTF-8"?>'.
  '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
  '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

file_put_contents($tmp.'/xl/workbook.xml',
  '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
  '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
  'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.
  '<sheet name="Work Log" sheetId="1" r:id="rId1"/></sheets></workbook>');

file_put_contents($tmp.'/xl/worksheets/sheet1.xml', $sheetXml);

file_put_contents($tmp.'/[Content_Types].xml',
  '<?xml version="1.0" encoding="UTF-8"?>'.
  '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
  '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
  '<Default Extension="xml" ContentType="application/xml"/>'.
  '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
  '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
  '</Types>');

// Zip & stream (let output .xlsx di luar $tmp supaya tidak ikut ter-zip)
$out = sys_get_temp_dir().'/input_timing_'.date('Ymd', strtotime($date)).'_'.uniqid().'.xlsx';

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
  http_response_code(500); echo 'Cannot create XLSX'; exit;
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
  $local = substr($file->getPathname(), strlen($tmp)+1);
  $zip->addFile($file->getPathname(), str_replace('\\','/',$local));
}
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="input_timing_'.date('Ymd', strtotime($date)).'.xlsx"');
header('Content-Length: '.filesize($out));
readfile($out);

// Cleanup
function rrmdir($dir){
  if (!is_dir($dir)) return;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
  }
  @rmdir($dir);
}
@unlink($out);
rrmdir($tmp);
exit;
