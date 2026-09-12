<?php
/**
 * env.example.php
 *
 * Copia este archivo como "env.php" en la misma carpeta y completa
 * los valores reales. "env.php" NUNCA debe subirse a GitHub
 * (ya está incluido en .gitignore).
 */

// ---- Base de datos ----
putenv('DB_HOST=localhost');
putenv('DB_NAME=aulainteractiva');
putenv('DB_USER=tu_usuario_mysql');
putenv('DB_PASSWORD=tu_password_mysql');
putenv('DB_PORT=3306');

// ---- Entorno ----
putenv('APP_ENV=local');              // 'local' en tu máquina, 'production' en InfinityFree
putenv('APP_URL=http://localhost/aulainteractiva');

// ---- Google Gemini ----
putenv('GEMINI_API_KEY=coloca_aqui_tu_api_key');
putenv('GEMINI_MODEL=gemini-1.5-flash');
