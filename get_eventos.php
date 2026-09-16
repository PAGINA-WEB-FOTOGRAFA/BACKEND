<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Use GET."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ?id=X => detalle del evento con su lista de fotos
    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $id = (int) $_GET['id'];

        $stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
        $stmt->execute([$id]);
        $evento = $stmt->fetch();

        if (!$evento) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Evento no encontrado."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtFotos = $pdo->prepare("SELECT id, ruta FROM fotos WHERE evento_id = ? ORDER BY id ASC");
        $stmtFotos->execute([$id]);
        $evento['fotos'] = $stmtFotos->fetchAll();

        echo json_encode(["status" => "success", "evento" => $evento], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ?admin=true => todos los eventos (solo administrador)
    if (isset($_GET['admin']) && $_GET['admin'] === 'true') {
        requireAuth();
        $eventos = $pdo->query("SELECT * FROM eventos ORDER BY fecha_evento DESC, fecha_creacion DESC")->fetchAll();
    } else {
        // Por defecto (cliente) => solo eventos activos
        $eventos = $pdo->query("SELECT * FROM eventos WHERE activo = 1 ORDER BY fecha_evento DESC, fecha_creacion DESC")->fetchAll();
    }

    echo json_encode(["status" => "success", "eventos" => $eventos], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al obtener eventos: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}