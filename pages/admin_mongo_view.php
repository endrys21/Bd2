<?php
/**
 * admin_mongo_view.php
 * Vista incluida dentro del panel de administrador.
 * Consulta directamente MongoDB y muestra artistas y obras en tablas.
 */

include_once '../scripts/db_mongo.php';

$error_mongo = null;
$artistas    = [];
$obras       = [];
$filtro_gen  = isset($_GET['genero']) ? trim($_GET['genero']) : '';
$filtro_est  = isset($_GET['status']) ? trim($_GET['status']) : '';

try {
    $mongo = mongo_connect();

    // ── 1. LEER ARTISTAS ──────────────────────────────────────────────────
    $query_art = new MongoDB\Driver\Query(
        [],                                     // filtro: todos
        ['sort' => ['nombre' => 1], 'limit' => 200]
    );
    $cursor_art = $mongo->executeQuery(MONGO_DB . '.artistas', $query_art);
    foreach ($cursor_art as $doc) {
        $artistas[] = $doc;
    }

    // ── 2. LEER OBRAS (con filtros opcionales) ────────────────────────────
    $filtro_obras = [];
    if (!empty($filtro_gen)) $filtro_obras['genero'] = $filtro_gen;
    if (!empty($filtro_est)) $filtro_obras['status'] = $filtro_est;

    $query_obras = new MongoDB\Driver\Query(
        $filtro_obras,
        ['sort' => ['fecha_publicacion' => -1], 'limit' => 200]
    );
    $cursor_obras = $mongo->executeQuery(MONGO_DB . '.obras', $query_obras);
    foreach ($cursor_obras as $doc) {
        $obras[] = $doc;
    }

} catch (Exception $e) {
    $error_mongo = $e->getMessage();
}

// Helper: convierte un stdClass de detalles a string legible
function detalles_a_texto($obj): string {
    if (!is_object($obj) && !is_array($obj)) return '—';
    $partes = [];
    foreach ((array)$obj as $k => $v) {
        $partes[] = "<span style='color:#888;'>$k:</span> " . htmlspecialchars((string)$v);
    }
    return implode('<br>', $partes);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    .mongo-view { font-family: 'Segoe UI', sans-serif; }
    .mongo-view h2 { color: #2c3e50; border-left: 4px solid #2980b9; padding-left: 12px; margin-top: 0; }
    .mongo-view h3 { color: #34495e; margin-top: 32px; margin-bottom: 10px; }

    .badge-db { display: inline-block; background: #2980b9; color: white; font-size: 0.78em; padding: 3px 10px; border-radius: 12px; vertical-align: middle; margin-left: 8px; }
    .badge-count { display: inline-block; background: #7f8c8d; color: white; font-size: 0.78em; padding: 3px 8px; border-radius: 10px; vertical-align: middle; margin-left: 6px; }

    .filter-bar { background: #f0f4f8; border: 1px solid #d1dbe8; border-radius: 8px; padding: 14px 18px; display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; }
    .filter-bar label { display: block; font-size: 0.82em; color: #555; margin-bottom: 4px; }
    .filter-bar select, .filter-bar input { padding: 7px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 0.92em; background: white; }
    .btn-filter { background: #2980b9; color: white; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; font-weight: bold; }
    .btn-clear   { color: #888; text-decoration: none; font-size: 0.88em; padding: 8px 0; }

    /* Tablas */
    .mongo-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 0.88em; }
    .mongo-table th { background: #2c3e50; color: white; padding: 10px 12px; text-align: left; font-weight: 600; }
    .mongo-table td { padding: 10px 12px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
    .mongo-table tr:nth-child(even) td { background: #f8f9fa; }
    .mongo-table tr:hover td { background: #e8f4fd; }
    .genero-pill { display: inline-block; background: #d6eaf8; color: #1a5276; padding: 2px 9px; border-radius: 10px; font-size: 0.82em; margin: 2px 1px; }
    .status-disp { color: #27ae60; font-weight: bold; }
    .status-vend { color: #e74c3c; font-weight: bold; }
    .oid { font-family: monospace; font-size: 0.82em; color: #7f8c8d; }

    .empty-msg { color: #888; text-align: center; padding: 30px; font-style: italic; }
    .error-box { background: #f8d7da; border: 1px solid #dc3545; border-radius: 8px; padding: 16px 20px; color: #721c24; }
    .error-box code { display: block; margin-top: 8px; font-size: 0.88em; word-break: break-all; }

    .section-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #dee2e6; padding-bottom: 8px; margin-bottom: 14px; }
    .link-migrar { font-size: 0.88em; color: #27ae60; text-decoration: none; }
</style>
</head>
<body>
<div class="mongo-view">
    <h2>🗄️ Datos en MongoDB <span class="badge-db">galeria_db_mongo</span></h2>

    <?php if ($error_mongo): ?>
        <div class="error-box">
            <strong>❌ No se pudo conectar a MongoDB.</strong>
            <code><?= htmlspecialchars($error_mongo) ?></code>
            <p style="margin-top:10px; font-size:0.9em;">
                Asegúrate de que el contenedor <code>mongo-museo</code> esté corriendo
                (<code>docker-compose up -d</code>) y de que la extensión <code>mongodb</code>
                esté habilitada en tu <code>php.ini</code>.
                <br><a href="admin_panel.php?view=mongo_migrar">→ Ir a Migración</a>
            </p>
        </div>

    <?php else: ?>

        <!-- ══ ARTISTAS ══════════════════════════════════════════════════════ -->
        <div class="section-header">
            <h3 style="margin:0;">👤 Colección: <code>artistas</code>
                <span class="badge-count"><?= count($artistas) ?> documentos</span>
            </h3>
            <a href="admin_panel.php?view=mongo_migrar" class="link-migrar">⟳ Actualizar datos</a>
        </div>

        <?php if (empty($artistas)): ?>
            <p class="empty-msg">No hay artistas en MongoDB. <a href="admin_panel.php?view=mongo_migrar">Ejecuta la migración primero →</a></p>
        <?php else: ?>
        <table class="mongo-table">
            <thead>
                <tr>
                    <th>_id (MySQL)</th>
                    <th>Nombre</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Nac.</th>
                    <th>Teléfono</th>
                    <th>Géneros</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($artistas as $a): ?>
                <tr>
                    <td class="oid"><?= htmlspecialchars((string)$a->_id) ?></td>
                    <td><strong><?= htmlspecialchars($a->nombre . ' ' . $a->apellido) ?></strong></td>
                    <td><?= htmlspecialchars($a->usuario ?? '—') ?></td>
                    <td><?= htmlspecialchars($a->email ?? '—') ?></td>
                    <td><?= htmlspecialchars($a->nacionalidad ?? '—') ?></td>
                    <td><?= htmlspecialchars($a->telefono ?? '—') ?></td>
                    <td>
                        <?php
                        $gens = isset($a->generos) ? (array)$a->generos : [];
                        if (empty($gens)) {
                            echo '<span style="color:#bbb;">Sin géneros</span>';
                        } else {
                            foreach ($gens as $g) {
                                echo '<span class="genero-pill">' . htmlspecialchars($g) . '</span>';
                            }
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>


        <!-- ══ OBRAS ══════════════════════════════════════════════════════════ -->
        <h3 style="margin-top:36px;">🖼️ Colección: <code>obras</code>
            <span class="badge-count"><?= count($obras) ?> documentos</span>
        </h3>

        <!-- Filtros -->
        <form method="GET" class="filter-bar">
            <input type="hidden" name="view" value="mongo_ver">
            <div>
                <label>Género:</label>
                <select name="genero">
                    <option value="">Todos</option>
                    <?php
                    $generos_disponibles = ['Pintura','Escultura','Fotografía','Ceramica','Orfebrería'];
                    foreach ($generos_disponibles as $gd) {
                        $sel = ($filtro_gen === $gd) ? 'selected' : '';
                        echo "<option value='$gd' $sel>$gd</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label>Estado:</label>
                <select name="status">
                    <option value="">Todos</option>
                    <option value="disponible" <?= $filtro_est === 'disponible' ? 'selected' : '' ?>>Disponible</option>
                    <option value="vendido"    <?= $filtro_est === 'vendido'    ? 'selected' : '' ?>>Vendido</option>
                </select>
            </div>
            <button type="submit" class="btn-filter">🔍 Filtrar</button>
            <a href="admin_panel.php?view=mongo_ver" class="btn-clear">✕ Limpiar</a>
        </form>

        <?php if (empty($obras)): ?>
            <p class="empty-msg">No hay obras en MongoDB que coincidan con el filtro. <a href="admin_panel.php?view=mongo_migrar">Ejecuta la migración →</a></p>
        <?php else: ?>
        <table class="mongo-table">
            <thead>
                <tr>
                    <th>_id</th>
                    <th>Nombre Archivo</th>
                    <th>Artista ID</th>
                    <th>Género</th>
                    <th>Precio</th>
                    <th>Estado</th>
                    <th>Fecha Publicación</th>
                    <th>Detalles Técnicos</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($obras as $o): ?>
                <?php
                $status    = $o->status ?? 'desconocido';
                $cls_status = ($status === 'disponible') ? 'status-disp' : 'status-vend';
                ?>
                <tr>
                    <td class="oid"><?= htmlspecialchars((string)$o->_id) ?></td>
                    <td style="max-width:160px; word-break:break-all; font-size:0.82em;"><?= htmlspecialchars($o->nombre ?? '—') ?></td>
                    <td class="oid"><?= htmlspecialchars((string)($o->id_artista ?? '—')) ?></td>
                    <td><span class="genero-pill"><?= htmlspecialchars($o->genero ?? '—') ?></span></td>
                    <td>$<?= number_format((float)($o->precio ?? 0), 2) ?></td>
                    <td class="<?= $cls_status ?>"><?= strtoupper(htmlspecialchars($status)) ?></td>
                    <td style="white-space:nowrap; font-size:0.85em;"><?= htmlspecialchars($o->fecha_publicacion ?? '—') ?></td>
                    <td style="font-size: 0.83em; line-height: 1.6;"><?= detalles_a_texto($o->detalles_tecnicos ?? null) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php endif; ?>
</div>
</body>
</html>
