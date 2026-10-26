<?php
require __DIR__ . '/config.php';

$db = (new Lab\Database())->connect();
$tools = new Lab\LocalDatabaseTools($db);
$tools->initSchema(__DIR__ . '/schema.sql');
$tools->inspect();
