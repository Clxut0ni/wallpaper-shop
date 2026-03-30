<?php
/**
 * File: product.php
 *
 * Страница детального просмотра товара.
 *
 * Отображает информацию о товаре, изображения, описание. Позволяет администраторам
 * загружать изображения и редактировать описание. Поддерживает drag-and-drop для изображений.
 */
// Запуск сессии для проверки роли пользователя
session_start();
// Подключение конфигурационного файла
require_once 'config.php';
/**
 * Получает название категории по её ID.
 *
 * @param string $category_id ID категории.
 * @return string|null Название категории или null, если не найдена.
 */
function getCategoryById($category_id) {
    $cache_file = CACHE_DIR . 'categories.json';
    if (file_exists($cache_file)) {
        $categories = json_decode(file_get_contents($cache_file), true);
        if (isset($categories[$category_id])) {
            return $categories[$category_id]['name'] ?: 'Без названия';
        }
    }
    return null;
}
/**
 * Получает товар по его ID.
 *
 * @param string $id ID товара.
 * @return array|null Данные товара или null, если не найден или остаток <= 0.
 */
function getProductById($id) {
    $cache_file = CACHE_DIR . 'products.json';
    if (file_exists($cache_file)) {
        $products = json_decode(file_get_contents($cache_file), true);
        foreach ($products as $product) {
            if ($product['id'] == $id) {
                return $product;
            }
        }
    }
    return null;
}
/**
 * Получает изображения товара.
 *
 * @param string $product_id ID товара.
 * @return array Список путей к изображениям (до 6) или градиенты по умолчанию.
 */
function getProductImages($product_id) {
    $images_file = CACHE_DIR . 'images.json';
    $default_gradient = 'linear-gradient(135deg, #00a6df, #59c3ff)';
    $images = array_fill(0, 6, $default_gradient);
    if (file_exists($images_file)) {
        $image_data = json_decode(file_get_contents($images_file), true) ?? [];
        if (isset($image_data[$product_id])) {
            foreach ($image_data[$product_id] as $index => $filename) {
                if ($index < 6 && !empty($filename) && file_exists(IMAGES_DIR . $filename)) {
                    $images[$index] = 'images/' . $filename;
                }
            }
        }
    }
    return $images;
}
/**
 * Получает описание товара.
 *
 * @param string $product_id ID товара.
 * @return string|null Описание товара или null, если отсутствует.
 */
function getProductDescription($product_id) {
    $descriptions_file = CACHE_DIR . 'descriptions.json';
    if (file_exists($descriptions_file)) {
        $descriptions = json_decode(file_get_contents($descriptions_file), true) ?? [];
        return $descriptions[$product_id] ?? null;
    }
    return null;
}
// Получение ID товара и данных
$product_id = $_GET['id'] ?? '';
$product = $product_id ? getProductById($product_id) : null;
// Замена фамилий в артикуле для не-админов
if ($product) {
    $product['article'] = replaceArticle($product['article'] ?? '', $is_admin);
}
$category_name = $product && isset($product['category_id']) ? getCategoryById($product['category_id']) : null;
$images = getProductImages($product_id);
$description = getProductDescription($product_id);
// Проверка прав доступа для загрузки изображений и редактирования описания
$is_admin = isset($_SESSION['logged_in']) && in_array($_SESSION['role'] ?? '', ['admin', 'developer']);
// Валидация параметра 'from' для безопасной обратной ссылки
$backLink = 'index';
if (!empty($_GET['from'])) {
    $decoded = urldecode($_GET['from']);
    $parsed = parse_url($decoded);
    if (isset($parsed['path']) && (basename($parsed['path']) === 'index' || $parsed['path'] === '')) {
        $allowed_params = ['category', 'search', 'page'];
        $query_str = $parsed['query'] ?? '';
        parse_str($query_str, $params);
        if (array_diff_key($params, array_flip($allowed_params)) === []) {
            $backLink = 'index' . ($query_str ? '?' . $query_str : '');
        } else {
            error_log("Недопустимые параметры в from: " . htmlspecialchars($query_str));
        }
    } else {
        error_log("Игнорирован неподходящий from-параметр: " . htmlspecialchars($decoded));
    }
}
// Вычисление даты последнего изменения import.xml
$import_file = IMPORT_DIR . 'import.xml';
$import_date = file_exists($import_file) ? date('d.m.Y', filemtime($import_file)) : 'неизвестна';
// Для обычных пользователей: фильтруем незаполненные слоты
if (!$is_admin) {
    $visible_images = array_filter($images, function($img) {
        return $img !== 'linear-gradient(135deg, #00a6df, #59c3ff)';
    });
    if (empty($visible_images)) {
        $visible_images = ['linear-gradient(135deg, #00a6df, #59c3ff)'];
    }
} else {
    $visible_images = $images; // Для админов показываем все 6 слотов
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <!-- Установка кодировки и адаптивности -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Товар - «Мир обоев»</title>
    <!-- Подключение стилей Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Стили для контейнера изображений */
        .product-image-container {
            position: relative; width: 100%; height: 200px; margin-bottom: 1rem;
            background-size: cover; background-position: center;
            border-radius: calc(0.25rem - 1px);
            cursor: <?php echo $is_admin ? 'pointer' : 'default'; ?>;
        }
        .product-image-container:hover { opacity: <?php echo $is_admin ? '0.8' : '1'; ?>; }
        .product-image {
            width: 100%; height: 100%; object-fit: cover;
            border-radius: calc(0.25rem - 1px);
        }

        /* Стили для крестика удаления */
        .delete-image {
            position: absolute; top: 5px; right: 5px;
            background: red; color: white; border: none; border-radius: 50%;
            width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.8;
        }
        .delete-image:hover { opacity: 1; }

        /* Стили для drag-and-drop */
        .dragover { border: 2px dashed #A73378; background: rgba(167, 51, 120, 0.1); }

        /* Стили для прогресс-бара */
        .progress-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.3); display: flex; align-items: center; justify-content: center;
            border-radius: calc(0.25rem - 1px); z-index: 10;
        }
        .progress-bar {
            width: 80%; height: 10px; background: #e0e0e0;
            border-radius: 5px; overflow: hidden;
        }
        .progress-bar-inner {
            height: 100%; background: #A73378; width: 0;
            transition: width 0.3s ease;
        }

        /* Стили для поля описания и кнопок */
        .description-textarea { width: 100%; min-height: 100px; resize: vertical; }
        .save-description-btn, .edit-description-btn { margin-top: 10px; margin-right: 10px; }

        /* Стили для оверлея загрузки описания */
        .overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9999;
            justify-content: center; align-items: center; flex-direction: column;
        }
        .overlay .spinner-border { width: 3rem; height: 3rem; margin-bottom: 1rem; }
        .overlay-text { color: white; font-size: 1.2rem; font-weight: bold; }
        .disabled-overlay { pointer-events: none; opacity: 0.6; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-5">
        <?php if ($product): ?>
            <!-- Отображение информации о товаре -->
            <div class="card product-card">
                <div class="card-body">
                    <h1 class="card-title"><?php echo htmlspecialchars($product['name'] ?: 'Без названия'); ?></h1>
                    <!-- Галерея изображений -->
                    <div class="row mb-3">
                        <?php foreach ($visible_images as $index => $image): ?>
                            <div class="col-md-4">
                                <div class="product-image-container"
                                     data-index="<?php echo $index; ?>"
                                     data-product-id="<?php echo htmlspecialchars($product_id); ?>"
                                     <?php if ($is_admin): ?>onclick="document.getElementById('image-upload-<?php echo $index; ?>').click();"<?php endif; ?>>
                                    <?php if ($image !== 'linear-gradient(135deg, #00a6df, #59c3ff)'): ?>
                                        <?php if ($is_admin): ?>
                                            <img src="<?php echo htmlspecialchars($image); ?>" class="product-image" alt="Изображение товара" loading="lazy">
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($image); ?>" target="_blank">
                                                <img src="<?php echo htmlspecialchars($image); ?>" class="product-image" alt="Изображение товара" loading="lazy">
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($is_admin): ?>
                                            <button class="delete-image" data-index="<?php echo $index; ?>" data-product-id="<?php echo htmlspecialchars($product_id); ?>" title="Удалить изображение">×</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="product-image" style="background-image: <?php echo $image; ?>"></div>
                                    <?php endif; ?>
                                    <?php if ($is_admin): ?>
                                        <input type="file" id="image-upload-<?php echo $index; ?>" style="display: none;" accept="image/jpeg,image/png,image/webp">
                                        <div class="progress-overlay" id="progress-overlay-<?php echo $index; ?>" style="display: none;">
                                            <div class="progress-bar">
                                                <div class="progress-bar-inner" id="progress-bar-<?php echo $index; ?>" style="width: 0%;"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="card-text"><strong>Категория:</strong> <?php echo htmlspecialchars($category_name ?: 'Без категории'); ?></p>
                    <p class="card-text"><strong>Артикул:</strong> <?php echo htmlspecialchars($product['article'] ?? ''); ?></p>
                    <p class="card-text"><strong>Цена на <?php echo htmlspecialchars($import_date); ?>:</strong> <?php echo number_format($product['price'], 2); ?> тг.</p>
                    <p class="card-text"><strong>Остаток на <?php echo htmlspecialchars($import_date); ?>:</strong> <?php echo $product['quantity'] ?? 0; ?> шт.</p>
                    <?php if ($description): ?>
                        <p class="card-text"><strong>Описание:</strong> <?php echo htmlspecialchars($description); ?></p>
                    <?php endif; ?>
                    <?php if ($is_admin): ?>
                        <div class="mt-3">
                            <label for="product-description" class="form-label"><strong>Описание товара:</strong></label>
                            <textarea id="product-description" class="form-control description-textarea" placeholder="Введите описание товара" <?php echo $description ? 'disabled' : ''; ?>><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                            <button class="btn btn-primary save-description-btn" id="save-description" data-product-id="<?php echo htmlspecialchars($product_id); ?>" style="<?php echo $description ? 'display: none;' : ''; ?>">Сохранить описание</button>
                            <?php if ($description): ?>
                                <button class="btn btn-outline-primary edit-description-btn" id="edit-description">Редактировать описание</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($backLink); ?>" class="btn btn-secondary mt-3" id="back-to-catalog">Назад к каталогу</a>
                    <button class="btn btn-success add-to-cart mt-3" data-product-id="<?php echo htmlspecialchars($product_id); ?>" data-product-name="<?php echo htmlspecialchars($product['name']); ?>" data-product-article="<?php echo htmlspecialchars($product['article'] ?? ''); ?>" data-product-price="<?php echo $product['price']; ?>" data-quantity-max="<?php echo $product['quantity'] ?? 0; ?>">В корзину</button> <!-- Убрано условие quantity > 0 -->
                </div>
            </div>
        <?php else: ?>
            <!-- Сообщение, если товар не найден -->
            <h1>Товар отсутствует</h1>
            <p>Товар с ID <?php echo htmlspecialchars($product_id); ?> либо не существует, либо отсутствует в наличии.</p>
            <a href="index" class="btn btn-secondary">Назад к каталогу</a>
        <?php endif; ?>
    </div>
    <!-- Оверлей для отображения загрузки -->
    <div class="overlay" id="loading-overlay">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
        <div class="overlay-text">Идет загрузка...</div>
    </div>
    <!-- Подключение скриптов Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Защита от XSS: экранирование HTML-символов
        function escapeHtml(str) {
            if (typeof str !== 'string') return str;
            return str.replace(/[&<>"'`=\/]/g, s => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
                "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
            })[s]);
        }
        <?php if ($is_admin): ?>
        // Функция для отправки файла с прогрессом (для изображений)
        function uploadImage(file, productId, index, csrfToken) {
            const formData = new FormData();
            formData.append('image', file);
            formData.append('product_id', productId);
            formData.append('index', index);
            formData.append('csrf_token', csrfToken);
            const progressOverlay = document.getElementById(`progress-overlay-${index}`);
            const progressBar = document.getElementById(`progress-bar-${index}`);
            progressOverlay.style.display = 'flex';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/images', true);
            // Отслеживание прогресса загрузки
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percent = (e.loaded / e.total) * 100;
                    progressBar.style.width = `${percent}%`;
                }
            };
            xhr.onload = function() {
                progressOverlay.style.display = 'none';
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (xhr.status === 200 && result.success) {
                        // Очистка кэша localStorage для обновления изображений
                        Object.keys(localStorage).forEach(key => {
                            if (key.startsWith('products_')) {
                                localStorage.removeItem(key);
                            }
                        });
                        window.location.reload();
                    } else {
                        alert(result.error || 'Ошибка при загрузке изображения');
                    }
                } catch (err) {
                    console.error('Ошибка парсинга ответа:', err);
                    alert('Ошибка при загрузке изображения');
                }
            };
            xhr.onerror = function() {
                progressOverlay.style.display = 'none';
                console.error('Ошибка загрузки:', xhr.statusText);
                alert('Ошибка при загрузке изображения');
            };
            xhr.send(formData);
        }
        // Обработка загрузки изображений через input
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', async (e) => {
                const index = e.target.id.replace('image-upload-', '');
                const productId = '<?php echo htmlspecialchars($product_id); ?>';
                const file = e.target.files[0];
                if (!file) return;
                uploadImage(file, productId, index, '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>');
            });
        });
        // Обработка drag-and-drop для изображений
        document.querySelectorAll('.product-image-container').forEach(container => {
            container.addEventListener('dragover', (e) => {
                e.preventDefault();
                container.classList.add('dragover');
            });
            container.addEventListener('dragleave', () => {
                container.classList.remove('dragover');
            });
            container.addEventListener('drop', async (e) => {
                e.preventDefault();
                container.classList.remove('dragover');
                const index = container.dataset.index;
                const productId = container.dataset.productId;
                const file = e.dataTransfer.files[0];
                if (!file) return;
                uploadImage(file, productId, index, '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>');
            });
        });
        // Обработка удаления изображений
        document.querySelectorAll('.delete-image').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.stopPropagation();
                const index = e.target.dataset.index;
                const productId = e.target.dataset.productId;
                if (!confirm('Вы уверены, что хотите удалить это изображение?')) return;
                try {
                    const res = await fetch('api/images', {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            product_id: productId,
                            index: index,
                            csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>'
                        })
                    });
                    const result = await res.json();
                    if (result.success) {
                        // Очистка кэша localStorage для обновления изображений
                        Object.keys(localStorage).forEach(key => {
                            if (key.startsWith('products_')) {
                                localStorage.removeItem(key);
                            }
                        });
                        window.location.reload();
                    } else {
                        alert(result.error || 'Ошибка при удалении изображения');
                    }
                } catch (err) {
                    console.error('Ошибка удаления:', err);
                    alert('Ошибка при удалении изображения');
                }
            });
        });
        // Обработка сохранения описания
        document.getElementById('save-description').addEventListener('click', async () => {
            const productId = '<?php echo htmlspecialchars($product_id); ?>';
            const description = document.getElementById('product-description').value.trim();
            const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
            const textarea = document.getElementById('product-description');
            const saveButton = document.getElementById('save-description');
            const overlay = document.getElementById('loading-overlay');
            const container = document.querySelector('.container');
            // Показать спиннер
            overlay.style.display = 'flex';
            container.classList.add('disabled-overlay');
            try {
                const res = await fetch('api/products', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: productId,
                        description: description,
                        csrf_token: csrfToken
                    })
                });
                const result = await res.json();
                overlay.style.display = 'none';
                container.classList.remove('disabled-overlay');
                if (result.success) {
                    // Блокируем textarea, скрываем "Сохранить", показываем "Редактировать"
                    textarea.disabled = true;
                    saveButton.style.display = 'none';
                    let editButton = document.getElementById('edit-description');
                    if (!editButton) {
                        editButton = document.createElement('button');
                        editButton.id = 'edit-description';
                        editButton.className = 'btn btn-outline-primary edit-description-btn';
                        editButton.textContent = 'Редактировать описание';
                        saveButton.insertAdjacentElement('afterend', editButton);
                        // Обработчик для кнопки редактирования
                        editButton.addEventListener('click', () => {
                            textarea.disabled = false;
                            editButton.style.display = 'none';
                            saveButton.style.display = 'inline-block';
                        });
                    } else {
                        editButton.style.display = 'inline-block';
                    }
                    // Очистка кэша localStorage и перезагрузка страницы
                    Object.keys(localStorage).forEach(key => {
                        if (key.startsWith('products_')) {
                            localStorage.removeItem(key);
                        }
                    });
                    window.location.reload();
                } else {
                    alert(result.error || 'Ошибка при сохранении описания');
                }
            } catch (err) {
                console.error('Ошибка сохранения описания:', err);
                overlay.style.display = 'none';
                container.classList.remove('disabled-overlay');
                alert('Ошибка при сохранении описания');
            }
        });
        // Инициализация кнопки редактирования, если она уже есть
        const editButton = document.getElementById('edit-description');
        if (editButton) {
            editButton.addEventListener('click', () => {
                document.getElementById('product-description').disabled = false;
                editButton.style.display = 'none';
                document.getElementById('save-description').style.display = 'inline-block';
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
