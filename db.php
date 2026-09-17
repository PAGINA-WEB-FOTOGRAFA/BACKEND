<?php
require_once __DIR__ . '/config.php';

// Credenciales locales (Laragon) para desarrollo.
// En producción (DonWeb) usá:
//   $host = "localhost";
//   $db   = "l0081231_foto_db";
//   $user = "l0081231_foto_db";
//   $pass = "biruRU95re";
$host = "localhost";
$db   = "fotografo_db";
$user = "root";
$pass = ""; // En Laragon por defecto va vacío

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Error de conexión: " . $e->getMessage(),
    ]);
    exit;
}