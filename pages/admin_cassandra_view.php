<?php
/**
 * admin_cassandra_view.php
 * Vista incluida dentro del panel de administrador.
 * Consulta directamente Cassandra usando cqlsh y muestra los registros.
 */

// Helper para extraer datos de Cassandra a través de Docker y cqlsh
function get_cassandra_data($table) {
    $cmd = "docker exec cassandra-museo cqlsh -e \"COPY museo_logs.$table TO STDOUT WITH HEADER=TRUE;\" 2>&1";
    $output = shell_exec($cmd);
    
    // Verificar si hay errores comunes en la salida
    if (strpos($output, 'InvalidRequest') !== false || 
        strpos($output, 'does not exist') !== false || 
        strpos($output, 'not found') !== false || 
        strpos($output, 'Connection error') !== false ||
        strpos($output, 'NoHostAvailable') !== false) {
        return ['error' => $output];
    }
    
    $lines = explode("\n", trim($output));
    
    $data = [];
    $header = [];
    $is_data_started = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        
        // Ignorar líneas de resumen de exportación o warnings
        if (strpos($line, 'rows exported') !== false || strpos($line, 'Starting copy') !== false || strpos($line, 'Using') !== false) {
            continue;
        }
        
        $row = str_getcsv($line);
        
        if (empty($header)) {
            $header = $row;
            $is_data_started = true;
        } else {
            // Asegurarnos de que el número de columnas coincida
            if (count($row) == count($header)) {
                $data[] = array_combine($header, $row);
            } else if (count($row) > count($header)) {
                // Truncar si hay extra (a veces cqlsh escapa mal)
                $data[] = array_combine($header, array_slice($row, 0, count($header)));
            }
        }
    }
    
    return ['data' => $data, 'header' => $header, 'raw' => $output];
}

// Obtener los datos de las 3 tablas principales de logs
$resumen_fact = get_cassandra_data('facturacion_resumen');
$bitacora_seg = get_cassandra_data('bitacora_seguridad');
$historial_ob = get_cassandra_data('historial_obras');

// Función helper para pintar una tabla HTML genérica a partir de un resultado
function render_cassandra_table($result, $title, $icon, $description) {
    echo "<div class='section-header' style='margin-top: 40px;'>";
    echo "    <h3 style='margin:0;'>$icon Familia de Columnas: <code>$title</code></h3>";
    echo "</div>";
    echo "<p style='font-size: 0.9em; color: #555; margin-bottom: 15px;'>$description</p>";

    if (isset($result['error'])) {
        echo "<div class='error-box'>";
        echo "    <strong>❌ No se pudo consultar la tabla.</strong>";
        echo "    <p style='margin-top: 5px; font-size: 0.85em;'>Asegúrate de haber generado las tablas primero en la opción de Setup.</p>";
        echo "    <code style='font-size: 0.8em; color: #721c24;'>" . htmlspecialchars($result['error']) . "</code>";
        echo "</div>";
        return;
    }

    $data = $result['data'] ?? [];
    $header = $result['header'] ?? [];

    if (empty($data)) {
        echo "<p class='empty-msg'>No hay registros en esta tabla o no se ha inicializado.</p>";
    } else {
        echo "<div style='overflow-x: auto;'>";
        echo "<table class='mongo-table'>";
        echo "<thead><tr>";
        foreach ($header as $col) {
            echo "<th>" . htmlspecialchars(strtoupper($col)) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($data as $row) {
            echo "<tr>";
            foreach ($header as $col) {
                $val = $row[$col] ?? '';
                // Formateo visual para UUIDs o vacíos
                if (strlen($val) == 36 && count(explode('-', $val)) == 5) {
                    echo "<td class='oid'>" . htmlspecialchars($val) . "</td>";
                } else {
                    echo "<td>" . htmlspecialchars($val) . "</td>";
                }
            }
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    .mongo-view { font-family: 'Segoe UI', sans-serif; }
    .mongo-view h2 { color: #2c3e50; border-left: 4px solid #8e44ad; padding-left: 12px; margin-top: 0; }
    .mongo-view h3 { color: #34495e; margin-top: 32px; margin-bottom: 10px; }

    .badge-db { display: inline-block; background: #8e44ad; color: white; font-size: 0.78em; padding: 3px 10px; border-radius: 12px; vertical-align: middle; margin-left: 8px; }

    /* Tablas reutilizando estilos de mongo */
    .mongo-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 0.88em; }
    .mongo-table th { background: #2c3e50; color: white; padding: 10px 12px; text-align: left; font-weight: 600; }
    .mongo-table td { padding: 10px 12px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
    .mongo-table tr:nth-child(even) td { background: #f8f9fa; }
    .mongo-table tr:hover td { background: #e8f4fd; }
    .oid { font-family: monospace; font-size: 0.82em; color: #7f8c8d; }

    .empty-msg { color: #888; padding: 15px; font-style: italic; background: #f9f9f9; border-radius: 5px; border: 1px dashed #ccc; }
    .error-box { background: #f8d7da; border: 1px solid #dc3545; border-radius: 8px; padding: 16px 20px; color: #721c24; margin-bottom: 20px; }
    .error-box code { display: block; margin-top: 8px; font-size: 0.88em; word-break: break-all; }

    .section-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #dee2e6; padding-bottom: 8px; margin-bottom: 14px; }
</style>
</head>
<body>
<div class="mongo-view">
    <h2>🗄️ Datos en Cassandra <span class="badge-db">Keyspace: museo_logs</span></h2>

    <?php 
        render_cassandra_table(
            $resumen_fact, 
            'facturacion_resumen', 
            '💰', 
            'Resúmenes mensuales de facturación, optimizados para consultas de totales rápidos por fecha.'
        );

        render_cassandra_table(
            $bitacora_seg, 
            'bitacora_seguridad', 
            '🛡️', 
            'Bitácora inmutable de eventos del sistema (inicios de sesión, intentos fallidos, etc).'
        );

        render_cassandra_table(
            $historial_ob, 
            'historial_obras', 
            '🖼️', 
            'Historial de cambios de estatus de las obras de arte a lo largo del tiempo.'
        );
    ?>

</div>
</body>
</html>
