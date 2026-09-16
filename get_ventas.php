<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Use GET."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $condiciones = [];
    $params = [];

    // ?id=X => detalle de una venta
    $filtrarPorId = isset($_GET['id']) && $_GET['id'] !== '';
    if ($filtrarPorId) {
        $condiciones[] = 'v.id = ?';
        $params[] = (int) $_GET['id'];
    }

    // ?estado=pendiente|pagado => filtro por estado
    if (isset($_GET['estado']) && trim((string) $_GET['estado']) !== '') {
        $estado = trim((string) $_GET['estado']);
        if (!in_array($estado, ['pendiente', 'pagado'], true)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El campo estado debe ser 'pendiente' o 'pagado'."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $condiciones[] = 'v.estado = ?';
        $params[] = $estado;
    }

    $sql = "SELECT v.*, (SELECT COUNT(*) FROM venta_fotos vf WHERE vf.venta_id = v.id) AS cantidad_fotos
            FROM ventas v";
    if (!empty($condiciones)) {
        $sql .= " WHERE " . implode(' AND ', $condiciones);
    }
    $sql .= " ORDER BY v.fecha DESC, v.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll();

    if ($filtrarPorId && empty($ventas)) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Venta no encontrada."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($ventas)) {
        // Trae las fotos de todas las ventas en una sola consulta
        $ids = array_column($ventas, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmtFoto = $pdo->prepare("
            SELECT vf.venta_id, vf.foto_id, vf.precio, fo.ruta, e.nombre AS evento_nombre
            FROM venta_fotos vf
            INNER JOIN fotos fo ON fo.id = vf.foto_id
            INNER JOIN eventos e ON e.id = fo.evento_id
            WHERE vf.venta_id IN ($placeholders)
            ORDER BY vf.venta_id, vf.id
        ");
        $stmtFoto->execute($ids);
        $fotos = $stmtFoto->fetchAll();

        $fotosPorVenta = [];
        foreach ($fotos as $foto) {
            $fotosPorVenta[$foto['venta_id']][] = [
                'foto_id'      => (int) $foto['foto_id'],
                'precio'       => $foto['precio'],
                'ruta'         => $foto['ruta'],
                'evento_nombre'=> $foto['evento_nombre'],
            ];
        }

        foreach ($ventas as &$venta) {
            $venta['id']      = (int) $venta['id'];
            $venta['cantidad_fotos'] = (int) $venta['cantidad_fotos'];
            $venta['fotos']   = $fotosPorVenta[$venta['id']] ?? [];
        }
        unset($venta);
    }

    echo json_encode(["status" => "success", "ventas" => $ventas], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al obtener las ventas: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}