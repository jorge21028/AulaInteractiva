<?php
/**
 * auth.php
 * Manejo de sesiones, autenticación y control de acceso por rol.
 *
 * Roles soportados: 'admin', 'teacher', 'student'
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ¿Hay un usuario con sesión iniciada?
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Devuelve el rol del usuario actual o null si no hay sesión.
 */
function current_role(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function current_user_name(): ?string
{
    return $_SESSION['user_name'] ?? null;
}

// ---------------------------------------------------------------------------
// Súper administrador: "entrar como" un profesor
// ---------------------------------------------------------------------------
// El administrador conserva su identidad real en $_SESSION['impersonator']; mientras tanto la sesión actúa como
// el profesor (user_id/user_role del profesor), así TODAS las pantallas del profesor funcionan sin cambios.

/** ¿El administrador está viendo el sistema como otro usuario? */
function is_impersonating(): bool
{
    return !empty($_SESSION['impersonator']['id']);
}

/** ID del usuario REAL (el administrador si está suplantando; si no, el usuario de la sesión). */
function real_user_id(): ?int
{
    return $_SESSION['impersonator']['id'] ?? ($_SESSION['user_id'] ?? null);
}

/**
 * Empieza a actuar como un profesor. Solo un administrador activo puede hacerlo.
 */
function start_impersonation(PDO $pdo, int $teacherId): bool
{
    if (is_impersonating() || current_role() !== 'admin') {
        return false;
    }
    $adminId = (int) current_user_id();

    $admin = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'admin' AND is_active = 1");
    $admin->execute(['id' => $adminId]);
    $adminRow = $admin->fetch();

    $teacher = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'teacher'");
    $teacher->execute(['id' => $teacherId]);
    $teacherRow = $teacher->fetch();
    if (!$adminRow || !$teacherRow) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['impersonator'] = ['id' => (int) $adminRow['id'], 'name' => $adminRow['name'], 'email' => $adminRow['email']];
    $_SESSION['user_id']    = (int) $teacherRow['id'];
    $_SESSION['user_name']  = $teacherRow['name'];
    $_SESSION['user_email'] = $teacherRow['email'];
    $_SESSION['user_role']  = 'teacher';
    audit_log($pdo, $adminId, 'impersonate_start', "El administrador entró como profesor #{$teacherId} ({$teacherRow['email']})");
    return true;
}

/** Vuelve a la sesión del administrador. */
function stop_impersonation(PDO $pdo): bool
{
    if (!is_impersonating()) {
        return false;
    }
    $adminId = (int) $_SESSION['impersonator']['id'];
    $teacherId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'admin' AND is_active = 1");
    $stmt->execute(['id' => $adminId]);
    $admin = $stmt->fetch();
    if (!$admin) {
        do_logout();
        return false;
    }

    session_regenerate_id(true);
    unset($_SESSION['impersonator']);
    $_SESSION['user_id']    = (int) $admin['id'];
    $_SESSION['user_name']  = $admin['name'];
    $_SESSION['user_email'] = $admin['email'];
    $_SESSION['user_role']  = 'admin';
    audit_log($pdo, $adminId, 'impersonate_stop', "El administrador salió del perfil del profesor #{$teacherId}");
    return true;
}

/**
 * Exige que haya sesión iniciada. Si no, redirige a login.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }

    // Cada 5 minutos se comprueba que la cuenta (la real, no la suplantada) siga activa: si un administrador
    // la desactivó, se cierra la sesión.
    if (time() - (int) ($_SESSION['active_checked_at'] ?? 0) > 300) {
        $realId = (int) ($_SESSION['impersonator']['id'] ?? $_SESSION['user_id']);
        try {
            $stmt = Database::getConnection()->prepare('SELECT is_active FROM users WHERE id = :id');
            $stmt->execute(['id' => $realId]);
            $row = $stmt->fetch();
            if (!$row || (int) $row['is_active'] !== 1) {
                do_logout();
                redirect('login.php');
            }
            $_SESSION['active_checked_at'] = time();
        } catch (Throwable $e) {
            error_log('require_login active check: ' . $e->getMessage());
        }
    }

    // Contraseña temporal (cuenta nueva o restablecida por un administrador): hay que cambiarla antes de seguir.
    if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'change_password.php' && !is_impersonating()) {
        redirect('change_password.php');
    }
}

/**
 * Exige uno o varios roles específicos. Responde 403 si no coincide.
 * Uso: require_role('teacher'); o require_role(['teacher', 'admin']);
 */
function require_role($roles): void
{
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];

    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta sección.');
    }
}

/**
 * Intenta autenticar a un usuario por email/contraseña.
 * Devuelve true y crea la sesión si es correcto; false en caso contrario.
 */
function attempt_login(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    if ((int) $user['is_active'] !== 1) {
        return false;
    }

    // Regenerar el ID de sesión al iniciar sesión (previene session fixation)
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email']= $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['must_change_password'] = (int) ($user['must_change_password'] ?? 0) === 1;
    $_SESSION['active_checked_at'] = time();

    return true;
}

/**
 * Crea un nuevo usuario (profesor o estudiante). Devuelve el ID insertado
 * o lanza una excepción con un mensaje seguro para mostrar al usuario.
 */
function register_user(PDO $pdo, string $name, string $email, string $password, string $role): int
{
    if (!in_array($role, ['teacher', 'student'], true)) {
        throw new InvalidArgumentException('Rol no permitido para autorregistro.');
    }

    if (!is_valid_email($email)) {
        throw new InvalidArgumentException('El correo electrónico no es válido.');
    }

    if (mb_strlen($password) < 8) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        throw new InvalidArgumentException('Ese correo ya está registrado.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, created_at) 
             VALUES (:name, :email, :password_hash, :role, 1, :created_at)'
        );
        $stmt->execute([
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => $role,
            'created_at'    => now_datetime(),
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($role === 'teacher') {
            $pdo->prepare('INSERT INTO teachers (user_id) VALUES (:user_id)')->execute(['user_id' => $userId]);
        } else {
            $pdo->prepare('INSERT INTO students (user_id) VALUES (:user_id)')->execute(['user_id' => $userId]);
        }

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('register_user error: ' . $e->getMessage());
        throw new RuntimeException('No fue posible completar el registro. Intenta nuevamente.');
    }
}

/**
 * Cierra la sesión actual de forma segura.
 */
function do_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Devuelve la URL del dashboard correspondiente al rol actual.
 */
function dashboard_url_for_role(string $role): string
{
    return match ($role) {
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php',
        'admin'   => 'admin/dashboard.php',
        default   => 'index.php',
    };
}
