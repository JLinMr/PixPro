document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('settings-modal');
    const settingsLink = document.querySelector('.settings-link');

    const generateRandomToken = (length) => 
        Array.from({length}, () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'[Math.floor(Math.random() * 62)]).join('');

    const closeModal = (modalElement) => {
        modalElement.classList.replace('show', 'hide');
        setTimeout(() => {
            modalElement.style.display = 'none';
            modalElement.classList.remove('hide');
        }, 300);
    };

    const setupModalCloseHandlers = (modalElement, closeHandler) => {
        modalElement.querySelector('.close-modal')?.addEventListener('click', closeHandler);
        modalElement.addEventListener('click', (e) => {
            const container = modalElement.querySelector('.settings-container');
            if (container && !container.contains(e.target)) closeHandler();
        });
    };

    const handleAction = async (btn, action, onSuccess) => {
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = action === 'optimize_db' ? '优化中...' : '检测中...';
        
        try {
            const formData = new FormData();
            formData.append('action', action);
            
            const response = await fetch('settings.php', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            });
            
            const data = await response.json();
            onSuccess(data);
        } catch (error) {
            console.error('操作失败:', error);
            UI.showNotification('操作失败', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    };

    const initializeSettingsForm = () => {
        const settingsForm = document.getElementById('settings-form');
        const tokenInput = document.getElementById('token-input');

        const updateStorageSettings = () => {
            const selectedStorage = document.querySelector('input[name="storage"]:checked');
            if (!selectedStorage) return;
            
            document.querySelectorAll('[id$="-settings"]').forEach(panel => panel.style.display = 'none');
            document.getElementById(`${selectedStorage.value}-settings`)?.style.setProperty('display', 'block');
        };

        document.querySelector('.copy-token')?.addEventListener('click', () => {
            if (tokenInput.value) {
                navigator.clipboard.writeText(tokenInput.value).then(() => UI.showNotification('Token已复制', 'success'));
            }
        });

        document.querySelector('.refresh-token')?.addEventListener('click', () => {
            tokenInput.value = generateRandomToken(32);
            UI.showNotification('Token已刷新', 'success');
        });

        // 密码显示/隐藏
        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling;
                if (input.type === 'password') {
                    input.type = 'text';
                    btn.querySelector('use').setAttribute('xlink:href', '#icon-eye-close');
                } else {
                    input.type = 'password';
                    btn.querySelector('use').setAttribute('xlink:href', '#icon-eye');
                }
            });
        });

        updateStorageSettings();
        document.querySelectorAll('input[name="storage"]').forEach(input => 
            input.addEventListener('change', updateStorageSettings)
        );

        settingsForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            const maxFileSize = formData.get('max_file_size');
            if (maxFileSize) formData.set('max_file_size', Math.floor(maxFileSize * 1024 * 1024));
            
            try {
                const response = await fetch('settings.php', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                });
                
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                
                const {message, success} = await response.json();
                UI.showNotification(message, success ? 'success' : 'error');
            } catch (error) {
                console.error('Error saving settings:', error);
                UI.showNotification('保存设置失败', 'error');
            }
        });

        document.getElementById('optimize-db-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            handleAction(e.target, 'optimize_db', (data) => {
                if (data.success) {
                    const msg = data.saved > 0 ? `优化完成！节省 ${data.saved} MB 空间` : '数据库已是最优状态';
                    UI.showNotification(msg, 'success');
                } else {
                    UI.showNotification(data.message, 'error');
                }
            });
        });
        
        document.getElementById('check-update-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            handleAction(e.target, 'check_update', (data) => {
                if (data.success) {
                    if (data.isDev) {
                        // 测试版本提示
                        const message = `<div class="title">${data.message}</div><div class="content">稳定版本: V${data.latest}</div><div class="footer">前往 dev 分支查看更新？</div>`;
                        UI.createConfirmDialog(message, () => window.open(data.url, '_blank'), {
                            confirmText: '前往 dev 分支',
                            type: 'warning'
                        });
                    } else if (data.hasUpdate) {
                        // 有新版本
                        const message = `<div class="title">${data.message}</div><div class="content">当前版本: V${data.current}</div><div class="footer">是否前往下载？</div>`;
                        UI.createConfirmDialog(message, () => window.open(data.url, '_blank'), {
                            confirmText: '前往下载',
                        });
                    } else {
                        // 已是最新版本
                        UI.showNotification(`当前版本 v${data.current} 已是最新`, 'success');
                    }
                } else {
                    UI.showNotification(data.message, 'error');
                }
            });
        });

        // 存储测试按钮
        document.querySelectorAll('.test-storage-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                btn.classList.add('testing');
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'test_storage');
                    formData.append('storage_type', btn.dataset.storage);
                    
                    const response = await fetch('settings.php', {
                        method: 'POST',
                        headers: {'X-Requested-With': 'XMLHttpRequest'},
                        body: formData
                    });
                    
                    const data = await response.json();
                    UI.showNotification(data.message, data.success ? 'success' : 'error');
                    
                    if (!data.success && data.error) {
                        console.error('存储连接错误详情:', data.error);
                    }
                } finally {
                    btn.disabled = false;
                    btn.classList.remove('testing');
                }
            });
        });
    };

    settingsLink.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            const response = await fetch('settings.php', {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            modal.querySelector('.modal-content').innerHTML = await response.text();
            modal.style.display = 'block';
            void modal.offsetHeight;
            modal.classList.add('show');
            
            initializeSettingsForm();
            setupModalCloseHandlers(modal, () => closeModal(modal));
        } catch (error) {
            console.error('Error loading settings:', error);
            UI.showNotification('加载设置失败', 'error');
        }
    });
});
