<?php
require_once 'bootstrap.php';

$salesManager = new SalesManager();
$stats = $salesManager->getDashboardStats();

echo "<pre>";
print_r($stats);
echo "</pre>";
?>