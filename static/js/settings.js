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

    const showUpdateModal = (url, title) => {
        const updateModal = document.createElement('div');
        updateModal.className = 'modal';
        updateModal.innerHTML = `
            <div class="settings-container" style="max-width: 700px;">
                <div class="settings-group">
                    <div class="settings-header">
                        <h2>${title}</h2>
                        <button type="button" class="close-modal">
                            <svg class="icon" aria-hidden="true"><use xlink:href="#icon-xmark"></use></svg>
                        </button>
                    </div>
                    <div id="update-content" style="min-height: 150px;">
                        <div style="text-align: center; padding: 40px; color: rgba(255,255,255,0.7);">
                            <div class="spinner" style="margin: 0 auto 15px;"></div>
                            <div>加载中...</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(updateModal);
        updateModal.style.display = 'block';
        requestAnimationFrame(() => updateModal.classList.add('show'));
        
        setupModalCloseHandlers(updateModal, () => {
            updateModal.classList.replace('show', 'hide');
            setTimeout(() => updateModal.remove(), 300);
        });
        
        fetch(url)
            .then(response => response.ok ? response.text() : Promise.reject(new Error('HTTP ' + response.status)))
            .then(html => {
                updateModal.querySelector('#update-content').innerHTML = html;
                
                // 手动执行内联脚本
                const scripts = updateModal.querySelectorAll('#update-content script');
                scripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    newScript.textContent = oldScript.textContent;
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });
            })
            .catch(error => {
                console.error('加载更新页面失败:', error);
                updateModal.querySelector('#update-content').innerHTML = `
                    <div style="padding: 20px; text-align: center; color: #f44336;">
                        <div style="font-size: 48px; margin-bottom: 15px;">✗</div>
                        <div style="font-size: 18px; font-weight: bold;">加载失败</div>
                        <div style="margin-top: 10px; opacity: 0.8;">${error.message}</div>
                    </div>
                `;
            });
    };

    const initializeSettingsForm = () => {
        const settingsForm = document.getElementById('settings-form');
        const tokenInput = document.getElementById('token-input');

        const updateStorageSettings = () => {
            const selectedStorage = document.querySelector('input[name="storage"]:checked').value;
            document.querySelectorAll('[id$="-settings"]').forEach(panel => panel.style.display = 'none');
            document.getElementById(`${selectedStorage}-settings`)?.style.setProperty('display', 'block');
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

        ['check-db-update-btn', 'check-version-update-btn'].forEach((id, index) => {
            document.getElementById(id)?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                showUpdateModal(['update/check.php', 'update/version.php'][index], ['数据库更新', '程序更新'][index]);
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
            requestAnimationFrame(() => modal.classList.add('show'));
            
            initializeSettingsForm();
            setupModalCloseHandlers(modal, () => closeModal(modal));
        } catch (error) {
            console.error('Error loading settings:', error);
            UI.showNotification('加载设置失败', 'error');
        }
    });
});
