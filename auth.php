<?php
require_once __DIR__ . '/config.php';

function jsonErrorAuth(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(["status" => "error", "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function base64urlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64urlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Genera un token firmado (HMAC-SHA256) con vencimiento.
 */
function crearToken(array $payload): string
{
    $payload['iat'] = time();
    $payload['exp'] = time() + TOKEN_TTL_HORAS * 3600;

    $body = base64urlEncode(json_encode($payload));
    $sig  = hash_hmac('sha256', $body, TOKEN_SECRET);

    return $body . '.' . $sig;
}

/**
 * Valida firma y vencimiento. Devuelve el payload o null.
 */
function verificarToken(string $token): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 2) {
        return null;
    }

    [$body, $sig] = $partes;
    $esperado = hash_hmac('sha256', $body, TOKEN_SECRET);

    if (!hash_equals($esperado, $sig)) {
        return null;
    }

    $payload = json_decode((string) base64urlDecode($body), true);
    if (!is_array($payload)) {
        return null;
    }

    if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

/**
 * Extrae el token del header Authorization: Bearer ...
 */
function leerTokenBearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nombre => $valor) {
            if (strtolower($nombre) === 'authorization') {
                $header = $valor;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $coincidencia)) {
        return trim($coincidencia[1]);
    }

    return '';
}

/**
 * Exige autenticación de administrador. Responde 401 si falta o es inválido.
 * Devuelve el payload del token en caso de éxito.
 */
function requireAuth(): array
{
    $token = leerTokenBearer();

    if ($token === '') {
        jsonErrorAuth(401, 'Autenticación requerida. Enviá el header Authorization: Bearer <token>.');
    }

    $payload = verificarToken($token);
    if ($payload === null) {
        jsonErrorAuth(401, 'Token inválido o expirado. Volvé a iniciar sesión.');
    }

    return $payload;
}