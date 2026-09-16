<?php
require_once __DIR__ . '/db.php';

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

$nombre     = trim((string) ($input['nombre'] ?? ''));
$apellido   = trim((string) ($input['apellido'] ?? ''));
$whatsapp   = trim((string) ($input['whatsapp'] ?? ''));
$email      = trim((string) ($input['email'] ?? ''));
$fotosEntrada = $input['fotos_ids'] ?? [];

if ($nombre === '' || $apellido === '' || $whatsapp === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Los campos nombre, apellido y whatsapp son obligatorios."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El email no es válido."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normaliza fotos_ids (array o CSV) a lista de enteros
$fotosIds = is_array($fotosEntrada) ? $fotosEntrada : explode(',', (string) $fotosEntrada);
$fotosIds = array_filter(array_map('intval', $fotosIds), fn ($id) => $id > 0);
$fotosIds = array_values(array_unique($fotosIds));

if (empty($fotosIds)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Seleccioná al menos una foto."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Lee las fotos con el evento y precio correspondiente (el total se calcula en el servidor)
    $placeholders = implode(',', array_fill(0, count($fotosIds), '?'));
    $stmtFotos = $pdo->prepare("
        SELECT f.id, f.ruta, e.nombre AS evento_nombre, e.precio_foto
        FROM fotos f
        INNER JOIN eventos e ON e.id = f.evento_id
        WHERE f.id IN ($placeholders)
    ");
    $stmtFotos->execute($fotosIds);
    $fotos = $stmtFotos->fetchAll();

    if (count($fotos) !== count($fotosIds)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Algunas fotos seleccionadas no existen."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Calcula el total sumando el precio de cada foto según su evento
    $total = 0;
    foreach ($fotos as $foto) {
        $total += (float) $foto['precio_foto'];
    }
    $totalStr = number_format($total, 2, '.', '');

    $pdo->beginTransaction();

    $stmtVenta = $pdo->prepare("INSERT INTO ventas (nombre, apellido, whatsapp, email, total, estado) VALUES (?, ?, ?, ?, ?, 'pendiente')");
    $stmtVenta->execute([$nombre, $apellido, $whatsapp, $email, $totalStr]);
    $ventaId = (int) $pdo->lastInsertId();

    $stmtVf = $pdo->prepare("INSERT INTO venta_fotos (venta_id, foto_id, precio) VALUES (?, ?, ?)");
    foreach ($fotos as $foto) {
        $stmtVf->execute([$ventaId, $foto['id'], number_format((float) $foto['precio_foto'], 2, '.', '')]);
    }

    // Crea la preferencia de pago en Mercado Pago
    $items = [];
    foreach ($fotos as $foto) {
        $item = [
            'id'          => (string) $foto['id'],
            'title'       => 'Foto - ' . $foto['evento_nombre'],
            'quantity'    => 1,
            'unit_price'  => (float) $foto['precio_foto'],
            'currency_id' => 'ARS',
        ];
        if (BACKEND_URL !== '') {
            $item['picture_url'] = BACKEND_URL . '/' . $foto['ruta'];
        }
        $items[] = $item;
    }

    $preferencia = [
        'items'                => $items,
        'external_reference'   => (string) $ventaId,
        'statement_descriptor' => 'FOTOGRAFIAS',
        'auto_return'          => 'approved',
    ];

    if (BACKEND_URL !== '') {
        $preferencia['notification_url'] = BACKEND_URL . '/webhook_mp.php';
    }

    $urlsWeb = array_filter([WEB_SUCCESS_URL, WEB_PENDING_URL, WEB_FAILURE_URL]);
    if (count($urlsWeb) === 3) {
        $preferencia['back_urls'] = [
            'success' => WEB_SUCCESS_URL,
            'pending' => WEB_PENDING_URL,
            'failure' => WEB_FAILURE_URL,
        ];
    }

    $respuesta = crearPreferenciaMP($preferencia);

    if (($respuesta['success'] ?? false) === false) {
        $pdo->rollBack();
        http_response_code(502);
        echo json_encode(["status" => "error", "message" => "No se pudo iniciar el pago en Mercado Pago: " . ($respuesta['message'] ?? 'error desconocido')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtUpd = $pdo->prepare("UPDATE ventas SET mp_preference_id = ? WHERE id = ?");
    $stmtUpd->execute([$respuesta['preference_id'], $ventaId]);

    $pdo->commit();

    echo json_encode([
        "status"        => "success",
        "message"       => "Venta registrada. Redirigiendo a Mercado Pago...",
        "venta_id"      => $ventaId,
        "mp_preference_id" => $respuesta['preference_id'],
        "init_point"    => $respuesta['init_point'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar la venta: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Crea una preferencia de pago en Mercado Pago.
 * @return array ['success' => bool, 'preference_id' => string, 'init_point' => string, 'message' => string]
 */
function crearPreferenciaMP(array $payload): array
{
    if (MP_ACCESS_TOKEN === '') {
        return ['success' => false, 'message' => 'Access token de Mercado Pago no configurado.'];
    }

    $ch = curl_init(MP_BASE_URL . '/checkout/preferences');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

    $respuestaRaw = curl_exec($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        return ['success' => false, 'message' => $curlError];
    }

    $respuesta = json_decode($respuestaRaw, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($respuesta['id']) && isset($respuesta['init_point'])) {
        return [
            'success'       => true,
            'preference_id' => (string) $respuesta['id'],
            'init_point'    => (string) $respuesta['init_point'],
        ];
    }

    $detalle = $respuesta['message'] ?? $respuesta['error'] ?? "HTTP $httpCode";
    if (isset($respuesta['cause'][0]['description'])) {
        $detalle .= ' - ' . $respuesta['cause'][0]['description'];
    }

    return ['success' => false, 'message' => $detalle];
}