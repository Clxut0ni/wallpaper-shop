<?php
/**
 * File: header.php
 *
 * Шапка сайта (header) с фиксированной навигацией и модальным окном для добавления в корзину.
 *
 * Этот файл содержит HTML-структуру навигационной панели, фиксированной на экране,
 * с постоянно видимой кнопкой "Корзина" на всех устройствах, и глобальный модал для корзины.
 */
// Подключение конфигурационного файла
require_once 'config.php';
?>
<nav class="navbar navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand" href="index">Мир обоев</a>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a class="nav-link" href="cart">Корзина <span id="cart-badge" class="badge bg-primary">0</span></a>
            </li>
        </ul>
        <!-- Бургер для категорий на мобильной версии в правом углу -->
        <?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
            <button class="navbar-toggler d-md-none" type="button" onclick="toggleCategoryMenu()">
                <span class="navbar-toggler-icon"></span>
            </button>
        <?php endif; ?>
    </div>
</nav>
<!-- Глобальный модал для добавления в корзину -->
<div class="modal fade" id="addToCartModal" tabindex="-1" aria-labelledby="addToCartModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addToCartModalLabel">Добавить в корзину</h5>
            </div>
            <div class="modal-body">
                <p id="product-name-modal"></p>
                <div class="input-group mb-3">
                    <span class="input-group-text">Количество:</span>
                    <input type="number" id="quantity-input" class="form-control" value="1" min="1" step="1">
                </div>
                <div id="quantity-warning" class="alert alert-warning" style="display: none;">Количество заказываемого вами товара превышает текущий остаток товара на складе. Обработка заказа может занять больше времени.</div>
                <div id="zero-stock-warning" class="alert alert-danger" style="display: none;">Возможно, заказываемый вами товар уже выведен из ассортимента. Обработка заказа может занять больше времени или не состояться вовсе.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="add-to-cart-confirm">Добавить</button>
            </div>
        </div>
    </div>
</div>
<style>
    /* Стили для фиксированной навигационной панели */
    .navbar {
        background: linear-gradient(135deg, #00a6df, #59c3ff);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
    }
    /* Отступ для контента под навбаром */
    body { padding-top: 70px; }
    .navbar-brand, .nav-link {
        color: #fff !important;
        transition: color 0.3s ease;
    }
    .navbar-brand { font-size: 1.5rem; font-weight: bold; }
    .nav-link { font-size: 1.1rem; margin-left: 15px; }
    .navbar-brand:hover, .nav-link:hover { color: #e0e0e0 !important; }
    .badge {
        vertical-align: middle;
        font-size: 0.85rem;
        background-color: #00a6df;
    }
    .bg-primary { background-color: #198754 !important; }
    /* Стили для модального окна добавления в корзину */
    .modal-content {
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    .modal-header {
        background: linear-gradient(135deg, #00a6df, #59c3ff);
        color: #fff;
        border-bottom: none;
    }
    .modal-title { font-weight: bold; }
    .modal-body { padding: 20px; }
    .input-group-text {
        background-color: #EDEDF5;
        border-right: none;
    }
    .form-control[type="number"] {
        border-left: none;
        max-width: 150px;
    }
    .modal-footer { border-top: none; }
    .btn-primary {
        background-color: #00a6df;
        border: none;
        transition: background-color 0.3s ease;
    }
    .btn-primary:hover { background-color: #59c3ff; }
    .btn-secondary {
        background-color: #2A2A2A;
        border: none;
        transition: background-color 0.3s ease;
    }
    .btn-secondary:hover { background-color: #5a6268; }
    /* Адаптивные стили для мобильных устройств */
    @media (max-width: 768px) {
        .navbar-brand { font-size: 1.2rem; }
        .nav-link {
            font-size: 1rem;
            margin-left: 10px;
            margin-right: 10px;
        }
        .modal-dialog { margin: 10px; }
        .navbar-nav { flex-direction: row; }
        /* Белый бургер на мобильной версии */
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e") !important;
        }
        /* Убираем обводку при фокусе и нажатии */
        .navbar-toggler:focus,
        .navbar-toggler:active {
            box-shadow: none !important;
            border-color: transparent !important;
        }
    }
</style>
<!-- Глобальная версия сайта для JS (чтобы версионировать fetch и img в модалах) -->
<script>
const SITE_VERSION = "<?php echo site_version(); ?>";
const IS_ADMIN = <?php echo json_encode($is_admin); ?>;
</script>
<!-- JS для корзины (глобальный) -->
<script>
    // Защита от XSS: экранирование HTML-символов
    function escapeHtml(str) {
        if (typeof str !== 'string') return str;
        return str.replace(/[&<>"'`=\/]/g, s => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
            "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
        })[s]);
    }
    // Функция замены фамилий в артикуле
    function replaceArticle(article) {
        if (typeof article !== 'string') return article;
        return article.replace(/РЕШЕТНЯК/gi, 'РЕШ.')
                      .replace(/ИП РЕШЕТНЯК/gi, 'ИП РЕШ.')
                      .replace(/Карапетьянц/gi, 'Кар.');
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
    // Функция обновления бейджа корзины (количество товаров)
    function updateCartBadge() {
        const cart = getCart();
        const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
        document.getElementById('cart-badge').textContent = totalItems;
    }
    // Функция добавления товара в корзину
    function addToCart(productId, name, article, price, quantity) {
        let cart = getCart();
        const existing = cart.find(item => item.id === productId);
        if (existing) {
            existing.quantity += quantity;
        } else {
            cart.push({ id: productId, name, article, price, quantity });
        }
        saveCart(cart);
    }
    // Инициализация бейджа при загрузке страницы
    document.addEventListener('DOMContentLoaded', updateCartBadge);
    // Обработка кнопок "В корзину" (глобально)
    let isAddingToCart = false; // Флаг для блокировки добавления в корзину
    document.addEventListener('click', e => {
        if (e.target.classList.contains('add-to-cart')) {
            if (isAddingToCart) return;
            isAddingToCart = true;
            e.target.style.pointerEvents = 'none'; // Блокировка кнопки
            const productId = e.target.dataset.productId;
            const maxQuantity = parseInt(e.target.dataset.quantityMax) || 0; // Изменено: теперь 0 для нулевых
            const name = e.target.dataset.productName;
            const article = e.target.dataset.productArticle;
            const price = parseFloat(e.target.dataset.productPrice);
            // Настройка модального окна с escapeHtml
            document.getElementById('product-name-modal').innerHTML = escapeHtml(name);
            const quantityInput = document.getElementById('quantity-input');
            const quantityWarning = document.getElementById('quantity-warning');
            const zeroStockWarning = document.getElementById('zero-stock-warning');
            // Убираем max, чтобы позволить ввод больше остатка
            quantityInput.removeAttribute('max');
            quantityInput.value = 1;
            quantityWarning.style.display = 'none';
            zeroStockWarning.style.display = 'none';
            // Показываем красное уведомление, если остаток == 0
            if (maxQuantity === 0) {
                zeroStockWarning.style.display = 'block';
            }
            // Показываем модальное окно
            const modal = new bootstrap.Modal(document.getElementById('addToCartModal'), { keyboard: false, backdrop: 'static' });
            modal.show();
            // Проверка на превышение остатка при изменении количества (если остаток >0)
            quantityInput.addEventListener('input', () => {
                const quantity = parseInt(quantityInput.value);
                if (maxQuantity > 0 && quantity > maxQuantity) {
                    quantityWarning.style.display = 'block';
                } else {
                    quantityWarning.style.display = 'none';
                }
            });
            // Подтверждение добавления в корзину
            document.getElementById('add-to-cart-confirm').onclick = () => {
                const quantity = parseInt(quantityInput.value);
                if (quantity > 0) { // Минимальная валидация
                    addToCart(productId, name, article, price, quantity);
                    modal.hide();
                }
                isAddingToCart = false;
                e.target.style.pointerEvents = 'auto'; // Разблокировка кнопки
            };
            // Разблокировка при закрытии модала без добавления
            document.getElementById('addToCartModal').addEventListener('hidden.bs.modal', () => {
                isAddingToCart = false;
                e.target.style.pointerEvents = 'auto';
            }, { once: true });
        }
    });
    // Обработка клика по логотипу
    document.querySelector('.navbar-brand').addEventListener('click', (e) => {
        e.preventDefault();
        // Очистка сохранённого состояния перед переходом на главную
        sessionStorage.removeItem('lastProduct');
        window.location.href = 'index';
    });
    // Проверка изменения роли пользователя и очистка кэша
    const currentRole = IS_ADMIN ? 'admin' : 'user';
    const storedRole = localStorage.getItem('user_role');
    if (storedRole !== currentRole) {
        // Очистка кэша продуктов
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('products_')) {
                localStorage.removeItem(key);
            }
        });
        localStorage.setItem('user_role', currentRole);
    }
</script>