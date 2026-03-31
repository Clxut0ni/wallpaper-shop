<?php
/**
 * Smoke-test script for the wallpaper store project.
 * Run in VS Code terminal: php smoke_test.php
 */

declare(strict_types=1);

$baseDir = __DIR__;
$phpFiles = [
    'admin.php',
    'config.php',
    'index.php',
    'product.php',
    'cart.php',
    'login.php',
    'header.php',
    'api/products.php',
    'api/images.php',
];

$requiredJson = [
    'cache/categories.json',
    'cache/products.json',
    'cache/images.json',
];

$requiredXml = [
    'import_files/import.xml',
    'import_files/offers.xml',
];

$passed = 0;
$failed = 0;

function report(bool $condition, string $okText, string $failText): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[OK]   {$okText}" . PHP_EOL;
        $passed++;
    } else {
        echo "[FAIL] {$failText}" . PHP_EOL;
        $failed++;
    }
}

foreach ($phpFiles as $file) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $file;
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($fullPath);
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    report(
        $exitCode === 0,
        "Синтаксис файла {$file} корректен.",
        "Синтаксическая ошибка в файле {$file}."
    );
}

foreach ($requiredXml as $file) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $file;
    $exists = file_exists($fullPath) && filesize($fullPath) > 0;
    report(
        $exists,
        "Файл {$file} найден и доступен для импорта.",
        "Файл {$file} отсутствует или пустой."
    );
}

$jsonData = [];
foreach ($requiredJson as $file) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $file;
    $decoded = null;
    $isValid = false;
    if (file_exists($fullPath)) {
        $raw = file_get_contents($fullPath);
        $decoded = json_decode($raw, true);
        $isValid = json_last_error() === JSON_ERROR_NONE;
    }
    report(
        $isValid,
        "JSON-файл {$file} найден и успешно декодирован.",
        "JSON-файл {$file} отсутствует или содержит ошибки."
    );
    if ($isValid) {
        $jsonData[$file] = $decoded;
    }
}

$products = $jsonData['cache/products.json'] ?? [];
$categories = $jsonData['cache/categories.json'] ?? [];
$images = $jsonData['cache/images.json'] ?? [];

report(
    is_array($products) && count($products) > 0,
    'Каталог товаров содержит как минимум одну запись.',
    'Каталог товаров пуст или не был сформирован.'
);

$firstProduct = is_array($products) && !empty($products) ? $products[0] : [];
$requiredFields = ['id', 'name', 'article', 'category_id', 'quantity', 'price'];
$allFieldsExist = !array_diff($requiredFields, array_keys($firstProduct));
report(
    $allFieldsExist,
    'Первая карточка товара содержит обязательные поля id, name, article, category_id, quantity и price.',
    'В карточке товара отсутствуют обязательные поля.'
);

report(
    is_array($categories) && count($categories) > 0,
    'Кэш категорий заполнен и готов к использованию на витрине.',
    'Кэш категорий пуст.'
);

report(
    is_array($images) && count($images) > 0,
    'Кэш изображений заполнен и содержит связи изображений с товарами.',
    'Кэш изображений пуст.'
);

echo str_repeat('-', 60) . PHP_EOL;
echo "ИТОГО: успешно {$passed}, ошибок {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
