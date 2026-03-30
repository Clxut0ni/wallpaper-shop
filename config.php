<?php
/**
 * File: config.php
 *
 * Конфигурационный файл проекта.
 *
 * Содержит настройки сессий, константы путей, массив администраторов
 * и функции для синхронизации кэша изображений и описаний с товарами.
 */

// Загрузка .env файла
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Настройка сессий для повышения безопасности
ini_set('session.cookie_httponly', 1); // Запрет доступа к cookies через JavaScript
ini_set('session.use_strict_mode', 1); // Строгий режим сессий
ini_set('session.gc_maxlifetime', 1800); // Время жизни сессии 30 минут
ini_set('session.cookie_lifetime', 1800); // Время жизни cookie 30 минут
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1); // Cookies только по HTTPS
}

// Определение констант для путей и параметров
define('IMPORT_DIR', __DIR__ . '/import_files/'); // Папка для распаковки ZIP
define('CACHE_DIR', __DIR__ . '/cache/'); // Папка для кэша
define('IMAGES_DIR', __DIR__ . '/images/'); // Папка для изображений
define('ITEMS_PER_PAGE', 12); // Количество товаров на странице

// Содержимое .htaccess для приватных папок (полный запрет доступа)
$privateHtaccessContent = <<<EOT
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
EOT;

// Содержимое .htaccess для /images (только скрытие листинга)
$imagesHtaccessContent = "Options -Indexes";

// Создание директорий и .htaccess, если они отсутствуют
foreach ([IMPORT_DIR, CACHE_DIR, IMAGES_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
if (!file_exists(IMPORT_DIR . '.htaccess')) {
    file_put_contents(IMPORT_DIR . '.htaccess', $privateHtaccessContent);
}
if (!file_exists(CACHE_DIR . '.htaccess')) {
    file_put_contents(CACHE_DIR . '.htaccess', $privateHtaccessContent);
}
if (!file_exists(IMAGES_DIR . '.htaccess')) {
    file_put_contents(IMAGES_DIR . '.htaccess', $imagesHtaccessContent);
}

// Для /vendor (если существует после composer install)
$vendor_dir = __DIR__ . '/vendor/';
if (is_dir($vendor_dir) && !file_exists($vendor_dir . '.htaccess')) {
    file_put_contents($vendor_dir . '.htaccess', $privateHtaccessContent);
}

// Массив пользователей для авторизации (из .env)
$admins = [
    $_ENV['DEVELOPER_EMAIL'] => [
        'password_hash' => $_ENV['DEVELOPER_PASSWORD_HASH'],
        'role' => 'developer'
    ],
    $_ENV['ADMIN_EMAIL'] => [
        'password_hash' => $_ENV['ADMIN_PASSWORD_HASH'],
        'role' => 'admin'
    ]
];

/**
 * Рекурсивно очищает директорию, удаляя все файлы и подпапки, кроме указанных в exclude.
 *
 * @param string $path Путь к директории.
 * @param array $exclude Массив имён файлов/папок для исключения.
 * @return bool True при успехе, false при ошибке.
 */
function clearDirectory($path, $exclude = []) {
    if (!is_dir($path)) {
        error_log("clearDirectory: Директория не существует: {$path}");
        return false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $fileinfo) {
        $item_name = $fileinfo->getFilename();
        if (in_array($item_name, $exclude)) {
            continue;
        }
        if ($fileinfo->isDir()) {
            if (rmdir($fileinfo->getRealPath())) {
                error_log("clearDirectory: Удалена папка {$fileinfo->getRealPath()}");
            } else {
                error_log("clearDirectory: Ошибка удаления папки {$fileinfo->getRealPath()}");
            }
        } else {
            if (unlink($fileinfo->getRealPath())) {
                error_log("clearDirectory: Удалён файл {$fileinfo->getRealPath()}");
            } else {
                error_log("clearDirectory: Ошибка удаления файла {$fileinfo->getRealPath()}");
            }
        }
    }
    return true;
}

/**
 * Синхронизирует images.json с актуальным каталогом товаров, удаляя устаревшие записи и файлы.
 *
 * Загружает актуальные товары, сравнивает с данными изображений и удаляет лишние файлы/записи.
 *
 * @return bool True при успешной синхронизации, false при ошибке.
 */
function syncImagesWithProducts() {
    $products_file = CACHE_DIR . 'products.json';
    $images_file = CACHE_DIR . 'images.json';
    
    // Загрузка актуальных товаров
    if (!file_exists($products_file)) {
        error_log("syncImagesWithProducts: products.json не найден");
        return false;
    }
    $products = json_decode(file_get_contents($products_file), true);
    if (!is_array($products)) {
        error_log("syncImagesWithProducts: Ошибка чтения products.json");
        return false;
    }
    
    // Получение списка актуальных ID товаров
    $valid_product_ids = array_column($products, 'id');
    
    // Загрузка текущих данных об изображениях
    $images_data = file_exists($images_file) ? json_decode(file_get_contents($images_file), true) ?? [] : [];
    
    // Флаг для отслеживания изменений
    $modified = false;
    $new_images_data = [];
    
    // Проверка записей в images.json
    foreach ($images_data as $product_id => $images) {
        if (in_array($product_id, $valid_product_ids)) {
            // Товар существует, сохраняем его изображения
            $new_images_data[$product_id] = $images;
        } else {
            // Товар удалён, удаляем связанные файлы
            foreach ($images as $filename) {
                if (!empty($filename) && file_exists(IMAGES_DIR . $filename)) {
                    if (unlink(IMAGES_DIR . $filename)) {
                        error_log("syncImagesWithProducts: Удалён файл изображения {$filename} для удалённого товара {$product_id}");
                    } else {
                        error_log("syncImagesWithProducts: Ошибка при удалении файла {$filename}");
                    }
                }
            }
            $modified = true;
            error_log("syncImagesWithProducts: Удалена запись для товара {$product_id} из images.json");
        }
    }
    
    // Сохранение обновлённого images.json
    if ($modified) {
        if (file_put_contents($images_file, json_encode($new_images_data, JSON_UNESCAPED_UNICODE))) {
            error_log("syncImagesWithProducts: images.json успешно обновлён");
        } else {
            error_log("syncImagesWithProducts: Ошибка при записи в images.json");
            return false;
        }
    }
    
    return true;
}

/**
 * Синхронизирует descriptions.json с актуальным каталогом товаров, удаляя устаревшие описания.
 *
 * Загружает актуальные товары, сравнивает с данными описаний и удаляет лишние записи.
 *
 * @return bool True при успешной синхронизации, false при ошибке.
 */
function syncDescriptionsWithProducts() {
    $products_file = CACHE_DIR . 'products.json';
    $descriptions_file = CACHE_DIR . 'descriptions.json';
    
    // Загрузка актуальных товаров
    if (!file_exists($products_file)) {
        error_log("syncDescriptionsWithProducts: products.json не найден");
        return false;
    }
    $products = json_decode(file_get_contents($products_file), true);
    if (!is_array($products)) {
        error_log("syncDescriptionsWithProducts: Ошибка чтения products.json");
        return false;
    }
    
    // Получение списка актуальных ID товаров
    $valid_product_ids = array_column($products, 'id');
    
    // Загрузка текущих данных об описаниях
    $descriptions_data = file_exists($descriptions_file) ? json_decode(file_get_contents($descriptions_file), true) ?? [] : [];
    
    // Флаг для отслеживания изменений
    $modified = false;
    $new_descriptions_data = [];
    
    // Проверка записей в descriptions.json
    foreach ($descriptions_data as $product_id => $description) {
        if (in_array($product_id, $valid_product_ids)) {
            // Товар существует, сохраняем его описание
            $new_descriptions_data[$product_id] = $description;
        } else {
            // Товар удалён, удаляем его описание
            $modified = true;
            error_log("syncDescriptionsWithProducts: Удалена запись описания для товара {$product_id} из descriptions.json");
        }
    }
    
    // Сохранение обновлённого descriptions.json
    if ($modified) {
        if (file_put_contents($descriptions_file, json_encode($new_descriptions_data, JSON_UNESCAPED_UNICODE))) {
            error_log("syncDescriptionsWithProducts: descriptions.json успешно обновлён");
        } else {
            error_log("syncDescriptionsWithProducts: Ошибка при записи в descriptions.json");
            return false;
        }
    }
    
    return true;
}

// Настройки Telegram-бота для уведомлений о заказах
define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN']); // Из .env
define('TELEGRAM_CHAT_ID', $_ENV['TELEGRAM_CHAT_ID']); // Из .env

// Настройка email владельца для уведомлений о заказах
define('OWNER_EMAIL', $_ENV['OWNER_EMAIL']); // Из .env (укажите в .env: OWNER_EMAIL=owner@example.com)

// Глобальная версия сайта — меняется при любом обновлении контента
function site_version() {
    $file = CACHE_DIR . 'last_update.txt';
    if (file_exists($file)) {
        return trim(file_get_contents($file));
    }
    // Если файла нет — создаём на лету
    $time = time();
    file_put_contents($file, $time);
    return $time;
}

// Удобная обёртка для HTML: выводит версию
function v() {
    echo site_version();
}

// Проверка черного списка IP перед любыми действиями
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (inBlacklist($client_ip)) {
    http_response_code(403);
    echo 'Доступ запрещен.';
    exit;
}

/**
 * Проверяет, находится ли IP в черном списке.
 *
 * @param string $ip IP-адрес.
 * @return bool True, если в черном списке, false иначе.
 */
function inBlacklist($ip) {
    $blacklist_file = CACHE_DIR . 'blacklist.json';
    if (file_exists($blacklist_file)) {
        $blacklist = json_decode(file_get_contents($blacklist_file), true) ?? [];
        return in_array($ip, $blacklist);
    }
    return false;
}

/**
 * Добавляет IP в черный список.
 *
 * @param string $ip IP-адрес.
 * @return bool True при успехе.
 */
function addToBlacklist($ip) {
    $blacklist_file = CACHE_DIR . 'blacklist.json';
    $blacklist = file_exists($blacklist_file) ? json_decode(file_get_contents($blacklist_file), true) ?? [] : [];
    if (!in_array($ip, $blacklist)) {
        $blacklist[] = $ip;
        file_put_contents($blacklist_file, json_encode($blacklist));
    }
    return true;
}

/**
 * Удаляет IP из черного списка.
 *
 * @param string $ip IP-адрес.
 * @return bool True при успехе.
 */
function removeFromBlacklist($ip) {
    $blacklist_file = CACHE_DIR . 'blacklist.json';
    if (file_exists($blacklist_file)) {
        $blacklist = json_decode(file_get_contents($blacklist_file), true) ?? [];
        $blacklist = array_filter($blacklist, fn($entry) => $entry !== $ip);
        file_put_contents($blacklist_file, json_encode(array_values($blacklist)));
    }
    return true;
}

/**
 * Получает список заблокированных IP.
 *
 * @return array Массив IP-адресов.
 */
function getBlacklist() {
    $blacklist_file = CACHE_DIR . 'blacklist.json';
    return file_exists($blacklist_file) ? json_decode(file_get_contents($blacklist_file), true) ?? [] : [];
}

// Функционал Remember Me
define('REMEMBER_COOKIE_NAME', 'remember_token');
define('REMEMBER_COOKIE_LIFETIME', 2592000); // 30 дней
$remember_file = CACHE_DIR . 'remember.json';

// Автоматический логин по remember cookie
if (!isset($_SESSION['logged_in']) && isset($_COOKIE[REMEMBER_COOKIE_NAME])) {
    $token = $_COOKIE[REMEMBER_COOKIE_NAME];
    $remember_data = file_exists($remember_file) ? json_decode(file_get_contents($remember_file), true) ?? [] : [];
    foreach ($remember_data as $email => $hashed_token) {
        if (password_verify($token, $hashed_token)) {
            // Логин пользователя
            $_SESSION['logged_in'] = true;
            $_SESSION['role'] = $admins[$email]['role'];
            $_SESSION['email'] = $email;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            error_log("Автоматический вход по remember cookie: email={$email}, IP={$client_ip}");
            break;
        }
    }
}

/**
 * Заменяет фамилии поставщиков в артикуле для обычных пользователей.
 *
 * @param string $article Артикул товара.
 * @param bool $is_admin Флаг, указывающий, является ли пользователь администратором.
 * @return string Модифицированный или оригинальный артикул.
 */
function replaceArticle($article, $is_admin) {
    if ($is_admin || !is_string($article)) {
        return $article;
    }
    $article = str_ireplace('РЕШЕТНЯК', 'РЕШ.', $article);
    $article = str_ireplace('ИП РЕШЕТНЯК', 'ИП РЕШ.', $article);
    $article = str_ireplace('Карапетьянц', 'Кар.', $article);
    return $article;
}

?>