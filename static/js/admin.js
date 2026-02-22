// 配置和DOM缓存
let DOM = null;
let lazyLoadInstance = null;
let isComponentsInitialized = false;

// LazyLoad 配置（复用）
const LAZYLOAD_CONFIG = {
    elements_selector: ".lazy",
    threshold: 100,
    callback_loaded: (img) => {
        img.classList.add('loaded');
        img.parentElement.querySelector('.image-placeholder')?.remove();
    },
    callback_error: (img) => {
        img.parentElement.classList.add('load-error');
        img.parentElement.querySelector('.image-placeholder')?.remove();
    }
};

// 多选状态管理
const MultiSelectState = {
    isActive: false,
    selectedItems: new Set(),
    
    toggle() {
        this.isActive = !this.isActive;
        if (!this.isActive) this.clear();
    },
    
    clear() {
        this.selectedItems.clear();
    },
    
    add(id) {
        this.selectedItems.add(id);
    },
    
    remove(id) {
        this.selectedItems.delete(id);
    },
    
    has(id) {
        return this.selectedItems.has(id);
    },
    
    getAll() {
        return Array.from(this.selectedItems);
    },
    
    count() {
        return this.selectedItems.size;
    }
};

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    DOM = {
        gallery: document.getElementById('gallery'),
        pagination: document.getElementById('pagination'),
        pageDisplay: document.getElementById('current-total-pages'),
        scrollTopBtn: document.querySelector('#scroll-to-top'),
        rightside: document.querySelector('.rightside')
    };
    
    initialize();
});

// 初始化
function initialize() {
    initComponents();
    setupEventHandlers();
    setupPageInput();
}

// API 工具类
const API = {
    async sendDeleteRequest(id, path) {
        const response = await fetch('/config/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&path=${encodeURIComponent(path)}`
        });
        const data = await response.json();
        if (data.result !== 'success') throw new Error();
        return true;
    },

    async deleteImages(ids, paths) {
        if (!Array.isArray(ids)) {
            ids = [ids];
            paths = [paths];
        }
        
        const results = await Promise.allSettled(
            ids.map((id, i) => this.sendDeleteRequest(id, paths[i]))
        );
        
        const errors = results.filter(r => r.status === 'rejected').length;
        
        const currentPage = parseInt(DOM.pageDisplay.textContent.split('/')[0]);
        const pageData = await this.loadPage(currentPage);
        
        if (pageData.success) updatePageContent(pageData);
        
        if (errors > 0) {
            UI.showNotification(`删除失败 ${errors} 张图片`, 'error');
            throw new Error();
        }
        
        UI.showNotification(ids.length > 1 ? `成功删除 ${ids.length} 张图片` : '删除成功');
        return true;
    },

    async loadPage(page) {
        const response = await fetch(`/admin/index.php?page=${page}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (!data.success) throw new Error();
        return data;
    }
};

// UI 工具类
const UI = {
    notificationTimer: null,
    
    showNotification(message, type = 'success') {
        // 清理旧通知，避免累积
        const oldNotification = document.querySelector('.msg');
        if (oldNotification) {
            oldNotification.remove();
            if (this.notificationTimer) {
                clearTimeout(this.notificationTimer);
                this.notificationTimer = null;
            }
        }
        
        const notification = document.createElement('div');
        notification.className = `msg ${type === 'error' ? 'msg-red' : 'msg-green'}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        this.notificationTimer = setTimeout(() => {
            notification.classList.add('msg-right');
            setTimeout(() => {
                notification.remove();
                this.notificationTimer = null;
            }, 800);
        }, 1500);
    },

    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showNotification('已复制到剪贴板');
        } catch {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            input.remove(); // 立即移除，避免累积
            this.showNotification('已复制到剪贴板');
        }
    },

    createConfirmDialog(message, onConfirm, options = {}) {
        const { confirmText = '确认', cancelText = '取消', type = 'success' } = options;

        // 清理旧对话框
        const oldDialog = document.querySelector('.custom-confirm');
        if (oldDialog) oldDialog.remove();

        const confirmBox = document.createElement('div');
        confirmBox.className = 'custom-confirm';
        confirmBox.innerHTML = `
            <div class="confirm-message">${message}</div>
            <div class="confirm-buttons">
                <button class="btn-${type}">${confirmText}</button>
                <button class="btn-cancel">${cancelText}</button>
            </div>
        `;
        document.body.appendChild(confirmBox);
        setTimeout(() => confirmBox.classList.add('visible'), 10);

        const handleClose = (callback) => {
            confirmBox.classList.remove('visible');
            document.removeEventListener('keydown', handleKeydown);
            setTimeout(() => {
                confirmBox.remove();
                callback?.();
            }, 300);
        };

        const handleKeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleClose(onConfirm);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                handleClose();
            }
        };

        document.addEventListener('keydown', handleKeydown);
        confirmBox.querySelector(`.btn-${type}`).onclick = () => handleClose(onConfirm);
        confirmBox.querySelector('.btn-cancel').onclick = () => handleClose();
    }
};

// 设置所有事件处理器
function setupEventHandlers() {
    setupCopyAndDelete();
    setupScrollToTop();
    setupMultiSelect();
    setupPagination();
}

// 复制和删除事件（使用事件委托，只绑定一次）
function setupCopyAndDelete() {
    let isProcessing = false;
    
    document.addEventListener('click', async e => {
        const btn = e.target.closest('.copy-btn, .delete-btn');
        if (!btn || isProcessing) return;
        
        e.stopPropagation();
        
        if (btn.classList.contains('copy-btn')) {
            isProcessing = true;
            await UI.copyToClipboard(btn.dataset.url);
            isProcessing = false;
        } else if (!MultiSelectState.isActive) {
            UI.createConfirmDialog('确定删除这张图片吗？', 
                () => API.deleteImages(btn.dataset.id, btn.dataset.path),
                { type: 'danger', confirmText: '删除' }
            );
        }
    }, { passive: false });
}

// 回到顶部
function setupScrollToTop() {
    let ticking = false;

    window.addEventListener('scroll', () => {
        if (ticking) return;
        
        ticking = true;
        window.requestAnimationFrame(() => {
            const shouldShow = window.scrollY > 100;
            DOM.scrollTopBtn.classList.toggle('visible', shouldShow);
            DOM.rightside.classList.toggle('shifted', shouldShow);
            ticking = false;
        });
    }, { passive: true });

    DOM.scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

// 分页处理
function setupPagination() {
    let isLoading = false;
    let abortController = null;
    
    DOM.pagination.addEventListener('click', async (e) => {
        e.preventDefault();
        
        if (isLoading) return;
        
        const pageLink = e.target.closest('.page-link');
        
        if (pageLink && !pageLink.classList.contains('ellipsis', 'active')) {
            const page = pageLink.dataset.page;
            if (page) {
                // 取消之前的请求
                if (abortController) {
                    abortController.abort();
                }
                
                isLoading = true;
                abortController = new AbortController();
                
                try {
                    const response = await fetch(`/admin/index.php?page=${page}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: abortController.signal
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        updatePageContent(data);
                        window.history.pushState({page}, '', `?page=${page}`);
                    }
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        UI.showNotification('加载失败', 'error');
                    }
                } finally {
                    isLoading = false;
                    abortController = null;
                }
            }
        }
    }, { passive: false });
}

// 页面输入控制
function setupPageInput() {
    const input = document.createElement('input');
    input.type = 'number';
    input.min = '1';
    input.className = 'page-input';
    input.style.display = 'none';
    
    DOM.pageDisplay.parentNode.appendChild(input);
    
    const toggle = (show) => {
        DOM.pageDisplay.style.display = show ? 'none' : 'inline-block';
        input.style.display = show ? 'inline-block' : 'none';
        if (show) input.focus();
        else input.value = '';
    };
    
    DOM.pageDisplay.addEventListener('click', () => toggle(true));
    
    input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const page = parseInt(input.value);
            const totalPages = parseInt(DOM.pageDisplay.textContent.split('/')[1]);
            
            if (page >= 1 && page <= totalPages) {
                window.location.href = `?page=${page}`;
            } else {
                UI.showNotification('请输入有效的页码', 'error');
                input.value = '';
            }
        }
    });
    
    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && e.target !== DOM.pageDisplay) {
            toggle(false);
        }
    });
}

// 多选功能
function setupMultiSelect() {
    const toolbar = createMultiSelectToolbar();
    const multiSelectBtn = document.querySelector('.select-link');
    const selectedCountEl = toolbar.querySelector('.selected-count');
    
    // 切换多选模式
    const toggleMode = () => {
        MultiSelectState.toggle();
        
        DOM.gallery.classList.toggle('multi-select-mode');
        toolbar.classList.toggle('show');
        multiSelectBtn.classList.toggle('show');
        
        // 清理选中状态
        if (!MultiSelectState.isActive) {
            const selected = DOM.gallery.querySelectorAll('.gallery-item.selected');
            selected.forEach(item => item.classList.remove('selected'));
        }
        updateSelectedCount();
    };
    
    // 更新选中数量
    const updateSelectedCount = () => {
        selectedCountEl.textContent = `已选择 ${MultiSelectState.count()}`;
    };
    
    // 处理图片选择（使用事件委托）
    const handleItemSelection = (e) => {
        if (!MultiSelectState.isActive) return;
        
        const galleryItem = e.target.closest('.gallery-item');
        if (!galleryItem || e.target.closest('.action-buttons')) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const itemId = galleryItem.id.slice(6); // 'image-'.length = 6
        
        if (MultiSelectState.has(itemId)) {
            MultiSelectState.remove(itemId);
            galleryItem.classList.remove('selected');
        } else {
            MultiSelectState.add(itemId);
            galleryItem.classList.add('selected');
        }
        updateSelectedCount();
    };
    
    // 删除选中的图片
    const handleDeleteSelected = () => {
        const count = MultiSelectState.count();
        if (count === 0) {
            UI.showNotification('请先选择要删除的图片', 'error');
            return;
        }
        
        UI.createConfirmDialog(
            `确定删除这 ${count} 张图片吗？`,
            async () => {
                try {
                    const ids = MultiSelectState.getAll();
                    const paths = ids.map(id => 
                        document.getElementById('image-' + id)?.querySelector('.delete-btn')?.dataset.path
                    ).filter(Boolean);
                    
                    await API.deleteImages(ids, paths);
                    MultiSelectState.clear();
                    toggleMode();
                } catch {}
            },
            { type: 'danger', confirmText: '删除' }
        );
    };
    
    // 绑定事件
    DOM.gallery.addEventListener('click', handleItemSelection, { passive: false });
    multiSelectBtn.addEventListener('click', toggleMode);
    toolbar.querySelector('.delete-selected').addEventListener('click', handleDeleteSelected);
    toolbar.querySelector('.cancel-select').addEventListener('click', toggleMode);
}

// 创建多选工具栏
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

// 图片懒加载和预览
function initComponents() {
    // 销毁旧的懒加载实例
    if (lazyLoadInstance) {
        lazyLoadInstance.destroy();
        lazyLoadInstance = null;
    }
    
    // 懒加载
    lazyLoadInstance = new LazyLoad(LAZYLOAD_CONFIG);
    
    // Fancybox 只初始化一次
    if (!isComponentsInitialized) {
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
        isComponentsInitialized = true;
    }
}


// 更新页面内容
function updatePageContent(data) {
    // 销毁懒加载实例
    if (lazyLoadInstance) {
        lazyLoadInstance.destroy();
        lazyLoadInstance = null;
    }
    
    // 直接替换内容（不用 RAF，减少延迟）
    DOM.gallery.innerHTML = data.html;
    DOM.pagination.innerHTML = data.pagination;
    DOM.pageDisplay.textContent = `${data.currentPage}/${data.totalPages}`;
    
    // 重新初始化懒加载（使用复用配置）
    lazyLoadInstance = new LazyLoad(LAZYLOAD_CONFIG);
}

// 页面卸载时清理资源
window.addEventListener('beforeunload', () => {
    if (lazyLoadInstance) {
        lazyLoadInstance.destroy();
        lazyLoadInstance = null;
    }
    Fancybox.close();
});
