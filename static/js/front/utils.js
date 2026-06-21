import { copyText } from '../core/clipboard.js';
import { scheduleIdle, formatKB, formatCompressionStats } from '../core/helpers.js';

let uploadedCount = 0;

const LINK_FORMATS = {
    url: (url) => url,
    markdown: (url, name) => `![${name}](${url})`,
    html: (url, name) => `<img src="${url}" alt="${name}">`
};

const PLACEHOLDER_URL = 'https://example.com/image.webp';

function formatLink(url, type) {
    const name = url.split('/').pop().split('?')[0] || 'image.webp';
    return (LINK_FORMATS[type] || LINK_FORMATS.url)(url, name);
}

export function uploadImage(file, quality, imageIndex, onProgress, onComplete) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('quality', quality);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.upload.addEventListener('progress', (e) => onProgress(e, imageIndex));
    xhr.onreadystatechange = () => {
        if (xhr.readyState === XMLHttpRequest.DONE) onComplete(xhr, imageIndex);
    };
    xhr.send(formData);
    return xhr;
}

export function resetUploadCount() {
    uploadedCount = 0;
}

export function markUploaded(total) {
    uploadedCount += 1;
    if (uploadedCount === total) {
        uploadedCount = 0;
        return true;
    }
    return false;
}

export function updateProgressBar(progressBar, progressContainer, eventOrProgress, imageIndex = null, totalImages = null) {
    progressContainer.style.display = 'block';
    if (eventOrProgress === 'downloading') {
        progressBar.style.transform = 'scaleX(0.3)';
        progressBar.textContent = '正在下载图片...';
        return;
    }
    if (typeof eventOrProgress === 'object' && eventOrProgress.lengthComputable) {
        const percent = ((eventOrProgress.loaded / eventOrProgress.total) * 100).toFixed(0);
        progressBar.style.transform = `scaleX(${percent / 100})`;
        progressBar.textContent = `上传中 ${imageIndex + 1}/${totalImages} - ${percent}%`;
    }
}

export function hideProgressBar(progressBar, progressContainer) {
    setTimeout(() => {
        progressContainer.style.display = 'none';
        progressBar.style.transform = 'scaleX(0)';
        progressBar.textContent = '';
    }, 500);
}

export function updateCompressedInfo(dom, data) {
    dom.compressedWidth.textContent = data.width > 0 && data.height > 0 ? `${data.width} × ${data.height}` : '未知';
    dom.compressedSize.textContent = data.size > 0 ? formatKB(data.size) : '未知';

    const originalBytes = parseFloat(dom.originalSize.textContent) * 1024;
    const stats = formatCompressionStats(originalBytes, data.size);
    const ratioEl = document.getElementById('compressionRatio');
    const savedEl = document.getElementById('savedSpace');
    if (ratioEl) ratioEl.textContent = stats.ratio;
    if (savedEl) savedEl.textContent = stats.saved;
}

export function clearImageInfo(dom) {
    dom.imagePreviewContainer.classList.remove('active');
    dom.deleteImageButton.style.display = 'none';
    ['originalWidth', 'originalSize', 'compressedWidth', 'compressedSize']
        .forEach((key) => { dom[key].textContent = ''; });
    const ratioEl = document.getElementById('compressionRatio');
    const savedEl = document.getElementById('savedSpace');
    if (ratioEl) ratioEl.textContent = '-';
    if (savedEl) savedEl.textContent = '-';
    hideProgressBar(dom.progressBar, dom.progressContainer);
}

export function updateCopyButtonsState(enabled) {
    document.querySelectorAll('.copy-tab-btn').forEach((btn) => { btn.disabled = !enabled; });
    document.querySelectorAll('.copy-link-display').forEach((el) => el.classList.toggle('disabled', !enabled));
}

export function updateLinkDisplays(urlData) {
    const url = urlData?.url || PLACEHOLDER_URL;
    document.getElementById('urlLinkText').textContent = formatLink(url, 'url');
    document.getElementById('markdownLinkText').textContent = formatLink(url, 'markdown');
    document.getElementById('htmlLinkText').textContent = formatLink(url, 'html');
}

async function copyLinks(urlDataList, type, successMsg, onSuccess, onError) {
    try {
        const text = urlDataList.map(({ url }) => formatLink(url, type)).join('\n');
        if (!await copyText(text)) throw new Error('copy failed');
        onSuccess(successMsg);
    } catch {
        onError('复制失败，请重试');
    }
}

export function copyCurrent(urlData, type, onSuccess, onError) {
    if (!urlData) return onError('图片还未上传完成');
    return copyLinks([urlData], type, '已复制当前图片链接', onSuccess, onError);
}

export function copyAll(urlDataList, type, onSuccess, onError) {
    if (!urlDataList.length) return onError('没有已上传的图片');
    return copyLinks(urlDataList, type, `已批量复制 ${urlDataList.length} 张图片链接`, onSuccess, onError);
}

const thumbContainer = document.getElementById('thumbnailScrollContainer');
const thumbStrip = document.getElementById('thumbnailStrip');
const thumbObjectUrls = new Set();

function revokeThumbUrls() {
    thumbObjectUrls.forEach((url) => URL.revokeObjectURL(url));
    thumbObjectUrls.clear();
}

export const Thumbnails = {
    create(images, currentIndex, onClick) {
        if (!thumbContainer) return;
        revokeThumbUrls();

        if (images.length <= 1) {
            thumbStrip.classList.remove('active');
            thumbContainer.innerHTML = '';
            return;
        }

        thumbStrip.classList.add('active');
        const fragment = document.createDocumentFragment();
        images.forEach((imgData, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'thumbnail-wrapper';
            wrapper.dataset.index = index;

            const thumb = document.createElement('div');
            thumb.className = `thumbnail ${index === currentIndex ? 'active' : ''}`;
            thumb.innerHTML = '<div class="thumbnail-status uploading"></div>';

            scheduleIdle(() => {
                const src = imgData.preview || URL.createObjectURL(imgData.file);
                if (!imgData.preview) thumbObjectUrls.add(src);
                const img = document.createElement('img');
                img.src = src;
                img.loading = 'lazy';
                thumb.appendChild(img);
            });

            thumb.addEventListener('click', (e) => {
                e.stopPropagation();
                onClick(index);
            });
            wrapper.appendChild(thumb);
            fragment.appendChild(wrapper);
        });
        thumbContainer.replaceChildren(fragment);
    },

    updateActive(index) {
        thumbStrip.querySelectorAll('.thumbnail').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === index);
        });
        const active = thumbContainer.querySelector(`[data-index="${index}"]`);
        if (active) {
            thumbContainer.scrollTo({
                left: active.offsetLeft - thumbContainer.offsetWidth / 2 + active.offsetWidth / 2,
                behavior: 'smooth'
            });
        }
    },

    updateStatus(index, status) {
        const indicator = thumbStrip.querySelector(`[data-index="${index}"] .thumbnail-status`);
        if (indicator) indicator.className = `thumbnail-status ${status}`;
    },

    clear() {
        revokeThumbUrls();
        thumbContainer.innerHTML = '';
        thumbStrip.classList.remove('active');
    }
};

export function setupNavigation({ dom, previewState, onPrev, onNext, onClear }) {
    const hasImages = () => previewState.images.length > 0;
    const hasMultiple = () => previewState.images.length > 1;

    document.addEventListener('keydown', (e) => {
        if (!hasImages()) return;
        if (e.key === 'Escape') onClear();
        else if (e.key === 'ArrowLeft') onPrev();
        else if (e.key === 'ArrowRight') onNext();
    });

    let wheelTimer;
    dom.uploadContainer.addEventListener('wheel', (e) => {
        if (!hasMultiple()) return;
        e.preventDefault();
        clearTimeout(wheelTimer);
        wheelTimer = setTimeout(() => (e.deltaY > 0 ? onNext : onPrev)(), 50);
    }, { passive: false });

    let touchStartX = 0;
    const onTouchEnd = (e) => {
        if (!hasMultiple()) return;
        const diff = touchStartX - e.changedTouches[0].screenX;
        if (Math.abs(diff) > 50) (diff > 0 ? onNext : onPrev)();
    };

    dom.imagePreviewContainer.addEventListener('touchstart', (e) => {
        if (hasMultiple()) touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    dom.imagePreviewContainer.addEventListener('touchend', onTouchEnd, { passive: true });

    dom.prevButton.addEventListener('click', (e) => { e.stopPropagation(); onPrev(); });
    dom.nextButton.addEventListener('click', (e) => { e.stopPropagation(); onNext(); });
}
