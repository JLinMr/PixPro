document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('settings-modal');
    const settingsLink = document.querySelector('.settings-link');

    const initializeSettingsForm = () => {
        const elements = {
            customProtocolInput: document.getElementById('custom-protocol-input'),
            settingsForm: document.getElementById('settings-form')
        };

        const toggleElement = (element, show) => 
            element && (element.style.display = show ? 'block' : 'none');

        const updateProtocolInput = () => {
            const isCustom = document.querySelector('input[name="protocol"]:checked').value === 'custom';
            toggleElement(elements.customProtocolInput, isCustom);
        };

        const updateStorageSettings = () => {
            const selectedStorage = document.querySelector('input[name="storage"]:checked').value;
            document.querySelectorAll('[id$="-settings"]').forEach(panel => {
                panel.style.display = 'none';
            });
            const selectedPanel = document.getElementById(`${selectedStorage}-settings`);
            if (selectedPanel) {
                selectedPanel.style.display = 'block';
            }
        };

        // Token相关功能
        const tokenInput = document.getElementById('token-input');
        
        // 复制Token
        document.querySelector('.copy-token')?.addEventListener('click', () => {
            if (!tokenInput.value) return;
            navigator.clipboard.writeText(tokenInput.value).then(() => {
                UI.showNotification('Token已复制', 'success');
            });
        });

        // 刷新Token
        document.querySelector('.refresh-token')?.addEventListener('click', () => {
            const randomToken = generateRandomToken(32);
            tokenInput.value = randomToken;
            UI.showNotification('Token已刷新', 'success');
        });

        // 生成随机Token
        function generateRandomToken(length) {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let token = '';
            for (let i = 0; i < length; i++) {
                token += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return token;
        }

        updateProtocolInput();
        updateStorageSettings();

        document.querySelectorAll('input[name="protocol"]')
            .forEach(input => input.addEventListener('change', updateProtocolInput));
            
        document.querySelectorAll('input[name="storage"]')
            .forEach(input => input.addEventListener('change', updateStorageSettings));

        elements.settingsForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            // 处理自定义协议
            if (formData.get('protocol') === 'custom') {
                const customProtocol = formData.get('custom_protocol');
                if (customProtocol) {
                    formData.set('protocol', customProtocol);
                }
            }
            
            // 处理文件大小单位转换（MB转字节）
            const maxFileSize = formData.get('max_file_size');
            if (maxFileSize) {
                formData.set('max_file_size', Math.floor(maxFileSize * 1024 * 1024));
            }
            
            try {
                const response = await fetch('settings.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const {message, success} = await response.json();
                UI.showNotification(message, success ? 'success' : 'error');
            } catch (error) {
                console.error('Error saving settings:', error);
                UI.showNotification('保存设置失败', 'error');
            }
        });
    };

    settingsLink.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            const response = await fetch('settings.php', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const content = await response.text();
            modal.querySelector('.modal-content').innerHTML = content;
            modal.style.display = 'block';
            requestAnimationFrame(() => {
                modal.classList.add('show');
            });
            initializeSettingsForm();
            
            modal.querySelector('.close-modal')?.addEventListener('click', () => {
                modal.classList.remove('show');
                modal.classList.add('hide');
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.classList.remove('hide');
                }, 300);
            });
        } catch (error) {
            console.error('Error loading settings:', error);
            UI.showNotification('加载设置失败', 'error');
        }
    });
}); 