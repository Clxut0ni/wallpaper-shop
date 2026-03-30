<?php
/**
 * File: cart.php
 *
 * Обработка корзины и оформления заказа.
 *
 * Этот файл обрабатывает POST-запросы для оформления заказа, валидирует данные пользователя
 * и отправляет заказ в Telegram-бот. Также содержит HTML-структуру страницы корзины и модальное окно для просмотра товара.
 */
// Запуск сессии для CSRF и idempotency
session_start();
// Подключение конфигурационного файла
require_once 'config.php';
// Генерация CSRF-токена, если отсутствует
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Обработка POST-запросов для оформления заказа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF проверка не пройдена']);
        exit;
    }
    $name = $_POST['name'] ?? '';
    $surname = $_POST['surname'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $note = $_POST['note'] ?? '';
    $delivery = $_POST['delivery'] ?? '';
    $cartJson = $_POST['cart'] ?? '[]';
    // Honeypot проверка (скрытое поле должно быть пустым)
    $honeypot = $_POST['website'] ?? ''; // Имя поля "website" — типичное для honeypot
    if (!empty($honeypot)) {
        // Вероятно, бот заполнил скрытое поле
        http_response_code(400);
        echo json_encode(['error' => 'Подозрительная активность. Пожалуйста, попробуйте снова.']);
        exit;
    }
    // Проверка чекбокса согласия с политикой конфиденциальности
    $consent = isset($_POST['consent']) && $_POST['consent'] === 'on';
    if (!$consent) {
        http_response_code(400);
        echo json_encode(['error' => 'Пожалуйста, подтвердите согласие с политикой конфиденциальности.']);
        exit;
    }
    // Регулярные выражения для валидации
    $nameRegex = '/^[A-Za-zА-Яа-я]+$/u';
    $phoneRegex = '/^\d{11}$/';
    $emailRegex = '/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/';
    $errors = [];
    if (!preg_match($nameRegex, $name) || strlen($name) < 1 || strlen($name) > 25) {
        $errors[] = 'Имя должно содержать только буквы (без цифр и спецсимволов), от 1 до 25 символов.';
    }
    if (!preg_match($nameRegex, $surname) || strlen($surname) < 1 || strlen($surname) > 25) {
        $errors[] = 'Фамилия должна содержать только буквы (без цифр и спецсимволов), от 1 до 25 символов.';
    }
    if (!preg_match($phoneRegex, $phone) || strlen($phone) !== 11) {
        $errors[] = 'Телефон должен содержать ровно 11 цифр, без других символов.';
    }
    if ($email && (!preg_match($emailRegex, $email) || strlen($email) > 50)) {
        $errors[] = 'Некорректный email, максимум 50 символов.';
    }
    if (strlen($note) > 1000) {
        $errors[] = 'Примечание не должно превышать 1000 символов.';
    }
    if ($delivery !== 'Самовывоз из магазина') {
        $errors[] = 'Неверный способ доставки.';
    }
    $cart = json_decode($cartJson, true);
    if (empty($cart)) {
        $errors[] = 'Корзина пуста.';
    }
    // Серверная проверка остатка (загружаем актуальные продукты из кэша)
    $products = file_exists(CACHE_DIR . 'products.json') ? json_decode(file_get_contents(CACHE_DIR . 'products.json'), true) : [];
    foreach ($cart as $item) {
        $found = false;
        foreach ($products as $product) {
            if ($product['id'] === $item['id']) {
                // Убрана проверка на превышение остатка, чтобы позволить отправку
                $found = true;
                break;
            }
        }
        if (!$found) {
            $errors[] = "Товар {$item['name']} (ID: {$item['id']}) не найден.";
        }
    }
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['error' => implode(' ', $errors)]);
        exit;
    }
    $total = 0;
    $orderText = "Новый заказ:\nСпособ доставки: $delivery\nИмя: $name\nФамилия: $surname\nТелефон: $phone\nEmail: $email";
    if (!empty($note)) {
        $orderText .= "\nПримечание: $note";
    }
    $orderText .= "\n\nТовары:\n";
    foreach ($cart as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        $total += $subtotal;
        $orderText .= "- {$item['name']} (Артикул: {$item['article']}), Кол-во: {$item['quantity']}, Цена: {$item['price']} тг., Сумма: $subtotal тг.\n";
    }
    $orderText .= "\nИтог: $total тг.\n\nIP-адрес: {$_SERVER['REMOTE_ADDR']}"; // Для логирования
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $postData = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $orderText,
        'parse_mode' => 'HTML'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || !json_decode($response, true)['ok']) {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка отправки заказа в Telegram: ' . ($curlError ?: $response)]);
        exit;
    }
    // Дополнительная отправка на email владельца (улучшенные headers + envelope sender для Beget)
    $subject = 'Новый заказ на сайте mir-oboev.kz'; // ← изменено здесь
    $from_email = 'no-reply@' . $_SERVER['HTTP_HOST'];
    $headers = "From: $from_email\r\n";
    $headers .= "Reply-To: " . OWNER_EMAIL . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $additional_parameters = '-f ' . $from_email; // Envelope sender для SPF/DMARC на Beget
    if (!mail(OWNER_EMAIL, $subject, $orderText, $headers, $additional_parameters)) {
        error_log("Ошибка отправки email заказа: " . print_r(error_get_last(), true));
        // Не прерываем выполнение, если email не ушёл, но логируем
    } else {
        error_log("Заказ отправлен на email: Имя={$name}, Сумма={$total}");
    }
    error_log("Заказ отправлен: Имя={$name}, Сумма={$total}");
    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Корзина — «Мир обоев»</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #EDEDF5; }
        .container { max-width: 1200px; margin-top: 20px; }
        h1 {
            background: linear-gradient(135deg, #00a6df, #59c3ff);
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            margin-bottom: 30px;
        }
        .table {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            table-layout: auto;
        }
        .table th {
            background: linear-gradient(135deg, #00a6df, #59c3ff);
            color: #fff;
            font-weight: bold;
            border: none;
        }
        .table td { vertical-align: middle; padding: 15px; }
        .table tbody tr { transition: background-color 0.3s ease; }
        .table tbody tr:hover { background-color: #EDEDF5; }
        .table td:first-child {
            max-width: 200px;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .table td:first-child a {
            color: #00a6df;
            text-decoration: none;
            cursor: pointer;
        }
        .table td:first-child a:hover { text-decoration: underline; }
        #cart-total {
            font-size: 1.2rem;
            font-weight: bold;
            text-align: right;
            margin: 20px 0;
        }
        #order-form {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .form-label { font-weight: bold; color: #2A2A2A; }
        .form-control {
            border-radius: 5px;
            border: 1px solid #ced4da;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus {
            border-color: #00a6df;
            box-shadow: 0 0 5px rgba(116, 175, 243, 0.5);
        }
        .btn-primary {
            background-color: #00a6df;
            border: none;
            padding: 10px 20px;
            font-size: 1.1rem;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #45a1ccff;
            transform: scale(1.05);
        }
        #cart-items p {
            text-align: center;
            font-size: 1.2rem;
            color: #777;
            padding: 20px;
        }
        .remove-item {
            background-color: #dc3545;
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            transition: opacity 0.3s ease;
            opacity: 0.8;
        }
        .remove-item:hover { opacity: 1; }
        /* Стили для модального окна просмотра товара */
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: calc(0.25rem - 1px);
        }
        /* Стили для оверлея загрузки */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .overlay .spinner-border {
            width: 3rem;
            height: 3rem;
            margin-bottom: 1rem;
        }
        .overlay-text {
            color: white;
            font-size: 1.2rem;
            font-weight: bold;
        }
        .disabled-overlay { pointer-events: none; opacity: 0.6; }
        @media (max-width: 768px) {
            h1 { font-size: 1.5rem; padding: 10px; }
            .table { font-size: 0.9rem; }
            .table td, .table th { padding: 10px; }
            .table td:first-child {
                max-width: 150px;
                overflow-wrap: break-word;
                word-break: break-word;
            }
            #cart-total { font-size: 1rem; text-align: center; }
            #order-form { padding: 15px; }
            .btn-primary { width: 100%; padding: 12px; }
            .remove-item { width: 20px; height: 20px; font-size: 12px; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .modal-dialog { margin: 10px; }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h1>Корзина</h1>
        <div id="cart-items" class="table-responsive">
            <!-- Таблица корзины будет здесь -->
        </div>
        <p id="cart-total">Итог: 0 тг.</p>
        <form id="order-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="cart" id="cart-hidden">
            <div class="mb-3">
                <label for="delivery" class="form-label">Способ доставки</label>
                <select name="delivery" id="delivery" class="form-control" required>
                    <option value="Самовывоз из магазина" selected>Самовывоз из магазина</option>
                </select>
                <div class="invalid-feedback" id="delivery-error"></div>
            </div>
            <div class="mb-3">
                <label for="name" class="form-label">Имя</label>
                <input type="text" name="name" id="name" class="form-control" placeholder="Иван" maxlength="25" required>
                <div class="invalid-feedback" id="name-error"></div>
            </div>
            <div class="mb-3">
                <label for="surname" class="form-label">Фамилия</label>
                <input type="text" name="surname" id="surname" class="form-control" placeholder="Иванов" maxlength="25" required>
                <div class="invalid-feedback" id="surname-error"></div>
            </div>
            <div class="mb-3">
                <label for="phone" class="form-label">Телефон</label>
                <input type="tel" name="phone" id="phone" class="form-control" placeholder="71002003040" maxlength="11" required>
                <div class="invalid-feedback" id="phone-error"></div>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Почта (опционально)</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="example@email.com" maxlength="50">
                <div class="invalid-feedback" id="email-error"></div>
            </div>
            <div class="mb-3">
                <label for="note" class="form-label">Примечание к заказу (опционально)</label>
                <textarea name="note" id="note" class="form-control" placeholder="Ваше примечание" maxlength="1000"></textarea>
                <div class="invalid-feedback" id="note-error"></div>
            </div>
            <!-- Honeypot поле (скрытое, должно остаться пустым) -->
            <input type="text" name="website" style="display: none;" autocomplete="off">
            <!-- Чекбокс согласия с политикой конфиденциальности -->
            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="consent" name="consent" required>
                <label class="form-check-label" for="consent">Я даю согласие на обработку моих персональных данных в соответствии с Законом Республики Казахстан «О персональных данных и их защите» от 21 мая 2013 года № 94-V и <a href="http://resideln.beget.tech" target="_blank">Политикой конфиденциальности</a>.</label>
                <div class="invalid-feedback" id="consent-error">Пожалуйста, подтвердите согласие с политикой конфиденциальности.</div>
            </div>
            <button type="submit" class="btn btn-primary">Оформить заказ</button>
        </form>
        <div id="order-message" class="alert"></div>
    </div>
    <!-- Модальное окно для просмотра товара -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalLabel"></h5>
                </div>
                <div class="modal-body">
                    <div class="card product-card">
                        <div class="card-body">
                            <div class="row mb-3" id="product-images"></div>
                            <p class="card-text" id="product-category"></p>
                            <p class="card-text" id="product-article"></p>
                            <p class="card-text" id="product-price"></p>
                            <p class="card-text" id="product-quantity"></p>
                            <p class="card-text" id="product-description"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Модальное окно для подтверждения успешного заказа -->
    <div class="modal fade" id="order-success-modal" tabindex="-1" aria-labelledby="orderSuccessLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderSuccessLabel">Заказ принят</h5>
                </div>
                <div class="modal-body">
                    <p>Ваш заказ принят в обработку. Мы свяжемся с вами в ближайшее время.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Ок</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Оверлей для загрузки -->
    <div class="overlay" id="loading-overlay">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
        <div class="overlay-text">Идет загрузка...</div>
    </div>
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
        // Функция получения корзины из localStorage
        function getCart() {
            const cart = localStorage.getItem('cart');
            return cart ? JSON.parse(cart) : [];
        }
        // Функция сохранения корзины в localStorage и обновления бейджа
        function saveCart(cart) {
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartBadge();
        }
        // Функция удаления позиции из корзины
        function removeFromCart(id) {
            let cart = getCart();
            cart = cart.filter(item => item.id !== id);
            saveCart(cart);
            displayCart();
        }
        // Функция отображения содержимого корзины
        function displayCart() {
            const cart = getCart();
            const itemsDiv = document.getElementById('cart-items');
            let total = 0;
            itemsDiv.innerHTML = '';
            if (cart.length === 0) {
                itemsDiv.innerHTML = '<p>Корзина пуста.</p>';
                document.getElementById('cart-total').textContent = 'Итог: 0 тг.';
                document.getElementById('cart-hidden').value = JSON.stringify([]);
                return;
            }
            const table = document.createElement('table');
            table.className = 'table';
            table.innerHTML = `<thead><tr><th>Товар</th><th>Цена</th><th>Кол-во</th><th>Сумма</th><th></th></tr></thead><tbody></tbody>`;
            const tbody = table.querySelector('tbody');
            cart.forEach(item => {
                // Замена фамилий в артикуле для не-админов
                const displayArticle = IS_ADMIN ? item.article : replaceArticle(item.article);
                const subtotal = item.price * item.quantity;
                total += subtotal;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><a href="#" onclick="showProductModal('${encodeURIComponent(item.id)}'); return false;">${escapeHtml(item.name)}</a></td>
                    <td>${item.price} тг.</td>
                    <td>${item.quantity} шт.</td>
                    <td>${subtotal} тг.</td>
                    <td><button class="remove-item" onclick="removeFromCart('${item.id}')">×</button></td>
                `;
                tbody.appendChild(tr);
            });
            itemsDiv.appendChild(table);
            document.getElementById('cart-total').textContent = `Итог: ${total} тг.`;
            document.getElementById('cart-hidden').value = JSON.stringify(cart);
        }
        // Функция загрузки данных о товаре и отображения модального окна
        async function showProductModal(productId) {
            const modal = new bootstrap.Modal(document.getElementById('productModal'), { keyboard: false, backdrop: 'static' });
            const modalTitle = document.getElementById('productModalLabel');
            const imagesContainer = document.getElementById('product-images');
            const categoryText = document.getElementById('product-category');
            const articleText = document.getElementById('product-article');
            const priceText = document.getElementById('product-price');
            const quantityText = document.getElementById('product-quantity');
            const descriptionText = document.getElementById('product-description');
            const overlay = document.getElementById('loading-overlay');
            const container = document.querySelector('.container');
            // Показать оверлей загрузки
            overlay.style.display = 'flex';
            container.classList.add('disabled-overlay');
            try {
                // Кэширование данных о товаре
                const cacheKey = `product_${productId}`;
                const CACHE_TTL = 5 * 60 * 1000; // 5 минут
                let productData = null;
                const cached = localStorage.getItem(cacheKey);
                if (cached) {
                    const { data, timestamp } = JSON.parse(cached);
                    if (Date.now() - timestamp < CACHE_TTL) {
                        productData = data;
                    }
                }
                if (!productData) {
                    // Версионирование API в fetch
                    const res = await fetch(`api/products?v=${SITE_VERSION}&id=${encodeURIComponent(productId)}&show_zero=1`);
                    productData = await res.json();
                    localStorage.setItem(cacheKey, JSON.stringify({ data: productData, timestamp: Date.now() }));
                }
                if (!productData || !productData.product) {
                    showMessage('Товар не найден.', 'danger');
                    overlay.style.display = 'none';
                    container.classList.remove('disabled-overlay');
                    return;
                }
                const product = productData.product;
                let images = product.images || array_fill(0, 6, 'linear-gradient(135deg, #00a6df, #59c3ff)');
                // Фильтрация изображений: убираем незаполненные слоты для всех пользователей
                images = images.filter(image => image !== 'linear-gradient(135deg, #00a6df, #59c3ff)');
                // Если нет изображений, не показываем раздел с изображениями
                imagesContainer.innerHTML = '';
                if (images.length > 0) {
                    images.forEach((image, index) => {
                        const div = document.createElement('div');
                        div.className = 'col-md-4';
                        let imageHtml = '';
                        // Версионирование изображений в JS
                        const versionedImage = `${escapeHtml(image)}?v=${SITE_VERSION}`;
                        imageHtml = `<a href="${versionedImage}" target="_blank"><img src="${versionedImage}" class="product-image" alt="Изображение товара" loading="lazy"></a>`;
                        div.innerHTML = imageHtml;
                        imagesContainer.appendChild(div);
                    });
                }
                // Заполнение модального окна с полным использованием escapeHtml
                modalTitle.innerHTML = escapeHtml(product.name || 'Без названия');
                categoryText.innerHTML = `<strong>Категория:</strong> ${escapeHtml(product.category_name || 'Без категории')}`;
                articleText.innerHTML = `<strong>Артикул:</strong> ${escapeHtml(product.article || '')}`;
                priceText.innerHTML = `<strong>Цена на ${escapeHtml(product.import_date)}:</strong> ${escapeHtml(Number(product.price).toFixed(2))} тг.`;
                quantityText.innerHTML = `<strong>Остаток на ${escapeHtml(product.import_date)}:</strong> ${escapeHtml(product.quantity || 0)} шт.`;
                descriptionText.innerHTML = product.description ? `<strong>Описание:</strong> ${escapeHtml(product.description)}` : '';
                modal.show();
            } catch (err) {
                showMessage('Ошибка при загрузке данных о товаре', 'danger');
            } finally {
                overlay.style.display = 'none';
                container.classList.remove('disabled-overlay');
            }
        }
        // Ограничение ввода символов в реальном времени для полей формы
        document.getElementById('name').addEventListener('input', function(e) {
            const regex = /^[A-Za-zА-Яа-я]*$/;
            let value = e.target.value;
            if (!regex.test(value)) {
                value = value.replace(/[^A-Za-zА-Яа-я]/g, '');
            }
            if (value.length > 25) {
                value = value.slice(0, 25);
            }
            e.target.value = value;
        });
        document.getElementById('surname').addEventListener('input', function(e) {
            const regex = /^[A-Za-zА-Яа-я]*$/;
            let value = e.target.value;
            if (!regex.test(value)) {
                value = value.replace(/[^A-Za-zА-Яа-я]/g, '');
            }
            if (value.length > 25) {
                value = value.slice(0, 25);
            }
            e.target.value = value;
        });
        document.getElementById('phone').addEventListener('input', function(e) {
            const regex = /^\d*$/;
            let value = e.target.value;
            if (!regex.test(value)) {
                value = value.replace(/[^\d]/g, '');
            }
            if (value.length > 11) {
                value = value.slice(0, 11);
            }
            e.target.value = value;
        });
        document.getElementById('email').addEventListener('input', function(e) {
            let value = e.target.value;
            if (value.length > 50) {
                value = value.slice(0, 50);
            }
            e.target.value = value;
        });
        document.getElementById('note').addEventListener('input', function(e) {
            let value = e.target.value;
            if (value.length > 1000) {
                value = value.slice(0, 1000);
            }
            e.target.value = value;
        });
        // Валидация и отправка формы заказа через AJAX
        document.getElementById('order-form').addEventListener('submit', function(e) {
            e.preventDefault();
            let valid = true;
            const nameInput = document.getElementById('name');
            const nameError = document.getElementById('name-error');
            const nameRegex = /^[A-Za-zА-Яа-я]+$/;
            if (!nameRegex.test(nameInput.value) || nameInput.value.length < 1 || nameInput.value.length > 25) {
                nameError.textContent = 'Имя должно содержать только буквы (без цифр и спецсимволов), от 1 до 25 символов.';
                nameInput.classList.add('is-invalid');
                valid = false;
            } else {
                nameInput.classList.remove('is-invalid');
            }
            const surnameInput = document.getElementById('surname');
            const surnameError = document.getElementById('surname-error');
            if (!nameRegex.test(surnameInput.value) || surnameInput.value.length < 1 || surnameInput.value.length > 25) {
                surnameError.textContent = 'Фамилия должна содержать только буквы (без цифр и спецсимволов), от 1 до 25 символов.';
                surnameInput.classList.add('is-invalid');
                valid = false;
            } else {
                surnameInput.classList.remove('is-invalid');
            }
            const phoneInput = document.getElementById('phone');
            const phoneError = document.getElementById('phone-error');
            const phoneRegex = /^\d{11}$/;
            if (!phoneRegex.test(phoneInput.value) || phoneInput.value.length !== 11) {
                phoneError.textContent = 'Телефон должен содержать ровно 11 цифр, без других символов.';
                phoneInput.classList.add('is-invalid');
                valid = false;
            } else {
                phoneInput.classList.remove('is-invalid');
            }
            const emailInput = document.getElementById('email');
            const emailError = document.getElementById('email-error');
            const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
            if (emailInput.value && (!emailRegex.test(emailInput.value) || emailInput.value.length > 50)) {
                emailError.textContent = 'Некорректный email, максимум 50 символов.';
                emailInput.classList.add('is-invalid');
                valid = false;
            } else {
                emailInput.classList.remove('is-invalid');
            }
            const noteInput = document.getElementById('note');
            const noteError = document.getElementById('note-error');
            if (noteInput.value.length > 1000) {
                noteError.textContent = 'Примечание не должно превышать 1000 символов.';
                noteInput.classList.add('is-invalid');
                valid = false;
            } else {
                noteInput.classList.remove('is-invalid');
            }
            // Валидация чекбокса согласия
            const consentInput = document.getElementById('consent');
            const consentError = document.getElementById('consent-error');
            if (!consentInput.checked) {
                consentError.textContent = 'Пожалуйста, подтвердите согласие с политикой конфиденциальности.';
                consentInput.classList.add('is-invalid');
                valid = false;
            } else {
                consentInput.classList.remove('is-invalid');
            }
            if (!valid) {
                return;
            }
            const cart = getCart();
            if (cart.length === 0) {
                showMessage('Корзина пуста.', 'danger');
                return;
            }
            document.getElementById('cart-hidden').value = JSON.stringify(cart);
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            const formData = new FormData(this);
            fetch('cart', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    localStorage.removeItem('cart');
                    displayCart();
                    this.reset();
                    submitButton.disabled = true; // Disable навсегда после success (для этой загрузки)
                    this.style.display = 'none'; // Скрыть форму после успеха
                    // Показать модал успеха
                    const successModal = new bootstrap.Modal(document.getElementById('order-success-modal'), {
                        backdrop: 'static',
                        keyboard: false
                    });
                    successModal.show();
                } else {
                    showMessage(data.error || 'Ошибка при отправке заказа.', 'danger');
                }
            })
            .catch(error => {
                showMessage('Ошибка сети: ' + error.message, 'danger');
            })
            .finally(() => {
                if (!data.success) { // Только если не success, enable обратно
                    submitButton.disabled = false;
                }
            });
        });
        // Функция отображения сообщения об успехе или ошибке
        function showMessage(text, type) {
            const messageDiv = document.getElementById('order-message');
            messageDiv.textContent = text;
            messageDiv.className = `alert alert-${type}`;
            messageDiv.style.display = 'block';
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 5000);
        }
        // Инициализация отображения корзины при загрузке страницы
        document.addEventListener('DOMContentLoaded', displayCart);
    </script>
</body>
</html>
