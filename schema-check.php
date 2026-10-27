<?php
require "C:\Users\Leo\Documents\ISAP BSIT 3A\! COL\attendance-monitoring-system\backend\config\database.php";
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name;");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
