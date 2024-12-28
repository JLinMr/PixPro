document.addEventListener('DOMContentLoaded', () => {
    initialize();
    setupEventHandlers();
});

// 缓存DOM元素
const DOM = {
    gallery: document.getElementById('gallery'),
    pagination: document.getElementById('pagination'),
    pageDisplay: document.getElementById('current-total-pages'),
    scrollTopBtn: document.querySelector('#scroll-to-top'),
    rightside: document.querySelector('.rightside')
};

function initialize() {
    initLazyLoad();
    initFancybox();
    setupPageInput();
}

function setupEventHandlers() {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    setupMultiSelect();
    setupPagination();
    setupDocumentClickHandler();
}

// API工具类
const API = {
    async sendDeleteRequest(id, path, showNotification = true) {
        try {
            const response = await fetch('/config/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${encodeURIComponent(id)}&path=${encodeURIComponent(path)}`
            });
            const data = await response.json();
            
            if (data.result === 'success') {
                document.getElementById('image-' + id)?.remove();
                showNotification && UI.showNotification('删除成功');
                return true;
            }
            throw new Error(data.message);
        } catch (error) {
            showNotification && UI.showNotification('删除失败', 'error');
            throw error;
        }
    },

    async loadPage(page) {
        try {
            const response = await fetch(`/admin/index.php?page=${page}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            return data.success ? data : Promise.reject(data.message || '加载失败');
        } catch (error) {
            UI.showNotification('加载失败', 'error');
            throw error;
        }
    },

    async deleteImages(selectedItems) {
        const errors = [];
        for (const id of selectedItems) {
            const element = document.getElementById('image-' + id);
            if (!element) continue;
            
            try {
                const path = element.querySelector('.delete-btn').dataset.path;
                await this.sendDeleteRequest(id, path, false);
            } catch (error) {
                errors.push(id);
                console.error(`Failed to delete image ${id}:`, error);
            }
        }
        
        if (errors.length > 0) {
            throw new Error(`Failed to delete ${errors.length} images`);
        }
        
        return true;
    }
};

// UI工具类
const UI = {
    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `msg ${type === 'error' ? 'msg-red' : 'msg-green'}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.classList.add('msg-right');
            setTimeout(() => notification.remove(), 800);
        }, 1500);
    },

    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showNotification('已复制到剪贴板');
        } catch (err) {
            this.showNotification('复制失败', 'error');
            console.error('Copy failed:', err);
        }
    },

    createConfirmDialog(message, onConfirm) {
        const existingDialog = document.querySelector('.custom-confirm');
        if (existingDialog) {
            existingDialog.remove();
        }

        const confirmBox = document.createElement('div');
        confirmBox.className = 'custom-confirm';
        confirmBox.innerHTML = `
            <div class="confirm-message">${message}</div>
            <div class="confirm-buttons">
                <button id="confirm-delete">确认</button>
                <button id="cancel-delete">取消</button>
            </div>
        `;
        document.body.appendChild(confirmBox);
        setTimeout(() => confirmBox.classList.add('visible'), 0);

        const handleClose = (callback) => {
            confirmBox.classList.remove('visible');
            setTimeout(() => {
                confirmBox.remove();
                callback?.();
            }, 300);
        };

        confirmBox.querySelector('#confirm-delete').onclick = () => handleClose(onConfirm);
        confirmBox.querySelector('#cancel-delete').onclick = () => handleClose();
    }
};

// 页面输入控制
function setupPageInput() {
    const currentTotalPages = document.getElementById('current-total-pages');
    const input = createPageInput();
    currentTotalPages.parentNode.appendChild(input);

    currentTotalPages.addEventListener('click', () => togglePageInputVisibility(currentTotalPages, input));
    input.addEventListener('keypress', (e) => handlePageInputKeypress(e, input));
}

function createPageInput() {
    const input = document.createElement('input');
    input.type = 'number';
    input.min = '1';
    input.classList.add('page-input');
    input.style.display = 'none';
    return input;
}

function togglePageInputVisibility(currentTotalPages, input) {
    currentTotalPages.style.display = 'none';
    input.style.display = 'inline-block';
    input.focus();
}

function handlePageInputKeypress(e, input) {
    if (e.key === 'Enter') {
        const page = parseInt(input.value, 10);
        const totalPages = parseInt(document.getElementById('current-total-pages').textContent.split('/')[1], 10);
        
        if (page && page >= 1 && page <= totalPages) {
            window.location.href = `?page=${page}`;
            hidePageInput(input, document.getElementById('current-total-pages'));
        } else {
            UI.showNotification('请输入有效的页码', 'error');
            input.value = '';
        }
    }
}

function hidePageInput(input, currentTotalPages) {
    currentTotalPages.style.display = 'inline-block';
    input.style.display = 'none';
    input.value = '';
}

// 事件处理器
function setupCopyAndDeleteHandlers() {
    document.addEventListener('click', async event => {
        const btn = event.target.closest('.copy-btn, .delete-btn');
        if (!btn) return;
        
        if (btn.classList.contains('copy-btn')) {
            await UI.copyToClipboard(btn.dataset.url);
        } else if (!document.querySelector('.multi-select-mode')) {
            UI.createConfirmDialog('确定删除这张图片吗？', 
                async () => {
                    try {
                        await API.sendDeleteRequest(btn.dataset.id, btn.dataset.path);
                    } catch (error) {
                        console.error('Delete error:', error);
                    }
                }
            );
        }
    });
}

function setupScrollToTop() {
    let ticking = false;

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(() => {
                const shouldShow = window.scrollY > 100;
                DOM.scrollTopBtn.classList.toggle('visible', shouldShow);
                DOM.rightside.classList.toggle('shifted', shouldShow);
                ticking = false;
            });
            ticking = true;
        }
    });

    DOM.scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function setupDocumentClickHandler() {
    document.addEventListener('click', e => {
        const input = document.querySelector('.page-input');
        const currentTotalPages = document.getElementById('current-total-pages');
        if (input && currentTotalPages && !input.contains(e.target) && e.target !== currentTotalPages) {
            hidePageInput(input, currentTotalPages);
        }
    });
}

// 多选功能
function setupMultiSelect() {
    const state = {
        isMultiSelectMode: false,
        selectedItems: new Set(),
        pressTimer: null,
        initialTouch: null
    };
    
    const toolbar = createMultiSelectToolbar();
    const gallery = document.getElementById('gallery');
    
    function createMultiSelectToolbar() {
        const toolbar = document.createElement('div');
        toolbar.className = 'multi-select-toolbar';
        toolbar.innerHTML = `
            <span class="selected-count">已选择 0</span>
            <button class="delete-selected">删除所选</button>
            <button class="cancel-select">取消选择</button>
        `;
        document.body.appendChild(toolbar);
        return toolbar;
    }
    
    function toggleMultiSelectMode() {
        state.isMultiSelectMode = !state.isMultiSelectMode;
        gallery.classList.toggle('multi-select-mode');
        toolbar.classList.toggle('show');
        if (!state.isMultiSelectMode) clearSelection();
    }
    
    function clearSelection() {
        state.selectedItems.clear();
        document.querySelectorAll('.gallery-item.selected')
            .forEach(item => item.classList.remove('selected'));
        updateSelectedCount();
    }
    
    function updateSelectedCount() {
        toolbar.querySelector('.selected-count').textContent = 
            `已选择 ${state.selectedItems.size}`;
    }

    function handleItemSelection(e) {
        if (!state.isMultiSelectMode) return;
        
        const galleryItem = e.target.closest('.gallery-item');
        if (!galleryItem) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const itemId = galleryItem.id.replace('image-', '');
        
        if (state.selectedItems.has(itemId)) {
            state.selectedItems.delete(itemId);
            galleryItem.classList.remove('selected');
        } else {
            state.selectedItems.add(itemId);
            galleryItem.classList.add('selected');
        }
        
        updateSelectedCount();
    }

    function handleMultiSelectTrigger(e) {
        const galleryItem = e.target.closest('.gallery-item');
        if (!galleryItem || state.isMultiSelectMode) return;
        
        toggleMultiSelectMode();
        handleItemSelection(e);
    }

    function handleDeleteSelected() {
        if (state.selectedItems.size === 0) {
            UI.showNotification('请先选择要删除的图片', 'error');
            return;
        }
        
        UI.createConfirmDialog(
            '确定删除这些图片吗？',
            async () => {
                try {
                    await API.deleteImages(state.selectedItems);
                    UI.showNotification(`成功删除 ${state.selectedItems.size} 张图片`);
                    clearSelection();
                    toggleMultiSelectMode();
                } catch (error) {
                    UI.showNotification('删除过程中发生错误', 'error');
                    console.error('Delete error:', error);
                }
            }
        );
    }

    // 触摸事件处理
    const touchHandlers = {
        handleTouchStart(e) {
            if (state.isMultiSelectMode) return;
            state.initialTouch = {
                x: e.touches[0].clientX,
                y: e.touches[0].clientY
            };
            state.pressTimer = setTimeout(() => handleMultiSelectTrigger(e), 500);
        },

        handleTouchMove(e) {
            if (!state.pressTimer || !state.initialTouch) return;
            
            const threshold = 10;
            const touch = e.touches[0];
            const moved = Math.abs(touch.clientX - state.initialTouch.x) > threshold || 
                         Math.abs(touch.clientY - state.initialTouch.y) > threshold;
            
            if (moved) {
                clearTimeout(state.pressTimer);
                state.pressTimer = null;
            }
        },

        handleTouchEnd() {
            clearTimeout(state.pressTimer);
            state.pressTimer = null;
            state.initialTouch = null;
        }
    };
    
    // 事件绑定
    gallery.addEventListener('contextmenu', e => {
        e.preventDefault();
        if (!state.isMultiSelectMode) {
            handleMultiSelectTrigger(e);
        }
    });
    
    gallery.addEventListener('click', e => {
        if (state.isMultiSelectMode) {
            handleItemSelection(e);
        }
    });
    
    Object.entries(touchHandlers).forEach(([event, handler]) => {
        gallery.addEventListener(event.toLowerCase(), handler);
    });
    
    // 工具栏按钮事件
    toolbar.querySelector('.delete-selected').onclick = handleDeleteSelected;
    toolbar.querySelector('.cancel-select').onclick = toggleMultiSelectMode;
}

// 添加分页处理函数
function setupPagination() {
    document.getElementById('pagination').addEventListener('click', async (e) => {
        e.preventDefault();
        const pageLink = e.target.closest('.page-link');
        
        if (pageLink && !pageLink.classList.contains('ellipsis') && !pageLink.classList.contains('active')) {
            const page = pageLink.dataset.page;
            if (page) {
                try {
                    const data = await API.loadPage(page);
                    
                    // 更新图片内容和分页
                    DOM.gallery.innerHTML = data.html;
                    DOM.pagination.innerHTML = data.pagination;
                    DOM.pageDisplay.textContent = `${page}/${data.totalPages}`;
                    
                    // 更新URL但不刷新页面
                    window.history.pushState({page}, '', `?page=${page}`);
                    
                    // 重新初始化组件
                    initLazyLoad();
                    initFancybox();
                } catch (error) {
                    console.error('Load error:', error);
                    UI.showNotification('加载失败', 'error');
                }
            }
        }
    });
}

// LazyLoad 函数
function initLazyLoad() {
    window.lazyLoadInstance = new LazyLoad({
        elements_selector: ".lazy",
        threshold: 50,
        callback_loaded: img => {
            img.classList.add('loaded');
            img.parentElement.querySelector('.image-placeholder')?.remove();
        },
        callback_error: img => {
            img.parentElement.classList.add('load-error');
            img.parentElement.querySelector('.image-placeholder')?.remove();
        }
    });
}

// Fancybox 函数
function initFancybox() {
    Fancybox.bind('[data-fancybox="gallery"]', {
        Toolbar: { display: { right: ["slideshow", "thumbs", "close"] }},
        Thumbs: { showOnStart: false },
        hideScrollbar: false,
        Image: { zoom: false },
        Hash: false,
        on: {
            beforeShow: () => document.body.style.overflow = 'hidden',
            destroy: () => document.body.style.overflow = ''
        }
    });
}