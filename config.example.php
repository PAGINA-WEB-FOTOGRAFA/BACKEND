<?php
// Configuración general de la API. Copiá este archivo como "config.php"
// y completá tus valores (config.php NO se sube al repositorio).

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==== Mercado Pago ====
// Access token de la cuenta vendedora (panel de desarrolladores > tus aplicaciones).
// En producción empieza con APP_USR-xxxx. Durante pruebas se usa el token de
// producción + usuarios de prueba (@testuser.com) para simular compras en sandbox.
define('MP_ACCESS_TOKEN', '');
define('MP_BASE_URL', 'https://api.mercadopago.com');

// ==== Notificaciones y redirecciones ====
// URL pública del backend. Se usa para las imágenes de las fotos en la order,
// y es la misma URL que tenés que configurar como webhook en el panel de MP
// (Tus integraciones > Webhooks) apuntando a https://TU_DOMINIO/webhook_mp.php.
// En local podés exponerla con ngrok (ej: https://xxxx.ngrok-free.app).
// Dejalo en '' hasta tener una URL pública.
define('BACKEND_URL', '');

// URLs de la web del cliente a donde vuelve el usuario tras pagar en Mercado Pago.
define('WEB_SUCCESS_URL', '');
define('WEB_PENDING_URL', '');
define('WEB_FAILURE_URL', '');

// ==== Notificaciones por email ====
// Gmail de la administradora que recibe la venta cuando el pago se confirma.
define('ADMIN_EMAIL', 'tu_email@example.com');

// Remitente de los correos (Gmail obliga a que coincida con la cuenta SMTP autenticada).
define('MAIL_FROM', ADMIN_EMAIL);

// ==== Acceso administrador ====
// Usuario y contraseña (hash) del panel de administración.
// Para generar el hash: php -r "echo password_hash('TU_CLAVE', PASSWORD_DEFAULT);"
define('ADMIN_USER', 'admin@example.com');
define('ADMIN_PASS_HASH', '');

// Clave secreta para firmar los tokens. Generala con: php -r "echo bin2hex(random_bytes(32));"
define('TOKEN_SECRET', '');

// Horas de validez del token de sesión.
define('TOKEN_TTL_HORAS', 12);