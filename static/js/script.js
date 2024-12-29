// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    initialize();
});

// 缓存DOM元素
const DOM = {
    imageInput: document.getElementById('imageInput'),
    imagePreview: document.getElementById('imagePreview'),
    qualityInput: document.getElementById('qualityInput'),
    qualityOutput: document.getElementById('qualityOutput'),
    progressBar: document.getElementById('progressBar'),
    progressContainer: document.getElementById('progressContainer'),
    imageUrl: document.getElementById('imageUrl'),
    originalWidth: document.getElementById('originalWidth'),
    originalHeight: document.getElementById('originalHeight'),
    originalSize: document.getElementById('originalSize'),
    compressedWidth: document.getElementById('compressedWidth'),
    compressedHeight: document.getElementById('compressedHeight'),
    compressedSize: document.getElementById('compressedSize'),
    deleteImageButton: document.getElementById('deleteImageButton'),
    imageUploadBox: document.getElementById('imageUploadBox')
};

// 配置常量
const CONFIG = {
    allowedTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']
};

// 初始化所有必要的组件和功能
function initialize() {
    setupEventListeners();
    loadSavedQuality();
    setupTabSwitching();
}

// API工具类
const API = {
    // 上传图片到服务器
    async uploadImage(file, quality) {
        const formData = new FormData();
        formData.append('image', file);
        formData.append('quality', quality);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api.php', true);
        xhr.upload.addEventListener('progress', UI.updateProgressBar);
        xhr.onreadystatechange = () => {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                UI.handleUploadResponse(xhr);
            }
        };
        xhr.send(formData);
    }
};

// UI工具类
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

    // 更新进度条
    updateProgressBar(event) {
        if (event.lengthComputable) {
            const percentComplete = (event.loaded / event.total) * 100;
            DOM.progressBar.style.width = percentComplete + '%';
            DOM.progressBar.textContent = percentComplete.toFixed(0) + '%';
            DOM.progressContainer.style.display = 'block';
        }
    },

    // 处理上传响应
    handleUploadResponse(xhr) {
        if (xhr.status === 200) {
            const response = JSON.parse(xhr.responseText);
            
            if (response.message) {
                this.showNotification(response.message, 'error');
                return;
            }
            
            if (response.url) {
                this.updateImageInfo(response);
            } else if (response.error) {
                this.showNotification(response.error, 'error');
            }
        } else if (xhr.status === 403) {
            this.showNotification('你的域名未授权', 'error');
        } else {
            this.showNotification('上传失败，请重试', 'error');
        }

        setTimeout(() => {
            DOM.progressContainer.style.display = 'none';
            DOM.progressBar.style.width = '0%';
            DOM.progressBar.textContent = '';
        }, 300);
    },

    // 更新图片信息
    updateImageInfo(response) {
        const imageName = response.url.split('/').pop().split('?')[0];
        
        // 更新压缩后的尺寸信息
        if (response.width && response.width > 0) {
            DOM.compressedWidth.textContent = response.width;
        } else {
            DOM.compressedWidth.textContent = '未知';
        }
        
        if (response.height && response.height > 0) {
            DOM.compressedHeight.textContent = response.height;
        } else {
            DOM.compressedHeight.textContent = '未知';
        }
        
        if (response.size && response.size > 0) {
            DOM.compressedSize.textContent = (response.size / 1024).toFixed(2);
        } else {
            DOM.compressedSize.textContent = '未知';
        }
        
        // 创建URL容器
        const containers = [
            { id: 'imageUrlContainer', value: response.url },
            { id: 'markdownUrlContainer', value: `![${imageName}](${response.url})` },
            { id: 'markdownLinkUrlContainer', value: `[![${imageName}](${response.url})](${response.url})` },
            { id: 'htmlUrlContainer', value: `<img src="${response.url}" alt="${imageName}">` }
        ];
        
        // 直接添加新的URL容器
        containers.forEach(({ id, value }) => {
            const container = document.getElementById(id);
            const input = this.createInput(value);
            container.appendChild(input);
        });
    },

    // 创建输入框
    createInput(value) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'copy-indicator blur';
        input.value = value;
        input.readOnly = true;
        input.addEventListener('click', Clipboard.copyToClipboard);
        return input;
    },

    // 清除图片信息
    clearImageInfo() {
        DOM.imagePreview.src = 'static/images/svg/up.svg';
        DOM.deleteImageButton.style.display = 'none';
        DOM.originalWidth.textContent = '';
        DOM.originalHeight.textContent = '';
        DOM.originalSize.textContent = '';
        DOM.compressedWidth.textContent = '';
        DOM.compressedHeight.textContent = '';
        DOM.compressedSize.textContent = '';
        
        // 清理进度条
        DOM.progressContainer.style.display = 'none';
        DOM.progressBar.style.width = '0%';
        DOM.progressBar.textContent = '';
        
        const containers = ['imageUrlContainer', 'markdownUrlContainer', 'markdownLinkUrlContainer', 'htmlUrlContainer'];
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }
        });
        
        this.showNotification('图片信息清理成功');
    }
};

// 剪贴板工具类
const Clipboard = {
    async copyToClipboard(event) {
        const text = event.target.value;
        try {
            await navigator.clipboard.writeText(text);
            UI.showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('复制到剪贴板失败: ', err);
            Clipboard.fallbackCopy(text);
        }
    },

    fallbackCopy(text) {
        try {
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            UI.showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('备用方法复制到剪贴板失败: ', err);
            UI.showNotification('复制到剪贴板失败', 'error');
        }
    }
};

// 图片处理工具类
const ImageHandler = {
    processFile(file) {
        if (!CONFIG.allowedTypes.includes(file.type)) {
            UI.showNotification(`不支持的文件类型`, 'error');
            return;
        }

        this.previewImage(file);
        DOM.originalSize.textContent = (file.size / 1024).toFixed(2);
        
        const img = new Image();
        img.onload = () => {
            DOM.originalWidth.textContent = img.width;
            DOM.originalHeight.textContent = img.height;
        };
        img.src = URL.createObjectURL(file);
        
        API.uploadImage(file, DOM.qualityInput.value);
    },

    previewImage(file) {
        const reader = new FileReader();
        reader.onload = () => {
            DOM.imagePreview.src = reader.result;
            DOM.deleteImageButton.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
};

// 事件处理器设置
function setupEventListeners() {
    // 质量控制相关
    DOM.qualityInput.addEventListener('input', () => {
        DOM.qualityOutput.textContent = DOM.qualityInput.value;
        localStorage.setItem('imageQuality', DOM.qualityInput.value);
    });

    // 文件上传功能
    DOM.imageInput.addEventListener('change', () => {
        if (DOM.imageInput.files.length > 0) {
            Array.from(DOM.imageInput.files).forEach(file => ImageHandler.processFile(file));
            DOM.imageInput.value = '';
        } else {
            UI.clearImageInfo();
        }
    });

    // 粘贴上传功能
    document.addEventListener('paste', event => {
        const items = event.clipboardData.items;
        Array.from(items)
            .filter(item => item.kind === 'file')
            .forEach(item => ImageHandler.processFile(item.getAsFile()));
    });

    // 阻止表单默认提交
    document.getElementById('uploadForm').addEventListener('submit', event => {
        event.preventDefault();
    });

    // 删除按钮
    DOM.deleteImageButton.addEventListener('click', (event) => {
        event.preventDefault();  // 阻止按钮默认行为
        UI.clearImageInfo();
    });

    // 拖放上传功能
    DOM.imageUploadBox.addEventListener('dragover', event => {
        event.preventDefault();
        DOM.imageUploadBox.style.border = '2px dashed blue';
    });

    DOM.imageUploadBox.addEventListener('dragleave', () => {
        DOM.imageUploadBox.style.border = '2px dashed #ccc';
    });

    DOM.imageUploadBox.addEventListener('drop', event => {
        event.preventDefault();
        DOM.imageUploadBox.style.border = '2px dashed #ccc';
        Array.from(event.dataTransfer.files).forEach(file => ImageHandler.processFile(file));
    });
}

// 标签页切换设置
function setupTabSwitching() {
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-target');
            document.querySelectorAll('.tab-pane, .tab-button').forEach(el => 
                el.classList.remove('active'));
            document.getElementById(target).classList.add('active');
            button.classList.add('active');
        });
    });
}

// 加载保存的压缩率设置
function loadSavedQuality() {
    const savedQuality = localStorage.getItem('imageQuality');
    if (savedQuality) {
        DOM.qualityInput.value = savedQuality;
        DOM.qualityOutput.textContent = savedQuality;
    }
}