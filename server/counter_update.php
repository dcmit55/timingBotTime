<?php
// Always JSON
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

try {
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
  }

  $op      = isset($in['operator_id']) ? (int)$in['operator_id'] : 0;
  $step    = array_key_exists('step', $in)    ? trim((string)$in['step'])    : null;
  $part    = array_key_exists('part', $in)    ? trim((string)$in['part'])    : null;
  $status  = array_key_exists('status', $in)  ? trim((string)$in['status'])  : null;
  $remarks = array_key_exists('remarks', $in) ? trim((string)$in['remarks']) : null;
  
  // 🆕 Flag untuk partial update (hanya update field tertentu)
  $updatePartOnly = isset($in['update_part_only']) && $in['update_part_only'];

  if ($op <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing/invalid operator_id']);
    exit;
  }

  $pdo->beginTransaction();

  // 🆕 Jika update_part_only = true, hanya update field 'part' saja
  if ($updatePartOnly) {
    // Cek apakah record sudah ada untuk hari ini
    $check = $pdo->prepare(
      "SELECT id, step, status, remarks FROM operator_counters WHERE counter_date = CURDATE() AND operator_id = ?"
    );
    $check->execute([$op]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
      // Update hanya field 'part' jika record sudah ada (preserve step, status, remarks)
      $updatePart = $pdo->prepare(
        "UPDATE operator_counters SET part = ? WHERE counter_date = CURDATE() AND operator_id = ?"
      );
      $updatePart->execute([$part, $op]);
      
      // Ambil nilai existing untuk context log nanti
      $step = $existing['step'];
      $status = $existing['status'];
      $remarks = $existing['remarks'];
    } else {
      // Jika belum ada, buat record baru dengan minimal data
      $insert = $pdo->prepare(
        "INSERT INTO operator_counters (counter_date, operator_id, count, first_hit, last_hit, step, part, status, remarks)
         VALUES (CURDATE(), ?, 0, NULL, NULL, 'Counting', ?, 'pending', '')"
      );
      $insert->execute([$op, $part]);
      
      // Set default values untuk context log
      $step = 'Counting';
      $status = 'pending';
      $remarks = '';
    }
  } else {
    // Normal update: update semua field seperti biasa
    $upsert = $pdo->prepare(
      "INSERT INTO operator_counters (counter_date, operator_id, count, first_hit, last_hit, step, part, status, remarks)
       VALUES (CURDATE(), ?, 0, NULL, NULL, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         step = VALUES(step),
         part = VALUES(part),
         status = VALUES(status),
         remarks = VALUES(remarks)"
    );
    $upsert->execute([$op, $step, $part, $status, $remarks]);
  }

  // 2) Ambil master operator (project + department)
  $ops = $pdo->prepare("SELECT project, department FROM operators WHERE id = ?");
  $ops->execute([$op]);
  $od = $ops->fetch(PDO::FETCH_ASSOC);
  if (!$od) {
    throw new RuntimeException('Operator not found');
  }

  $project = $od['project'] ?? null;
  $dept    = $od['department'] ?? null;

  // 3) Sinkron context agar Export Input Timing mengikuti yang terlihat di web
  //    - Kalau sudah ada context aktif hari ini, UPDATE context terakhir.
  //    - Kalau belum ada, atau context terakhir sudah "complete", buat context baru.
  // 🆕 Skip context update jika update_part_only (karena step/status/remarks tidak berubah)
  if (!$updatePartOnly) {
    $ctxSel = $pdo->prepare(
      "SELECT id, status
       FROM operator_context_log
       WHERE operator_id = ? AND DATE(created_at) = CURDATE()
       ORDER BY created_at DESC
       LIMIT 1"
    );
    $ctxSel->execute([$op]);
    $ctx = $ctxSel->fetch(PDO::FETCH_ASSOC);

    if ($ctx && isset($ctx['id']) && (($ctx['status'] ?? null) !== 'complete')) {
      $ctxUpd = $pdo->prepare(
        "UPDATE operator_context_log
         SET project = ?, department = ?, step = ?, part = ?, status = ?, remarks = ?
         WHERE id = ?"
      );
      $ctxUpd->execute([$project, $dept, $step, $part, $status, $remarks, (int)$ctx['id']]);
    } else {
      $ctxIns = $pdo->prepare(
        "INSERT INTO operator_context_log (operator_id, project, department, step, part, status, remarks, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
      );
      $ctxIns->execute([$op, $project, $dept, $step, $part, $status, $remarks]);
    }
  }

  // 4) Trigger UI refresh
  $pdo->exec(
    "INSERT INTO app_kv (k, v) VALUES ('last_change', UNIX_TIMESTAMP())
     ON DUPLICATE KEY UPDATE v = UNIX_TIMESTAMP()"
  );

  $pdo->commit();
  echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
