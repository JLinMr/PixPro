import { toast } from '../core/ui.js';
import { debounce, scheduleIdle, formatKB } from '../core/helpers.js';
import {
    assertContentLength,
    buildBatchLimitMessage,
    buildBatchRejectionMessage,
    buildPartialSkipMessage,
    getFileRejectionReason,
    getRejectionMessage,
    limitBatchFiles,
    partitionFiles,
    validateFile as checkFile
} from '../core/limits.js';
import {
    uploadImage,
    copyCurrent,
    copyAll,
    resetUploadCount,
    markUploaded,
    updateProgressBar,
    hideProgressBar,
    updateCompressedInfo,
    clearImageInfo,
    updateCopyButtonsState,
    updateLinkDisplays,
    Thumbnails
} from './utils.js';

const URL_ERROR = {
    CORS: '下载失败：目标网站可能设置了防盗链或跨域限制',
    HTTP: (msg) => `下载失败：${msg}`,
    DEFAULT: '下载失败，请检查链接是否正确或尝试其他图片'
};

export class ImageHandler {
    constructor(config, dom, previewState) {
        this.config = config;
        this.dom = dom;
        this.previewState = previewState;
        this.objectURLs = new Set();
        this.uploadQueue = [];
        this.xhrRequests = new Set();
    }

    setupEventListeners() {
        const { dom, config } = this;

        dom.qualityInput.addEventListener('input', () => {
            dom.qualityOutput.textContent = dom.qualityInput.value;
            localStorage.setItem('imageQuality', dom.qualityInput.value);
        });

        dom.imageInput.addEventListener('change', () => {
            if (dom.imageInput.files.length) {
                this.ingestFiles(dom.imageInput.files);
                dom.imageInput.value = '';
            } else {
                this.clearImageInfo();
            }
        });

        document.addEventListener('paste', (event) => {
            const files = [...(event.clipboardData?.items || [])]
                .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
                .map((item) => item.getAsFile())
                .filter(Boolean);
            if (files.length) this.ingestFiles(files);
        });

        dom.pasteOrUrlInput.addEventListener('input', debounce((event) => {
            const value = event.target.value.trim();
            if (!value) return;
            try {
                const url = new URL(value);
                if (url.protocol === 'http:' || url.protocol === 'https:') this.ingestUrl(value);
            } catch {}
        }, 500));

        document.getElementById('uploadForm')?.addEventListener('submit', (e) => e.preventDefault());
        dom.deleteImageButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.clearImageInfo();
        });

        document.querySelector('.copy-section')?.addEventListener('click', (e) => {
            const el = e.target.closest('.copy-tab-btn, .copy-link-display');
            if (!el || el.disabled || el.classList.contains('disabled')) return;
            e.stopPropagation();

            const notify = (msg, type = 'success') => toast(msg, type);
            const type = el.dataset.type;
            const onError = (msg) => notify(msg, 'error');
            if (e.ctrlKey || e.metaKey) {
                copyAll(this.previewState.getAllUploadedUrls(), type, notify, onError);
            } else {
                copyCurrent(this.previewState.getCurrentUrl(), type, notify, onError);
            }
        });

        dom.imageUploadBox.addEventListener('dragover', (e) => {
            e.preventDefault();
            dom.imageUploadBox.classList.add('dragover');
        });
        dom.imageUploadBox.addEventListener('dragleave', () => dom.imageUploadBox.classList.remove('dragover'));
        dom.imageUploadBox.addEventListener('drop', (e) => {
            e.preventDefault();
            dom.imageUploadBox.classList.remove('dragover');
            const files = [...(e.dataTransfer?.files || [])].filter((file) => file.type.startsWith('image/'));
            if (files.length) this.ingestFiles(files);
        });

        window.addEventListener('beforeunload', () => this.cleanup());
    }

    clearImageInfo() {
        clearImageInfo(this.dom);
        updateLinkDisplays(null);
        this.cleanup();
        toast('图片信息清理成功');
    }

    ingestFiles(files) {
        const { fileList: cappedFiles, truncated } = limitBatchFiles(files);
        if (truncated > 0) {
            toast(buildBatchLimitMessage(truncated), 'error');
        }

        const { fileList, validFiles, rejectedCount } = partitionFiles(cappedFiles, this.config);

        if (!validFiles.length) {
            if (rejectedCount) toast(buildBatchRejectionMessage(fileList, this.config), 'error');
            return;
        }
        if (rejectedCount) toast(buildPartialSkipMessage(rejectedCount, fileList, this.config), 'error');
        this.startUpload(validFiles);
    }

    async ingestUrl(urlString) {
        const url = urlString.trim();
        try {
            new URL(url);
        } catch {
            return toast('无效的URL格式', 'error');
        }

        this.resetState();
        updateProgressBar(this.dom.progressBar, this.dom.progressContainer, 'downloading');

        try {
            const file = await this.fetchAsFile(url);
            if (!checkFile(file, this.config, { onReject: (msg) => toast(msg, 'error') })) {
                hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
                return toast('图片验证失败', 'error');
            }
            this.dom.pasteOrUrlInput.value = '';
            this.startUpload([file]);
        } catch (error) {
            hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
            const msg = error.message || '';
            if (msg.includes('CORS') || msg.includes('Failed to fetch')) toast(URL_ERROR.CORS, 'error');
            else if (msg.includes('HTTP error')) toast(URL_ERROR.HTTP(msg), 'error');
            else toast(msg.includes('限制') ? msg : URL_ERROR.DEFAULT, 'error');
            console.error('URL下载错误:', error);
        }
    }

    startUpload(validFiles) {
        this.resetState();
        validFiles.forEach((file, index) => {
            this.previewState.addImage(file);
            this.uploadQueue.push({ file, index });
        });
        this.ensurePreview(0);
        scheduleIdle(() => {
            Thumbnails.create(this.previewState.images, this.previewState.currentIndex, (index) => {
                if (this.previewState.goTo(index)) {
                    this.showPreview(index);
                    Thumbnails.updateActive(index);
                }
            });
            this.uploadAllImages();
        });
    }

    resetState() {
        this.previewState.clear();
        this.uploadQueue = [];
        resetUploadCount();
    }

    async fetchAsFile(url) {
        let response;
        try {
            response = await fetch(url, { mode: 'cors', credentials: 'omit', referrerPolicy: 'no-referrer' });
        } catch {
            throw new Error('CORS');
        }
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status} - ${response.statusText}`);

        assertContentLength(parseInt(response.headers.get('content-length'), 10), this.config);
        const blob = await response.blob();
        if (!blob.type.startsWith('image/')) throw new Error('下载的内容不是有效的图片格式');

        const path = new URL(url).pathname;
        const filename = path.slice(path.lastIndexOf('/') + 1) || 'image.jpg';
        return new File([blob], filename, { type: blob.type });
    }

    ensurePreview(index) {
        const imgData = this.previewState.images[index];
        if (!imgData || imgData.preview) return;

        const url = URL.createObjectURL(imgData.file);
        this.objectURLs.add(url);
        this.previewState.setPreview(index, url);
        if (index === this.previewState.currentIndex) this.showPreview(index);
    }

    showPreview(index) {
        const imgData = this.previewState.images[index];
        if (!imgData) return;
        if (!imgData.preview) return this.ensurePreview(index);

        this.dom.imagePreview.src = imgData.preview;
        this.dom.imagePreviewContainer.classList.add('active');
        this.dom.deleteImageButton.style.display = 'flex';
        Thumbnails.updateActive(index);

        const multiple = this.previewState.images.length > 1;
        this.dom.imageCounter.textContent = multiple ? `${index + 1} / ${this.previewState.images.length}` : '';
        this.dom.prevButton.style.display = multiple ? 'flex' : 'none';
        this.dom.nextButton.style.display = multiple ? 'flex' : 'none';

        this.renderFileInfo(imgData.file);

        const urlData = this.previewState.uploadStatus[index] === 'completed'
            ? this.previewState.getUploadedUrl(index)
            : null;
        updateCopyButtonsState(!!urlData);
        if (urlData) {
            updateCompressedInfo(this.dom, urlData);
            updateLinkDisplays(urlData);
        } else {
            updateLinkDisplays(null);
        }

        scheduleIdle(() => [index - 1, index + 1].forEach((i) => {
            if (i >= 0 && i < this.previewState.images.length) this.ensurePreview(i);
        }));
    }

    async renderFileInfo(file) {
        this.dom.originalSize.textContent = formatKB(file.size);
        try {
            const bitmap = await createImageBitmap(file);
            this.dom.originalWidth.textContent = `${bitmap.width} × ${bitmap.height}`;
            bitmap.close();
        } catch {
            this.dom.originalWidth.textContent = '未知';
        }
    }

    uploadAllImages() {
        this.uploadQueue.forEach(({ file, index }) => {
            if (!checkFile(file, this.config, { silent: true })) {
                const reason = getFileRejectionReason(file, this.config);
                return this.failUpload(getRejectionMessage(reason, this.config), index);
            }

            const xhr = uploadImage(
                file,
                this.dom.qualityInput.value,
                index,
                (e, idx) => this.onUploadProgress(e, idx),
                (xhrRef, idx) => {
                    this.xhrRequests.delete(xhrRef);
                    this.onUploadDone(xhrRef, idx);
                }
            );
            this.xhrRequests.add(xhr);
        });
    }

    onUploadProgress(event, imageIndex) {
        this.previewState.setUploadStatus(imageIndex, 'uploading');
        updateProgressBar(
            this.dom.progressBar,
            this.dom.progressContainer,
            event,
            imageIndex,
            this.previewState.images.length
        );
    }

    onUploadDone(xhr, imageIndex) {
        let response;
        try {
            response = JSON.parse(xhr.responseText);
        } catch {
            return this.failUpload('上传失败', imageIndex);
        }

        if (xhr.status !== 200 || response.status !== true) {
            return this.failUpload(response.message || '上传失败', imageIndex);
        }

        if (!response.data?.url) {
            return this.failUpload('上传失败：未返回图片地址', imageIndex);
        }

        if (markUploaded(this.previewState.images.length)) {
            toast(`成功上传 ${this.previewState.images.length} 张图片`);
            hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
        }

        this.previewState.setUploadedUrl(imageIndex, response.data);
        Thumbnails.updateStatus(imageIndex, 'completed');

        if (imageIndex === this.previewState.currentIndex) {
            updateCompressedInfo(this.dom, response.data);
            updateLinkDisplays(response.data);
            updateCopyButtonsState(true);
        }
    }

    failUpload(message, imageIndex) {
        toast(message, 'error');
        this.previewState.setUploadStatus(imageIndex, 'error');
        Thumbnails.updateStatus(imageIndex, 'error');
        hideProgressBar(this.dom.progressBar, this.dom.progressContainer);
    }

    showPreviewAt(direction) {
        if (this.previewState.move(direction)) this.showPreview(this.previewState.currentIndex);
    }

    cleanup() {
        this.xhrRequests.forEach((xhr) => { if (xhr.readyState !== 4) xhr.abort(); });
        this.xhrRequests.clear();
        this.objectURLs.forEach((url) => URL.revokeObjectURL(url));
        this.objectURLs.clear();
        this.previewState.clear();
        this.uploadQueue = [];
        this.dom.imagePreviewContainer.classList.remove('active');
        this.dom.imagePreview.src = '';
        updateCopyButtonsState(false);
        Thumbnails.clear();
    }
}
