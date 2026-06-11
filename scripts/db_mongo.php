<?php
/**
 * db_mongo.php
 * Script de conexión centralizada a MongoDB.
 * Requiere: extensión mongodb habilitada en php.ini
 *           (php_mongodb.dll en Windows/XAMPP)
 */

define('MONGO_URI',  'mongodb://localhost:27017');
define('MONGO_DB',   'galeria_db_mongo');

function mongo_connect(): MongoDB\Driver\Manager {
    try {
        $manager = new MongoDB\Driver\Manager(MONGO_URI);
        // Ping rápido para confirmar que el servidor responde
        $command = new MongoDB\Driver\Command(['ping' => 1]);
        $manager->executeCommand('admin', $command);
        return $manager;
    } catch (MongoDB\Driver\Exception\Exception $e) {
        die(json_encode([
            'error' => true,
            'mensaje' => 'No se pudo conectar a MongoDB: ' . $e->getMessage()
        ]));
    }
}
?>
