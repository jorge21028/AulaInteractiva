<?php
/**
 * admin_helpers.php
 * Funciones del súper administrador: estadísticas globales, listados de profesores/cursos/estudiantes,
 * gestión de cuentas (activar/desactivar, restablecer contraseña, crear usuarios) y registro de actividad.
 */

if (!defined('AULA_APP')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

/** Solo el administrador (no suplantando a nadie). */
function admin_require(): void
{
    require_role('admin');
}

/** Contraseña temporal legible (sin caracteres ambiguos). */
function admin_generate_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    do {
        $p = '';
        for ($i = 0; $i < $length; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/\d/', $p));
    return $p;
}

function admin_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) ($stmt->fetch(PDO::FETCH_NUM)[0] ?? 0);
}

/** Números globales del sistema. */
function admin_stats(PDO $pdo): array
{
    $uploadBytes = 0;
    $dir = rtrim(UPLOAD_DIR, '/') . '/images';
    if (is_dir($dir)) {
        foreach ((array) glob($dir . '/*') as $f) {
            if (is_file($f)) {
                $uploadBytes += (int) filesize($f);
            }
        }
    }
    return [
        'teachers'       => admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'teacher'"),
        'students'       => admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'"),
        'courses_active' => admin_scalar($pdo, 'SELECT COUNT(*) FROM courses WHERE is_active = 1'),
        'courses_inactive' => admin_scalar($pdo, 'SELECT COUNT(*) FROM courses WHERE is_active = 0'),
        'activities'     => admin_scalar($pdo, 'SELECT COUNT(*) FROM activities'),
        'quizzes'        => admin_scalar($pdo, 'SELECT COUNT(*) FROM quizzes'),
        'assignments'    => admin_scalar($pdo, 'SELECT COUNT(*) FROM assignments'),
        'submissions_done' => admin_scalar($pdo, "SELECT COUNT(*) FROM submissions WHERE status = 'completed'"),
        'upload_bytes'   => $uploadBytes,
    ];
}

/** Profesores con sus totales y último inicio de sesión. */
function admin_teachers(PDO $pdo): array
{
    return $pdo->query(
        "SELECT u.id, u.name, u.email, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM teacher_courses tc INNER JOIN courses c ON c.id = tc.course_id WHERE tc.teacher_id = u.id AND c.is_active = 1) AS courses_active,
            (SELECT COUNT(*) FROM teacher_courses tc INNER JOIN courses c ON c.id = tc.course_id WHERE tc.teacher_id = u.id AND c.is_active = 0) AS courses_inactive,
            (SELECT COUNT(DISTINCT cs.student_id) FROM teacher_courses tc INNER JOIN course_students cs ON cs.course_id = tc.course_id WHERE tc.teacher_id = u.id) AS students_count,
            (SELECT COUNT(*) FROM activities a WHERE a.teacher_id = u.id) AS activities_count,
            (SELECT COUNT(*) FROM quizzes q WHERE q.teacher_id = u.id) AS quizzes_count,
            (SELECT COUNT(*) FROM assignments x WHERE x.teacher_id = u.id) AS assignments_count,
            (SELECT MAX(al.created_at) FROM audit_log al WHERE al.user_id = u.id AND al.action = 'login') AS last_login
         FROM users u WHERE u.role = 'teacher' ORDER BY u.name ASC"
    )->fetchAll();
}

/** Todos los cursos con sus profesores y totales. */
function admin_courses(PDO $pdo): array
{
    return $pdo->query(
        "SELECT c.id, c.name, c.is_active, c.enrollment_code, c.school_year,
            (SELECT GROUP_CONCAT(CONCAT(u.id, ':', u.name) SEPARATOR '||') FROM teacher_courses tc INNER JOIN users u ON u.id = tc.teacher_id WHERE tc.course_id = c.id) AS teachers,
            (SELECT COUNT(*) FROM subjects s WHERE s.course_id = c.id) AS subjects_count,
            (SELECT COUNT(*) FROM course_students cs WHERE cs.course_id = c.id) AS students_count,
            (SELECT COUNT(*) FROM assignments a INNER JOIN subjects s ON s.id = a.subject_id WHERE s.course_id = c.id) AS assignments_count
         FROM courses c ORDER BY c.is_active DESC, c.name ASC"
    )->fetchAll();
}

/** Estudiantes (con búsqueda opcional por nombre o correo). */
function admin_students(PDO $pdo, string $q = '', int $limit = 300): array
{
    $sql = "SELECT u.id, u.name, u.email, u.is_active, u.created_at,
                (SELECT COUNT(*) FROM course_students cs WHERE cs.student_id = u.id) AS courses_count,
                (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM course_students cs INNER JOIN courses c ON c.id = cs.course_id WHERE cs.student_id = u.id) AS courses,
                (SELECT MAX(al.created_at) FROM audit_log al WHERE al.user_id = u.id AND al.action = 'login') AS last_login
            FROM users u WHERE u.role = 'student'";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    $sql .= ' ORDER BY u.name ASC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Activa/desactiva una cuenta de profesor o estudiante (nunca administradores). */
function admin_set_user_active(PDO $pdo, int $userId, bool $active): bool
{
    $stmt = $pdo->prepare("UPDATE users SET is_active = :a, updated_at = :t WHERE id = :id AND role IN ('teacher','student')");
    $stmt->execute(['a' => $active ? 1 : 0, 't' => now_datetime(), 'id' => $userId]);
    return $stmt->rowCount() > 0;
}

/** Restablece la contraseña de un profesor/estudiante a una temporal (la persona debe cambiarla al entrar). */
function admin_reset_password(PDO $pdo, int $userId): ?string
{
    $check = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role IN ('teacher','student')");
    $check->execute(['id' => $userId]);
    if (!$check->fetch()) {
        return null;
    }
    $temp = admin_generate_password();
    $pdo->prepare('UPDATE users SET password_hash = :h, must_change_password = 1, updated_at = :t WHERE id = :id')
        ->execute(['h' => password_hash($temp, PASSWORD_DEFAULT), 't' => now_datetime(), 'id' => $userId]);
    return $temp;
}

/** Activa/desactiva cualquier curso (sin importar de qué profesor sea). */
function admin_set_course_active(PDO $pdo, int $courseId, bool $active): bool
{
    $stmt = $pdo->prepare('UPDATE courses SET is_active = :a, deactivated_at = :w WHERE id = :id');
    $stmt->execute(['a' => $active ? 1 : 0, 'w' => $active ? null : now_datetime(), 'id' => $courseId]);
    return $stmt->rowCount() > 0;
}

/**
 * Crea un profesor o un administrador con contraseña temporal (debe cambiarla al entrar).
 * Lanza InvalidArgumentException con un mensaje seguro si hay un problema.
 */
function admin_create_user(PDO $pdo, string $name, string $email, string $role, string $password): int
{
    if (!in_array($role, ['teacher', 'admin'], true)) {
        throw new InvalidArgumentException('Rol no válido.');
    }
    if ($name === '' || mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Escribe un nombre (máximo 150 caracteres).');
    }
    if (!is_valid_email($email)) {
        throw new InvalidArgumentException('El correo electrónico no es válido.');
    }
    if (mb_strlen($password) < 8) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $check->execute(['e' => $email]);
    if ($check->fetch()) {
        throw new InvalidArgumentException('Ese correo ya está registrado.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, must_change_password, created_at)
             VALUES (:n, :e, :h, :r, 1, 1, :t)'
        )->execute(['n' => $name, 'e' => $email, 'h' => password_hash($password, PASSWORD_DEFAULT), 'r' => $role, 't' => now_datetime()]);
        $id = (int) $pdo->lastInsertId();
        if ($role === 'teacher') {
            $pdo->prepare('INSERT INTO teachers (user_id) VALUES (:id)')->execute(['id' => $id]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('admin_create_user: ' . $e->getMessage());
        throw new RuntimeException('No fue posible crear el usuario.');
    }
}

/** Registro de actividad (audit_log) con filtros opcionales. */
function admin_audit(PDO $pdo, string $action = '', string $q = '', int $limit = 200): array
{
    $sql = 'SELECT al.id, al.action, al.details, al.created_at, u.name AS user_name, u.email AS user_email, u.role AS user_role
            FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1';
    $params = [];
    if ($action !== '') {
        $sql .= ' AND al.action = :a';
        $params['a'] = $action;
    }
    if ($q !== '') {
        $sql .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2 OR al.details LIKE :q3)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
    }
    $sql .= ' ORDER BY al.id DESC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format($bytes / 1024, 0, ',', '.') . ' KB';
}
