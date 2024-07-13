document.addEventListener('DOMContentLoaded', () => {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    lazyLoadImages();
});

/**
 * 设置复制和删除按钮的事件处理程序
 */
function setupCopyAndDeleteHandlers() {
    document.addEventListener('click', event => {
        const btn = event.target.closest('.copy-btn, .delete-btn');
        if (!btn) return;

        if (btn.classList.contains('copy-btn')) {
            handleCopy(btn.dataset.url);
        } else {
            deleteImage(btn.dataset.id, btn.dataset.path);
        }
    });

    function deleteImage(id, path) {
        const confirmBox = createCustomConfirm();
        document.body.appendChild(confirmBox);

        confirmBox.querySelector('#confirm-delete').addEventListener('click', () => {
            confirmBox.classList.add('fade-out');
            setTimeout(() => {
                confirmBox.remove();
                sendDeleteRequest(id, path);
            }, 500);
        });

        confirmBox.querySelector('#cancel-delete').addEventListener('click', () => {
            confirmBox.classList.add('fade-out');
            setTimeout(() => confirmBox.remove(), 500);
        });
    }

    function createCustomConfirm() {
        const confirmBox = document.createElement('div');
        confirmBox.className = 'custom-confirm';
        confirmBox.innerHTML = `
            <div class="confirm-message">确定删除这张图片吗？</div>
            <div class="confirm-buttons">
                <button id="confirm-delete">确认</button>
                <button id="cancel-delete">取消</button>
            </div>
        `;
        return confirmBox;
    }

    function sendDeleteRequest(id, path) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/config/del.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = () => {
            const response = JSON.parse(xhr.responseText);
            showNotification(response.result === 'success' ? response.message : '错误：' + xhr.status, 'red-success');
            if (response.result === 'success') document.getElementById('image-' + id)?.remove();
        };
        xhr.onerror = () => showNotification('请求失败。', 'red-success');
        xhr.send('id=' + encodeURIComponent(id) + '&path=' + encodeURIComponent(path));
    }
}

/**
 * 处理复制操作
 * @param {string} url - 要复制的 URL
 */
async function handleCopy(url) {
    try {
        await navigator.clipboard.writeText(url);
        showNotification('已复制到剪贴板', 'green-success');
    } catch (err) {
        console.error('复制到剪贴板失败: ', err);
        try {
            // 失败后使用备用方法
            const tempInput = document.createElement('input');
            tempInput.value = url;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showNotification('已复制到剪贴板', 'green-success');
        } catch (err) {
            console.error('备用方法复制到剪贴板失败: ', err);
            showNotification('复制到剪贴板失败', 'red-success');
        }
    }
}

/**
 * 设置滚动到顶部按钮的功能
 */
function setupScrollToTop() {
    const button = document.querySelector('#scroll-to-top');
    const rightside = document.querySelector('.rightside');

    button.addEventListener('click', () => {
        if ('scrollBehavior' in document.documentElement.style) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            for (let i = 0; i < 30; i++) setTimeout(() => window.scrollBy(0, -window.scrollY / 30), i * 15);
        }
    });

    window.addEventListener('scroll', () => {
        button.classList.toggle('visible', window.scrollY > 100);
        rightside.classList.toggle('shifted', window.scrollY > 100);
    });
}

/**
 * 显示通知
 * @param {string} message - 通知消息
 * @param {string} [className='green-success'] - 通知样式类名
 */
function showNotification(message, className = 'green-success') {
    const existingNotification = document.querySelector('.green-success, .red-success');
    if (existingNotification) {
        existingNotification.parentNode.removeChild(existingNotification);
    }
    const notification = document.createElement('div');
    notification.classList.add(className);
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('success-right');
        setTimeout(() => notification.parentNode.removeChild(notification), 1000);
    }, 1500);
}

/**
 * 检查页码是否超过最大页数
 * @param {number} page - 当前页码
 * @param {number} totalPages - 总页数
 */
function checkPageLimit(page, totalPages) {
    const input = document.querySelector('.page-input');
    if (totalPages === 0) {
        input.value = '';
        showNotification('你还没有上传图片呢', 'red-success');
    } else if (page > totalPages) {
        input.value = '';
        showNotification('输入的页数超过最大页数，请重新输入', 'red-success');
        loadPage(1);
    }
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
                    lazyImage.src = '/static/svg/404.svg';
                    handleLoad();
                };
                observer.unobserve(lazyImage);
            }
        });
    }, { threshold: 0.8 });

    lazyImages.forEach(lazyImage => observer.observe(lazyImage));
}