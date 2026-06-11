<?php
/**
 * admin_mongo_migrate.php
 * Vista incluida dentro del panel de administrador.
 * Lee artistas y obras de MySQL, los transforma al esquema BSON
 * (documentos embebidos según género) y los inserta en MongoDB.
 */

// $conexion ya está disponible desde admin_panel.php (db.php)
include_once '../scripts/db_mongo.php';

$resultado_migracion = null;  // null = todavía no se ejecutó

// ──────────────────────────────────────────────────────────────────────────────
// ACCIÓN: El admin hace clic en "Ejecutar Migración"
// ──────────────────────────────────────────────────────────────────────────────
if (isset($_POST['migrar'])) {

    $errores  = [];
    $totales  = ['artistas' => 0, 'obras' => 0];

    try {
        $mongo = mongo_connect();

        // ── 1. MIGRAR ARTISTAS ─────────────────────────────────────────────
        // Obtiene artistas + sus géneros desde la tabla intermedia genero_artista
        $sql_artistas = "
            SELECT a.*,
                   GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR '|') AS generos_nombres
            FROM   artista a
            LEFT JOIN genero_artista ga ON ga.id_artista = a.id_artista
            LEFT JOIN genero g          ON g.ID = ga.id_genero
            GROUP BY a.id_artista
        ";
        $res_art = mysqli_query($conexion, $sql_artistas);
        if (!$res_art) {
            throw new Exception("Error MySQL (artistas): " . mysqli_error($conexion));
        }

        $bulk_art = new MongoDB\Driver\BulkWrite(['ordered' => false]);

        // Limpiar colección antes de reinsertar (idempotente)
        $cmd_drop_art = new MongoDB\Driver\Command(['drop' => 'artistas']);
        try { $mongo->executeCommand(MONGO_DB, $cmd_drop_art); } catch (Exception $e) { /* ignora si no existe */ }

        while ($a = mysqli_fetch_assoc($res_art)) {
            $generos_arr = !empty($a['generos_nombres'])
                ? explode('|', $a['generos_nombres'])
                : [];

            $doc = [
                '_id'              => (int)$a['id_artista'],
                'nombre'           => $a['nombre'],
                'apellido'         => $a['apellido'],
                'email'            => $a['email'],
                'fecha_nacimiento' => $a['fecha_nacimiento'] !== '0000-00-00' ? $a['fecha_nacimiento'] : null,
                'nacionalidad'     => $a['nacionalidad'],
                'usuario'          => $a['usuario'],
                'telefono'         => (string)$a['telefono'],
                'foto_perfil'      => (int)$a['foto_perfil'],
                'generos'          => $generos_arr,
            ];
            $bulk_art->insert($doc);
            $totales['artistas']++;
        }

        if ($totales['artistas'] > 0) {
            $mongo->executeBulkWrite(MONGO_DB . '.artistas', $bulk_art);
        }

        // ── 2. MIGRAR OBRAS (con detalles técnicos embebidos) ─────────────
        // Mapas de tablas técnicas por id_genero
        $tablas_tecnicas = [
            1 => ['tabla' => 'pintura',    'pk' => 'id_pintura',    'campos' => ['tecnica','soporte','alto','ancho']],
            2 => ['tabla' => 'escultura',  'pk' => 'id_escultura',  'campos' => ['material','peso','alto','ancho']],
            3 => ['tabla' => 'fotografia', 'pk' => 'id_fotografia', 'campos' => ['tecnica','papel','alto','ancho']],
            4 => ['tabla' => 'ceramica',   'pk' => 'id_ceramica',   'campos' => ['arcilla','tecnica','peso','alto','ancho']],
            5 => ['tabla' => 'orferbreria','pk' => 'id_orfebreria', 'campos' => ['material','tecnica','peso']],
        ];

        $nombre_genero = [
            1 => 'Pintura', 2 => 'Escultura', 3 => 'Fotografía',
            4 => 'Ceramica', 5 => 'Orfebrería'
        ];

        $sql_obras = "SELECT * FROM obra ORDER BY id_obra";
        $res_obras = mysqli_query($conexion, $sql_obras);
        if (!$res_obras) {
            throw new Exception("Error MySQL (obras): " . mysqli_error($conexion));
        }

        $bulk_obras = new MongoDB\Driver\BulkWrite(['ordered' => false]);

        $cmd_drop_obras = new MongoDB\Driver\Command(['drop' => 'obras']);
        try { $mongo->executeCommand(MONGO_DB, $cmd_drop_obras); } catch (Exception $e) { /* ignora si no existe */ }

        while ($o = mysqli_fetch_assoc($res_obras)) {
            $id_genero = (int)$o['id_genero'];
            $id_obra   = (int)$o['id_obra'];

            // Consultar tabla técnica correspondiente
            $detalles = new stdClass();   // vacío por defecto
            if (isset($tablas_tecnicas[$id_genero])) {
                $meta  = $tablas_tecnicas[$id_genero];
                $tabla = $meta['tabla'];
                $pk    = $meta['pk'];
                $sql_t = "SELECT * FROM `$tabla` WHERE `$pk` = $id_obra LIMIT 1";
                $res_t = mysqli_query($conexion, $sql_t);
                if ($res_t && $fila_t = mysqli_fetch_assoc($res_t)) {
                    // Solo guardar los campos técnicos (excluir la PK)
                    foreach ($meta['campos'] as $campo) {
                        if (isset($fila_t[$campo])) {
                            $val = $fila_t[$campo];
                            // Convertir numéricos
                            if (is_numeric($val)) $val = $val + 0;
                            $detalles->$campo = $val;
                        }
                    }
                }
            }

            $doc_obra = [
                '_id'              => $id_obra,
                'id_artista'       => (int)$o['id_artista'],
                'genero'           => $nombre_genero[$id_genero] ?? 'Desconocido',
                'nombre'           => $o['nombre'],
                'precio'           => (float)$o['precio'],
                'fecha_publicacion'=> $o['fecha_publicacion'],
                'status'           => $o['status'],
                'detalles_tecnicos'=> $detalles,
            ];

            $bulk_obras->insert($doc_obra);
            $totales['obras']++;
        }

        if ($totales['obras'] > 0) {
            $mongo->executeBulkWrite(MONGO_DB . '.obras', $bulk_obras);

            // Crear índices útiles
            $cmd_idx1 = new MongoDB\Driver\Command([
                'createIndexes' => 'obras',
                'indexes' => [
                    ['key' => ['id_artista' => 1], 'name' => 'idx_artista'],
                    ['key' => ['genero'     => 1], 'name' => 'idx_genero'],
                    ['key' => ['status'     => 1], 'name' => 'idx_status'],
                ]
            ]);
            $mongo->executeCommand(MONGO_DB, $cmd_idx1);
        }

        $resultado_migracion = ['ok' => true, 'totales' => $totales];

    } catch (Exception $e) {
        $resultado_migracion = ['ok' => false, 'error' => $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    .mongo-section { font-family: 'Segoe UI', sans-serif; }
    .mongo-section h2 { color: #2c3e50; border-left: 4px solid #27ae60; padding-left: 12px; }
    .info-box { background: #eafaf1; border: 1px solid #27ae60; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
    .info-box p { margin: 6px 0; color: #555; }
    .info-box strong { color: #1a1a1a; }
    .schema-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
    .schema-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
    .schema-card h4 { margin: 0 0 10px; color: #34495e; font-size: 0.95em; text-transform: uppercase; letter-spacing: 0.05em; }
    .schema-card pre { background: #2c3e50; color: #ecf0f1; padding: 12px; border-radius: 5px; font-size: 0.78em; overflow-x: auto; margin: 0; white-space: pre-wrap; }

    .btn-migrar { background: #27ae60; color: white; border: none; padding: 14px 32px; font-size: 1.05em; font-weight: bold; border-radius: 8px; cursor: pointer; transition: background 0.2s; }
    .btn-migrar:hover { background: #1e8449; }

    .result-ok  { background: #d4edda; border: 1px solid #28a745; color: #155724; border-radius: 8px; padding: 18px 24px; margin-top: 20px; }
    .result-err { background: #f8d7da; border: 1px solid #dc3545; color: #721c24; border-radius: 8px; padding: 18px 24px; margin-top: 20px; }
    .result-ok h3, .result-err h3 { margin: 0 0 8px; }
    .stat-row { display: flex; gap: 30px; margin-top: 10px; }
    .stat-box { background: white; border-radius: 6px; padding: 12px 20px; text-align: center; min-width: 120px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
    .stat-box .num  { font-size: 2em; font-weight: bold; color: #27ae60; }
    .stat-box .lbl  { font-size: 0.8em; color: #666; margin-top: 2px; }
    .warning-ext { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; color: #856404; font-size: 0.92em; }
</style>
</head>
<body>
<div class="mongo-section">
    <h2>🚀 Migrar Datos a MongoDB</h2>

    <div class="warning-ext">
        <strong>⚠️ Requisito previo:</strong> Esta función necesita la extensión <code>mongodb</code> habilitada en PHP
        (línea <code>extension=php_mongodb.dll</code> en tu <code>php.ini</code> de XAMPP) y el contenedor
        <code>mongo-museo</code> corriendo (<code>docker-compose up -d</code>).
    </div>

    <div class="info-box">
        <p><strong>¿Qué hace este proceso?</strong></p>
        <p>Lee todos los <strong>artistas</strong> y <strong>obras</strong> actuales de MySQL, aplica el esquema
           documental definido (campos polimórficos embebidos según el género de cada obra) y los inserta en la
           base de datos <strong>galeria_db_mongo</strong> en MongoDB.</p>
        <p>⚡ Cada ejecución <strong>limpia y re-inserta</strong> las colecciones (operación idempotente, puedes correrla múltiples veces sin duplicados).</p>
    </div>

    <div class="schema-grid">
        <div class="schema-card">
            <h4>📋 Colección: artistas</h4>
            <pre>{
  "_id": 70810283,
  "nombre": "albaricoke",
  "apellido": "munos",
  "email": "...",
  "fecha_nacimiento": "2003-12-19",
  "nacionalidad": "Nigeria",
  "usuario": "poche4",
  "generos": ["Pintura","Escultura"]
}</pre>
        </div>
        <div class="schema-card">
            <h4>🖼️ Colección: obras (detalles embebidos)</h4>
            <pre>{
  "_id": 11,
  "id_artista": 70810283,
  "genero": "Escultura",
  "nombre": "69ad6dbd1c5e6.jpg",
  "precio": 0,
  "status": "disponible",
  "detalles_tecnicos": {
    "material": "Mármol",
    "peso": 45.5,
    "alto": 120, "ancho": 50
  }
}</pre>
        </div>
    </div>

    <form method="POST">
        <button type="submit" name="migrar" class="btn-migrar">
            ▶ Ejecutar Migración MySQL → MongoDB
        </button>
    </form>

    <?php if ($resultado_migracion !== null): ?>
        <?php if ($resultado_migracion['ok']): ?>
            <div class="result-ok">
                <h3>✅ Migración completada con éxito</h3>
                <div class="stat-row">
                    <div class="stat-box">
                        <div class="num"><?= $resultado_migracion['totales']['artistas'] ?></div>
                        <div class="lbl">Artistas migrados</div>
                    </div>
                    <div class="stat-box">
                        <div class="num"><?= $resultado_migracion['totales']['obras'] ?></div>
                        <div class="lbl">Obras migradas</div>
                    </div>
                </div>
                <p style="margin-top:14px;">
                    👉 <a href="admin_panel.php?view=mongo_ver">Ver datos en MongoDB →</a>
                </p>
            </div>
        <?php else: ?>
            <div class="result-err">
                <h3>❌ Error durante la migración</h3>
                <p><strong>Detalle:</strong> <?= htmlspecialchars($resultado_migracion['error']) ?></p>
                <p style="font-size:0.9em;">Verifica que Docker esté corriendo y que la extensión mongodb esté habilitada en php.ini.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
