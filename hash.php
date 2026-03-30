<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST["password"] ?? '';

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    } else {
        $error = "Введите пароль!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Генератор хэша пароля</title>
</head>
<body>

<h2>Генератор хэша пароля</h2>

<form method="post">
<input type="text" name="password" placeholder="Введите пароль">
<button type="submit">Сгенерировать</button>
</form>

<?php if (!empty($hash)): ?>
<p><strong>Хэш:</strong></p>
<textarea rows="4" cols="60"><?php echo htmlspecialchars($hash); ?></textarea>
<?php endif; ?>

<?php if (!empty($error)): ?>
<p style="color:red;"><?php echo $error; ?></p>
<?php endif; ?>

</body>
</html>
