<?php
require_once __DIR__ . '/db.php';

// Config: correo de la fotógrafa que recibe las notificaciones de venta
$correoFotografa = 'fotografa@example.com';

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

$eventoId = isset($input['evento_id']) ? (int) $input['evento_id'] : 0;
$nombre   = trim((string) ($input['nombre'] ?? ''));
$apellido = trim((string) ($input['apellido'] ?? ''));
$whatsapp = trim((string) ($input['whatsapp'] ?? ''));
$email    = trim((string) ($input['email'] ?? ''));
$total    = trim((string) ($input['total'] ?? ''));
$fotosIdsEntrada = $input['fotos_ids'] ?? [];

if ($eventoId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo evento_id es obligatorio."], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($nombre === '' || $apellido === '' || $whatsapp === '' || $total === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Los campos nombre, apellido, whatsapp y total son obligatorios."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El email no es válido."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_numeric($total) || (float) $total < 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El total debe ser un número mayor o igual a 0."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normaliza fotos_ids (array de enteros o string separado por comas) a una lista de enteros
$fotosIds = [];
if (is_array($fotosIdsEntrada)) {
    foreach ($fotosIdsEntrada as $fid) {
        $fid = (int) $fid;
        if ($fid > 0) {
            $fotosIds[] = $fid;
        }
    }
} else {
    foreach (explode(',', (string) $fotosIdsEntrada) as $fid) {
        $fid = (int) trim($fid);
        if ($fid > 0) {
            $fotosIds[] = $fid;
        }
    }
}
$fotosIds = array_values(array_unique($fotosIds));
$fotosIdsCsv = implode(',', $fotosIds);

try {
    // Verifica que el evento exista
    $stmtEvento = $pdo->prepare("SELECT id, nombre, lugar FROM eventos WHERE id = ?");
    $stmtEvento->execute([$eventoId]);
    $evento = $stmtEvento->fetch();

    if (!$evento) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Evento no encontrado."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO ventas (evento_id, nombre, apellido, whatsapp, email, total, fotos_ids, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    $stmt->execute([$eventoId, $nombre, $apellido, $whatsapp, $email, $total, $fotosIdsCsv]);
    $ventaId = (int) $pdo->lastInsertId();

    // Obtiene las rutas de las fotos solicitadas para el correo
    $fotos = [];
    if (!empty($fotosIds)) {
        $placeholders = implode(',', array_fill(0, count($fotosIds), '?'));
        $stmtFotos = $pdo->prepare("SELECT id, ruta FROM fotos WHERE id IN ($placeholders) ORDER BY id ASC");
        $stmtFotos->execute($fotosIds);
        $fotos = $stmtFotos->fetchAll();
    }

    $listaFotos = count($fotos) > 0 ? '' : '<li>Sin fotos especificadas</li>';
    foreach ($fotos as $foto) {
        $listaFotos .= '<li>Foto ID ' . $foto['id'] . ' — Ruta: ' . $foto['ruta'] . '</li>';
    }

    $asunto = 'Nueva venta #' . $ventaId . ' - Evento: ' . $evento['nombre'];

    $cuerpo = '
        <html>
        <body style="font-family: Arial, sans-serif; color: #333;">
            <h2 style="color: #2c3e50;">Nueva venta de fotos</h2>
            <p>Se registró una nueva compra. Las fotos solicitadas deben enviarse manualmente al cliente.</p>
            <h3>Datos de la venta</h3>
            <table cellpadding="4" cellspacing="0">
                <tr><td><strong>Nº de venta:</strong></td><td>' . $ventaId . '</td></tr>
                <tr><td><strong>Evento:</strong></td><td>' . $evento['nombre'] . ' (' . $evento['lugar'] . ')</td></tr>
                <tr><td><strong>Total:</strong></td><td>$ ' . number_format((float) $total, 2, ',', '.') . '</td></tr>
                <tr><td><strong>Estado:</strong></td><td>pendiente</td></tr>
            </table>
            <h3>Datos del cliente</h3>
            <table cellpadding="4" cellspacing="0">
                <tr><td><strong>Nombre:</strong></td><td>' . $nombre . ' ' . $apellido . '</td></tr>
                <tr><td><strong>WhatsApp:</strong></td><td>' . $whatsapp . '</td></tr>
                <tr><td><strong>Email:</strong></td><td>' . $email . '</td></tr>
            </table>
            <h3>Fotos solicitadas</h3>
            <ul>' . $listaFotos . '</ul>
            <p>Recordá completar la venta y enviar las fotos al cliente.</p>
        </body>
        </html>
    ';

    $cabeceras  = "MIME-Version: 1.0\r\n";
    $cabeceras .= "Content-Type: text/html; charset=UTF-8\r\n";
    $cabeceras .= "From: no-reply@localhost\r\n";

    $mailEnviado = mail($correoFotografa, $asunto, $cuerpo, $cabeceras);

    echo json_encode([
        "status"        => "success",
        "message"       => "Venta registrada en estado pendiente.",
        "venta"         => [
            "id"              => $ventaId,
            "evento_id"       => $eventoId,
            "nombre"          => $nombre,
            "apellido"        => $apellido,
            "whatsapp"        => $whatsapp,
            "email"           => $email,
            "total"           => $total,
            "fotos_ids"       => $fotosIdsCsv,
            "estado"          => "pendiente",
        ],
        "email_enviado" => $mailEnviado,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar la venta: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}