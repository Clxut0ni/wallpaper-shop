<?php
/**
 * File: admin.php
 *
 * Административная панель для управления ZIP-архивом с XML-файлами, изображениями и кэшем.
 *
 * Этот файл позволяет загружать ZIP-архив, распаковывать его в /import_files/, генерировать кэш категорий и товаров,
 * синхронизировать изображения и описания. Доступен только авторизованным пользователям.
 */

// Запуск сессии для управления авторизацией и CSRF-токеном
session_start();

// Подключение конфигурационного файла с настройками и константами
require_once 'config.php';

// Путь к файлу блокировки обработки
define('PROCESSING_LOCK', CACHE_DIR . 'processing.lock');

// Проверка на наличие блокировки обработки (для всех запросов, кроме начала загрузки ZIP)
if (file_exists(PROCESSING_LOCK)) {
    echo '<div class="alert alert-info">Обработка данных в процессе. Пожалуйста, подождите и обновите страницу позже.</div>';
    exit;
}

// Инициализация переменной для хранения сообщений об ошибках или успехе
$message = '';

// Проверка авторизации: если пользователь не вошёл, перенаправляем на страницу логина
if (!isset($_SESSION['logged_in'])) {
    header('Location: login');
    exit;
}

// Генерация CSRF-токена для защиты от атак CSRF, если он ещё не создан
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Заголовки для предотвращения кэширования страницы
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/**
 * Парсит XML-структуру групп (категорий) рекурсивно.
 *
 * Эта функция читает XML-структуру категорий и строит ассоциативный массив категорий.
 * Поддерживает вложенные группы категорий.
 *
 * @param XMLReader $reader Объект для чтения XML.
 * @param string $parent_id ID родительской категории (по умолчанию пустая строка для корневых категорий).
 * @param array &$categories Массив для хранения категорий (передаётся по ссылке).
 */
function parseGroups($reader, $parent_id = '', &$categories = []) {
    // Чтение XML до конца или до завершения блока групп
    while ($reader->read()) {
        // Обработка элемента "Группа"
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Группа') {
            $category = ['id' => '', 'name' => '', 'parent_id' => $parent_id];
            // Чтение содержимого группы
            while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Группа')) {
                if ($reader->nodeType == XMLReader::ELEMENT) {
                    $key = $reader->name;
                    $reader->read();
                    // Извлечение текстовых данных (ID или название)
                    if ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) {
                        if ($key == 'Ид') {
                            $category['id'] = $reader->value ?: '';
                        } elseif ($key == 'Наименование') {
                            $category['name'] = $reader->value ?: 'Без названия';
                        }
                    }
                    $reader->moveToElement();
                    // Рекурсивная обработка вложенных групп
                    if ($key == 'Группы') {
                        parseGroups($reader, $category['id'], $categories);
                    }
                }
            }
            // Сохранение категории, если ID присутствует
            if ($category['id']) {
                $categories[$category['id']] = $category;
                error_log("Добавлена категория: ID={$category['id']}, Name={$category['name']}, ParentID={$parent_id}");
            } else {
                error_log("Пропущена категория: ID=" . ($category['id'] ?? 'пусто') . ", Name=" . ($category['name'] ?? 'пусто'));
            }
        } elseif ($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Группы') {
            break;
        }
    }
}

/**
 * Получает список категорий из import.xml.
 *
 * Функция проверяет наличие файла, валидирует XML и парсит категории с помощью XMLReader.
 *
 * @return array Ассоциативный массив категорий с ID в качестве ключей.
 */
function getCategories() {
    $categories = [];
    $file = IMPORT_DIR . 'import.xml';
    
    // Проверка существования файла
    if (!file_exists($file)) {
        error_log("Не удалось открыть import.xml: файл не существует");
        return $categories;
    }

    // Валидация XML-файла
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    if ($xml === false || !empty($errors)) {
        $error_msg = "Ошибки валидации import.xml: ";
        foreach ($errors as $error) {
            $error_msg .= trim($error->message) . " (строка {$error->line}); ";
        }
        error_log($error_msg);
        global $message;
        $message .= 'Ошибка валидации import.xml. ';
        return $categories;
    }

    // Чтение категорий из XML
    $reader = new XMLReader();
    if (!$reader->open($file)) {
        error_log("Не удалось открыть import.xml");
        return $categories;
    }

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Группы') {
            parseGroups($reader, '', $categories);
        }
    }

    $reader->close();
    error_log("Найдено категорий: " . count($categories));
    return $categories;
}

/**
 * Получает список товаров из import.xml и offers.xml.
 *
 * Функция парсит товары из import.xml и дополняет их ценами и остатками из offers.xml.
 * Поддерживает свойства товаров, такие как артикул, единица измерения и т.д.
 * Также обрабатывает изображения из <Картинка>, копирует их в IMAGES_DIR и обновляет images.json.
 *
 * @return array Массив с 'products' и 'stats' (successful, unsuccessful, with_images, without_images).
 */
function getProducts() {
    $products = [];
    $prices = [];
    $quantities = [];
    $images_data = []; // Для обновления images.json
    $successful = 0;
    $unsuccessful = 0;
    $with_images = 0;
    $without_images = 0;

    // Обработка offers.xml для цен и остатков
    $offers_file = IMPORT_DIR . 'offers.xml';
    if (file_exists($offers_file)) {
        // Валидация XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($offers_file);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($xml === false || !empty($errors)) {
            $error_msg = "Ошибки валидации offers.xml: ";
            foreach ($errors as $error) {
                $error_msg .= trim($error->message) . " (строка {$error->line}); ";
            }
            error_log($error_msg);
            global $message;
            $message .= 'Ошибка валидации offers.xml. ';
            return ['products' => [], 'stats' => ['successful' => 0, 'unsuccessful' => 0, 'with_images' => 0, 'without_images' => 0]];
        }

        $reader = new XMLReader();
        if ($reader->open($offers_file)) {
            error_log("offers.xml открыт успешно");
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Предложение') {
                    $offer = ['id' => ''];
                    while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Предложение')) {
                        if ($reader->nodeType == XMLReader::ELEMENT) {
                            $key = $reader->name;
                            $reader->read();
                            if ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) {
                                if ($key == 'Ид') {
                                    $offer['id'] = $reader->value ?: '';
                                } elseif ($key == 'Количество') {
                                    $offer['quantity'] = $reader->value !== '' ? (int)$reader->value : 0;
                                }
                            }
                            $reader->moveToElement();
                            if ($key == 'Цены') {
                                while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Цены')) {
                                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Цена') {
                                        while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Цена')) {
                                            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'ЦенаЗаЕдиницу') {
                                                $reader->read();
                                                if ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) {
                                                    $offer['price'] = (float)($reader->value ?? 0);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($offer['id']) {
                        if (isset($offer['price'])) {
                            $prices[$offer['id']] = $offer['price'];
                            error_log("Цена для ID={$offer['id']}: {$offer['price']}");
                        }
                        if (isset($offer['quantity'])) {
                            $quantities[$offer['id']] = $offer['quantity'];
                        }
                    } else {
                        error_log("Пропущено предложение: ID=" . ($offer['id'] ?? 'пусто') . ", Цена=" . (isset($offer['price']) ? $offer['price'] : 'не найдена'));
                    }
                }
            }
            $reader->close();
            error_log("Найдено цен: " . count($prices));
        } else {
            error_log("Не удалось открыть offers.xml");
        }
    }

    // Обработка import.xml для товаров
    $import_file = IMPORT_DIR . 'import.xml';
    if (file_exists($import_file)) {
        // Валидация XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($import_file);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($xml === false || !empty($errors)) {
            $error_msg = "Ошибки валидации import.xml для товаров: ";
            foreach ($errors as $error) {
                $error_msg .= trim($error->message) . " (строка {$error->line}); ";
            }
            error_log($error_msg);
            global $message;
            $message .= 'Ошибка валидации import.xml для товаров. ';
            return ['products' => [], 'stats' => ['successful' => 0, 'unsuccessful' => 0, 'with_images' => 0, 'without_images' => 0]];
        }

        $reader = new XMLReader();
        if (!$reader->open($import_file)) {
            error_log("Не удалось открыть import.xml для товаров");
            return ['products' => [], 'stats' => ['successful' => 0, 'unsuccessful' => 0, 'with_images' => 0, 'without_images' => 0]];
        }

        $in_catalog = false;
        $in_products = false;
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Каталог') {
                $in_catalog = true;
                continue;
            }

            if ($in_catalog && $reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Товары') {
                $in_products = true;
                continue;
            }

            if ($in_products && $reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Товар') {
                $product = ['id' => '', 'name' => ''];
                $product_images_temp = []; // Временный массив для изображений
                while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Товар')) {
                    if ($reader->nodeType == XMLReader::ELEMENT) {
                        $key = $reader->name;
                        $reader->read();
                        if ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) {
                            if ($key == 'Ид') {
                                $product['id'] = $reader->value ?: '';
                            } elseif ($key == 'Наименование') {
                                $product['name'] = $reader->value ?: 'Без названия';
                            } elseif ($key == 'Артикул') {
                                $product['article'] = $reader->value;
                            } elseif ($key == 'БазоваяЕдиница') {
                                $product['unit'] = $reader->value;
                            } elseif ($key == 'Картинка') {
                                $picture_path = $reader->value;
                                if ($picture_path) {
                                    $filename = basename($picture_path);
                                    $src = IMPORT_DIR . $filename;
                                    if (file_exists($src)) {
                                        $ext = pathinfo($src, PATHINFO_EXTENSION);
                                        $new_filename = uniqid('img_') . '.' . $ext;
                                        $destination = IMAGES_DIR . $new_filename;
                                        if (copy($src, $destination)) {
                                            $product_images_temp[] = $new_filename;
                                            error_log("Скопировано изображение для товара {$product['id']}: {$new_filename}");
                                        } else {
                                            error_log("Ошибка копирования изображения: {$src}");
                                        }
                                    } else {
                                        error_log("Изображение не найдено: {$src}");
                                    }
                                }
                            }
                        }
                        $reader->moveToElement();
                        if ($key == 'Группы') {
                            while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Группы')) {
                                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'Ид') {
                                    $reader->read();
                                    $product['category_id'] = $reader->value;
                                }
                            }
                        } elseif ($key == 'ЗначенияСвойств') {
                            while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'ЗначенияСвойств')) {
                                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'ЗначенияСвойства') {
                                    $prop_id = '';
                                    $value = '';
                                    while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'ЗначенияСвойства')) {
                                        if ($reader->nodeType == XMLReader::ELEMENT) {
                                            $prop_key = $reader->name;
                                            $reader->read();
                                            if ($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) {
                                                if ($prop_key == 'Ид') {
                                                    $prop_id = $reader->value;
                                                } elseif ($prop_key == 'Значение') {
                                                    $value = $reader->value;
                                                }
                                            }
                                        }
                                    }
                                    if ($prop_id == '221d2a4bfdae13dbd5aeff3b02adb8c1') {
                                        $product['quantity'] = $value !== '' ? (int)$value : 0;
                                    } elseif ($prop_id == '92a2b5cb9c6906035c2864fa225e1940') {
                                        $product['article'] = $value;
                                    } elseif ($prop_id == '3e34bdebd9bd5edda27e8728904a2552') {
                                        $product['unit'] = $value;
                                    } elseif ($prop_id == 'c13367945d5d4c91047b3b50234aa7ab') {
                                        $product['code'] = $value;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($product['id']) {
                    $product['price'] = $prices[$product['id']] ?? 0;
                    $product['quantity'] = isset($quantities[$product['id']]) ? $quantities[$product['id']] : ($product['quantity'] ?? 0);
                    $products[] = $product;
                    $successful++;
                    // Обработка изображений (до 6)
                    if (!empty($product_images_temp)) {
                        $images_data[$product['id']] = array_slice($product_images_temp, 0, 6);
                        $with_images++;
                    } else {
                        $without_images++;
                    }
                    error_log("Добавлен товар: ID={$product['id']}, Name={$product['name']}, Price={$product['price']}, Quantity={$product['quantity']}");
                } else {
                    $unsuccessful++;
                    error_log("Пропущен товар: ID=" . ($product['id'] ?? 'пусто') . ", Name=" . ($product['name'] ?? 'пусто'));
                }
            }

            if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Товары') {
                $in_products = false;
            }

            if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'Каталог') {
                $in_catalog = false;
            }
        }
        $reader->close();
        error_log("Найдено товаров: " . count($products));
    }

    // Сохранение images.json после парсинга
    $images_file = CACHE_DIR . 'images.json';
    file_put_contents($images_file, json_encode($images_data, JSON_UNESCAPED_UNICODE));

    $stats = [
        'successful' => $successful,
        'unsuccessful' => $unsuccessful,
        'with_images' => $with_images,
        'without_images' => $without_images
    ];

    return ['products' => $products, 'stats' => $stats];
}

/**
 * Генерирует кэш категорий и товаров в JSON-файлы.
 *
 * Функция получает категории и товары, сериализует их в JSON и записывает в кэш.
 * Также синхронизирует изображения и описания с товарами.
 *
 * @return array Статистика обработки товаров.
 */
function generateCache() {
    $categories = getCategories();
    $products_data = getProducts();
    $products = $products_data['products'];
    $stats = $products_data['stats'];
    
    // Сериализация данных в JSON с поддержкой UTF-8
    $categories_json = json_encode($categories, JSON_UNESCAPED_UNICODE);
    $products_json = json_encode($products, JSON_UNESCAPED_UNICODE);
    
    // Запись кэша в файлы
    $categories_written = file_put_contents(CACHE_DIR . 'categories.json', $categories_json);
    $products_written = file_put_contents(CACHE_DIR . 'products.json', $products_json);
    
    global $message;
    if ($categories_written === false || $products_written === false) {
        error_log("Ошибка записи кэша: categories.json=" . ($categories_written === false ? 'failed' : 'ok') . ", products.json=" . ($products_written === false ? 'failed' : 'ok'));
        $message .= 'Ошибка при записи кэша. ';
    } else {
        error_log("Кэш обновлён: categories.json (" . ($categories_written !== false ? $categories_written : '0') . " bytes), products.json (" . ($products_written !== false ? $products_written : '0') . " bytes)");
        // Синхронизация изображений и описаний после обновления кэша
        if (syncImagesWithProducts()) {
            $message .= 'Изображения успешно синхронизированы. ';
        } else {
            $message .= 'Ошибка при синхронизации изображений. ';
        }
        if (syncDescriptionsWithProducts()) {
            $message .= 'Описания успешно синхронизированы. ';
        } else {
            $message .= 'Ошибка при синхронизации описаний. ';
        }
        // Обновляем глобальную версию сайта после генерации кэша
        file_put_contents(CACHE_DIR . 'last_update.txt', time());
    }

    return $stats;
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена для защиты от атак
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        error_log("CSRF проверка не пройдена для пользователя: " . ($_SESSION['email'] ?? 'unknown'));
        exit('CSRF проверка не пройдена');
    }

    // Обработка выхода из системы
    if (isset($_POST['logout'])) {
        // Удаление remember me, если активно
        if (isset($_SESSION['email'])) {
            $remember_data = file_exists($remember_file) ? json_decode(file_get_contents($remember_file), true) ?? [] : [];
            unset($remember_data[$_SESSION['email']]);
            file_put_contents($remember_file, json_encode($remember_data));
        }
        setcookie(REMEMBER_COOKIE_NAME, '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        session_destroy();
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
        header('Location: login?loggedout=1');
        exit;
    }

    // Очистка каталога
    if (isset($_POST['action']) && $_POST['action'] === 'clear_catalog') {
        // Очистка директорий
        clearDirectory(CACHE_DIR, ['.htaccess', 'last_update.txt', 'blacklist.json', 'remember.json']);
        clearDirectory(IMAGES_DIR, ['.htaccess']);
        clearDirectory(IMPORT_DIR, ['.htaccess']);
        // Обновляем версию сайта
        file_put_contents(CACHE_DIR . 'last_update.txt', time());
        echo json_encode(['success' => true, 'message' => 'Каталог успешно очищен.']);
        exit;
    }

    // Загрузка ZIP-архива
    if (isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['zip_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $message = 'Ошибка: файл должен быть ZIP-архивом';
        } else {
            // Проверка MIME-типа файла
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['zip_file']['tmp_name']);
            finfo_close($finfo);
            if ($mime !== 'application/zip') {
                $message = 'Ошибка: файл не распознан как ZIP (MIME: ' . htmlspecialchars($mime) . ')';
                error_log("Попытка загрузить не-ZIP как архив (MIME: {$mime})");
            } else {
                $tmp_name = $_FILES['zip_file']['tmp_name'];
                $zip_destination = __DIR__ . '/temp_upload.zip';
                if (move_uploaded_file($tmp_name, $zip_destination)) {
                    // Создание файла блокировки
                    touch(PROCESSING_LOCK);
                    try {
                        // Для потокового вывода (но без NDJSON, просто text/plain для совместимости)
                        header('Content-Type: text/plain; charset=utf-8');

                        // Очистка IMPORT_DIR (с сохранением .htaccess)
                        if (!is_dir(IMPORT_DIR)) {
                            mkdir(IMPORT_DIR, 0755, true);
                        }
                        clearDirectory(IMPORT_DIR, ['.htaccess']);

                        // Распаковка ZIP
                        $zip = new ZipArchive();
                        if ($zip->open($zip_destination) === true) {
                            $zip->extractTo(IMPORT_DIR);
                            $zip->close();
                            unlink($zip_destination);

                            // Генерация кэша
                            $categories = getCategories();
                            $products_data = getProducts();
                            $products = $products_data['products'];
                            $stats = $products_data['stats'];

                            $categories_json = json_encode($categories, JSON_UNESCAPED_UNICODE);
                            $products_json = json_encode($products, JSON_UNESCAPED_UNICODE);

                            file_put_contents(CACHE_DIR . 'categories.json', $categories_json);
                            file_put_contents(CACHE_DIR . 'products.json', $products_json);

                            syncImagesWithProducts();
                            syncDescriptionsWithProducts();

                            file_put_contents(CACHE_DIR . 'last_update.txt', time());

                            echo json_encode(['success' => true, 'stats' => $stats]);
                        } else {
                            $err = error_get_last();
                            error_log("Ошибка распаковки ZIP: " . ($err['message'] ?? 'неизвестная'));
                            echo json_encode(['success' => false, 'error' => 'Ошибка при распаковке ZIP']);
                        }
                    } finally {
                        // Удаление файла блокировки
                        if (file_exists(PROCESSING_LOCK)) {
                            unlink(PROCESSING_LOCK);
                        }
                    }
                    exit;
                } else {
                    $err = error_get_last();
                    error_log("Ошибка move_uploaded_file для ZIP: " . ($err['message'] ?? 'неизвестная'));
                    echo json_encode(['success' => false, 'error' => 'Ошибка при загрузке ZIP']);
                    exit;
                }
            }
        }
    } else {
        if (isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла']);
            exit;
        }
    }

    // Обработка добавления IP в blacklist
    if (isset($_POST['action']) && $_POST['action'] === 'add_ip') {
        $ip = $_POST['ip'] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            addToBlacklist($ip);
            echo json_encode(['success' => true, 'message' => 'IP добавлен в черный список.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Некорректный IP-адрес.']);
        }
        exit;
    }

    // Обработка удаления IP из blacklist
    if (isset($_POST['action']) && $_POST['action'] === 'remove_ip') {
        $ip = $_POST['ip'] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            removeFromBlacklist($ip);
            echo json_encode(['success' => true, 'message' => 'IP удален из черного списка.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Некорректный IP-адрес.']);
        }
        exit;
    }
}

/**
 * Строит дерево категорий для отображения в select.
 *
 * @param array $categories Список категорий.
 * @param string $parent_id ID родительской категории.
 * @return array Дерево категорий.
 */
function buildCategoryTree($categories, $parent_id = '') {
    $tree = [];
    foreach ($categories as $cat) {
        if ($cat['parent_id'] == $parent_id) {
            $cat['children'] = buildCategoryTree($categories, $cat['id']);
            $tree[] = $cat;
        }
    }
    return $tree;
}

/**
 * Рекурсивно генерирует опции для select с отступами.
 *
 * @param array $categories Дерево категорий.
 * @param int $level Уровень вложенности.
 * @return string HTML опций.
 */
function generateCategoryOptions($categories, $level = 0) {
    $options = '';
    foreach ($categories as $cat) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level); // Увеличенный отступ
        $prefix = ($level > 0) ? '└─ ' : ''; // Символ для дочерних
        $options .= '<option value="' . htmlspecialchars($cat['id']) . '" data-level="' . $level . '">' . $indent . $prefix . htmlspecialchars($cat['name']) . '</option>';
        if (!empty($cat['children'])) {
            $options .= generateCategoryOptions($cat['children'], $level + 1);
        }
    }
    return $options;
}

// Загрузка категорий для select
$categories = json_decode(file_get_contents(CACHE_DIR . 'categories.json'), true) ?? [];
$category_tree = buildCategoryTree($categories);
$category_options = generateCategoryOptions($category_tree);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <!-- Установка кодировки и адаптивности -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Админ-панель - «Мир обоев»</title>
    <!-- Подключение стилей Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;justify-content:center;align-items:center;flex-direction:column}.overlay .spinner-border{width:3rem;height:3rem;margin-bottom:1rem}.overlay-text{color:#fff;font-size:1.2rem;font-weight:700}.disabled-overlay{pointer-events:none;opacity:.6}.card{margin-bottom:20px}option[data-level="0"]{font-weight:700;color:#000}option[data-level]:not([data-level="0"]){color:#555}</style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-5">
        <!-- Заголовок и форма выхода -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Админ-панель</h1>
            <form method="post" id="logout-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit" name="logout" class="btn btn-danger">Выйти</button>
            </form>
        </div>
        <!-- Отображение сообщений -->
        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <!-- Блок: Информация о пользователе и статистика -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Информация о пользователе и статистика</h5>
            </div>
            <div class="card-body">
                <p>Вы вошли как: <?php echo htmlspecialchars($_SESSION['email'] ?? 'Неизвестный пользователь'); ?></p>
                <p>Роль: <?php echo htmlspecialchars($_SESSION['role'] ?? 'Неизвестная роль'); ?></p>
                <?php
                // Отображение статистики товаров
                $products_file = CACHE_DIR . 'products.json';
                $total_products = 0;
                if (file_exists($products_file)) {
                    $products = json_decode(file_get_contents($products_file), true);
                    if (is_array($products)) {
                        $total_products = count($products);
                    }
                }
                echo "<p>Всего карточек товара: {$total_products}</p>";
                ?>
            </div>
        </div>

        <!-- Блок: Загрузка ZIP-архива -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Загрузка ZIP-архива</h5>
            </div>
            <div class="card-body">
                <form id="zip-form" method="post" enctype="multipart/form-data" class="input-group mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="file" class="form-control" id="zip_file" name="zip_file" accept=".zip" aria-label="Загрузить ZIP-архив">
                    <button type="submit" class="btn btn-primary">Загрузить</button>
                </form>
                <label for="zip_file" class="form-label">Загрузить ZIP-архив (содержит import.xml, offers.xml и изображения)</label>
            </div>
        </div>

        <!-- Новый блок: Массовое добавление описания для категории -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Массовое добавление описания для категории</h5>
            </div>
            <div class="card-body">
                <form id="batch-description-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label for="category-select" class="form-label">Выберите категорию</label>
                        <select id="category-select" class="form-select" required>
                            <option value="">-- Выберите категорию --</option>
                            <?php echo $category_options; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="batch-description" class="form-label">Описание</label>
                        <textarea id="batch-description" class="form-control description-textarea" placeholder="Введите описание для всех товаров в категории" rows="4"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Сохранить описание</button>
                </form>
            </div>
        </div>

        <!-- Блок: Очистка каталога -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Очистка каталога</h5>
            </div>
            <div class="card-body">
                <form id="clear-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="clear_catalog">
                    <button type="submit" class="btn btn-danger">Удалить каталог</button>
                </form>
            </div>
        </div>

        <!-- Блок: Черный список IP-адресов -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Черный список IP-адресов</h5>
            </div>
            <div class="card-body">
                <form id="add-ip-form" method="post" class="input-group mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add_ip">
                    <input type="text" name="ip" class="form-control" placeholder="Введите IP-адрес" required>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>
                <ul class="list-group" id="blacklist-list">
                    <?php
                    $blacklist = getBlacklist();
                    foreach ($blacklist as $ip) {
                        echo '<li class="list-group-item d-flex justify-content-between align-items-center">' . htmlspecialchars($ip) . 
                             '<button class="btn btn-sm btn-danger remove-ip" data-ip="' . htmlspecialchars($ip) . '">Удалить</button></li>';
                    }
                    ?>
                </ul>
            </div>
        </div>
    </div>
    <!-- Модальное окно для статистики загрузки -->
    <div class="modal fade" id="upload-stats-modal" tabindex="-1" aria-labelledby="uploadStatsLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadStatsLabel">Статистика загрузки</h5>
                </div>
                <div class="modal-body">
                    <p>Успешно сгенерированных карточек товара: <span id="successful-count">0</span></p>
                    <p>Неуспешно сгенерированных карточек товара: <span id="unsuccessful-count">0</span></p>
                    <p>Карточек товара с изображениями: <span id="with-images-count">0</span></p>
                    <p>Карточек товара без изображений: <span id="without-images-count">0</span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Ок</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Модальное окно для подтверждения массового обновления описания -->
    <div class="modal fade" id="batch-confirm-modal" tabindex="-1" aria-labelledby="batchConfirmLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="batchConfirmLabel">Подтверждение</h5>
                </div>
                <div class="modal-body">
                    <p>Это действие перезапишет уже существующие описания у товаров в выбранной вами категории. Вы уверены?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="confirm-batch-save">Да</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Оверлей для отображения загрузки -->
    <div class="overlay" id="loading-overlay">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
        <div class="overlay-text" id="overlay-text">Обработка в процессе...</div>
    </div>
    <!-- Подключение скриптов Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция показа оверлея загрузки
        function showOverlay(text = 'Обработка в процессе...') {
            document.getElementById('loading-overlay').style.display = 'flex';
            document.getElementById('overlay-text').textContent = text;
        }
        // Функция скрытия оверлея загрузки
        function hideOverlay() {
            document.getElementById('loading-overlay').style.display = 'none';
        }
        // Обработка формы с загрузкой ZIP через AJAX
        document.getElementById('zip-form').addEventListener('submit', function(e) {
            e.preventDefault();
            showOverlay();
            const formData = new FormData(this);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'admin', true);
            xhr.responseType = 'text';

            xhr.onload = function() {
                hideOverlay();
                try {
                    const progressData = JSON.parse(xhr.responseText);
                    if (progressData.success !== undefined) {
                        // Финальный ответ с stats
                        localStorage.setItem('upload_stats', JSON.stringify(progressData.stats));
                        location.reload();
                    } else if (progressData.error !== undefined) {
                        alert(progressData.error);
                    }
                } catch (err) {
                    console.error('Ошибка парсинга ответа:', err);
                    alert('Ошибка при обработке ZIP');
                }
            };

            xhr.onerror = function() {
                hideOverlay();
                alert('Ошибка при загрузке');
            };
            xhr.send(formData);
        });
        // Обработка формы очистки каталога через AJAX с симуляцией прогресса
        document.getElementById('clear-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!confirm('Вы уверены, что хотите удалить каталог?')) return;
            showOverlay('Удаление в процессе...');
            const formData = new FormData(this);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'admin', true);
            xhr.onload = function() {
                hideOverlay();
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (xhr.status === 200 && result.success) {
                        // Очистка браузерного кэша
                        Object.keys(localStorage).forEach(key => {
                            if (key.startsWith('product_') || key.startsWith('products_')) {
                                localStorage.removeItem(key);
                            }
                        });
                        location.reload();
                    } else {
                        alert(result.message || 'Ошибка при очистке каталога');
                    }
                } catch (err) {
                    alert('Ошибка при очистке каталога');
                }
            };
            xhr.onerror = function() {
                hideOverlay();
                alert('Ошибка при очистке каталога');
            };
            xhr.send(formData);
        });
        // Автоматическое скрытие оверлея после загрузки страницы
        window.addEventListener('load', function() {
            hideOverlay();
            // Показ модального окна со статистикой, если есть в localStorage
            const stats = JSON.parse(localStorage.getItem('upload_stats'));
            if (stats) {
                document.getElementById('successful-count').textContent = stats.successful;
                document.getElementById('unsuccessful-count').textContent = stats.unsuccessful;
                document.getElementById('with-images-count').textContent = stats.with_images;
                document.getElementById('without-images-count').textContent = stats.without_images;
                const modal = new bootstrap.Modal(document.getElementById('upload-stats-modal'));
                modal.show();
                localStorage.removeItem('upload_stats');
            }
        });
        // Обработка добавления IP через AJAX
        document.getElementById('add-ip-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('admin', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload(); // Перезагрузка для обновления списка
                    } else {
                        alert(data.error || 'Ошибка при добавлении IP');
                    }
                })
                .catch(err => alert('Ошибка: ' + err.message));
        });
        // Обработка удаления IP через AJAX
        document.querySelectorAll('.remove-ip').forEach(btn => {
            btn.addEventListener('click', function() {
                const ip = this.dataset.ip;
                if (!confirm(`Удалить IP ${ip}?`)) return;
                const formData = new FormData();
                formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
                formData.append('action', 'remove_ip');
                formData.append('ip', ip);
                fetch('admin', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            location.reload(); // Перезагрузка для обновления списка
                        } else {
                            alert(data.error || 'Ошибка при удалении IP');
                        }
                    })
                    .catch(err => alert('Ошибка: ' + err.message));
            });
        });
        // Обработка формы массового добавления описания
        document.getElementById('batch-description-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('batch-confirm-modal'));
            modal.show();
            document.getElementById('confirm-batch-save').onclick = () => {
                modal.hide();
                const categoryId = document.getElementById('category-select').value;
                const description = document.getElementById('batch-description').value.trim();
                const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
                if (!categoryId) {
                    alert('Выберите категорию');
                    return;
                }
                fetch('api/products', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'batch_description',
                        category_id: categoryId,
                        description: description,
                        csrf_token: csrfToken
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Описание успешно сохранено для категории');
                        location.reload();
                    } else {
                        alert(data.error || 'Ошибка при сохранении описания');
                    }
                })
                .catch(err => alert('Ошибка: ' + err.message));
            };
        });
    </script>
</body>
</html>