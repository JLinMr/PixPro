document.addEventListener('DOMContentLoaded', function() {
    initialize();
});

/**
 * 初始化函数，调用所有必要的设置和加载函数
 */
function initialize() {
    loadPage(1);
    lazyLoadImages();
    setupPagination();
    setupPageInput();
    setupDocumentClickHandler();
}

/**
 * 设置分页点击事件
 */
function setupPagination() {
    document.getElementById('pagination').addEventListener('click', handlePaginationClick);
}

/**
 * 处理分页点击事件
 */
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
            hidePageInput(input, document.getElementById('current-total-pages'));
        }
    }
}

/**
 * 设置点击事件处理程序
 */
function setupDocumentClickHandler() {
    document.addEventListener('click', handleDocumentClick);
}

/**
 * 处理点击事件
 */
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

        setTimeout(() => {
            if (data.images.length > 0) {
                setElementDisplay([gallery, pagination], 'block');
            } else {
                setElementDisplay([gallery, pagination], 'none');
            }
            toggleLoadingIndicator(loadingIndicator, false);
        }, 200);
    })
    .catch(error => {
        console.error('Error:', error);
        toggleLoadingIndicator(loadingIndicator, false);
    });
}

/**
 * 懒加载图片
 */
function lazyLoadImages() {
    const lazyImages = document.querySelectorAll('.lazy-image');
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const lazyImage = entry.target;
                const placeholder = lazyImage.previousElementSibling;
                lazyImage.src = lazyImage.dataset.src;
                const handleLoad = () => {
                    lazyImage.classList.add('loaded');
                    setTimeout(() => placeholder.style.opacity = 0, 50);
                };
                lazyImage.onload = handleLoad;
                lazyImage.onerror = () => {
                    lazyImage.src = '/static/images/svg/404.svg';
                    handleLoad();
                };
                observer.unobserve(lazyImage);
            }
        });
    }, { threshold: 0.8 });

    lazyImages.forEach(lazyImage => observer.observe(lazyImage));
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
            <div class="image-info">
                <p class="info-p">大小: <span>${formatFileSize(image.size)}</span></p>
                <p class="info-p">IP: <span>${image.upload_ip}</span></p>
                <p class="info-p">时间: <span>${image.created_at}</span></p>
            </div>
        </div>
    `).join('');

    lazyLoadImages();
}

/**
 * 显示或隐藏加载指示器
 */
function toggleLoadingIndicator(loadingIndicator, show) {
    loadingIndicator.style.display = show ? 'block' : 'none';
}

/**
 * 格式化文件大小
 */
function formatFileSize(sizeInBytes) {
    const sizeInKB = sizeInBytes / 1024;
    return `${sizeInKB.toFixed(2)} KB`;
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

/**
 * 检查页码是否超过最大页数
 */
function checkPageLimit(page, totalPages) {
    const input = document.querySelector('.page-input');
    if (totalPages === 0) {
        if (!notificationShown) {
            input.value = '';
            showNotification('你还没有上传图片呢', 'msg-red');
            notificationShown = true;
        }
    } else if (page > totalPages) {
        if (!notificationShown) {
            input.value = '';
            showNotification('输入的页数超过最大页数，请重新输入', 'msg-red');
            notificationShown = true;
            loadPage(1);
        }
    } else {
        notificationShown = false;
    }
}