<?php
namespace Lab;

use PDO;

final class Database
{
    public function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'marketing_lab';
        $db = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            Settings::required('DB_USER'),
            getenv('DB_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $db->exec("SET time_zone = '+00:00'");
        return $db;
    }
}
