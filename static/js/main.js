import { Navigation, UI } from './upload/utils.js';
import { ImageHandler } from './upload/handler.js';

// 配置和DOM缓存
let CONFIG = null;
let DOM = null;
let imageHandler = null;

// 图片预览状态管理
const PreviewState = {
    images: [],
    currentIndex: 0,
    uploadedUrls: {},
    uploadStatus: {},
    
    addImage(file) {
        this.images.push({ file, preview: null, uploaded: false });
    },
    
    clear() {
        Object.assign(this, { images: [], currentIndex: 0, uploadedUrls: {}, uploadStatus: {} });
    },
    
    setPreview(index, dataUrl) {
        if (this.images[index]) this.images[index].preview = dataUrl;
    },
    
    setUploadedUrl(index, url) {
        this.uploadedUrls[index] = url;
        if (this.images[index]) this.images[index].uploaded = true;
        this.uploadStatus[index] = 'completed';
    },
    
    setUploadStatus(index, status) {
        this.uploadStatus[index] = status;
    },
    
    getUploadStatus(index) {
        return this.uploadStatus[index] || 'pending';
    },
    
    getUploadedUrl(index) {
        return this.uploadedUrls[index];
    },
    
    getCurrentUrl() {
        return this.uploadedUrls[this.currentIndex];
    },
    
    next() {
        return this.move(1);
    },
    
    prev() {
        return this.move(-1);
    },
    
    move(direction) {
        if (this.images.length === 0) return false;
        
        let newIndex = this.currentIndex + direction;
        
        // 循环滚动逻辑
        if (newIndex >= this.images.length) {
            newIndex = 0; // 到达末尾，跳转到开头
        } else if (newIndex < 0) {
            newIndex = this.images.length - 1; // 到达开头，跳转到末尾
        }
        
        this.currentIndex = newIndex;
        return true;
    },
    
    goTo(index) {
        if (index >= 0 && index < this.images.length) {
            this.currentIndex = index;
            return true;
        }
        return false;
    },
    
    getAllUploadedUrls() {
        return Object.values(this.uploadedUrls);
    }
};

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    const scriptTag = document.currentScript || document.querySelector('script[src*="script.js"]');
    CONFIG = {
        allowedTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
        maxFileSize: parseInt(scriptTag?.dataset?.maxFileSize) || 0
    };
    
    // DOM元素缓存
    DOM = {
        imageInput: document.getElementById('imageInput'),
        imagePreview: document.getElementById('imagePreview'),
        imagePreviewContainer: document.getElementById('imagePreviewContainer'),
        prevButton: document.getElementById('prevButton'),
        nextButton: document.getElementById('nextButton'),
        imageCounter: document.getElementById('imageCounter'),
        qualityInput: document.getElementById('qualityInput'),
        qualityOutput: document.getElementById('qualityOutput'),
        progressBar: document.getElementById('progressBar'),
        progressContainer: document.getElementById('progressContainer'),
        originalWidth: document.getElementById('originalWidth'),
        originalHeight: document.getElementById('originalHeight'),
        originalSize: document.getElementById('originalSize'),
        compressedWidth: document.getElementById('compressedWidth'),
        compressedHeight: document.getElementById('compressedHeight'),
        compressedSize: document.getElementById('compressedSize'),
        deleteImageButton: document.getElementById('deleteImageButton'),
        imageUploadBox: document.getElementById('imageUploadBox'),
        pasteOrUrlInput: document.getElementById('pasteOrUrlInput'),
        thumbnailStrip: document.getElementById('thumbnailStrip'),
        thumbnailScrollContainer: document.getElementById('thumbnailScrollContainer'),
        uploadContainer: document.querySelector('.upload-container')
    };
    
    initialize();
});

// 初始化
function initialize() {
    imageHandler = new ImageHandler(CONFIG, DOM, PreviewState);
    
    imageHandler.setupEventListeners();
    setupNavigationListeners();
    loadSavedQuality();
    UI.updateCopyButtonsState(false);
    UI.updateLinkDisplays(null); // 初始化显示示例内容
}

// 设置导航监听器
function setupNavigationListeners() {
    const prev = () => imageHandler.prevImage();
    const next = () => imageHandler.nextImage();
    const clear = () => {
        UI.clearImageInfo(DOM);
        imageHandler.cleanup();
        UI.showNotification('图片信息清理成功');
    };
    
    Navigation.setupKeyboard(prev, next, clear, () => PreviewState.images.length > 0);
    Navigation.setupWheel(DOM.uploadContainer, prev, next, () => PreviewState.images.length > 1);
    Navigation.setupTouch(DOM.imagePreviewContainer, prev, next, () => PreviewState.images.length > 1);
    Navigation.setupButtons(DOM.prevButton, DOM.nextButton, prev, next);
}

// 加载保存的压缩率
function loadSavedQuality() {
    const savedQuality = localStorage.getItem('imageQuality');
    if (savedQuality) {
        DOM.qualityInput.value = savedQuality;
        DOM.qualityOutput.textContent = savedQuality;
    }
}
