<?php
/**
 * database.php
 * Provee una única conexión PDO reutilizable a MySQL/MariaDB.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = DB_HOST;
            $db   = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASSWORD;
            $port = defined('DB_PORT') ? DB_PORT : '3306';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                if (APP_ENV === 'local') {
                    exit('Error de conexión a la base de datos: ' . $e->getMessage());
                }
                error_log('DB connection error: ' . $e->getMessage());
                exit('No fue posible conectar con la base de datos. Intenta más tarde.');
            }
        }

        return self::$instance;
    }
}
