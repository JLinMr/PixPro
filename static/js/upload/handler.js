// 图片处理工具类
import { Thumbnails, API, UI, Clipboard } from './utils.js';

export class ImageHandler {
    constructor(config, dom, previewState) {
        this.config = config;
        this.dom = dom;
        this.previewState = previewState;
        this.objectURLs = new Set();
        this.uploadQueue = [];
        this.urlInputTimeout = null;
        this.readers = new Set(); // 跟踪 FileReader
        this.xhrRequests = new Set(); // 跟踪 XHR 请求
    }

    // 设置所有事件监听器
    setupEventListeners() {
        // 质量控制
        this.dom.qualityInput.addEventListener('input', () => {
            this.dom.qualityOutput.textContent = this.dom.qualityInput.value;
            localStorage.setItem('imageQuality', this.dom.qualityInput.value);
        });

        // 文件上传
        this.dom.imageInput.addEventListener('change', () => {
            if (this.dom.imageInput.files.length > 0) {
                this.processFiles(this.dom.imageInput.files);
                this.dom.imageInput.value = '';
            } else {
                this.clearImageInfo();
            }
        });

        // 粘贴上传
        document.addEventListener('paste', event => {
            const files = Array.from(event.clipboardData?.items || [])
                .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
                .map(item => item.getAsFile())
                .filter(file => file !== null);
            
            if (files.length > 0) this.processFiles(files);
        });

        // URL输入自动检测
        this.dom.pasteOrUrlInput.addEventListener('input', (event) => {
            clearTimeout(this.urlInputTimeout);
            const urlInput = event.target.value.trim();
            if (!urlInput) return;
            
            this.urlInputTimeout = setTimeout(() => {
                try {
                    const url = new URL(urlInput);
                    if (url.protocol === 'http:' || url.protocol === 'https:') {
                        this.processUrls(urlInput);
                    }
                } catch (e) {}
            }, 500);
        });

        // 阻止表单提交
        document.getElementById('uploadForm').addEventListener('submit', e => e.preventDefault());

        // 删除按钮
        this.dom.deleteImageButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.clearImageInfo();
        });

        // 复制按钮和链接
        document.querySelectorAll('.copy-tab-btn, .copy-link-display').forEach(element => {
            element.addEventListener('click', (e) => {
                e.stopPropagation();
                
                const type = element.getAttribute('data-type');
                if (e.ctrlKey || e.metaKey) {
                    Clipboard.copyAllUrls(
                        this.previewState.getAllUploadedUrls(),
                        type,
                        (msg) => UI.showNotification(msg),
                        (msg) => UI.showNotification(msg, 'error')
                    );
                } else {
                    Clipboard.copyImageUrl(
                        this.previewState.getCurrentUrl(),
                        type,
                        (msg) => UI.showNotification(msg),
                        (msg) => UI.showNotification(msg, 'error')
                    );
                }
            });
        });

        // 拖放上传
        this.dom.imageUploadBox.addEventListener('dragover', e => {
            e.preventDefault();
            this.dom.imageUploadBox.classList.add('dragover');
        });

        this.dom.imageUploadBox.addEventListener('dragleave', () => {
            this.dom.imageUploadBox.classList.remove('dragover');
        });

        this.dom.imageUploadBox.addEventListener('drop', e => {
            e.preventDefault();
            this.dom.imageUploadBox.classList.remove('dragover');
            
            const files = Array.from(e.dataTransfer?.files || [])
                .filter(file => file.type.startsWith('image/'));
            if (files.length > 0) this.processFiles(files);
        });
        
        // 页面卸载时清理
        window.addEventListener('beforeunload', () => this.cleanup());
    }

    clearImageInfo() {
        UI.clearImageInfo(this.dom);
        UI.updateLinkDisplays(null);
        this.cleanup();
        UI.showNotification('图片信息清理成功');
    }

    processFiles(files) {
        const validFiles = Array.from(files).filter(file => this.validateFile(file));
        if (validFiles.length === 0) return;
        
        this.resetState();
        
        validFiles.forEach((file, index) => {
            this.previewState.addImage(file);
            this.uploadQueue.push({ file, index });
        });
        
        this.loadImagePreview(0);
        
        if ('requestIdleCallback' in window) {
            requestIdleCallback(() => {
                this.createThumbnails();
                this.uploadAllImages();
            }, { timeout: 500 });
        } else {
            setTimeout(() => {
                this.createThumbnails();
                this.uploadAllImages();
            }, 50);
        }
    }

    async processUrls(urlString) {
        const url = urlString.trim();
        if (!url) return UI.showNotification('请输入有效的图片链接', 'error');

        try {
            new URL(url);
        } catch {
            return UI.showNotification('无效的URL格式', 'error');
        }

        this.resetState();
        UI.updateProgressBar(this.dom.progressBar, this.dom.progressContainer, 'downloading');
        
        try {
            const file = await this.urlToFile(url);
            
            if (file && this.validateFile(file)) {
                this.previewState.addImage(file);
                this.uploadQueue.push({ file, index: 0 });
                this.loadImagePreview(0);
                this.showPreview(0);
                
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(() => {
                        this.createThumbnails();
                        this.uploadAllImages();
                    }, { timeout: 500 });
                } else {
                    setTimeout(() => {
                        this.createThumbnails();
                        this.uploadAllImages();
                    }, 50);
                }
                
                this.dom.pasteOrUrlInput.value = '';
            } else {
                UI.hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
                UI.showNotification('图片验证失败', 'error');
            }
        } catch (error) {
            UI.hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
            const errorMsg = error.message || '下载失败';
            if (errorMsg.includes('CORS') || errorMsg.includes('Failed to fetch')) {
                UI.showNotification('下载失败：目标网站可能设置了防盗链或跨域限制', 'error');
            } else if (errorMsg.includes('HTTP error')) {
                UI.showNotification(`下载失败：${errorMsg}`, 'error');
            } else {
                UI.showNotification('下载失败，请检查链接是否正确或尝试其他图片', 'error');
            }
            console.error('URL下载错误:', error);
        }
    }

    resetState() {
        this.previewState.clear();
        this.uploadQueue = [];
        UI.uploadedCount = 0;
    }

    createThumbnails() {
        Thumbnails.create(this.previewState.images, this.previewState.currentIndex, (index) => {
            if (this.previewState.goTo(index)) {
                this.showPreview(index);
                Thumbnails.updateActive(index);
            }
        });
    }

    async urlToFile(url) {
        try {
            let response;
            try {
                response = await fetch(url, {
                    mode: 'cors',
                    credentials: 'omit',
                    referrerPolicy: 'no-referrer'
                });
            } catch (corsError) {
                console.warn('CORS 请求失败:', corsError);
                throw new Error('CORS: 目标网站不允许跨域访问，可能设置了防盗链');
            }
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status} - ${response.statusText}`);
            }
            
            const blob = await response.blob();
            
            // 验证是否为有效的图片
            if (!blob.type.startsWith('image/')) {
                throw new Error('下载的内容不是有效的图片格式');
            }
            
            const urlPath = new URL(url).pathname;
            const filename = urlPath.substring(urlPath.lastIndexOf('/') + 1) || 'image.jpg';
            
            return new File([blob], filename, { type: blob.type });
        } catch (error) {
            console.error('URL转文件失败:', error);
            throw error;
        }
    }

    validateFile(file) {
        if (!this.config.allowedTypes.includes(file.type)) {
            UI.showNotification('不支持的文件类型，请上传图片文件', 'error');
            return false;
        }

        if (this.config.maxFileSize > 0 && file.size > this.config.maxFileSize) {
            const maxMB = Math.floor(this.config.maxFileSize / (1024 * 1024));
            UI.showNotification(`文件 ${file.name} 大小超过限制，最大允许 ${maxMB}MB`, 'error');
            return false;
        }

        return true;
    }

    loadImagePreview(index) {
        const imgData = this.previewState.images[index];
        if (!imgData || imgData.preview) return;
        
        const reader = new FileReader();
        this.readers.add(reader);
        
        reader.onload = (e) => {
            this.readers.delete(reader);
            this.previewState.setPreview(index, e.target.result);
            if (index === this.previewState.currentIndex) {
                this.showPreview(index);
            }
        };
        
        reader.onerror = () => {
            this.readers.delete(reader);
        };
        
        reader.readAsDataURL(imgData.file);
    }

    showPreview(index) {
        const imgData = this.previewState.images[index];
        if (!imgData) return;
        
        if (!imgData.preview) {
            this.loadImagePreview(index);
            return;
        }

        if (this.dom.imagePreview.src && this.dom.imagePreview.src !== imgData.preview) {
            this.dom.imagePreview.src = '';
        }
        
        this.dom.imagePreview.src = imgData.preview;
        this.dom.imagePreviewContainer.classList.add('active');
        this.dom.deleteImageButton.style.display = 'flex';
        UI.updateCopyButtonsState(true);
        Thumbnails.updateActive(index);
        
        const hasMultiple = this.previewState.images.length > 1;
        this.dom.imageCounter.textContent = hasMultiple ? `${index + 1} / ${this.previewState.images.length}` : '';
        this.dom.prevButton.style.display = hasMultiple ? 'flex' : 'none';
        this.dom.nextButton.style.display = hasMultiple ? 'flex' : 'none';

        this.displayImageInfo(imgData.file);
        
        const status = this.previewState.getUploadStatus(index);
        if (status === 'completed') {
            const urlData = this.previewState.getUploadedUrl(index);
            if (urlData) {
                UI.updateCompressedInfo(this.dom, urlData);
                UI.updateLinkDisplays(urlData);
            }
        } else {
            UI.updateLinkDisplays(null);
        }
        
        if ('requestIdleCallback' in window) {
            requestIdleCallback(() => this.preloadAdjacentImages(index), { timeout: 2000 });
        } else {
            setTimeout(() => this.preloadAdjacentImages(index), 100);
        }
    }

    preloadAdjacentImages(index) {
        [index + 1, index - 1].forEach(i => {
            if (i >= 0 && i < this.previewState.images.length) this.loadImagePreview(i);
        });
    }

    displayImageInfo(file) {
        this.dom.originalSize.textContent = `${(file.size / 1024).toFixed(2)} KB`;
        
        const objectURL = URL.createObjectURL(file);
        this.objectURLs.add(objectURL);
        
        const img = new Image();
        const cleanup = () => {
            URL.revokeObjectURL(objectURL);
            this.objectURLs.delete(objectURL);
        };
        
        img.onload = () => {
            this.dom.originalWidth.textContent = `${img.width} × ${img.height}`;
            this.dom.originalHeight.textContent = '';
            cleanup();
        };
        img.onerror = cleanup;
        img.src = objectURL;
    }

    uploadAllImages() {
        this.uploadQueue.forEach(({ file, index }) => {
            const xhr = API.uploadImage(
                file,
                this.dom.qualityInput.value,
                index,
                (e, idx) => this.handleUploadProgress(e, idx),
                (xhr, idx) => {
                    this.xhrRequests.delete(xhr);
                    this.handleUploadResponse(xhr, idx);
                }
            );
            this.xhrRequests.add(xhr);
        });
    }

    handleUploadProgress(event, imageIndex) {
        this.previewState.setUploadStatus(imageIndex, 'uploading');
        UI.updateProgressBar(
            this.dom.progressBar,
            this.dom.progressContainer,
            event,
            imageIndex,
            this.previewState.images.length
        );
    }

    handleUploadResponse(xhr, imageIndex) {
        const response = JSON.parse(xhr.responseText);
        
        if (xhr.status !== 200 || response.message || response.error) {
            return this.handleUploadError(response.error || response.message || '上传失败', imageIndex);
        }
        
        if (response.data?.url) {
            UI.uploadedCount++;
            this.previewState.setUploadedUrl(imageIndex, response.data);
            Thumbnails.updateStatus(imageIndex, 'completed');
            
            if (imageIndex === this.previewState.currentIndex) {
                UI.updateCompressedInfo(this.dom, response.data);
                UI.updateLinkDisplays(response.data);
            }
            
            if (UI.uploadedCount === this.previewState.images.length) {
                UI.showNotification(`成功上传 ${UI.uploadedCount} 张图片`);
                UI.uploadedCount = 0;
                UI.hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
            }
        }
    }

    handleUploadError(message, imageIndex) {
        UI.showNotification(message, 'error');
        this.previewState.setUploadStatus(imageIndex, 'error');
        Thumbnails.updateStatus(imageIndex, 'error');
        UI.hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
    }

    nextImage() {
        if (this.previewState.next()) this.showPreview(this.previewState.currentIndex);
    }

    prevImage() {
        if (this.previewState.prev()) this.showPreview(this.previewState.currentIndex);
    }

    cleanup() {
        this.xhrRequests.forEach(xhr => {
            if (xhr.readyState !== 4) xhr.abort();
        });
        this.xhrRequests.clear();
        
        this.readers.forEach(reader => {
            if (reader.readyState === 1) reader.abort();
        });
        this.readers.clear();
        
        this.objectURLs.forEach(url => URL.revokeObjectURL(url));
        this.objectURLs.clear();
        
        this.previewState.clear();
        this.uploadQueue = [];
        clearTimeout(this.urlInputTimeout);
        
        this.dom.imagePreviewContainer.classList.remove('active');
        this.dom.imagePreview.src = '';
        UI.updateCopyButtonsState(false);
        Thumbnails.clear();
    }
}
