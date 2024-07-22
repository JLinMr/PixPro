// 获取页面元素
const elements = {
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
    pasteOrUrlInput: document.getElementById('pasteOrUrlInput'),
    deleteImageButton: document.getElementById('deleteImageButton'),
    imageUploadBox: document.getElementById('imageUploadBox')
};

// 定义最大文件大小和每次上传的最大文件数
const maxFileSize = 5 * 1024 * 1024; // 5MB
const maxFilesPerUpload = 5; // 最多上传5张图片

// 设置事件监听器
function setupEventListeners() {
    elements.qualityInput.addEventListener('input', updateQualityOutput);
    elements.imageInput.addEventListener('change', handleFiles);
    elements.pasteOrUrlInput.addEventListener('paste', handlePaste);
    elements.pasteOrUrlInput.addEventListener('input', handleUrlInput);
    elements.deleteImageButton.addEventListener('click', clearImageInfo);
    elements.imageUploadBox.addEventListener('dragover', handleDragOver);
    elements.imageUploadBox.addEventListener('dragleave', handleDragLeave);
    elements.imageUploadBox.addEventListener('drop', handleDrop);
}

// 更新质量输出
function updateQualityOutput() {
    elements.qualityOutput.textContent = elements.qualityInput.value;
}

// 处理文件输入
function handleFiles() {
    handleFileInput(elements.imageInput.files);
}

// 处理粘贴事件
function handlePaste(event) {
    const items = event.clipboardData.items;
    const files = [];
    for (const item of items) {
        if (item.kind === 'file') {
            files.push(item.getAsFile());
        }
    }
    if (files.length > maxFilesPerUpload) {
        showNotification(`单次最多粘贴 ${maxFilesPerUpload} 张图片`, 'msg-red');
        return;
    }
    for (const file of files) {
        processFile(file);
    }
}

// 处理URL输入
function handleUrlInput() {
    const url = elements.pasteOrUrlInput.value;
    if (url.trim() !== '') {
        if (!isValidUrl(url)) {
            showNotification("请输入有效的图片URL", 'msg-red');
            return;
        }
        loadImageFromUrl(url);
    }
}

// URL格式检查
function isValidUrl(string) {
    try {
        new URL(string);
        return true;
    } catch (_) {
        return false;
    }
}

// 处理文件输入
function handleFileInput(files) {
    if (files.length > 0) {
        if (files.length > maxFilesPerUpload) {
            showNotification(`单次最多上传 ${maxFilesPerUpload} 张图片`, 'msg-red');
            return;
        }
        for (const file of files) {
            processFile(file);
        }
        elements.imageInput.value = '';
    } else {
        clearImageInfo();
    }
}

// 处理文件
function processFile(file) {
    if (file.size > maxFileSize) {
        showNotification(`文件大小超过限制，最大允许 ${maxFileSize / 1024 / 1024}MB`, 'msg-red');
        return;
    }
    previewImage(file);
    elements.originalSize.textContent = (file.size / 1024).toFixed(2);
    const img = new Image();
    img.onload = () => {
        elements.originalWidth.textContent = img.width;
        elements.originalHeight.textContent = img.height;
    };
    img.src = URL.createObjectURL(file);
    uploadImage(file);
}

// 预览图片
function previewImage(file) {
    const reader = new FileReader();
    reader.onload = () => {
        elements.imagePreview.src = reader.result;
        elements.deleteImageButton.style.display = 'block';
    };
    reader.readAsDataURL(file);
}

// 清除图片信息
function clearImageInfo() {
    elements.imagePreview.src = 'static/images/svg/up.svg';
    elements.deleteImageButton.style.display = 'none';
    elements.originalWidth.textContent = '';
    elements.originalHeight.textContent = '';
    elements.originalSize.textContent = '';
    elements.compressedWidth.textContent = '';
    elements.compressedHeight.textContent = '';
    elements.compressedSize.textContent = '';
    const containers = ['imageUrlContainer', 'markdownUrlContainer', 'markdownLinkUrlContainer', 'htmlUrlContainer'];
    containers.forEach(containerId => {
        const container = document.getElementById(containerId);
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    });
    elements.pasteOrUrlInput.value = '';
    showNotification('图片信息清理成功');
}

// 从URL加载图片
function loadImageFromUrl(url) {
    const img = new Image();
    img.crossOrigin = "Anonymous"; // 尝试处理跨域请求
    img.onload = () => {
        elements.imagePreview.src = url;
        elements.originalWidth.textContent = img.width;
        elements.originalHeight.textContent = img.height;
        fetch(url, { mode: 'cors' }).then(response => response.blob()).then(blob => {
            elements.originalSize.textContent = (blob.size / 1024).toFixed(2);
            uploadImage(blob);
            elements.deleteImageButton.style.display = 'block';
        }).catch(error => {
            console.error('Fetch error:', error);
            showNotification("无法加载图片，请检查URL是否正确", 'msg-red');
        });
    };
    img.onerror = () => {
        console.error('Image load error:', url);
        showNotification("无法加载图片，请检查URL是否正确", 'msg-red');
    };
    img.src = url;
}

// 显示通知
function showNotification(message, className = 'msg-green') {
    const notification = document.createElement('div');
    notification.className = `msg ${className}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('msg-right');
        setTimeout(() => notification.remove(), 800);
    }, 1500);
}

// 上传图片
function uploadImage(file) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('quality', elements.qualityInput.value);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.upload.addEventListener('progress', updateProgressBar);
    xhr.onreadystatechange = () => xhr.readyState === XMLHttpRequest.DONE && handleUploadResponse(xhr);
    xhr.send(formData);
}

// 更新进度条
function updateProgressBar(event) {
    if (event.lengthComputable) {
        const percentComplete = (event.loaded / event.total) * 100;
        elements.progressBar.style.width = percentComplete + '%';
        elements.progressBar.textContent = percentComplete.toFixed(0) + '%';
        elements.progressContainer.style.display = 'block';
    }
}

// 处理拖拽悬停
function handleDragOver(event) {
    event.preventDefault();
    elements.imageUploadBox.style.border = '2px dashed blue';
}

// 处理拖拽离开
function handleDragLeave() {
    elements.imageUploadBox.style.border = '2px dashed #ccc';
}

// 处理拖拽释放
function handleDrop(event) {
    event.preventDefault();
    elements.imageUploadBox.style.border = '2px dashed #ccc';
    handleFileInput(event.dataTransfer.files);
}

// 处理上传响应
function handleUploadResponse(xhr) {
    if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.url) {
            const imageName = response.url.split('/').pop().split('?')[0];
            if (response.width && response.height && response.size) {
                elements.compressedWidth.textContent = response.width;
                elements.compressedHeight.textContent = response.height;
                elements.compressedSize.textContent = (response.size / 1024).toFixed(2);
                const containers = [
                    { id: 'imageUrlContainer', value: response.url },
                    { id: 'markdownUrlContainer', value: `![${imageName}](${response.url})` },
                    { id: 'markdownLinkUrlContainer', value: `[![${imageName}](${response.url})](${response.url})` },
                    { id: 'htmlUrlContainer', value: `<img src="${response.url}" alt="${imageName}">` }
                ];
                containers.forEach(({ id, value }) => {
                    const input = createInput(value, copyToClipboard);
                    document.getElementById(id).appendChild(input);
                });
            } else {
                showNotification("缺少压缩图片的尺寸或大小信息", 'msg-red');
            }
        } else if (response.error) {
            showNotification(response.error, 'msg-red');
        }
    } else {
        showNotification('上传失败，请重试', 'msg-red');
    }
    setTimeout(() => {
        elements.progressContainer.style.display = 'none';
        elements.progressBar.style.width = '0%';
        elements.progressBar.textContent = '';
    }, 300);
}

// 创建输入框
function createInput(value, clickHandler) {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'copy-indicator blur';
    input.value = value;
    input.readOnly = true;
    input.addEventListener('click', clickHandler);
    return input;
}

// 复制到剪贴板
async function copyToClipboard(event) {
    const text = event.target.value;
    try {
        await navigator.clipboard.writeText(text);
        showNotification('已复制到剪贴板');
    } catch (err) {
        console.error('复制到剪贴板失败: ', err);
        try {
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('备用方法复制到剪贴板失败: ', err);
            showNotification('复制到剪贴板失败', 'msg-red');
        }
    }
}

// 设置标签页切换
document.querySelectorAll('.tab-button').forEach(button => {
    button.addEventListener('click', () => {
        const target = button.getAttribute('data-target');
        document.querySelectorAll('.tab-pane, .tab-button').forEach(el => el.classList.remove('active'));
        document.getElementById(target).classList.add('active');
        button.classList.add('active');
    });
});

// 初始化事件监听器
setupEventListeners();