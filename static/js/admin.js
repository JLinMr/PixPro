document.addEventListener('DOMContentLoaded', () => {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    initializeFancyBox();
});

/**
 * 初始化 FancyBox
 */
function initializeFancyBox() {
    Fancybox.bind('[data-fancybox="gallery"]', {
        // 默认配置选项
        Toolbar: {
            display: {
                left: [],
                middle: [],
                right: ["thumbs","close"],
            },
        },
        Thumbs: {
            showOnStart: false,
        },
    });
}

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
            currentConfirmBox.remove();
            currentConfirmBox = null;
        }

        currentConfirmBox = createCustomConfirm();
        document.body.appendChild(currentConfirmBox);

        const confirmButton = currentConfirmBox.querySelector('#confirm-delete');
        const cancelButton = currentConfirmBox.querySelector('#cancel-delete');

        const handleConfirm = () => {
            confirmButton.blur();
            currentConfirmBox.classList.add('fade-out');
            setTimeout(() => {
                currentConfirmBox.remove();
                currentConfirmBox = null;
                sendDeleteRequest(id, path);
            });
        };

        const handleCancel = () => {
            cancelButton.blur();
            currentConfirmBox.classList.add('fade-out');
            setTimeout(() => {
                currentConfirmBox.remove();
                currentConfirmBox = null;
            });
        };

        confirmButton.addEventListener('click', handleConfirm);
        cancelButton.addEventListener('click', handleCancel);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                handleConfirm();
            } else if (event.key === 'Escape') {
                handleCancel();
            }
        }, { once: true });
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
            showNotification(response.result === 'success' ? response.message : '错误信息：' + xhr.status, 'msg-red');
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

let notificationShown = false;