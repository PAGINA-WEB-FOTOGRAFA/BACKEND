<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAuth();

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

$id = isset($input['id']) ? (int) $input['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo id es obligatorio."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Obtiene las rutas de las fotos antes de borrar
    $stmtFotos = $pdo->prepare("SELECT id, ruta FROM fotos WHERE evento_id = ?");
    $stmtFotos->execute([$id]);
    $fotos = $stmtFotos->fetchAll();

    $check = $pdo->prepare("SELECT id FROM eventos WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Evento no encontrado."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    // El FK con ON DELETE CASCADE borra las filas de fotos en BD
    $stmt = $pdo->prepare("DELETE FROM eventos WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    // Borra los archivos físicos del disco
    foreach ($fotos as $foto) {
        $archivo = __DIR__ . '/' . $foto['ruta'];
        if (file_exists($archivo)) {
            @unlink($archivo);
        }
    }

    echo json_encode([
        "status"          => "success",
        "message"         => "Evento eliminado correctamente.",
        "id"              => $id,
        "fotos_borradas"  => count($fotos),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar el evento: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}