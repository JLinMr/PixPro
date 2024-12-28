document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('settings-modal');
    const settingsLink = document.querySelector('.settings-link');

    const initializeSettingsForm = () => {
        const elements = {
            ossSettings: document.getElementById('oss-settings'),
            s3Settings: document.getElementById('s3-settings'),
            upyunSettings: document.getElementById('upyun-settings'),
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
            toggleElement(elements.ossSettings, selectedStorage === 'oss');
            toggleElement(elements.s3Settings, selectedStorage === 's3');
            toggleElement(elements.upyunSettings, selectedStorage === 'upyun');
        };

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
            
            const response = await fetch('settings.php', {
                method: 'POST',
                body: formData
            });
            const {message, success} = await response.json();
            UI.showNotification(message, success ? 'success' : 'error');
        });
    };

    settingsLink.addEventListener('click', async (e) => {
        e.preventDefault();
        const response = await fetch('settings.php');
        modal.innerHTML = await response.text();
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
    });
}); 