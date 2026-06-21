import { toast, dialog } from '../core/ui.js';
import { copyText } from '../core/clipboard.js';

const STORAGE_TEST_REQUIRED = {
    oss: ['oss_endpoint', 'oss_bucket', 'oss_access_key_id', 'oss_access_key_secret'],
    s3: ['s3_endpoint', 's3_region', 's3_bucket', 's3_access_key_id', 's3_access_key_secret'],
    upyun: ['upyun_bucket', 'upyun_operator', 'upyun_password']
};

const ajaxHeaders = { 'X-Requested-With': 'XMLHttpRequest' };
const CLOSE_OUTSIDE_MARGIN = 300; // 遮罩点击关闭的安全区域

async function postSettings(body) {
    const response = await fetch('settings.php', { method: 'POST', headers: ajaxHeaders, body });
    return response.json();
}

export function initSettings() {
    const settingsModal = document.getElementById('settings-modal');
    const settingsLink = document.querySelector('.settings-link');
    if (!settingsModal || !settingsLink) return;

    const generateRandomToken = (length = 32) => {
        const bytes = new Uint8Array(length / 2);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    };

    const updateStorageSettings = () => {
        const selectedStorage = settingsModal.querySelector('input[name="storage"]:checked');
        if (!selectedStorage) return;

        settingsModal.querySelectorAll('[id$="-settings"]').forEach((panel) => {
            panel.style.display = 'none';
        });
        settingsModal.querySelector(`#${selectedStorage.value}-settings`)?.style.setProperty('display', 'block');
    };

    const isStorageConfigComplete = (storage) => {
        const required = STORAGE_TEST_REQUIRED[storage];
        if (!required) return true;

        const panel = settingsModal.querySelector(`#${storage}-settings`);
        return panel && required.every((key) => panel.querySelector(`[name="${key}"]`)?.value.trim());
    };

    const updateTestButtons = () => {
        settingsModal.querySelectorAll('.test-storage-btn').forEach((btn) => {
            btn.disabled = !isStorageConfigComplete(btn.dataset.storage);
        });
    };

    const openSettings = () => {
        updateStorageSettings();
        updateTestButtons();
        settingsModal.classList.remove('is-closing');
        settingsModal.classList.add('show');
        settingsModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    const closeSettings = () => {
        if (!settingsModal.classList.contains('show') || settingsModal.classList.contains('is-closing')) return;

        const container = settingsModal.querySelector('.settings-container');
        if (!container) {
            settingsModal.classList.remove('show');
            settingsModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            return;
        }

        settingsModal.classList.add('is-closing');
        let closed = false;
        const finishClose = () => {
            if (closed) return;
            closed = true;
            settingsModal.classList.remove('show', 'is-closing');
            settingsModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        };

        container.addEventListener('animationend', (e) => {
            if (e.target === container) finishClose();
        }, { once: true });
        setTimeout(finishClose, 320);
    };

    const handleAction = async (btn, action, onSuccess) => {
        const originalText = btn.textContent;
        const pendingText = action === 'optimize_db' ? '优化中...' : '检测中...';
        btn.disabled = true;
        btn.textContent = pendingText;

        try {
            const formData = new FormData();
            formData.append('action', action);
            onSuccess(await postSettings(formData));
        } catch (error) {
            console.error('操作失败:', error);
            toast('操作失败', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    };

    updateStorageSettings();
    updateTestButtons();

    settingsLink.addEventListener('click', (e) => {
        e.preventDefault();
        openSettings();
    });

    settingsModal.addEventListener('click', (e) => {
        const container = settingsModal.querySelector('.settings-container');
        if (!container) return;

        const rect = container.getBoundingClientRect();
        const m = CLOSE_OUTSIDE_MARGIN;
        const insideBuffer = e.clientX >= rect.left - m && e.clientX <= rect.right + m
            && e.clientY >= rect.top - m && e.clientY <= rect.bottom + m;

        if (!insideBuffer) closeSettings();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !settingsModal.classList.contains('show')) return;
        if (document.querySelector('.custom-confirm')) return;
        e.preventDefault();
        closeSettings();
    });

    settingsModal.addEventListener('change', (e) => {
        if (e.target.name === 'storage') {
            updateStorageSettings();
            updateTestButtons();
        }
    });

    settingsModal.addEventListener('input', (e) => {
        if (e.target.closest('#settings-form')) updateTestButtons();
    });

    settingsModal.addEventListener('submit', async (e) => {
        if (e.target.id !== 'settings-form') return;
        e.preventDefault();

        const formData = new FormData(e.target);
        const maxFileSize = formData.get('max_file_size');
        if (maxFileSize) {
            formData.set('max_file_size', Math.floor(maxFileSize * 1024 * 1024));
        }

        try {
            const { message, success } = await postSettings(formData);
            toast(message, success ? 'success' : 'error');
        } catch (error) {
            console.error('Error saving settings:', error);
            toast('保存设置失败', 'error');
        }
    });

    settingsModal.addEventListener('click', async (e) => {
        if (e.target.closest('.close-modal')) {
            closeSettings();
            return;
        }

        const tokenInput = settingsModal.querySelector('#token-input');

        if (e.target.closest('.copy-token') && tokenInput?.value) {
            const ok = await copyText(tokenInput.value);
            toast(ok ? 'Token已复制' : '复制失败，请重试', ok ? 'success' : 'error');
            return;
        }

        if (e.target.closest('.refresh-token') && tokenInput) {
            tokenInput.value = generateRandomToken(32);
            toast('Token已刷新', 'success');
            return;
        }

        const toggleBtn = e.target.closest('.toggle-password');
        if (toggleBtn) {
            const input = toggleBtn.previousElementSibling;
            const use = toggleBtn.querySelector('use');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            use?.setAttribute('xlink:href', show ? '#icon-eye-close' : '#icon-eye');
            return;
        }

        const optimizeBtn = e.target.closest('#optimize-db-btn');
        if (optimizeBtn) {
            e.preventDefault();
            handleAction(optimizeBtn, 'optimize_db', (data) => {
                if (!data.success) {
                    toast(data.message, 'error');
                    return;
                }
                let msg = data.saved > 0 ? `优化完成！节省 ${data.saved} MB 空间` : '数据库已是最优状态';
                if (data.image_count !== undefined) msg += `，共 ${data.image_count} 张图片`;
                toast(msg, 'success');
            });
            return;
        }

        const updateBtn = e.target.closest('#check-update-btn');
        if (updateBtn) {
            e.preventDefault();
            handleAction(updateBtn, 'check_update', (data) => {
                if (!data.success) {
                    toast(data.message, 'error');
                    return;
                }

                if (data.isDev) {
                    dialog({
                        message: `<div class="title">${data.message}</div><div class="content">稳定版本: V${data.latest}</div><div class="footer">前往 dev 分支查看更新？</div>`,
                        onConfirm: () => window.open(data.url, '_blank'),
                        confirmText: '前往 dev 分支',
                        type: 'warning'
                    });
                } else if (data.hasUpdate) {
                    dialog({
                        message: `<div class="title">${data.message}</div><div class="content">当前版本: V${data.current}</div><div class="footer">是否前往下载？</div>`,
                        onConfirm: () => window.open(data.url, '_blank'),
                        confirmText: '前往下载'
                    });
                } else {
                    toast(`当前版本 v${data.current} 已是最新`, 'success');
                }
            });
            return;
        }

        const testBtn = e.target.closest('.test-storage-btn');
        if (!testBtn) return;

        e.preventDefault();
        const storage = testBtn.dataset.storage;
        if (testBtn.disabled || !isStorageConfigComplete(storage)) {
            toast('请先填写完整的存储配置', 'error');
            return;
        }

        testBtn.disabled = true;
        testBtn.classList.add('testing');

        try {
            const formData = new FormData(settingsModal.querySelector('#settings-form'));
            formData.set('action', 'test_storage');
            formData.set('storage_type', storage);

            const data = await postSettings(formData);
            toast(data.message, data.success ? 'success' : 'error');
            if (!data.success && data.error) console.error('存储连接错误详情:', data.error);
        } catch (error) {
            console.error('存储连接测试失败:', error);
            toast('测试失败，请稍后重试', 'error');
        } finally {
            testBtn.classList.remove('testing');
            updateTestButtons();
        }
    });
}
