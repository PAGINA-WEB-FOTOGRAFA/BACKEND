<?php
require_once __DIR__ . '/db.php';

// Mercado Pago avisa este endpoint cuando cambia el estado de un pago.
// Se responde siempre 200 para que MP no reintente innecesariamente.

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!is_array($data)) {
    parse_str((string) $input, $data);
}

$type      = $data['type'] ?? ($_GET['topic'] ?? '');
$paymentId = $data['data']['id'] ?? ($_GET['id'] ?? ($data['id'] ?? ''));

if ($type !== 'payment' && $type !== 'merchant_order') {
    http_response_code(200);
    echo json_encode(["status" => "ok", "message" => "Notificación ignorada."], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($paymentId === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Falta el id del pago."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pago = getPagoMP((string) $paymentId);

    if (empty($pago) || !isset($pago['status'])) {
        http_response_code(200);
        echo json_encode(["status" => "ok", "message" => "No se pudo verificar el pago."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Solo actúa cuando el pago fue aprobado
    if (($pago['status'] ?? '') === 'approved') {
        $ventaRef = (string) ($pago['external_reference'] ?? '');
        $ventaId  = (int) $ventaRef;

        if ($ventaId > 0) {
            $stmt = $pdo->prepare("SELECT id, nombre, apellido, whatsapp, email, total, estado FROM ventas WHERE id = ?");
            $stmt->execute([$ventaId]);
            $venta = $stmt->fetch();

            if ($venta && $venta['estado'] !== 'pagado') {
                $stmtUpd = $pdo->prepare("UPDATE ventas SET estado = 'pagado', mp_payment_id = ? WHERE id = ?");
                $stmtUpd->execute([(string) $paymentId, $ventaId]);

                $stmtFotos = $pdo->prepare("
                    SELECT vf.foto_id, vf.precio, fo.ruta, e.nombre AS evento_nombre
                    FROM venta_fotos vf
                    INNER JOIN fotos fo ON fo.id = vf.foto_id
                    INNER JOIN eventos e ON e.id = fo.evento_id
                    WHERE vf.venta_id = ?
                ");
                $stmtFotos->execute([$ventaId]);
                $fotos = $stmtFotos->fetchAll();

                enviarMailVenta($venta, $fotos);
            }
        }
    }

    http_response_code(200);
    echo json_encode(["status" => "ok"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Webhook MP error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(["status" => "ok", "message" => "Error interno no fatal: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Consulta el estado de un pago a la API de Mercado Pago.
 */
function getPagoMP(string $paymentId): array
{
    if (MP_ACCESS_TOKEN === '') {
        return [];
    }

    $ch = curl_init(MP_BASE_URL . '/v1/payments/' . $paymentId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Accept: application/json',
    ]);

    $respuestaRaw = curl_exec($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && $respuestaRaw !== false) {
        return json_decode($respuestaRaw, true) ?: [];
    }

    return [];
}

/**
 * Envía el correo a la administradora con los datos de la venta pagada.
 */
function enviarMailVenta(array $venta, array $fotos): void
{
    $listaFotos = count($fotos) > 0 ? '' : '<li>Sin fotos especificadas</li>';
    foreach ($fotos as $foto) {
        $listaFotos .= '<li>Foto ID ' . $foto['foto_id'] . ' — Evento: ' . $foto['evento_nombre'] . ' — ' . $foto['ruta'] . ' ($ ' . number_format((float) $foto['precio'], 2, ',', '.') . ')</li>';
    }

    $asunto = 'Venta CONFIRMADA #' . $venta['id'] . ' - Pago aprobado';

    $cuerpo = '
        <html>
        <body style="font-family: Arial, sans-serif; color: #333;">
            <h2 style="color: #27ae60;">Pago aprobado - Venta #' . $venta['id'] . '</h2>
            <p>El cliente pagó por Mercado Pago. Enviale las fotos a través de WhatsApp.</p>
            <table cellpadding="4" cellspacing="0">
                <tr><td><strong>Nombre:</strong></td><td>' . $venta['nombre'] . ' ' . $venta['apellido'] . '</td></tr>
                <tr><td><strong>WhatsApp:</strong></td><td>' . $venta['whatsapp'] . '</td></tr>
                <tr><td><strong>Email:</strong></td><td>' . $venta['email'] . '</td></tr>
                <tr><td><strong>Total:</strong></td><td>$ ' . number_format((float) $venta['total'], 2, ',', '.') . '</td></tr>
                <tr><td><strong>Estado:</strong></td><td>pagado</td></tr>
            </table>
            <h3>Fotos vendidas</h3>
            <ul>' . $listaFotos . '</ul>
            <p>Recordá enviarle las fotos al cliente apenas puedas.</p>
        </body>
        </html>
    ';

    $cabeceras  = "MIME-Version: 1.0\r\n";
    $cabeceras .= "Content-Type: text/html; charset=UTF-8\r\n";
    $cabeceras .= "From: no-reply@localhost\r\n";

    if (!mail(ADMIN_EMAIL, $asunto, $cuerpo, $cabeceras)) {
        error_log('No se pudo enviar el mail de la venta #' . $venta['id']);
    }
}