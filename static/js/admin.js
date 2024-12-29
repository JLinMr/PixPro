// 页面加载完成后初始化
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

// 初始化所有必要的组件和功能
function initialize() {
    initComponents();
    setupPageInput();
}

// 设置所有事件处理器
function setupEventHandlers() {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    setupMultiSelect();
    setupPagination();
    setupDocumentClickHandler();
}

// API工具类
const API = {
    // 发送删除请求到服务器
    async sendDeleteRequest(id, path) {
        try {
            const response = await fetch('/config/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${encodeURIComponent(id)}&path=${encodeURIComponent(path)}`
            });
            const data = await response.json();
            return data.result === 'success';
        } catch (error) {
            console.error('Delete error:', error);
            return false;
        }
    },

    // 批量删除图片
    async deleteImages(ids, paths) {
        const errors = [];
        
        // 处理单个删除的情况
        if (!Array.isArray(ids)) {
            ids = [ids];
            paths = [paths];
        }
        
        // 执行删除操作
        for (let i = 0; i < ids.length; i++) {
            try {
                const success = await this.sendDeleteRequest(ids[i], paths[i]);
                if (!success) {
                    errors.push(ids[i]);
                }
            } catch (error) {
                errors.push(ids[i]);
                console.error(`Failed to delete image ${ids[i]}:`, error);
            }
        }

        // 刷新页面内容
        const currentPage = parseInt(document.getElementById('current-total-pages').textContent.split('/')[0]);
        const pageData = await this.loadPage(currentPage);
        
        if (pageData.success) {
            updatePageContent(pageData);
        }
        
        // 显示结果通知
        if (errors.length > 0) {
            UI.showNotification(`删除失败 ${errors.length} 张图片`, 'error');
            throw new Error(`Failed to delete ${errors.length} images`);
        }
        
        UI.showNotification(ids.length > 1 ? `成功删除 ${ids.length} 张图片` : '删除成功');
        return true;
    },

    // 加载指定页码的数据
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
    }
};

// UI工具类 - 处理界面交互和显示
const UI = {
    // 显示通知消息
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

    // 复制内容到剪贴板
    async copyToClipboard(text) {
        try {
            // 优先使用现代API
            await navigator.clipboard.writeText(text);
            this.showNotification('已复制到剪贴板');
        } catch (err) {
            // 降级使用传统方法
            this.fallbackCopy(text);
        }
    },

    // 降级的复制方法
    fallbackCopy(text) {
        try {
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            this.showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('复制到剪贴板失败: ', err);
            this.showNotification('复制到剪贴板失败', 'error');
        }
    },

    // 创建确认对话框
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
    const input = document.createElement('input');
    input.type = 'number';
    input.min = '1';
    input.classList.add('page-input');
    input.style.display = 'none';
    
    currentTotalPages.parentNode.appendChild(input);

    // 切换输入框显示状态
    const toggleInput = (show) => {
        currentTotalPages.style.display = show ? 'none' : 'inline-block';
        input.style.display = show ? 'inline-block' : 'none';
        if (show) {
            input.focus();
        } else {
            input.value = '';
        }
    };

    // 处理页码输入
    const handlePageInput = (e) => {
        if (e.key === 'Enter') {
            const page = parseInt(input.value, 10);
            const totalPages = parseInt(currentTotalPages.textContent.split('/')[1], 10);
            
            if (page && page >= 1 && page <= totalPages) {
                window.location.href = `?page=${page}`;
                toggleInput(false);
            } else {
                UI.showNotification('请输入有效的页码', 'error');
                input.value = '';
            }
        }
    };

    currentTotalPages.addEventListener('click', () => toggleInput(true));
    input.addEventListener('keypress', handlePageInput);
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && e.target !== currentTotalPages) {
            toggleInput(false);
        }
    });
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
                        await API.deleteImages(btn.dataset.id, btn.dataset.path);
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
    // 多选状态管理
    const state = {
        isMultiSelectMode: false,
        selectedItems: new Set()
    };
    
    const toolbar = createMultiSelectToolbar();
    const gallery = document.getElementById('gallery');
    const multiSelectBtn = document.querySelector('.select-link');
    
    // 创建工具栏
    function createMultiSelectToolbar() {
        const toolbar = document.createElement('div');
        toolbar.className = 'multi-select-toolbar';
        toolbar.innerHTML = `
            <span class="selected-count">已选择 0</span>
            <button class="delete-selected">删除所选</button>
            <button class="cancel-select">取消选择</button>
        `;
        
        // 绑定工具栏按钮事件
        toolbar.querySelector('.delete-selected').onclick = handleDeleteSelected;
        toolbar.querySelector('.cancel-select').onclick = toggleMultiSelectMode;
        
        document.body.appendChild(toolbar);
        return toolbar;
    }
    
    // 切换多选模式
    function toggleMultiSelectMode() {
        state.isMultiSelectMode = !state.isMultiSelectMode;
        [gallery, toolbar, multiSelectBtn].forEach(el => 
            el.classList.toggle(el === gallery ? 'multi-select-mode' : 'show'));
        if (!state.isMultiSelectMode) {
            clearSelection();
        }
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

    function handleDeleteSelected() {
        if (state.selectedItems.size === 0) {
            UI.showNotification('请先选择要删除的图片', 'error');
            return;
        }
        
        UI.createConfirmDialog(
            '确定删除这些图片吗？',
            async () => {
                try {
                    const ids = Array.from(state.selectedItems);
                    const paths = ids.map(id => {
                        const element = document.getElementById('image-' + id);
                        return element ? element.querySelector('.delete-btn').dataset.path : null;
                    }).filter(path => path !== null);
                    
                    await API.deleteImages(ids, paths);
                    clearSelection();
                    toggleMultiSelectMode();
                } catch (error) {
                    console.error('Delete error:', error);
                }
            }
        );
    }
    
    // 事件绑定
    gallery.addEventListener('click', handleItemSelection);
    
    // 多选按钮事件
    multiSelectBtn.addEventListener('click', toggleMultiSelectMode);
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
                    updatePageContent(data);
                    window.history.pushState({page}, '', `?page=${page}`);
                } catch (error) {
                    console.error('Load error:', error);
                    UI.showNotification('加载失败', 'error');
                }
            }
        }
    });
}

// 图片懒加载初始化
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

// Fancybox图片预览初始化
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

// 更新页面内容的函数
function updatePageContent(data) {
    DOM.gallery.innerHTML = data.html;
    DOM.pagination.innerHTML = data.pagination;
    DOM.pageDisplay.textContent = `${data.currentPage}/${data.totalPages}`;
    initComponents();
}

// 初始化所有UI组件
function initComponents() {
    initLazyLoad();
    initFancybox();
}