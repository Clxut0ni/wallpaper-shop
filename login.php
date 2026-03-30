<?php
/**
 * File: login.php
 *
 * Страница входа в админ-панель.
 *
 * Обрабатывает форму логина, проверяет учетные данные, управляет попытками входа
 * для защиты от брутфорса и перенаправляет на админ-панель при успехе.
 */
// Запуск сессии для управления авторизацией
session_start();
// Подключение конфигурационного файла
require_once 'config.php';
// Инициализация сообщения об ошибке
$error = '';
// Настройка сессий для повышения безопасности
ini_set('session.cookie_httponly', 1); // Запрет доступа к cookies через JavaScript
ini_set('session.use_strict_mode', 1); // Строгий режим сессий
ini_set('session.gc_maxlifetime', 1800); // Время жизни сессии 30 минут
ini_set('session.cookie_lifetime', 1800); // Время жизни cookie 30 минут
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1); // Cookies только по HTTPS
}
// Константы для защиты от брутфорса
define('MAX_LOGIN_ATTEMPTS', 5); // Максимум попыток входа
define('LOCKOUT_DURATION', 15 * 60); // Время блокировки в секундах (15 минут)
define('ATTEMPT_WINDOW', 15 * 60); // Временное окно для подсчёта попыток (15 минут)
// Получение IP-адреса пользователя
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// Инициализация данных о попытках входа в сессии
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}
if (!isset($_SESSION['login_attempts'][$client_ip])) {
    $_SESSION['login_attempts'][$client_ip] = [
        'count' => 0,
        'last_attempt' => 0,
        'lockout_until' => 0
    ];
}
// Проверка, заблокирован ли IP
$attempt_data = &$_SESSION['login_attempts'][$client_ip];
$current_time = time();
if ($attempt_data['lockout_until'] > $current_time) {
    $remaining = $attempt_data['lockout_until'] - $current_time;
    $error = sprintf('Слишком много попыток входа. Попробуйте снова через %d минут.', ceil($remaining / 60));
    error_log("IP {$client_ip} заблокирован до " . date('Y-m-d H:i:s', $attempt_data['lockout_until']));
} else {
    // Сброс счётчика, если истекло окно
    if ($current_time - $attempt_data['last_attempt'] > ATTEMPT_WINDOW) {
        $attempt_data['count'] = 0;
        $attempt_data['last_attempt'] = 0;
    }
}
// Генерация CSRF-токена для защиты формы логина
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Перенаправление на админ-панель, если пользователь уже авторизован
if (isset($_SESSION['logged_in'])) {
    header('Location: admin');
    exit;
}
// Обработка формы логина
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $attempt_data['lockout_until'] <= $current_time) {
    // Проверка CSRF-токена
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF проверка не пройдена';
        error_log("CSRF проверка не пройдена для логина пользователя: " . ($_POST['email'] ?? 'unknown') . ", IP: {$client_ip}");
        // Увеличиваем счётчик попыток
        $attempt_data['count']++;
        $attempt_data['last_attempt'] = $current_time;
        if ($attempt_data['count'] >= MAX_LOGIN_ATTEMPTS) {
            $attempt_data['lockout_until'] = $current_time + LOCKOUT_DURATION;
            error_log("IP {$client_ip} заблокирован за превышение попыток входа до " . date('Y-m-d H:i:s', $attempt_data['lockout_until']));
        }
    } else {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] === '1';
        // Проверка учетных данных
        if (isset($admins[$email]) && password_verify($password, $admins[$email]['password_hash'])) {
            session_regenerate_id(true); // Регенерация ID сессии для безопасности
            $_SESSION['logged_in'] = true;
            $_SESSION['role'] = $admins[$email]['role'];
            $_SESSION['email'] = $email;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Обновление CSRF-токена
            // Сброс попыток входа при успешной авторизации
            $attempt_data['count'] = 0;
            $attempt_data['last_attempt'] = 0;
            $attempt_data['lockout_until'] = 0;
            error_log("Успешный вход: email={$email}, IP={$client_ip}");
            // Обработка remember me
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $hashed_token = password_hash($token, PASSWORD_DEFAULT);
                $remember_data = file_exists($remember_file) ? json_decode(file_get_contents($remember_file), true) ?? [] : [];
                $remember_data[$email] = $hashed_token;
                file_put_contents($remember_file, json_encode($remember_data));
                setcookie(REMEMBER_COOKIE_NAME, $token, time() + REMEMBER_COOKIE_LIFETIME, '/', '', isset($_SERVER['HTTPS']), true);
                error_log("Remember me установлен для email={$email}");
            }
            header('Location: admin');
            exit;
        } else {
            $error = 'Неверный логин или пароль';
            // Увеличиваем счётчик попыток
            $attempt_data['count']++;
            $attempt_data['last_attempt'] = $current_time;
            if ($attempt_data['count'] >= MAX_LOGIN_ATTEMPTS) {
                $attempt_data['lockout_until'] = $current_time + LOCKOUT_DURATION;
                error_log("IP {$client_ip} заблокирован за превышение попыток входа до " . date('Y-m-d H:i:s', $attempt_data['lockout_until']));
            }
            error_log("Неудачная попытка входа: email={$email}, IP={$client_ip}, попытка {$attempt_data['count']}/" . MAX_LOGIN_ATTEMPTS);
        }
    }
    // Обновление CSRF-токена после обработки формы
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <!-- Установка кодировки и адаптивности -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход - «Мир обоев»</title>
    <!-- Подключение стилей Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Стили для элементов формы */
        .pointer { cursor: pointer; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-5">
        <h2>Вход в админ-панель</h2>
        <!-- Отображение ошибки, если она есть -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <!-- Форма логина -->
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <div class="input-group">
                    <span class="input-group-text pointer" id="togglePassword">
                        <i class="bi bi-eye-slash"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                <label class="form-check-label" for="remember">Сохранить вход</label>
            </div>
            <button type="submit" class="btn btn-primary">Войти</button>
        </form>
    </div>
    <!-- Подключение скриптов Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Переключение видимости пароля
        document.getElementById('togglePassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        });
    </script>
    <?php if (isset($_GET['loggedout'])): ?>
    <script>
        // Блокировка кнопки "Назад" после logout
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.go(1);
        };
    </script>
    <?php endif; ?>
</body>
</html>