<?php
require 'db.php';
echo "<pre>DB OK\n";
$c = $pdo->query("SELECT COUNT(*) FROM operators")->fetchColumn();
echo "operators: $c\n";
$r = $pdo->query("SELECT id,code,name,project,is_active FROM operators ORDER BY id LIMIT 5")->fetchAll();
print_r($r);
