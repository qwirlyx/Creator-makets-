<?php
session_name('CREATOR_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (file_exists(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php')['session_path'] ?? '/' : '/'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Если уже авторизован — сразу в приложение
if (isset($_SESSION['bm_logged_in']) && $_SESSION['bm_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';
$authFile = __DIR__ . '/json/auth.json';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $pass = $_POST['password'] ?? '';

    if (file_exists($authFile)) {
        $creds = json_decode(file_get_contents($authFile), true);
        
        // Сверка логина и пароля
        if (($creds['login'] ?? '') === $login && ($creds['password'] ?? '') === $pass) {
            $_SESSION['bm_logged_in'] = true;
            header("Location: index.php");
            exit;
        } else {
            $error = "Неверный логин или пароль";
        }
    } else {
        $error = "Ошибка конфигурации: файл json/auth.json не найден";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | Креатор</title>
    <link rel="icon" type="image/x-icon" href="../icon.ico">
    <link rel="stylesheet" href="styles.css?v=2.0">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <h2>🔐 Вход в систему</h2>
            <?php if($error): ?>
                <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group login-form-group">
                    <input type="text" name="login" placeholder="Логин" required autofocus>
                </div>

                <div class="form-group login-form-group">
                    <input type="password" name="password" placeholder="Пароль" required>
                </div>

                <button type="submit" class="login-btn">Войти</button>
            </form>
        </div>
    </div>
</body>
</html>