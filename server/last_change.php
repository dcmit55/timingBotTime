<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require 'db.php';

$pdo->exec("INSERT INTO app_kv (k, v) VALUES ('last_change', UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE v = v");

$st = $pdo->prepare("SELECT UNIX_TIMESTAMP(updated_at) AS ts FROM app_kv WHERE k='last_change'");
$st->execute();
$ts = intval($st->fetchColumn());
echo json_encode(['ts'=>$ts]);
