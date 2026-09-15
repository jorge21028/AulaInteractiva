<?php
/**
 * env.example.php
 *
 * Copia este archivo como "env.php" en la misma carpeta y completa
 * los valores reales. "env.php" NUNCA debe subirse a GitHub
 * (ya está incluido en .gitignore).
 *
 * NOTA TÉCNICA: usamos define() en vez de putenv()/getenv() porque
 * varios hosting compartidos gratuitos (incluido InfinityFree)
 * desactivan putenv()/getenv() por seguridad. define() siempre
 * funciona, sin depender de la configuración del servidor.
 */

// ---- Base de datos ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'aulainteractiva');
define('DB_USER', 'tu_usuario_mysql');
define('DB_PASSWORD', 'tu_password_mysql');
define('DB_PORT', '3306');

// ---- Entorno ----
define('APP_ENV', 'local');              // 'local' en tu máquina, 'production' en InfinityFree
define('APP_URL', 'http://localhost/aulainteractiva');

// ---- Google Gemini ----
define('GEMINI_API_KEY', 'coloca_aqui_tu_api_key');
define('GEMINI_MODEL', 'gemini-3.5-flash');
