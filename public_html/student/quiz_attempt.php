<?php
define('AULA_APP', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';

require_role('student');

$pdo = Database::getConnection();
$studentId = current_user_id();
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.id AS assignment_id, a.due_date, a.points AS assignment_points, a.quiz_id AS assignment_quiz_id,
        qz.id AS quiz_id, qz.title, qz.description, qz.instructions,
        qz.time_limit_minutes, qz.max_attempts, qz.questions_per_attempt, qz.status
     FROM assignments a
     INNER JOIN quizzes qz ON qz.id = a.quiz_id
     INNER JOIN assignment_students ast ON ast.assignment_id = a.id AND ast.student_id = :student_id
     WHERE a.id = :assignment_id LIMIT 1"
);
$stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
$row = $stmt->fetch();

if (!$row || $row['status'] !== 'published') {
    http_response_code(404);
    exit('Cuestionario no encontrado o no disponible.');
}

$quiz = [
    'id' => (int) $row['quiz_id'],
    'title' => $row['title'],
    'description' => $row['description'],
    'instructions' => $row['instructions'],
    'time_limit_minutes' => $row['time_limit_minutes'],
    'max_attempts' => (int) $row['max_attempts'],
    'questions_per_attempt' => $row['questions_per_attempt'] !== null ? (int) $row['questions_per_attempt'] : null,
];

$isOverdue = $row['due_date'] && strtotime($row['due_date']) < time();
$errors = [];
$reviewOnly = isset($_GET['review']);

// ---- Entrega del intento ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $attemptId = (int) ($_POST['attempt_id'] ?? 0);

    // Verificar que el intento es de este estudiante y de este cuestionario, y sigue abierto
    $attStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE id = :id AND quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress'"
    );
    $attStmt->execute(['id' => $attemptId, 'quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $attempt = $attStmt->fetch();

    if (!$attempt) {
        $errors[] = 'Este intento ya no está disponible (puede que ya lo hayas entregado o haya vencido).';
    } else {
        $rawAnswers = $_POST['answers'] ?? [];
        $answers = [];
        foreach ($rawAnswers as $questionId => $optionId) {
            if ($optionId !== '') {
                $answers[(int) $questionId] = (int) $optionId;
            }
        }
        $attempt = quiz_submit_attempt($pdo, $attemptId, $answers);
    }
}

// ---- ¿Hay un intento recién entregado (para mostrar resultado) o hay que iniciar/retomar uno? ----
$submittedAttempt = null;
if (isset($attempt) && $attempt && $attempt['status'] === 'submitted') {
    $submittedAttempt = $attempt;
}

$activeAttempt = null;
$startError = null;

if ($reviewOnly && !$submittedAttempt) {
    // Solo quiere ver su mejor resultado, sin iniciar ni retomar ningún intento.
    $bestStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'
         ORDER BY (score_points / NULLIF(score_max, 0)) DESC, id DESC LIMIT 1"
    );
    $bestStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $submittedAttempt = $bestStmt->fetch() ?: null;
    if (!$submittedAttempt) {
        $startError = 'Todavía no has entregado ningún intento de este cuestionario.';
    }
}

if (!$submittedAttempt && !$reviewOnly) {
    // ¿Cuántos intentos ya usó, para decidir si puede empezar uno nuevo?
    $usedStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'"
    );
    $usedStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $usedAttempts = (int) ($usedStmt->fetch()['total'] ?? 0);

    $openStmt = $pdo->prepare(
        "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
    );
    $openStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
    $hasOpenAttempt = (bool) $openStmt->fetch();

    if ($isOverdue && !$hasOpenAttempt) {
        $startError = 'La fecha de entrega de esta asignación ya venció y no tienes ningún intento en curso.';
    } elseif ($usedAttempts >= $quiz['max_attempts'] && !$hasOpenAttempt) {
        $startError = 'Ya utilizaste todos tus intentos disponibles (' . $quiz['max_attempts'] . ') para este cuestionario.';
    } else {
        $result = quiz_start_or_resume_attempt($pdo, [
            'id' => $quiz['id'], 'max_attempts' => $quiz['max_attempts'], 'time_limit_minutes' => $quiz['time_limit_minutes'],
            'questions_per_attempt' => $quiz['questions_per_attempt'],
        ], $studentId, $assignmentId);

        if ($result['error']) {
            $startError = $result['error'];
        } else {
            $activeAttempt = $result['attempt'];
            if ($activeAttempt['status'] === 'submitted') {
                // Se autoentregó por vencimiento del tiempo justo ahora.
                $submittedAttempt = $activeAttempt;
                $activeAttempt = null;
            }
        }
    }

    // Si no se pudo iniciar/retomar un intento (venció o se acabaron), pero ya
    // había al menos uno entregado antes (incluyendo uno que se acabe de
    // autoentregar por vencimiento justo en esta visita), mostramos la
    // revisión del mejor en vez de dejar al estudiante solo con un error.
    if ($startError) {
        $bestStmt = $pdo->prepare(
            "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'submitted'
             ORDER BY (score_points / NULLIF(score_max, 0)) DESC, id DESC LIMIT 1"
        );
        $bestStmt->execute(['quiz_id' => $quiz['id'], 'student_id' => $studentId]);
        $bestAttempt = $bestStmt->fetch();
        if ($bestAttempt) {
            $submittedAttempt = $bestAttempt;
            $startError = null;
        }
    }
}

$questions = $activeAttempt ? quiz_questions_for_attempt($pdo, (int) $activeAttempt['id']) : [];
$review = $submittedAttempt ? quiz_attempt_review($pdo, (int) $submittedAttempt['id']) : [];

$pageTitle = $quiz['title'];
require __DIR__ . '/../includes/header.php';
?>
<p><a href="assignment.php?id=<?= (int) $assignmentId ?>">&larr; Volver a la asignación</a></p>
<h1>📝 <?= e($quiz['title']) ?></h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($submittedAttempt): ?>
    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">✅ Cuestionario entregado</h2>
        <p style="font-size:1.4rem;">
            <strong><?= e((string) round((float) $submittedAttempt['score_points'], 2)) ?> / <?= e((string) round((float) $submittedAttempt['score_max'], 2)) ?></strong>
            puntos
            (<?= $submittedAttempt['score_max'] > 0 ? round(((float) $submittedAttempt['score_points'] / (float) $submittedAttempt['score_max']) * 100) : 0 ?>%)
        </p>
        <p class="text-muted" style="font-size:0.85rem;">
            Intento <?= (int) $submittedAttempt['attempt_number'] ?> de <?= (int) $quiz['max_attempts'] ?> ·
            Entregado <?= e(date('d/m/Y H:i', strtotime($submittedAttempt['submitted_at']))) ?>
        </p>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2 style="margin-top:0;">Revisión</h2>
        <?php foreach ($review as $i => $q): ?>
            <div class="card" style="margin-bottom:10px; padding:14px 16px; border-left:4px solid <?= $q['is_correct'] ? 'var(--color-success)' : '#C0392B' ?>;">
                <strong><?= $i + 1 ?>. <?= e($q['statement']) ?></strong>
                <span class="text-muted" style="font-size:0.85rem;"> (<?= e((string) round((float) $q['points_earned'], 2)) ?> / <?= (int) $q['points'] ?> pts)</span>
                <div style="margin-top:8px;">
                    <?php foreach ($q['options'] as $o): ?>
                        <?php
                            $isSelected = (int) $q['selected_option_id'] === (int) $o['id'];
                            $isCorrectOpt = (int) $o['is_correct'] === 1;
                            $style = 'padding:4px 8px; border-radius:6px; margin-bottom:4px;';
                            if ($isCorrectOpt) {
                                $style .= 'background:#E8F8EF; color:#1E7E42; font-weight:600;';
                            } elseif ($isSelected) {
                                $style .= 'background:#FDECEA; color:#C0392B;';
                            }
                        ?>
                        <div style="<?= $style ?>">
                            <?= $isSelected ? '➡️ ' : '' ?><?= e($o['text']) ?><?= $isCorrectOpt ? ' ✓' : '' ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($q['selected_option_id'] === null): ?>
                        <p class="text-muted" style="font-size:0.85rem;">No respondiste esta pregunta.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ((int) $submittedAttempt['attempt_number'] < $quiz['max_attempts'] && !$isOverdue): ?>
        <p style="margin-top:16px;">
            <a class="btn" href="quiz_attempt.php?assignment_id=<?= (int) $assignmentId ?>">Intentar de nuevo</a>
            <span class="text-muted" style="font-size:0.85rem;"> (se guarda tu mejor calificación)</span>
        </p>
    <?php endif; ?>

<?php elseif ($startError): ?>
    <div class="card" style="text-align:center;">
        <h2 style="margin-top:0;">No disponible</h2>
        <p class="text-muted"><?= e($startError) ?></p>
    </div>

<?php elseif ($activeAttempt): ?>
    <?php if ($quiz['description']): ?>
        <div class="card"><?= nl2br(e($quiz['description'])) ?></div>
    <?php endif; ?>
    <?php if ($quiz['instructions']): ?>
        <div class="card" style="background:#F8FAFC;"><strong>Instrucciones:</strong> <?= nl2br(e($quiz['instructions'])) ?></div>
    <?php endif; ?>

    <div id="quiz-gate" class="card" style="text-align:center;">
        <h2 style="margin-top:0;">🔒 Este cuestionario se realiza en pantalla completa</h2>
        <p class="text-muted">
            Al hacer clic en "Comenzar" se activará la pantalla completa. Si cambias de pestaña, minimizas la
            ventana o sales de pantalla completa mientras respondes, recibirás un <strong>strike</strong>:
        </p>
        <ul style="text-align:left; display:inline-block; margin:0 0 12px;">
            <li><strong>Strike 1:</strong> se descuenta el 10&nbsp;% del tiempo del cuestionario.</li>
            <li><strong>Strike 2:</strong> se descuenta el 20&nbsp;% del tiempo del cuestionario.</li>
            <li><strong>Strike 3:</strong> el cuestionario se cierra y se entrega con lo que hayas respondido.</li>
        </ul>
        <div><button type="button" class="btn" id="btn-start-fullscreen">Comenzar cuestionario</button></div>
    </div>

    <div id="quiz-content" style="display:none;">
        <p class="text-muted" style="font-size:0.85rem;">
            Intento <?= (int) $activeAttempt['attempt_number'] ?> de <?= (int) $quiz['max_attempts'] ?>
            <?php if ($activeAttempt['expires_at']): ?>
                · Tiempo restante: <strong id="quiz-timer"></strong>
            <?php endif; ?>
            · Strikes: <strong id="quiz-strikes"><?= (int) $activeAttempt['tab_switches'] ?>/<?= (int) QUIZ_MAX_STRIKES ?></strong>
        </p>

        <form method="post" action="quiz_attempt.php?assignment_id=<?= (int) $assignmentId ?>" id="quiz-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="submit_attempt">
            <input type="hidden" name="attempt_id" value="<?= (int) $activeAttempt['id'] ?>">

            <?php foreach ($questions as $i => $q): ?>
                <div class="card" style="margin-bottom:12px;">
                    <strong><?= $i + 1 ?>. <?= e($q['statement']) ?></strong>
                    <span class="text-muted" style="font-size:0.85rem;"> (<?= (int) $q['points'] ?> pts)</span>
                    <div style="margin-top:10px;">
                        <?php foreach ($q['options'] as $o): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:400; padding:6px 0;">
                                <input type="radio" name="answers[<?= (int) $q['id'] ?>]" value="<?= (int) $o['id'] ?>" style="width:auto;">
                                <?= e($o['text']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn">Entregar cuestionario</button>
        </form>
    </div>

    <?php $remainingSeconds = $activeAttempt['expires_at'] ? max(0, strtotime($activeAttempt['expires_at']) - time()) : null; ?>
    <script>
    (function () {
        const attemptId = <?= (int) $activeAttempt['id'] ?>;
        const csrfToken = <?= json_encode(csrf_token()) ?>;
        const reportUrl = '<?= e(rtrim(APP_URL, '/')) ?>/api/quiz/report_violation.php';
        const MAX_STRIKES = <?= (int) QUIZ_MAX_STRIKES ?>;
        const gate = document.getElementById('quiz-gate');
        const content = document.getElementById('quiz-content');
        const startBtn = document.getElementById('btn-start-fullscreen');
        const quizForm = document.getElementById('quiz-form');
        const timerEl = document.getElementById('quiz-timer');
        const strikesEl = document.getElementById('quiz-strikes');

        // El tiempo se maneja con segundos restantes que calcula el servidor (no depende
        // de la zona horaria ni del reloj del dispositivo del estudiante).
        let deadline = <?= $remainingSeconds !== null ? 'Date.now() + ' . (int) $remainingSeconds . ' * 1000' : 'null' ?>;
        let closing = false;      // true cuando el cuestionario se está entregando: se detiene todo el monitoreo
        let monitoring = false;
        let inFlight = false;
        let lastReportAt = 0;
        let tickTimer = null;

        // ---------- Ventana de aviso propia (NO usa alert/confirm nativos) ----------
        // Los diálogos nativos pueden sacar al navegador de pantalla completa o quitarle el foco
        // a la página, y eso se contaría como otra salida. Esta ventana vive dentro de la página.
        let modalEl = null;
        function closeModal() {
            if (modalEl) { modalEl.remove(); modalEl = null; }
        }
        function showModal({ icon, title, lines, color, buttons }) {
            closeModal();
            modalEl = document.createElement('div');
            modalEl.setAttribute('role', 'alertdialog');
            modalEl.setAttribute('aria-modal', 'true');
            modalEl.style.cssText = 'position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.75); display:flex; align-items:center; justify-content:center; padding:16px;';
            const box = document.createElement('div');
            box.style.cssText = 'background:#fff; border-radius:14px; max-width:480px; width:100%; padding:24px; text-align:center; border-top:6px solid ' + color + '; box-shadow:0 20px 60px rgba(0,0,0,0.4);';
            const h = document.createElement('h2');
            h.style.cssText = 'margin:0 0 12px; color:' + color + ';';
            h.textContent = icon + ' ' + title;
            box.appendChild(h);
            lines.forEach(t => {
                const p = document.createElement('p');
                p.style.cssText = 'margin:8px 0; font-size:1rem;';
                p.innerHTML = t; // solo texto controlado por este script
                box.appendChild(p);
            });
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:16px;';
            buttons.forEach(b => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = b.secondary ? 'btn btn-secondary' : 'btn';
                btn.style.margin = '0';
                btn.textContent = b.label;
                btn.addEventListener('click', b.onClick);
                row.appendChild(btn);
            });
            box.appendChild(row);
            modalEl.appendChild(box);
            document.body.appendChild(modalEl);
            const first = row.querySelector('button:last-child');
            if (first) first.focus();
        }

        function fmt(seconds) {
            const m = Math.floor(seconds / 60), s = seconds % 60;
            if (m > 0 && s > 0) return m + ' min ' + s + ' s';
            if (m > 0) return m + ' min';
            return s + ' s';
        }

        // ---------- Entrega ----------
        function submitNow() {
            if (closing && quizForm.dataset.sending === '1') return;
            closing = true; // detiene el monitoreo ANTES de salir de pantalla completa
            quizForm.dataset.sending = '1';
            if (document.fullscreenElement && document.exitFullscreen) {
                document.exitFullscreen().catch(() => {});
            }
            quizForm.submit(); // submit() programático: no dispara el evento 'submit' ni pide confirmación
        }

        quizForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (closing) return;
            const total = quizForm.querySelectorAll('.card').length;
            const answered = new Set(Array.from(quizForm.querySelectorAll('input[type=radio]:checked')).map(r => r.name)).size;
            const missing = total - answered;
            showModal({
                icon: '📝', title: '¿Entregar el cuestionario?', color: '#4F46E5',
                lines: [
                    missing > 0 ? 'Tienes <strong>' + missing + '</strong> pregunta(s) sin responder.' : 'Respondiste todas las preguntas.',
                    'No podrás cambiar tus respuestas después.',
                ],
                buttons: [
                    { label: 'Seguir respondiendo', secondary: true, onClick: closeModal },
                    { label: 'Sí, entregar', onClick: submitNow },
                ],
            });
        });

        // ---------- Temporizador ----------
        function tick() {
            if (closing || deadline === null) return;
            const diffMs = deadline - Date.now();
            if (diffMs <= 0) {
                if (timerEl) timerEl.textContent = '00:00';
                closing = true;
                showModal({
                    icon: '⏰', title: 'Se acabó el tiempo', color: '#C0392B',
                    lines: ['El cuestionario se entregará automáticamente con lo que hayas respondido.'],
                    buttons: [{ label: 'Entregar ahora', onClick: () => { closing = false; submitNow(); } }],
                });
                setTimeout(() => { closing = false; submitNow(); }, 2500);
                return;
            }
            const totalSeconds = Math.floor(diffMs / 1000);
            const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const s = (totalSeconds % 60).toString().padStart(2, '0');
            if (timerEl) timerEl.textContent = m + ':' + s;
            tickTimer = setTimeout(tick, 1000);
        }
        tick();

        // ---------- Strikes ----------
        function handleStrike(data) {
            if (strikesEl) strikesEl.textContent = data.strike + '/' + data.max_strikes;
            if (data.remaining_seconds !== null && data.remaining_seconds !== undefined) {
                deadline = Date.now() + data.remaining_seconds * 1000;
                if (tickTimer) clearTimeout(tickTimer);
                tick();
            }

            if (data.closed) {
                closing = true; // ya no se cuentan más eventos
                if (tickTimer) clearTimeout(tickTimer);
                showModal({
                    icon: '⛔', title: 'Strike ' + data.strike + ' de ' + data.max_strikes, color: '#C0392B',
                    lines: [
                        'Saliste de la pantalla del cuestionario por tercera vez.',
                        '<strong>Consecuencia:</strong> el cuestionario se cierra y se entrega con lo que tenías respondido.',
                        'Se enviará automáticamente en unos segundos...',
                    ],
                    buttons: [{ label: 'Entregar ahora', onClick: () => { closing = false; submitNow(); } }],
                });
                setTimeout(() => { closing = false; submitNow(); }, 5000);
                return;
            }

            const left = data.strikes_left;
            const lines = ['Saliste de la pantalla del cuestionario.'];
            if (data.has_time_limit && data.penalty_seconds > 0) {
                lines.push('<strong>Consecuencia:</strong> se descontó el ' + data.penalty_percent + '&nbsp;% del tiempo del cuestionario (' + fmt(data.penalty_seconds) + ').');
                lines.push('Tiempo restante ahora: <strong>' + fmt(data.remaining_seconds) + '</strong>.');
            } else {
                lines.push('<strong>Consecuencia:</strong> advertencia (este cuestionario no tiene límite de tiempo que descontar).');
            }
            lines.push('Te ' + (left === 1 ? 'queda <strong>1 strike</strong>' : 'quedan <strong>' + left + ' strikes</strong>') + '.');
            lines.push(data.strike === 1
                ? 'En el strike 2 se descuenta el 20&nbsp;% del tiempo y en el strike 3 el cuestionario se cierra y se entrega.'
                : 'Un strike más y el cuestionario se cierra y se entrega con lo que tengas respondido.');

            showModal({
                icon: '⚠️', title: 'Strike ' + data.strike + ' de ' + data.max_strikes,
                color: data.strike === 1 ? '#D97706' : '#C0392B', lines,
                buttons: [{
                    label: 'Entendido, continuar',
                    onClick: () => {
                        closeModal();
                        // Si salió de pantalla completa, este clic (gesto del usuario) la reactiva.
                        if (!document.fullscreenElement && document.documentElement.requestFullscreen) {
                            document.documentElement.requestFullscreen().catch(() => {});
                        }
                    },
                }],
            });
        }

        function reportViolation() {
            if (closing || inFlight) return;
            const now = Date.now();
            if (now - lastReportAt < 1500) return; // un mismo gesto puede disparar varios eventos
            lastReportAt = now;
            inFlight = true;
            fetch(reportUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: JSON.stringify({ attempt_id: attemptId, csrf_token: csrfToken }),
            })
                .then(r => r.json())
                .then(data => {
                    inFlight = false;
                    if (closing) return; // ya se está entregando
                    if (data && data.success && data.active !== false) handleStrike(data);
                })
                .catch(() => { inFlight = false; });
        }

        function beginMonitoring() {
            if (monitoring) return;
            monitoring = true;
            document.addEventListener('visibilitychange', () => { if (document.hidden) reportViolation(); });
            document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) reportViolation(); });
            window.addEventListener('blur', reportViolation);
        }

        startBtn.addEventListener('click', () => {
            const el = document.documentElement;
            const request = el.requestFullscreen ? el.requestFullscreen()
                : el.webkitRequestFullscreen ? el.webkitRequestFullscreen()
                : Promise.reject(new Error('Pantalla completa no disponible en este navegador.'));

            Promise.resolve(request).catch(() => {
                // Si el navegador no lo permite (ej: algunos navegadores de iOS),
                // igual dejamos continuar con el cuestionario en lugar de bloquearlo.
            }).finally(() => {
                gate.style.display = 'none';
                content.style.display = 'block';
                // Pequeña pausa: el propio cambio a pantalla completa puede disparar un 'blur' inicial.
                setTimeout(beginMonitoring, 800);
            });
        });
    })();
    </script>

<?php else: ?>
    <div class="card" style="text-align:center;">
        <p class="text-muted">No se pudo cargar el cuestionario.</p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
