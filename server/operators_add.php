<?php
header('Content-Type: application/json');
require 'db.php';

$input      = json_decode(file_get_contents('php://input'), true);
$name       = trim($input['name'] ?? '');
$project    = trim($input['project'] ?? '');
$code       = trim($input['code'] ?? '');
$department = trim($input['department'] ?? 'costume');

if ($name === '') {
  echo json_encode(['status' => 'error', 'message' => 'Name is required']);
  exit;
}

// auto generate code kalau kosong
if ($code === '') {
  $next = $pdo->query("
    SELECT COALESCE(MAX(CAST(SUBSTRING(code,3) AS UNSIGNED)),0)+1
    FROM operators
  ")->fetchColumn();
  $code = 'OP' . str_pad($next, 2, '0', STR_PAD_LEFT);
}

$st = $pdo->prepare("
  INSERT INTO operators (code, name, project, department, is_active)
  VALUES (?, ?, ?, ?, 1)
");
$st->execute([$code, $name, $project, $department]);

echo json_encode([
  'status' => 'ok',
  'id'     => $pdo->lastInsertId(),
  'code'   => $code
]);
