<?php
// Configuración general de la API.

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==== Mercado Pago ====
// Access token de la cuenta vendedora (panel de desarrolladores > tus aplicaciones).
// Dejalo en '' mientras no tengas el token; reemplazalo por tu valor real
// (en sandbox suele ser TEST-xxxx, en producción APP_USR-xxxx).
define('MP_ACCESS_TOKEN', '');
define('MP_BASE_URL', 'https://api.mercadopago.com');

// ==== Notificaciones y redirecciones ====
// URL pública del backend (para el webhook de Mercado Pago).
// En local podés exponerla con ngrok (ej: https://xxxx.ngrok-free.app).
// Dejalo en '' hasta tener una URL pública.
define('BACKEND_URL', '');

// URLs de la web del cliente a donde vuelve el usuario tras pagar en Mercado Pago.
define('WEB_SUCCESS_URL', '');
define('WEB_PENDING_URL', '');
define('WEB_FAILURE_URL', '');

// ==== Notificaciones por email ====
// Gmail de la administradora que recibe la venta cuando el pago se confirma.
define('ADMIN_EMAIL', 'webxiadev@gmail.com');