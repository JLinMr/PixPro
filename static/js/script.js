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

const token = '1c17b11693cb5ec63859b091c5b9c1b2';  // 验证令牌，需要与配置文件相同
const maxFileSize = 5 * 1024 * 1024; // 文件大小限制 5MB
const maxFilesPerUpload = 5; // 一次最多上传5张图片

// 设置事件监听器
function setupEventListeners() {
    elements.qualityInput.addEventListener('input', updateQualityOutput);
    elements.imageInput.addEventListener('change', handleFiles);
    elements.pasteOrUrlInput.addEventListener('paste', handlePaste);
    elements.pasteOrUrlInput.addEventListener('input', handleUrlInput);
    elements.deleteImageButton.addEventListener('click', handleDeleteImage);
    elements.imageUploadBox.addEventListener('dragover', handleDragOver);
    elements.imageUploadBox.addEventListener('dragleave', handleDragLeave);
    elements.imageUploadBox.addEventListener('drop', handleDrop);
}

// 更新图片质量输出显示
function updateQualityOutput() {
    elements.qualityOutput.textContent = elements.qualityInput.value;
}

// 处理文件输入的变化
function handleFiles() {
    const files = elements.imageInput.files;
    if (files.length > 0) {
        if (files.length > maxFilesPerUpload) {
            showNotification(`一次最多上传 ${maxFilesPerUpload} 张图片`, 'red-success');
            clearPreview();
            return;
        }
        for (const file of files) {
            if (file.size > maxFileSize) {
                showNotification(`文件大小超过限制，最大允许 ${maxFileSize / 1024 / 1024}MB`, 'red-success');
                clearPreview();
            } else {
                processFile(file);
            }
        }
        elements.imageInput.value = '';
    } else {
        clearPreview();
    }
}

// 处理粘贴事件
function handlePaste(event) {
    const items = event.clipboardData.items;
    for (const item of items) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            processFile(file);
        }
    }
}

// 处理URL输入的变化
function handleUrlInput() {
    const url = elements.pasteOrUrlInput.value;
    if (url) loadImageFromUrl(url);
}

// 处理文件，预览并上传
function processFile(file) {
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
        elements.imagePreview.style.display = 'block';
        elements.deleteImageButton.style.display = 'block';
    };
    reader.readAsDataURL(file);
}

// 清除预览
function clearPreview() {
    elements.imagePreview.src = '';
    elements.imagePreview.style.display = 'none';
}

// 从URL加载图片
function loadImageFromUrl(url) {
    const img = new Image();
    img.crossOrigin = "Anonymous";
    img.onload = () => {
        elements.imagePreview.src = url;
        elements.imagePreview.style.display = 'block';
        elements.originalWidth.textContent = img.width;
        elements.originalHeight.textContent = img.height;
        fetch(url, { mode: 'cors' }).then(response => response.blob()).then(blob => {
            elements.originalSize.textContent = (blob.size / 1024).toFixed(2);
            uploadImage(blob);
        });
    };
    img.onerror = () => showNotification("无法加载图片，请检查URL是否正确", 'red-success');
    img.src = url;
}

// 显示通知
function showNotification(message, className = 'green-success') {
    const existingNotification = document.querySelector('.green-success, .red-success');
    if (existingNotification) {
        existingNotification.parentNode.removeChild(existingNotification);
    }
    const notification = document.createElement('div');
    notification.classList.add(className);
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('success-right');
        setTimeout(() => notification.parentNode.removeChild(notification), 1000);
    }, 1500);
}

// 上传图片
function uploadImage(file) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('quality', elements.qualityInput.value);
    formData.append('token', token);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.upload.addEventListener('progress', updateProgressBar);
    xhr.onreadystatechange = () => xhr.readyState === XMLHttpRequest.DONE && handleUploadResponse(xhr);
    xhr.send(formData);
}

// 更新上传进度条
function updateProgressBar(event) {
    if (event.lengthComputable) {
        const percentComplete = (event.loaded / event.total) * 100;
        elements.progressBar.style.width = percentComplete + '%';
        elements.progressBar.textContent = percentComplete.toFixed(0) + '%';
        elements.progressContainer.style.display = 'block';
    }
}

// 处理拖拽进入事件
function handleDragOver(event) {
    event.preventDefault();
    elements.imageUploadBox.style.border = '2px dashed blue';
}

// 处理拖拽离开事件
function handleDragLeave() {
    elements.imageUploadBox.style.border = '2px dashed #ccc';
}

// 处理拖拽释放事件
function handleDrop(event) {
    event.preventDefault();
    elements.imageUploadBox.style.border = '2px dashed #ccc';
    const files = event.dataTransfer.files;
    if (files.length > maxFilesPerUpload) {
        showNotification(`一次最多上传 ${maxFilesPerUpload} 张图片`, 'red-success');
        return;
    }
    for (const file of files) {
        if (file.type.startsWith('image/')) {
            processFile(file);
        }
    }
}

// 删除图片按钮事件监听
function handleDeleteImage() {
    clearImageInfo();
}

// 清理图片信息
function clearImageInfo() {
    elements.imagePreview.src = 'static/svg/up.svg';
    elements.deleteImageButton.style.display = 'none';
    const inputsToClear = document.querySelectorAll('#urlOutput input');
    inputsToClear.forEach(input => input.value = '');

    // 清理 imageInfo 的内容
    elements.originalWidth.textContent = '';
    elements.originalHeight.textContent = '';
    elements.originalSize.textContent = '';
    elements.compressedWidth.textContent = '';
    elements.compressedHeight.textContent = '';
    elements.compressedSize.textContent = '';

    // 清理动态生成的 input 框
    const containers = ['imageUrlContainer', 'markdownUrlContainer', 'markdownLinkUrlContainer', 'htmlUrlContainer'];
    containers.forEach(containerId => {
        const container = document.getElementById(containerId);
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    });
    showNotification('图片信息清理成功', 'green-success');
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
                const imageUrlInput = createInput(response.url, copyToClipboard);
                document.getElementById('imageUrlContainer').appendChild(imageUrlInput);
                const markdownUrlInput = createInput(`![${imageName}](${response.url})`, copyToClipboard);
                document.getElementById('markdownUrlContainer').appendChild(markdownUrlInput);
                const markdownLinkUrlInput = createInput(`[![${imageName}](${response.url})](${response.url})`, copyToClipboard);
                document.getElementById('markdownLinkUrlContainer').appendChild(markdownLinkUrlInput);
                const htmlUrlInput = createInput(`<img src="${response.url}" alt="${imageName}">`, copyToClipboard);
                document.getElementById('htmlUrlContainer').appendChild(htmlUrlInput);
            } else {
                showNotification("缺少压缩图片的尺寸或大小信息", 'red-success');
            }
        } else if (response.error) {
            showNotification(response.error, 'red-success');
        }
    } else {
        showNotification('上传失败，请重试', 'red-success');
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
    input.className = 'copy-indicator';
    input.value = value;
    input.readOnly = true;
    input.setAttribute('aria-label', '点击复制到剪贴板');
    input.addEventListener('click', clickHandler);
    return input;
}

// 复制到剪贴板
async function copyToClipboard(event) {
    const text = event.target.value;
    try {
        await navigator.clipboard.writeText(text);
        showNotification('已复制到剪贴板', 'green-success');
    } catch (err) {
        console.error('复制到剪贴板失败: ', err);
        try {
            // 失败后使用
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showNotification('已复制到剪贴板', 'green-success');
        } catch (err) {
            console.error('备用方法复制到剪贴板失败: ', err);
            showNotification('复制到剪贴板失败', 'red-success');
        }
    }
}

/*tab切换*/
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