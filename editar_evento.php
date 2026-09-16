<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Use POST."], JSON_UNESCAPED_UNICODE);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$esJson = strpos($contentType, 'application/json') !== false;

if ($esJson) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "JSON inválido."], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $data = $_POST;
}

$id = isset($data['id']) ? (int) $data['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo id es obligatorio."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Campos editables (solo se actualizan los que vienen completos)
$actualizaciones = [];
$params = [];

foreach (['nombre', 'lugar', 'fecha_evento', 'precio_foto', 'activo'] as $campo) {
    if (isset($data[$campo]) && trim((string) $data[$campo]) !== '') {
        $valor = trim((string) $data[$campo]);

        if ($campo === 'fecha_evento' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Formato de fecha_evento inválido. Use YYYY-MM-DD."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($campo === 'precio_foto' && (!is_numeric($valor) || (float) $valor < 0)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "precio_foto debe ser un número mayor o igual a 0."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($campo === 'activo' && !in_array((int) $valor, [0, 1], true)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El campo activo debe ser 0 o 1."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $actualizaciones[] = "$campo = ?";
        $params[] = $valor;
    }
}

// Fotos a eliminar (ids: array o CSV)
$fotosEliminar = [];
if (isset($data['fotos_eliminar'])) {
    $rawFotos = is_array($data['fotos_eliminar']) ? $data['fotos_eliminar'] : explode(',', (string) $data['fotos_eliminar']);
    foreach ($rawFotos as $fid) {
        $fid = (int) trim($fid);
        if ($fid > 0) {
            $fotosEliminar[] = $fid;
        }
    }
}
$fotosEliminar = array_values(array_unique($fotosEliminar));

// Crea el directorio uploads/ si hará falta para fotos nuevas
$uploadsDir = __DIR__ . '/uploads';
if (!empty($_FILES['fotos']['name']) && !is_dir($uploadsDir)) {
    if (!mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "No se pudo crear el directorio uploads/."], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Sube fotos nuevas (FormData con fotos[])
$archivosMovidos = [];
if (!$esJson && isset($_FILES['fotos']) && is_array($_FILES['fotos']['name'])) {
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
    $check = $pdo->prepare("SELECT id FROM eventos WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Evento no encontrado."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    // Actualiza los campos
    if (!empty($actualizaciones)) {
        $params[] = $id;
        $sql = "UPDATE eventos SET " . implode(', ', $actualizaciones) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
    }

    // Elimina fotos (BD + archivo físico)
    $fotosBorradas = [];
    if (!empty($fotosEliminar)) {
        $placeholders = implode(',', array_fill(0, count($fotosEliminar), '?'));

        $stmtSel = $pdo->prepare("SELECT id, ruta FROM fotos WHERE evento_id = ? AND id IN ($placeholders)");
        $stmtSel->execute(array_merge([$id], $fotosEliminar));
        $fotosPorBorrar = $stmtSel->fetchAll();

        $stmtDel = $pdo->prepare("DELETE FROM fotos WHERE evento_id = ? AND id IN ($placeholders)");
        $stmtDel->execute(array_merge([$id], $fotosEliminar));

        foreach ($fotosPorBorrar as $foto) {
            $archivo = __DIR__ . '/' . $foto['ruta'];
            if (file_exists($archivo)) {
                @unlink($archivo);
            }
            $fotosBorradas[] = [
                'id'   => (int) $foto['id'],
                'ruta' => $foto['ruta'],
            ];
        }
    }

    // Registra fotos nuevas
    $stmtFoto = $pdo->prepare("INSERT INTO fotos (evento_id, ruta) VALUES (?, ?)");
    $fotosAgregadas = [];
    foreach ($archivosMovidos as $archivo) {
        $stmtFoto->execute([$id, $archivo['ruta']]);
        $fotosAgregadas[] = [
            'id'   => (int) $pdo->lastInsertId(),
            'ruta' => $archivo['ruta'],
        ];
    }

    $pdo->commit();

    echo json_encode([
        "status"          => "success",
        "message"         => "Evento actualizado correctamente.",
        "id"              => $id,
        "fotos_agregadas" => $fotosAgregadas,
        "fotos_borradas"  => $fotosBorradas,
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
    echo json_encode(["status" => "error", "message" => "Error al actualizar el evento: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}