<?php
ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Connection: keep-alive');
require 'db.php';

// ensure key exists
$pdo->exec("INSERT INTO app_kv (k, v) VALUES ('last_change', UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE v=v");

$last = 0;
while (!connection_aborted()) {
  $st = $pdo->query("SELECT UNIX_TIMESTAMP(updated_at) FROM app_kv WHERE k='last_change'");
  $ts = (int)$st->fetchColumn();
  if ($ts > $last) {
    $last = $ts;
    echo "data: {\"type\":\"change\",\"ts\":$ts}\n\n";
    @ob_flush(); @flush();
  }
  usleep(150000); // 150ms
}
