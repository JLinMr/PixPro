import { toast, dialog } from '../core/ui.js';
import { copyText } from '../core/clipboard.js';

let dom = null;
let lazyLoad = null;
let fancyboxReady = false;

const LAZYLOAD_CONFIG = {
    elements_selector: '.lazy',
    threshold: 100,
    callback_loaded: (img) => {
        img.classList.add('loaded');
        img.closest('.image-wrapper')?.querySelector('.image-placeholder')?.remove();
    },
    callback_error: (img) => {
        const wrapper = img.closest('.image-wrapper');
        if (!wrapper) return;
        wrapper.classList.add('load-error');
        wrapper.querySelector('.image-placeholder')?.remove();
        const link = wrapper.querySelector('.image-link');
        if (link) {
            link.removeAttribute('data-fancybox');
            link.removeAttribute('href');
        }
    }
};

const selection = {
    active: false,
    ids: new Set(),
    toggle() {
        this.active = !this.active;
        if (!this.active) this.ids.clear();
    }
};

const api = {
    async deleteOne(path) {
        const res = await fetch('../delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': window.PIXPRO_CSRF_TOKEN || ''
            },
            body: new URLSearchParams({
                path,
                csrf_token: window.PIXPRO_CSRF_TOKEN || ''
            })
        });
        const data = await res.json();
        if (data.result !== 'success') throw new Error();
    },

    async deleteMany(paths) {
        const pathList = Array.isArray(paths) ? paths : [paths];
        const errors = (await Promise.allSettled(pathList.map((path) => this.deleteOne(path))))
            .filter((r) => r.status === 'rejected').length;
        const data = await this.loadPage(getCurrentPage());
        if (data.success) renderPage(data);
        if (errors) {
            toast(`删除失败 ${errors} 张图片`, 'error');
            throw new Error();
        }
        toast(pathList.length > 1 ? `成功删除 ${pathList.length} 张图片` : '删除成功');
    },

    async loadPage(page, signal) {
        const res = await fetch(`/admin/index.php?page=${page}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal
        });
        const data = await res.json();
        if (!data.success) throw new Error();
        return data;
    }
};

const itemId = (el) => el.id.slice(6);

function initLazyLoad() {
    lazyLoad?.destroy();
    lazyLoad = new LazyLoad(LAZYLOAD_CONFIG);
}

function getCurrentPage() {
    const input = dom.pagination?.querySelector('.pagination-jumper-input');
    return parseInt(input?.value, 10) || 1;
}

function renderPage(data) {
    dom.gallery.innerHTML = data.html;
    dom.pagination.innerHTML = data.pagination;
    initLazyLoad();

    if (selection.active) {
        selection.ids.clear();
        const countEl = document.querySelector('.multi-select-toolbar .selected-count');
        if (countEl) countEl.textContent = '已选择 0';
        const selectAllBtn = document.querySelector('.multi-select-toolbar .select-all');
        if (selectAllBtn) selectAllBtn.textContent = '全选';
    }
}

function bindActions() {
    let copying = false;
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.copy-btn, .delete-btn');
        if (!btn || copying) return;
        e.stopPropagation();
        if (btn.classList.contains('copy-btn')) {
            copying = true;
            if (await copyText(btn.dataset.url)) toast('已复制到剪贴板');
            else toast('复制失败，请重试', 'error');
            copying = false;
            return;
        }
        if (!selection.active) {
            dialog({
                message: '确定删除这张图片吗？',
                onConfirm: () => api.deleteMany(btn.dataset.path),
                type: 'danger',
                confirmText: '删除'
            });
        }
    }, { passive: false });

    let ticking = false;
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            const show = window.scrollY > 100;
            dom.scrollTopBtn.classList.toggle('visible', show);
            dom.rightside.classList.toggle('shifted', show);
            ticking = false;
        });
    }, { passive: true });
    dom.scrollTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

function bindPagination() {
    let loading = false;
    let abort;

    const loadPageView = async (page, signal) => {
        const data = await api.loadPage(page, signal);
        renderPage(data);
    };

    const navigate = async (page) => {
        if (loading) return;
        abort?.abort();
        loading = true;
        abort = new AbortController();

        try {
            await loadPageView(page, abort.signal);
            history.pushState({ page }, '', `?page=${page}`);
        } catch (err) {
            if (err.name !== 'AbortError') toast('加载失败', 'error');
        } finally {
            loading = false;
            abort = null;
        }
    };

    window.addEventListener('popstate', () => {
        const page = parseInt(new URLSearchParams(location.search).get('page'), 10) || 1;
        if (loading) return;
        loadPageView(page).catch(() => toast('加载失败', 'error'));
    });

    dom.pagination.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-page]');
        if (!btn || btn.disabled || btn.classList.contains('is-disabled')) return;
        e.preventDefault();
        await navigate(btn.dataset.page);
    }, { passive: false });

    dom.pagination.addEventListener('focusin', (e) => {
        if (e.target.matches('.pagination-jumper-input')) {
            e.target.dataset.committed = e.target.value;
        }
    }, true);

    dom.pagination.addEventListener('keydown', (e) => {
        if (!e.target.matches('.pagination-jumper-input')) return;

        const input = e.target;
        if (e.key === 'Enter') {
            e.preventDefault();
            const page = parseInt(input.value, 10);
            const total = parseInt(input.max, 10);
            if (page >= 1 && page <= total) {
                navigate(page);
            } else {
                toast('请输入有效的页码', 'error');
                input.value = input.dataset.committed || 1;
            }
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            input.value = input.dataset.committed || input.value;
            input.blur();
        }
    });

    dom.pagination.addEventListener('blur', (e) => {
        if (e.target.matches('.pagination-jumper-input')) {
            e.target.value = e.target.dataset.committed || e.target.value;
        }
    }, true);
}

function bindMultiSelect() {
    const toolbar = document.createElement('div');
    toolbar.className = 'multi-select-toolbar glass';
    toolbar.innerHTML = `
        <span class="selected-count glass-panel">已选择 0</span>
        <button type="button" class="select-all glass-btn">全选</button>
        <button type="button" class="delete-selected glass-btn">删除</button>
        <button type="button" class="cancel-select glass-btn">取消</button>`;
    document.body.appendChild(toolbar);

    const countEl = toolbar.querySelector('.selected-count');
    const selectAllBtn = toolbar.querySelector('.select-all');
    const selectBtn = document.querySelector('.select-link');

    const getPageItems = () => [...dom.gallery.querySelectorAll('.gallery-item')];

    const updateSelectAllBtn = () => {
        const items = getPageItems();
        const allSelected = items.length > 0 && items.every((item) => selection.ids.has(itemId(item)));
        selectAllBtn.textContent = allSelected ? '取消全选' : '全选';
    };

    const syncCount = () => {
        countEl.textContent = `已选择 ${selection.ids.size}`;
        updateSelectAllBtn();
    };

    const toggleMode = () => {
        selection.toggle();
        dom.gallery.classList.toggle('multi-select-mode', selection.active);
        toolbar.classList.toggle('show', selection.active);
        selectBtn.classList.toggle('active', selection.active);
        if (!selection.active) dom.gallery.querySelectorAll('.gallery-item.selected').forEach((el) => el.classList.remove('selected'));
        syncCount();
    };

    const toggleSelectAll = () => {
        const items = getPageItems();
        if (!items.length) return;

        const allSelected = items.every((item) => selection.ids.has(itemId(item)));
        items.forEach((item) => {
            const id = itemId(item);
            if (allSelected) {
                selection.ids.delete(id);
                item.classList.remove('selected');
            } else {
                selection.ids.add(id);
                item.classList.add('selected');
            }
        });
        syncCount();
    };

    dom.gallery.addEventListener('click', (e) => {
        if (!selection.active) return;
        const item = e.target.closest('.gallery-item');
        if (!item || e.target.closest('.action-buttons')) return;
        e.preventDefault();
        e.stopPropagation();

        const id = itemId(item);
        if (selection.ids.has(id)) {
            selection.ids.delete(id);
            item.classList.remove('selected');
        } else {
            selection.ids.add(id);
            item.classList.add('selected');
        }
        syncCount();
    }, { passive: false });

    selectBtn.addEventListener('click', toggleMode);
    selectAllBtn.addEventListener('click', toggleSelectAll);
    toolbar.querySelector('.cancel-select').addEventListener('click', toggleMode);
    toolbar.querySelector('.delete-selected').addEventListener('click', () => {
        if (!selection.ids.size) return toast('请先选择要删除的图片', 'error');
        dialog({
            message: `确定删除这 ${selection.ids.size} 张图片吗？`,
            onConfirm: async () => {
                try {
                    const ids = [...selection.ids];
                    const paths = ids.map((id) =>
                        document.getElementById(`image-${id}`)?.dataset.path
                    ).filter(Boolean);
                    await api.deleteMany(paths);
                    selection.ids.clear();
                    toggleMode();
                } catch {}
            },
            type: 'danger',
            confirmText: '删除'
        });
    });
}

function initFancybox() {
    if (fancyboxReady) return;
    Fancybox.bind('[data-fancybox="gallery"]', {
        mainClass: 'fancybox-glass',
        Toolbar: {
            display: {
                left: ['slideshow', 'infobar'],
                middle: ['toggle1to1', 'rotateCW', 'flipX', 'flipY'],
                right: ['fullscreen', 'thumbs', 'close']
            }
        },
        Thumbs: { showOnStart: false },
        hideScrollbar: false,
        Hash: false,
        on: {
            beforeShow: () => { document.body.style.overflow = 'hidden'; },
            destroy: () => { document.body.style.overflow = ''; }
        }
    });
    fancyboxReady = true;
}

export function initGallery() {
    dom = {
        gallery: document.getElementById('gallery'),
        pagination: document.getElementById('pagination'),
        scrollTopBtn: document.querySelector('#scroll-to-top'),
        rightside: document.querySelector('.rightside')
    };

    initLazyLoad();
    initFancybox();
    bindActions();
    bindPagination();
    bindMultiSelect();

    window.addEventListener('beforeunload', () => {
        lazyLoad?.destroy();
        Fancybox.close();
    });
}
