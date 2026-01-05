<?php
// Always JSON
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Optional: cegah HTML error keluar ke response JSON
ini_set('display_errors', 0);

require 'db.php';

try {
  // Terima JSON body
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) { echo json_encode(['status'=>'error','message'=>'Invalid JSON body']); exit; }

  $id      = isset($in['id']) ? (int)$in['id'] : 0;
  $name    = isset($in['name']) ? trim($in['name']) : '';
  $project = isset($in['project']) ? trim($in['project']) : '';
  $department = isset($in['department']) ? trim($in['department']) : '';

  error_log("[operators_update.php] Received - ID: $id, Name: $name, Project: $project, Department: $department");

  if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid operator id']); exit; }

  // Pastikan operator ada & aktif (opsional)
  $st = $pdo->prepare("SELECT id FROM operators WHERE id=?");
  $st->execute([$id]);
  if (!$st->fetch()) { echo json_encode(['status'=>'error','message'=>'Operator not found']); exit; }

  // Update nama, project, & department
  $st = $pdo->prepare("UPDATE operators SET name = ?, project = ?, department = ? WHERE id = ?");
  $st->execute([$name, $project, $department, $id]);
  
  error_log("[operators_update.php] Updated - Rows affected: " . $st->rowCount());

  // Sentuh last_change
  $pdo->exec("INSERT INTO app_kv (k, v) VALUES ('last_change', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE v = UNIX_TIMESTAMP()");

  echo json_encode(['status'=>'ok']);
} catch (Throwable $e) {
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
exit;
