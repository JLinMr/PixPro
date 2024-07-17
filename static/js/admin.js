document.addEventListener('DOMContentLoaded', () => {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    lazyLoadImages();
});

/**
 * 设置复制和删除按钮的事件处理程序
 */
function setupCopyAndDeleteHandlers() {
    let currentConfirmBox = null;

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
        if (currentConfirmBox) {
            currentConfirmBox.classList.remove('fade-out');
            currentConfirmBox.querySelector('.confirm-message').textContent = '确定删除这张图片吗？';
        } else {
            currentConfirmBox = createCustomConfirm();
            document.body.appendChild(currentConfirmBox);
        }

        currentConfirmBox.querySelector('#confirm-delete').addEventListener('click', () => {
            currentConfirmBox.classList.add('fade-out');
            setTimeout(() => {
                currentConfirmBox.remove();
                currentConfirmBox = null;
                sendDeleteRequest(id, path);
            }, 500);
        });

        currentConfirmBox.querySelector('#cancel-delete').addEventListener('click', () => {
            currentConfirmBox.classList.add('fade-out');
            setTimeout(() => {
                currentConfirmBox.remove();
                currentConfirmBox = null;
            }, 500);
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
            showNotification(response.result === 'success' ? response.message : '错误：' + xhr.status, 'msg-red');
            if (response.result === 'success') document.getElementById('image-' + id)?.remove();
        };
        xhr.onerror = () => showNotification('请求失败。', 'msg-red');
        xhr.send('id=' + encodeURIComponent(id) + '&path=' + encodeURIComponent(path));
    }
}

/**
 * 处理复制操作
 */
async function handleCopy(url) {
    try {
        await navigator.clipboard.writeText(url);
        showNotification('已复制到剪贴板');
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
            showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('备用方法复制到剪贴板失败: ', err);
            showNotification('复制到剪贴板失败', 'msg-red');
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
 */
function showNotification(message, className = 'msg-green') {
    const notification = document.createElement('div');
    notification.className = `msg ${className}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('msg-right');
        setTimeout(() => notification.remove(), 800);
    }, 1500);
}

/**
 * 检查页码是否超过最大页数
 */
function checkPageLimit(page, totalPages) {
    const input = document.querySelector('.page-input');
    if (totalPages === 0) {
        input.value = '';
        showNotification('你还没有上传图片呢', 'msg-red');
    } else if (page > totalPages) {
        input.value = '';
        showNotification('输入的页数超过最大页数，请重新输入', 'msg-red');
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
                    lazyImage.src = '/static/images/svg/404.svg';
                    handleLoad();
                };
                observer.unobserve(lazyImage);
            }
        });
    }, { threshold: 0.8 });

    lazyImages.forEach(lazyImage => observer.observe(lazyImage));
}