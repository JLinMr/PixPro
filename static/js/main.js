import { readUploadLimits } from './core/limits.js';
import {
    setupNavigation,
    updateCopyButtonsState,
    updateLinkDisplays
} from './front/utils.js';
import { ImageHandler } from './front/handler.js';

const limits = readUploadLimits(
    document.currentScript || document.querySelector('script[data-max-file-size]')
);

class PreviewState {
    images = [];
    currentIndex = 0;
    uploadedUrls = {};
    uploadStatus = {};

    addImage(file) {
        this.images.push({ file, preview: null });
    }

    clear() {
        this.images = [];
        this.currentIndex = 0;
        this.uploadedUrls = {};
        this.uploadStatus = {};
    }

    setPreview(index, url) {
        if (this.images[index]) this.images[index].preview = url;
    }

    setUploadedUrl(index, url) {
        this.uploadedUrls[index] = url;
        this.uploadStatus[index] = 'completed';
    }

    setUploadStatus(index, status) {
        this.uploadStatus[index] = status;
    }

    getUploadedUrl(index) {
        return this.uploadedUrls[index];
    }

    getCurrentUrl() {
        return this.uploadedUrls[this.currentIndex];
    }

    getAllUploadedUrls() {
        return Object.values(this.uploadedUrls);
    }

    goTo(index) {
        if (index < 0 || index >= this.images.length) return false;
        this.currentIndex = index;
        return true;
    }

    move(direction) {
        if (!this.images.length) return false;
        const len = this.images.length;
        this.currentIndex = (this.currentIndex + direction + len) % len;
        return true;
    }
}

const UPLOAD_DOM_IDS = [
    'imageInput', 'imagePreview', 'imagePreviewContainer', 'prevButton', 'nextButton',
    'imageCounter', 'qualityInput', 'qualityOutput', 'progressBar', 'progressContainer',
    'originalWidth', 'originalSize', 'compressedWidth', 'compressedSize', 'deleteImageButton', 'imageUploadBox', 'pasteOrUrlInput',
    'thumbnailStrip', 'thumbnailScrollContainer'
];

function getUploadDom() {
    const dom = Object.fromEntries(UPLOAD_DOM_IDS.map((id) => [id, document.getElementById(id)]));
    dom.uploadContainer = document.querySelector('.upload-container');
    return dom;
}

document.addEventListener('DOMContentLoaded', () => {
    const dom = getUploadDom();
    const previewState = new PreviewState();
    const handler = new ImageHandler(limits, dom, previewState);

    handler.setupEventListeners();
    setupNavigation({
        dom,
        previewState,
        onPrev: () => handler.showPreviewAt(-1),
        onNext: () => handler.showPreviewAt(1),
        onClear: () => handler.clearImageInfo()
    });

    const savedQuality = localStorage.getItem('imageQuality');
    if (savedQuality) {
        dom.qualityInput.value = savedQuality;
        dom.qualityOutput.textContent = savedQuality;
    }

    updateCopyButtonsState(false);
    updateLinkDisplays(null);
});
