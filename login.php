<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Use POST."], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "JSON inválido."], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario  = trim((string) ($input['usuario'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($usuario === '' || $password === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Los campos usuario y password son obligatorios."], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($usuario !== ADMIN_USER || !password_verify($password, ADMIN_PASS_HASH)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Credenciales inválidas."], JSON_UNESCAPED_UNICODE);
    exit;
}

$token   = crearToken(['usuario' => $usuario]);
$payload = verificarToken($token);

echo json_encode([
    "status"  => "success",
    "message" => "Login correcto.",
    "token"   => $token,
    "expira"  => date('Y-m-d H:i:s', $payload['exp']),
], JSON_UNESCAPED_UNICODE);