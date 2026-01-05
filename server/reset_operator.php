<?php
header('Content-Type: application/json');
require 'db.php';

$body = json_decode(file_get_contents('php://input'), true);
$op   = isset($body['operator_id']) ? (int)$body['operator_id'] : 0;
$note = isset($body['note']) ? trim($body['note']) : null;

if ($op <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'bad payload']); exit; }

try {
  $pdo->beginTransaction();

  // Ambil count sebelum reset
  $st = $pdo->prepare("SELECT `count` FROM operator_counters
                       WHERE operator_id=:op AND counter_date=CURDATE()
                       ORDER BY id DESC LIMIT 1");
  $st->execute([':op'=>$op]);
  $count_before = (int)($st->fetchColumn() ?? 0);

  // Reset ke 0 untuk hari ini
  $st = $pdo->prepare("INSERT INTO operator_counters (counter_date, operator_id, `count`, first_hit, last_hit)
                       VALUES (CURDATE(), :op, 0, NULL, NULL)
                       ON DUPLICATE KEY UPDATE `count`=0, first_hit=NULL, last_hit=NULL");
  $st->execute([':op'=>$op]);

  // Catat log reset (wajib ada kolom count_before, note di DB)
  $st = $pdo->prepare("INSERT INTO operator_reset_log (operator_id, count_before, note, created_at)
                       VALUES (:op, :cb, :note, NOW())");
  $st->execute([':op'=>$op, ':cb'=>$count_before, ':note'=>$note]);

  // Sentuh last_change
  $pdo->exec("INSERT INTO app_kv (k, v, updated_at)
              VALUES ('last_change', UNIX_TIMESTAMP(), NOW())
              ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)");

  $pdo->commit();
  echo json_encode(['status'=>'ok']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
