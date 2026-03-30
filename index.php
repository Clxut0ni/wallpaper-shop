<?php
/**
 * File: index.php
 *
 * Главная страница каталога товаров — финальная версия с исправленным скроллом
 *
 * Этот файл отображает каталог товаров с боковой панелью категорий,
 * поиском, сортировками и бесконечной подгрузкой товаров через AJAX.
 * Использует кэш категорий и товаров, поддерживает мобильную адаптацию
 * и восстановление позиции при возврате с карточки товара.
 */
require_once 'config.php';
session_start();

/**
 * Получает категории из кэша.
 *
 * Функция читает JSON-файл с категориями из директории кэша.
 * Если файл отсутствует или повреждён — возвращает пустой массив.
 *
 * @return array Массив категорий (каждая содержит id, name, parent_id и children при рекурсии).
 */
function getCategories() {
    $file = CACHE_DIR . 'categories.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

/**
 * Строит дерево категорий.
 *
 * Рекурсивная функция преобразует плоский массив категорий
 * в иерархическое дерево с вложенными children.
 *
 * @param array $categories Плоский массив категорий из кэша.
 * @param string $parent_id ID родительской категории (по умолчанию пустая строка для корня).
 * @return array Дерево категорий с вложенной структурой.
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

$categories = getCategories();
$category_tree = buildCategoryTree($categories);
$is_admin = isset($_SESSION['logged_in']) && in_array($_SESSION['role'] ?? '', ['admin', 'developer']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Каталог — «Мир обоев»</title>
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --primary: #00a6df;
    --primary-dark: #0088b8;
    --accent: #59c3ff;
    --text: #2a2a2a;
    --bg: #f8f9fc;
    --card-bg: #ffffff;
    --shadow: 0 20px 25px -5px rgb(0 166 223 / 0.12),
    0 8px 10px -6px rgb(0 166 223 / 0.1);
    --shadow-hover: 0 25px 50px -12px rgb(0 166 223 / 0.25);
}
* { transition-property: color, background-color, border-color, box-shadow, transform; }
* { transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1); }
body {
    background: var(--bg);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: var(--text);
}
.navbar {
    background: linear-gradient(135deg, var(--primary), var(--accent)) !important;
    box-shadow: 0 10px 15px -3px rgb(0 166 223 / 0.3);
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 1100;
    border-radius: 0 !important;
}
.navbar-brand {
    font-size: 1.65rem;
    font-weight: 700;
    color: #fff !important;
}
.card {
    border: none;
    border-radius: calc(0.25rem - 1px);
    background: var(--card-bg);
    box-shadow: var(--shadow);
    overflow: hidden;
    height: 100%;
}
.card:hover {
    transform: translateY(-12px) scale(1.03);
    box-shadow: var(--shadow-hover);
}
.product-image {
    width: 100% !important;
    height: 240px;
    object-fit: cover;
    display: block;
}
.card:hover .product-image {
    transform: scale(1.08);
}
.product-card {
    opacity: 0;
    transform: translateY(30px);
    animation: fadeUp 0.6s forwards;
}
@keyframes fadeUp {
    to { opacity: 1; transform: translateY(0); }
}
.btn-primary, .btn-success {
    border-radius: 0.375rem !important;
    padding: 12px 28px;
    font-weight: 600;
    box-shadow: 0 10px 15px -3px rgb(0 166 223 / 0.3);
}
.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 25px -5px rgb(0 166 223 / 0.4);
}
.category-link {
    border-radius: 0.375rem; /* Приведено к единому значению с остальными кнопками (.btn-primary, .btn-success) */
    padding: 16px 20px;
    font-weight: 600;
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
}
.category-link:hover,
.category-link.active {
    background: linear-gradient(135deg, var(--primary), var(--accent));
    color: white !important;
    transform: translateX(8px);
    box-shadow: var(--shadow);
}
.sidebar {
    background: white;
    border-radius: 0.375rem; /* Приведено к единому значению с кнопками и ссылками категорий */
    box-shadow: var(--shadow);
    padding: 24px 20px;
    height: 100%;
}
@media (max-width: 768px) {
    .back-to-top { display: flex; }
    .sidebar {
        position: fixed;
        top: 0;
        left: -100%;
        width: 85%;
        height: 100vh;
        z-index: 1050;
        border-radius: 0 0.375rem 0.375rem 0; /* Приведено к единому значению с кнопками */
        transition: left 0.4s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .sidebar.show { left: 0; }
    .product-image { height: 200px; }
}
#catalog-title {
font-size: 2.2rem;
font-weight: 700;
background: linear-gradient(90deg, #00a6df, #59c3ff);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
}
.back-to-top {
    width: 58px;
    height: 58px;
    background: var(--primary);
    box-shadow: var(--shadow-hover);
    display: none !important;
}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="container-fluid mt-5 pt-4">
<div class="row">
<!-- Боковая панель категорий -->
<div class="col-md-3 sidebar p-3" id="category-sidebar">
<h4 class="mb-4 text-center fw-bold">Категории</h4>
<ul class="category-list list-unstyled">
<?php
/**
 * Рекурсивно выводит дерево категорий в виде HTML-списка.
 *
 * Используется непосредственно в шаблоне для формирования
 * боковой панели с collapsible подкатегориями.
 *
 * @param array $categories Дерево категорий.
 * @param int $level Уровень вложенности (не используется в текущей реализации).
 */
function displayCategories($categories, $level = 0) {
    foreach ($categories as $cat) {
        $hasChildren = !empty($cat['children']);
        echo '<li class="category-item mb-2">';
        echo '<div class="category-header">';
        echo '<a href="#" class="category-link d-flex align-items-center text-decoration-none" data-category="' . htmlspecialchars($cat['id']) . '">';
        echo htmlspecialchars($cat['name']);
        echo '</a>';
        if ($hasChildren) {
            echo '<button class="toggle-btn border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#sub-' . htmlspecialchars($cat['id']) . '"><i class="bi bi-chevron-down"></i></button>';
        }
        echo '</div>';
        if ($hasChildren) {
            echo '<ul class="subcategories collapse mt-2" id="sub-' . htmlspecialchars($cat['id']) . '">';
            displayCategories($cat['children'], $level + 1);
            echo '</ul>';
        }
        echo '</li>';
    }
}
displayCategories($category_tree);
?>
</ul>
</div>
<!-- Основной контент -->
<div class="col-md-9">
<h1 id="catalog-title" class="mb-4">Каталог товаров</h1>
<!-- Поиск -->
<form id="search-form" class="mb-4">
<div class="input-group">
<input type="text" id="search-input" class="form-control form-control-lg" placeholder="Поиск по названию или артикулу...">
<button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
<button class="btn btn-outline-secondary ms-2" id="reset-btn" type="button">Сбросить</button>
</div>
</form>
<!-- Сортировки -->
<div class="d-flex flex-wrap gap-3 mb-4">
<div>
<label class="form-label">По цене:</label>
<select id="sort-select" class="form-select">
<option value="">По умолчанию</option>
<option value="price_asc">Сначала дешевле</option>
<option value="price_desc">Сначала дороже</option>
</select>
</div>
<div>
<label class="form-label">По фото:</label>
<select id="photo-sort-select" class="form-select">
<option value="">По умолчанию</option>
<option value="with_photo">Сначала с фото</option>
<option value="no_photo">Сначала без фото</option>
</select>
</div>
<div class="form-check align-self-end">
<input type="checkbox" class="form-check-input" id="show-zero-checkbox">
<label class="form-check-label" for="show-zero-checkbox">Показывать товары с нулевым остатком</label>
</div>
</div>
<div class="row" id="products-list"></div>
<div id="loader" class="text-center py-5 fs-5 text-muted">Загрузка товаров...</div>
</div>
</div>
</div>
<!-- Мобильный оверлей -->
<div class="overlay" id="mobile-overlay" onclick="toggleCategoryMenu()"></div>
<!-- Кнопка "Наверх" -->
<button class="back-to-top position-fixed bottom-4 end-4 rounded-circle border-0 d-flex align-items-center justify-content-center text-white" id="back-to-top">
<i class="bi bi-arrow-up fs-3"></i>
</button>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====================== ПОЛНЫЙ JAVASCRIPT ======================
/**
 * Debounce-функция для ограничения частоты вызовов.
 *
 * @param {Function} func Функция, которую нужно вызвать.
 * @param {number} wait Время задержки в миллисекундах.
 * @return {Function} Обёрнутая функция с debounce.
 */
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

const CACHE_TTL = 5 * 60 * 1000;

/**
 * Генерирует уникальный ключ для кэша товаров.
 *
 * @param {number} page Номер страницы.
 * @param {string} category ID категории.
 * @param {string} search Поисковый запрос.
 * @param {string} sort Сортировка по цене.
 * @param {string} photoSort Сортировка по наличию фото.
 * @param {boolean} showZero Показывать товары с нулевым остатком.
 * @return {string} Ключ кэша.
 */
function getCacheKey(page, category, search, sort, photoSort, showZero) {
    return `products_${SITE_VERSION}_${page}_${category || 'all'}_${search || 'none'}_${sort || 'none'}_${photoSort || 'none'}_${showZero ? 'true' : 'false'}`;
}

/**
 * Получает данные из localStorage по ключу.
 *
 * Проверяет срок жизни кэша (CACHE_TTL).
 *
 * @param {string} key Ключ кэша.
 * @return {object|null} Данные или null, если кэш устарел/отсутствует.
 */
function getCachedData(key) {
    const cached = localStorage.getItem(key);
    if (!cached) return null;
    const { data, timestamp } = JSON.parse(cached);
    if (Date.now() - timestamp > CACHE_TTL) {
        localStorage.removeItem(key);
        return null;
    }
    return data;
}

/**
 * Сохраняет данные в localStorage с текущим timestamp.
 *
 * @param {string} key Ключ кэша.
 * @param {object} data Данные для сохранения.
 */
function setCachedData(key, data) {
    localStorage.setItem(key, JSON.stringify({ data, timestamp: Date.now() }));
}

/**
 * Очищает устаревшие записи кэша товаров.
 */
function cleanOldCache() {
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key.startsWith('products_')) {
            const { timestamp } = JSON.parse(localStorage.getItem(key) || '{}');
            if (timestamp && Date.now() - timestamp > CACHE_TTL) localStorage.removeItem(key);
        }
    }
}

let currentPage = 1;
let currentCategory = '';
let currentSearch = '';
let lastSearch = '';
let currentSort = '';
let currentPhotoSort = '';
let currentShowZero = false;
let totalPages = 1;
let loading = false;
let isProcessing = false;

const categoryTree = <?php echo json_encode($category_tree); ?>;
const productsList = document.getElementById('products-list');
const loader = document.getElementById('loader');
const backToTopButton = document.getElementById('back-to-top');
const catalogTitle = document.getElementById('catalog-title');

/**
 * Обновляет заголовок каталога в зависимости от текущих фильтров.
 */
function updateCatalogTitle() {
    if (currentSearch) catalogTitle.textContent = `Поиск: "${currentSearch}"`;
    else if (currentCategory) {
        const name = findCategoryName(categoryTree, currentCategory);
        catalogTitle.textContent = name || 'Каталог товаров';
    } else catalogTitle.textContent = 'Каталог товаров';
}

/**
 * Рекурсивно ищет название категории по ID.
 *
 * @param {array} categories Дерево категорий.
 * @param {string} id ID искомой категории.
 * @return {string|null} Название категории или null.
 */
function findCategoryName(categories, id) {
    for (const cat of categories) {
        if (cat.id === id) return cat.name;
        if (cat.children && cat.children.length > 0) {
            const found = findCategoryName(cat.children, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * Находит цепочку родительских категорий для раскрытия подкатегорий.
 *
 * @param {array} tree Дерево категорий.
 * @param {string} targetId ID целевой категории.
 * @param {array} path Текущий путь (для рекурсии).
 * @return {array} Массив ID родителей.
 */
function findParents(tree, targetId, path = []) {
    for (const cat of tree) {
        if (cat.id === targetId) return path;
        if (cat.children && cat.children.length > 0) {
            const found = findParents(cat.children, targetId, [...path, cat.id]);
            if (found.length > 0) return found;
        }
    }
    return [];
}

/**
 * Раскрывает все родительские collapsible-блоки для выбранной категории.
 *
 * @param {string} targetCategory ID категории.
 */
function expandParents(targetCategory) {
    const parents = findParents(categoryTree, targetCategory);
    parents.forEach(parentId => {
        const sub = document.getElementById(`sub-${parentId}`);
        if (sub) {
            new bootstrap.Collapse(sub, { toggle: false }).show();
            const btn = document.querySelector(`[data-bs-target="#sub-${parentId}"]`);
            if (btn) btn.classList.add('expanded');
        }
    });
}

/**
 * Загружает товары с сервера (или из кэша).
 *
 * Основная функция каталога: поддерживает пагинацию, фильтры,
 * кэширование и анимацию карточек.
 *
 * @param {number} page Номер страницы.
 * @param {boolean} append Добавлять к существующим карточкам (для infinite scroll).
 */
async function loadProducts(page = 1, append = false) {
    if (loading || isProcessing) return;
    isProcessing = true;
    loading = true;
    loader.style.display = 'block';
    loader.textContent = 'Загрузка...';

    const cacheKey = getCacheKey(page, currentCategory, currentSearch, currentSort, currentPhotoSort, currentShowZero);
    let data = getCachedData(cacheKey);

    try {
        if (!data) {
            const params = new URLSearchParams({
                page: page,
                category: currentCategory,
                search: currentSearch,
                sort: currentSort,
                sort_photo: currentPhotoSort,
                show_zero: currentShowZero ? '1' : '0'
            });
            const res = await fetch('api/products?' + params.toString());
            data = await res.json();
            setCachedData(cacheKey, data);
        }

        totalPages = data.pages || 1;

        if (!data.products || data.products.length === 0) {
            productsList.innerHTML = '<p class="text-center py-5">Товары не найдены.</p>';
            backToTopButton.style.display = 'none';
            loader.style.display = 'none';
            loading = false;
            isProcessing = false;
            return;
        }

        if (!append) productsList.innerHTML = '';

        const isAdmin = <?php echo json_encode($is_admin); ?>;
        const fragment = document.createDocumentFragment();

        (data.products || []).forEach(p => {
            const col = document.createElement('div');
            col.className = 'col-md-4 mb-3 product-card';

        const mainImage = p.main_image || 'linear-gradient(135deg, #00a6df, #59c3ff)';
        const imageHtml = mainImage.startsWith('linear-gradient')
        ? `<div class="product-image" style="background-image: ${mainImage}"></div>`
        : `<a href="${mainImage}" target="_blank" class="d-block"><img src="${mainImage}" class="product-image" alt="Изображение товара" loading="lazy"></a>`;

        const addToCartButton = `<button class="btn btn-success add-to-cart w-100 mt-2" data-product-id="${p.id}" data-product-name="${escapeHtml(p.name)}" data-product-article="${escapeHtml(p.article || '')}" data-product-price="${p.price}" data-quantity-max="${p.quantity || 0}">В корзину</button>`;

        col.innerHTML = `
        <div class="card h-100" data-product-id="${p.id}">
        ${isAdmin ? `<div class="product-image" style="background-image: ${mainImage.startsWith('linear-gradient') ? mainImage : `url('${mainImage}')`}"></div>` : imageHtml}
        <div class="card-body d-flex flex-column">
        <h5 class="card-title">${escapeHtml(p.name || 'Без названия')}</h5>
        <p class="text-muted small"><strong>Артикул:</strong> ${escapeHtml(p.article || '')}</p>
        <p><strong>Цена на ${escapeHtml(p.import_date)}:</strong> ${Number(p.price).toFixed(2)} тг.</p>
        <p><strong>Остаток на ${escapeHtml(p.import_date)}:</strong> ${p.quantity ?? 0} шт.</p>
        <div class="mt-auto">
        <a href="product?id=${encodeURIComponent(p.id)}" class="btn btn-primary w-100 mb-2 product-link" data-product-id="${p.id}">Подробнее</a>
        ${addToCartButton}
        </div>
        </div>
        </div>`;

        fragment.appendChild(col);
        });

        productsList.appendChild(fragment);
        animateCards();

        loader.textContent = page >= totalPages ? 'Все товары загружены' : 'Прокрутите вниз, чтобы загрузить ещё';
        backToTopButton.style.display = 'flex';
    } catch (err) {
        console.error('Ошибка при загрузке товаров:', err);
        loader.textContent = 'Ошибка загрузки.';
    } finally {
        loading = false;
        isProcessing = false;
    }
}

/**
 * Добавляет анимацию появления карточек через IntersectionObserver.
 */
function animateCards() {
    const cards = document.querySelectorAll('.product-card:not(.visible)');
    cards.forEach(c => {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    c.classList.add('visible');
                    observer.unobserve(c);
                }
            });
        }, { threshold: 0.1 });
        observer.observe(c);
    });
}

/**
 * IntersectionObserver для бесконечной подгрузки товаров.
 */
const io = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && !loading && currentPage < totalPages) {
        currentPage++;
        loadProducts(currentPage, true);
    }
}, { rootMargin: '200px' });
io.observe(loader);

const searchInput = document.getElementById('search-input');
const searchForm = document.getElementById('search-form');
searchForm.addEventListener('submit', e => { e.preventDefault(); handleSearch(); });

const debouncedSearch = debounce(() => handleSearch(), 300);
searchInput.addEventListener('input', debouncedSearch);

/**
 * Обработчик поиска (сброс страницы и перезагрузка товаров).
 */
function handleSearch() {
    const newSearch = searchInput.value.trim();
    if (newSearch === lastSearch && currentPage === 1) return;
    currentSearch = newSearch;
    lastSearch = newSearch;
    currentPage = 1;
    updateCatalogTitle();
    loadProducts(1);
}

document.getElementById('sort-select').addEventListener('change', () => {
    currentSort = document.getElementById('sort-select').value;
    currentPage = 1;
    loadProducts(1);
});
document.getElementById('photo-sort-select').addEventListener('change', () => {
    currentPhotoSort = document.getElementById('photo-sort-select').value;
    currentPage = 1;
    loadProducts(1);
});
document.getElementById('show-zero-checkbox').addEventListener('change', () => {
    currentShowZero = document.getElementById('show-zero-checkbox').checked;
    currentPage = 1;
    loadProducts(1);
});
document.getElementById('reset-btn').addEventListener('click', () => {
    currentCategory = '';
currentSearch = '';
lastSearch = '';
currentSort = '';
currentPhotoSort = '';
currentShowZero = false;
document.getElementById('show-zero-checkbox').checked = false;
searchInput.value = '';
document.getElementById('sort-select').value = '';
document.getElementById('photo-sort-select').value = '';
currentPage = 1;
document.querySelectorAll('.category-link').forEach(l => l.classList.remove('active'));
updateCatalogTitle();
loadProducts(1);
});
document.querySelectorAll('.category-link').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        currentCategory = e.target.dataset.category;
        currentPage = 1;
        document.querySelectorAll('.category-link').forEach(l => l.classList.remove('active'));
        e.target.classList.add('active');
        updateCatalogTitle();
        const isMobile = window.matchMedia("(max-width: 768px)").matches;
        if (isMobile) toggleCategoryMenu();
        loadProducts(1);
    });
});

/**
 * Экранирование HTML для безопасного вывода названий и артикулов.
 *
 * @param {string} str Строка для экранирования.
 * @return {string} Экранированная строка.
 */
function escapeHtml(str) {
    if (typeof str !== 'string') return str;
    return str.replace(/[&<>"'`=\/]/g, s => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
        "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
    })[s]);
}

// Сохранение состояния при переходе на товар
document.addEventListener('click', e => {
    const link = e.target.closest('.product-link');
    if (link) {
        const id = link.dataset.productId;
        sessionStorage.setItem('lastProduct', JSON.stringify({
            id, category: currentCategory, search: currentSearch,
            page: currentPage, sort: currentSort,
            photoSort: currentPhotoSort, showZero: currentShowZero
        }));
    }
});

// Восстановление позиции после возврата из product.php
window.addEventListener('DOMContentLoaded', async () => {
    cleanOldCache();
    const saved = JSON.parse(sessionStorage.getItem('lastProduct') || '{}');
    const urlParams = new URLSearchParams(window.location.search);

    if (!saved.id) {
        currentCategory = urlParams.get('category') || '990144';
currentSort = urlParams.get('sort') || 'price_asc';
currentPhotoSort = urlParams.get('sort_photo') || 'with_photo';
document.getElementById('sort-select').value = currentSort;
document.getElementById('photo-sort-select').value = currentPhotoSort;
const activeLink = document.querySelector(`.category-link[data-category="${currentCategory}"]`);
if (activeLink) activeLink.classList.add('active');
expandParents(currentCategory);
updateCatalogTitle();
loadProducts(1);
return;
    }

    // Восстановление фильтров
    currentCategory = saved.category || '';
currentSearch = saved.search || '';
lastSearch = currentSearch;
currentSort = saved.sort || '';
currentPhotoSort = saved.photoSort || '';
currentShowZero = saved.showZero || false;
document.getElementById('show-zero-checkbox').checked = currentShowZero;
document.getElementById('sort-select').value = currentSort;
document.getElementById('photo-sort-select').value = currentPhotoSort;

if (currentCategory) {
    const activeLink = document.querySelector(`.category-link[data-category="${currentCategory}"]`);
    if (activeLink) activeLink.classList.add('active');
}
expandParents(currentCategory);
updateCatalogTitle();
await loadProducts(1);

// Более надёжное скроллирование до последней просмотренной карточки
let attempts = 0;
const maxAttempts = 15;
const scrollToSavedProduct = () => {
    const el = document.querySelector(`[data-product-id="${saved.id}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return true;
    }
    return false;
};
while (attempts < maxAttempts) {
    if (scrollToSavedProduct()) break;
    if (currentPage < totalPages) {
        currentPage++;
        await loadProducts(currentPage, true);
    } else {
        await new Promise(resolve => setTimeout(resolve, 150)); // небольшая пауза
    }
    attempts++;
}
// Финальная попытка через небольшую задержку
setTimeout(() => {
    const el = document.querySelector(`[data-product-id="${saved.id}"]`);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}, 800);
});

function toggleBackToTopButton() {
    const isMobile = window.matchMedia("(max-width: 768px)").matches;
    backToTopButton.style.display = (isMobile && window.scrollY > 100) ? 'flex' : 'none';
}
backToTopButton.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
window.addEventListener('scroll', toggleBackToTopButton);
window.addEventListener('resize', toggleBackToTopButton);

/**
 * Переключение мобильного меню категорий (sidebar).
 */
function toggleCategoryMenu() {
    const sidebar = document.getElementById('category-sidebar');
    const overlay = document.getElementById('mobile-overlay');
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}
</script>
</body>
</html>
