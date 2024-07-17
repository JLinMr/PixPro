document.addEventListener('DOMContentLoaded', function() {
    loadPage(1);
    setupPagination();
    setupPageInput();
    setupDocumentClickHandler();
});

/**
 * 设置分页点击事件
 */
function setupPagination() {
    document.getElementById('pagination').addEventListener('click', handlePaginationClick);
}

function handlePaginationClick(e) {
    e.preventDefault();
    if (e.target.classList.contains('page-link')) {
        const page = e.target.getAttribute('data-page');
        loadPage(page);
    }
}

/**
 * 设置页码输入框
 */
function setupPageInput() {
    const currentTotalPages = document.getElementById('current-total-pages');
    const input = createPageInput();

    currentTotalPages.parentNode.appendChild(input);

    currentTotalPages.addEventListener('click', () => togglePageInputVisibility(currentTotalPages, input));
    input.addEventListener('keypress', (e) => handlePageInputKeypress(e, input));
}

/**
 * 创建页码输入框
 */
function createPageInput() {
    const input = document.createElement('input');
    input.type = 'number';
    input.min = '1';
    input.classList.add('page-input');
    input.style.display = 'none';
    return input;
}

/**
 * 切换页码输入框的显示状态
 */
function togglePageInputVisibility(currentTotalPages, input) {
    currentTotalPages.style.display = 'none';
    input.style.display = 'inline-block';
    input.focus();
}

/**
 * 处理页码输入框的回车事件
 */
function handlePageInputKeypress(e, input) {
    if (e.key === 'Enter') {
        const page = input.value;
        if (page) {
            loadPage(page);
        }
    }
}

/**
 * 设置文档点击事件处理程序
 */
function setupDocumentClickHandler() {
    document.addEventListener('click', handleDocumentClick);
}

function handleDocumentClick(e) {
    const input = document.querySelector('.page-input');
    const currentTotalPages = document.getElementById('current-total-pages');
    if (!input.contains(e.target) && e.target !== currentTotalPages) {
        hidePageInput(input, currentTotalPages);
    }
}

/**
 * 隐藏页码输入框
 */
function hidePageInput(input, currentTotalPages) {
    currentTotalPages.style.display = 'inline-block';
    input.style.display = 'none';
    input.style.animation = '';
    input.value = '';
}

/**
 * 加载指定页面的内容
 */
function loadPage(page) {
    const gallery = document.getElementById('gallery');
    const pagination = document.getElementById('pagination');
    const loadingIndicator = document.getElementById('loading-indicator');
    const currentTotalPages = document.getElementById('current-total-pages');

    // 显示加载指示器并隐藏画廊和页码
    toggleLoadingIndicator(loadingIndicator, true);
    setElementDisplay([gallery, pagination], 'none');

    fetch(`?page=${page}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        updateGallery(gallery, data.images);
        updatePagination(pagination, data.pagination);
        updateTotalPagesDisplay(currentTotalPages, data.current_page, data.total_pages);
        checkPageLimit(page, data.total_pages);
        lazyLoadImages();

        // 延迟200毫秒显示画廊和页码并隐藏加载指示器
        setTimeout(() => {
            setElementDisplay([gallery, pagination], 'block');
            toggleLoadingIndicator(loadingIndicator, false);
        }, 200);
    })
    .catch(error => {
        console.error('Error:', error);
        toggleLoadingIndicator(loadingIndicator, false);
    });
}

/**
 * 显示或隐藏加载指示器
 */
function toggleLoadingIndicator(loadingIndicator, show) {
    loadingIndicator.style.display = show ? 'block' : 'none';
}

/**
 * 更新画廊内容
 */
function updateGallery(gallery, images) {
    gallery.innerHTML = images.map(image => `
        <div class="gallery-item" id="image-${image.id}">
            <div class="placeholder-image"></div>
            <img src="" data-src="${image.url}" alt="Image" class="lazy-image" onerror="this.onerror=null;this.src='/static/images/svg/404.svg';" onclick="zoomImage(this)">
            <div class="action-buttons">
                <button class="copy-btn" data-url="${image.url}"><img src="/static/images/svg/link.svg" alt="Copy" /></button>
                <button class="delete-btn" data-id="${image.id}" data-path="${image.path}"><img src="/static/images/svg/xmark.svg" alt="X" /></button>
            </div>
        </div>
    `).join('');

    document.querySelectorAll('.gallery-item').forEach(item => {
        item.classList.add('visible');
    });

    // 重新初始化懒加载
    lazyLoadImages();
}

/**
 * 更新分页内容
 */
function updatePagination(pagination, paginationHTML) {
    pagination.innerHTML = paginationHTML;
}

/**
 * 更新总页码显示
 */
function updateTotalPagesDisplay(currentTotalPages, currentPage, totalPages) {
    currentTotalPages.textContent = `${currentPage}/${totalPages}`;
}

/**
 * 设置元素的显示状态
 */
function setElementDisplay(elements, display) {
    elements.forEach(element => {
        element.style.display = display;
    });
}

// 在页面加载完成后调用 loadPage 函数
window.onload = function() {
    loadPage(1);
};