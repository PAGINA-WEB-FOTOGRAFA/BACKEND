<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Use POST."], JSON_UNESCAPED_UNICODE);
    exit;
}

$nombre      = trim((string) ($_POST['nombre'] ?? ''));
$lugar       = trim((string) ($_POST['lugar'] ?? ''));
$fechaEvento = trim((string) ($_POST['fecha_evento'] ?? ''));
$precioFoto  = trim((string) ($_POST['precio_foto'] ?? ''));

if ($nombre === '' || $lugar === '' || $fechaEvento === '' || $precioFoto === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Los campos nombre, lugar, fecha_evento y precio_foto son obligatorios."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEvento)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Formato de fecha_evento inválido. Use YYYY-MM-DD."], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_numeric($precioFoto) || (float) $precioFoto < 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "precio_foto debe ser un número mayor o igual a 0."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Crea el directorio uploads/ si no existe
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    if (!mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "No se pudo crear el directorio uploads/."], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Sube las imágenes con nombre único (timestamp + uniqid) antes de tocar la BD
$archivosMovidos = [];
if (isset($_FILES['fotos']) && is_array($_FILES['fotos']['name'])) {
    $mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    foreach ($_FILES['fotos']['name'] as $i => $nombreOriginal) {
        if ((int) $_FILES['fotos']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $mime = finfo_file($finfo, $_FILES['fotos']['tmp_name'][$i]);

        if ($nombreOriginal === '' || !isset($mimes[$mime])) {
            continue;
        }

        $nombreArchivo = time() . '_' . uniqid() . '.' . $mimes[$mime];
        $rutaFisica    = $uploadsDir . '/' . $nombreArchivo;

        if (move_uploaded_file($_FILES['fotos']['tmp_name'][$i], $rutaFisica)) {
            $archivosMovidos[] = [
                'rutaFisica' => $rutaFisica,
                'ruta'       => 'uploads/' . $nombreArchivo,
            ];
        }
    }

    finfo_close($finfo);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO eventos (nombre, lugar, fecha_evento, precio_foto) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nombre, $lugar, $fechaEvento, $precioFoto]);
    $eventoId = (int) $pdo->lastInsertId();

    $stmtFoto = $pdo->prepare("INSERT INTO fotos (evento_id, ruta) VALUES (?, ?)");
    $fotosRegistradas = [];
    foreach ($archivosMovidos as $archivo) {
        $stmtFoto->execute([$eventoId, $archivo['ruta']]);
        $fotosRegistradas[] = [
            'id'   => (int) $pdo->lastInsertId(),
            'ruta' => $archivo['ruta'],
        ];
    }

    $pdo->commit();

    echo json_encode([
        "status"  => "success",
        "message" => "Evento creado correctamente.",
        "evento"  => [
            "id"           => $eventoId,
            "nombre"       => $nombre,
            "lugar"        => $lugar,
            "fecha_evento" => $fechaEvento,
            "precio_foto"  => $precioFoto,
            "activo"       => 1,
            "fotos"        => $fotosRegistradas,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($archivosMovidos as $archivo) {
        if (file_exists($archivo['rutaFisica'])) {
            @unlink($archivo['rutaFisica']);
        }
    }

    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al crear el evento: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}