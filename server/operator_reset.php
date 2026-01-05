<?php
// Reset segment: create new context + mark previous segment as 'complete'
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

try {
  $in = $_SERVER['REQUEST_METHOD'] === 'POST'
      ? json_decode(file_get_contents('php://input'), true)
      : $_GET;
  if (!is_array($in)) $in = [];

  $op = isset($in['operator_id']) ? (int)$in['operator_id'] : 0;
  if ($op <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Missing operator_id']); exit; }

  $pdo->beginTransaction();

  // === Operator master (defaults) ===
  $ops = $pdo->prepare("SELECT project, department, name FROM operators WHERE id = ?");
  $ops->execute([$op]);
  $od = $ops->fetch(PDO::FETCH_ASSOC);
  if (!$od) throw new RuntimeException('Operator not found');

  $project    = isset($in['project']) ? trim($in['project']) : ($od['project'] ?? null);
  $department = $od['department'] ?? null;
  $step       = isset($in['step']) ? trim($in['step']) : null;
  $part       = isset($in['part']) ? trim($in['part']) : null;
  $status     = (isset($in['status']) && $in['status'] !== '') ? trim($in['status']) : 'reset';
  $remarks    = isset($in['remarks']) ? trim($in['remarks']) : 'UI reset';

  // === Snapshot operator_counters sebelum diubah (buat fallback synthetic ctx bila perlu) ===
  $ocPrev = $pdo->prepare("SELECT step, part, status, remarks FROM operator_counters WHERE counter_date = CURDATE() AND operator_id = ?");
  $ocPrev->execute([$op]);
  $ocBefore = $ocPrev->fetch(PDO::FETCH_ASSOC) ?: ['step'=>null,'part'=>null,'status'=>null,'remarks'=>null];

  // === Context terakhir HARI INI (sebelum reset) ===
  $prevCtxStmt = $pdo->prepare("
    SELECT id, created_at FROM operator_context_log
    WHERE operator_id = ? AND DATE(created_at) = CURDATE()
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $prevCtxStmt->execute([$op]);
  $prevCtx = $prevCtxStmt->fetch(PDO::FETCH_ASSOC); // bisa null (first reset today)

  // (1) Jika ada project baru, update master operators → UI Project ikut ganti
  if ($project !== null && $project !== '' && $project !== ($od['project'] ?? null)) {
    $up = $pdo->prepare("UPDATE operators SET project = ? WHERE id = ?");
    $up->execute([$project, $op]);
  }

  // (2) Buat context BARU (segment boundary)
  $ins = $pdo->prepare("INSERT INTO operator_context_log
    (operator_id, project, department, step, part, status, remarks, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
  $ins->execute([$op, $project, $department, $step, $part, $status, $remarks]);

  // (3) Tandai context SEBELUMNYA menjadi 'complete' (jika ada)
  if ($prevCtx && isset($prevCtx['id'])) {
    $done = $pdo->prepare("UPDATE operator_context_log SET status = 'complete' WHERE id = ? AND (status IS NULL OR status <> 'complete')");
    $done->execute([$prevCtx['id']]);
  } else {
    // Fallback: first reset today, tapi mungkin sudah ada hit sebelumnya → buat context sintetis 'complete'
    $minHitStmt = $pdo->prepare("
      SELECT MIN(created_at) AS first_hit
      FROM operator_hit_log
      WHERE operator_id = ? AND DATE(created_at) = CURDATE()
    ");
    $minHitStmt->execute([$op]);
    $firstHit = $minHitStmt->fetchColumn();
    if ($firstHit) {
      $syn = $pdo->prepare("INSERT INTO operator_context_log
        (operator_id, project, department, step, part, status, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, 'complete', 'auto-complete on reset', ?)");
      // Pakai project lama (sebelum diubah), step/part sebelumnya
      $syn->execute([
        $op,
        $od['project'] ?? null,
        $department,
        $ocBefore['step'] ?? null,
        $ocBefore['part'] ?? null,
        $firstHit  // timestamp di awal segmen lama → supaya semua hit lama ter-cover
      ]);
    }
  }

  // (4) Sinkron meta harian + soft reset count ke 0 (UI)
  $upd = $pdo->prepare("INSERT INTO operator_counters
      (counter_date, operator_id, count, first_hit, last_hit, step, part, status, remarks)
      VALUES (CURDATE(), ?, 0, NULL, NULL, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE step=VALUES(step), part=VALUES(part), status=VALUES(status), remarks=VALUES(remarks)");
  $upd->execute([$op, $step, $part, $status, $remarks]);

  $zero = $pdo->prepare("UPDATE operator_counters
    SET count = 0, first_hit = NULL, last_hit = NULL
    WHERE counter_date = CURDATE() AND operator_id = ?");
  $zero->execute([$op]);

  // (5) Trigger UI refresh
  $pdo->exec("INSERT INTO app_kv (k, v) VALUES ('last_change', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE v = UNIX_TIMESTAMP()");

  $pdo->commit();
  echo json_encode(['status'=>'ok','operator_id'=>$op,'project'=>$project]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
