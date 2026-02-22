// 工具函数集合

// API 上传
export const API = {
    uploadImage(file, quality, imageIndex, onProgress, onComplete) {
        const formData = new FormData();
        formData.append('image', file);
        formData.append('quality', quality);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api.php', true);
        xhr.upload.addEventListener('progress', (e) => onProgress(e, imageIndex));
        xhr.onreadystatechange = () => {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                onComplete(xhr, imageIndex);
            }
        };
        xhr.send(formData);
        return xhr; // 返回 xhr 以便跟踪
    }
};

export const UI = {
    uploadedCount: 0,
    copyButtons: null,
    notificationTimer: null,

    showNotification(message, type = 'success') {
        const oldNotification = document.querySelector('.msg');
        if (oldNotification) {
            oldNotification.remove();
            clearTimeout(this.notificationTimer);
        }
        
        const notification = document.createElement('div');
        notification.className = `msg ${type === 'error' ? 'msg-red' : 'msg-green'}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        this.notificationTimer = setTimeout(() => {
            notification.classList.add('msg-right');
            setTimeout(() => notification.remove(), 800);
        }, 1500);
    },

    updateProgressBar(progressBar, progressContainer, eventOrProgress, imageIndex = null, totalImages = null) {
        progressContainer.style.display = 'block';
        
        if (eventOrProgress === 'downloading') {
            progressBar.style.transform = 'scaleX(0.3)';
            progressBar.textContent = '正在下载图片...';
        } else if (typeof eventOrProgress === 'object' && eventOrProgress.lengthComputable) {
            const percent = ((eventOrProgress.loaded / eventOrProgress.total) * 100).toFixed(0);
            progressBar.style.transform = `scaleX(${percent / 100})`;
            progressBar.textContent = `上传中 ${imageIndex + 1}/${totalImages} - ${percent}%`;
        }
    },

    hideProgressBar(progressBar, progressContainer) {
        setTimeout(() => {
            progressContainer.style.display = 'none';
            progressBar.style.transform = 'scaleX(0)';
            progressBar.textContent = '';
        }, 500);
    },

    updateCompressedInfo(dom, data) {
        const dimensionText = (data.width > 0 && data.height > 0) 
            ? `${data.width} × ${data.height}` 
            : '未知';
        dom.compressedWidth.textContent = dimensionText;
        dom.compressedHeight.textContent = '';
        
        const compressedSizeKB = data.size > 0 ? (data.size / 1024).toFixed(2) : 0;
        dom.compressedSize.textContent = compressedSizeKB > 0 ? `${compressedSizeKB} KB` : '未知';
        
        const originalSizeText = dom.originalSize.textContent;
        if (originalSizeText && compressedSizeKB > 0) {
            const originalSizeKB = parseFloat(originalSizeText);
            if (!isNaN(originalSizeKB) && originalSizeKB > 0) {
                const ratio = ((1 - compressedSizeKB / originalSizeKB) * 100).toFixed(1);
                const saved = (originalSizeKB - compressedSizeKB).toFixed(2);
                
                const compressionRatio = document.getElementById('compressionRatio');
                const savedSpace = document.getElementById('savedSpace');
                
                if (compressionRatio) compressionRatio.textContent = `${ratio}%`;
                if (savedSpace) savedSpace.textContent = `${saved} KB`;
            }
        }
    },

    clearImageInfo(dom) {
        dom.imagePreviewContainer.classList.remove('active');
        dom.deleteImageButton.style.display = 'none';
        
        ['originalWidth', 'originalHeight', 'originalSize', 'compressedWidth', 'compressedHeight', 'compressedSize']
            .forEach(key => dom[key].textContent = '');
        
        const compressionRatio = document.getElementById('compressionRatio');
        const savedSpace = document.getElementById('savedSpace');
        if (compressionRatio) compressionRatio.textContent = '-';
        if (savedSpace) savedSpace.textContent = '-';
        
        dom.progressContainer.style.display = 'none';
        dom.progressBar.style.transform = 'scaleX(0)';
        dom.progressBar.textContent = '';
    },

    updateCopyButtonsState(enabled) {
        if (!this.copyButtons) {
            this.copyButtons = document.querySelectorAll('.copy-tab-btn');
        }
        
        this.copyButtons.forEach(btn => btn.disabled = !enabled);
        document.querySelectorAll('.copy-link-display').forEach(display => {
            display.classList.toggle('disabled', !enabled);
        });
    },

    updateLinkDisplays(urlData) {
        const imageName = urlData?.url.split('/').pop().split('?')[0] || 'image.webp';
        const url = urlData?.url || 'https://example.com/image.webp';
        
        document.getElementById('urlLinkText').textContent = Clipboard.formatUrl(url, imageName, 'url');
        document.getElementById('markdownLinkText').textContent = Clipboard.formatUrl(url, imageName, 'markdown');
        document.getElementById('htmlLinkText').textContent = Clipboard.formatUrl(url, imageName, 'html');
    }
};

// 剪贴板操作
export const Clipboard = {
    async copyImageUrl(urlData, type, onSuccess, onError) {
        if (!urlData) return onError('图片还未上传完成');
        await this.copy([urlData], type, onSuccess, onError, '已复制当前图片链接');
    },

    async copyAllUrls(allUrls, type, onSuccess, onError) {
        if (allUrls.length === 0) return onError('没有已上传的图片');
        await this.copy(allUrls, type, onSuccess, onError, `已批量复制 ${allUrls.length} 张图片链接`);
    },

    async copy(urlDataList, type, onSuccess, onError, successMsg) {
        try {
            const texts = urlDataList.map(urlData => {
                const imageName = urlData.url.split('/').pop().split('?')[0];
                return this.formatUrl(urlData.url, imageName, type);
            });
            await navigator.clipboard.writeText(texts.join('\n'));
            onSuccess(successMsg);
        } catch (err) {
            console.error('复制失败:', err);
            onError('复制失败，请重试');
        }
    },

    formatUrl(url, imageName, type) {
        const formats = {
            url,
            markdown: `![${imageName}](${url})`,
            'markdown-link': `[![${imageName}](${url})](${url})`,
            html: `<img src="${url}" alt="${imageName}">`
        };
        return formats[type] || url;
    }
};

export const Thumbnails = {
    container: document.getElementById('thumbnailScrollContainer'),
    strip: document.getElementById('thumbnailStrip'),
    readers: new Set(),
    
    create(images, currentIndex, onThumbnailClick) {
        if (!this.container) return;
        
        this.abortReaders();
        
        if (images.length <= 1) {
            this.strip.classList.remove('active');
            this.container.innerHTML = '';
            return;
        }

        this.strip.classList.add('active');
        
        const fragment = document.createDocumentFragment();
        images.forEach((imgData, index) => {
            fragment.appendChild(this.createThumbnail(imgData, index, currentIndex, onThumbnailClick));
        });
        
        this.container.innerHTML = '';
        this.container.appendChild(fragment);
    },
    
    createThumbnail(imgData, index, currentIndex, onThumbnailClick) {
        const thumbWrapper = document.createElement('div');
        thumbWrapper.className = 'thumbnail-wrapper';
        thumbWrapper.dataset.index = index;
        
        const thumb = document.createElement('div');
        thumb.className = `thumbnail ${index === currentIndex ? 'active' : ''}`;
        
        const statusIndicator = document.createElement('div');
        statusIndicator.className = 'thumbnail-status uploading';
        thumb.appendChild(statusIndicator);
        
        const loadImage = () => {
            const reader = new FileReader();
            this.readers.add(reader);
            
            reader.onload = (e) => {
                this.readers.delete(reader);
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = imgData.file.name;
                img.loading = 'lazy';
                thumb.appendChild(img);
            };
            
            reader.onerror = () => this.readers.delete(reader);
            
            reader.readAsDataURL(imgData.file);
        };
        
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadImage, { timeout: 2000 });
        } else {
            setTimeout(loadImage, 0);
        }
        
        thumb.onclick = (e) => {
            e.stopPropagation();
            onThumbnailClick(index);
        };
        
        thumbWrapper.appendChild(thumb);
        return thumbWrapper;
    },

    updateActive(index) {
        this.strip.querySelectorAll('.thumbnail').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === index);
        });
        
        const activeThumbnail = this.container.querySelector(`[data-index="${index}"]`);
        if (activeThumbnail) {
            const scrollPosition = activeThumbnail.offsetLeft - (this.container.offsetWidth / 2) + (activeThumbnail.offsetWidth / 2);
            this.container.scrollTo({ left: scrollPosition, behavior: 'smooth' });
        }
    },

    updateStatus(index, status) {
        const statusIndicator = this.strip.querySelector(`[data-index="${index}"] .thumbnail-status`);
        if (statusIndicator) statusIndicator.className = `thumbnail-status ${status}`;
    },

    clear() {
        this.abortReaders();
        this.container.innerHTML = '';
        this.strip.classList.remove('active');
    },
    
    abortReaders() {
        this.readers.forEach(reader => {
            if (reader.readyState === 1) reader.abort();
        });
        this.readers.clear();
    }
};

export const Navigation = {
    scrollTimeout: null,

    setupKeyboard(onPrev, onNext, onEscape, hasImages) {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && hasImages()) return onEscape();
            if (hasImages()) {
                if (e.key === 'ArrowLeft') onPrev();
                else if (e.key === 'ArrowRight') onNext();
            }
        });
    },

    setupWheel(container, onPrev, onNext, hasMultiple) {
        container.addEventListener('wheel', (e) => {
            if (!hasMultiple()) return;
            e.preventDefault();
            clearTimeout(this.scrollTimeout);
            this.scrollTimeout = setTimeout(() => e.deltaY > 0 ? onNext() : onPrev(), 50);
        }, { passive: false });
    },

    setupTouch(container, onPrev, onNext, hasMultiple) {
        let touchStartX = 0;
        container.addEventListener('touchstart', (e) => {
            if (hasMultiple()) touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        
        container.addEventListener('touchend', (e) => {
            if (!hasMultiple()) return;
            const diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 50) diff > 0 ? onNext() : onPrev();
        }, { passive: true });
    },

    setupButtons(prevButton, nextButton, onPrev, onNext) {
        prevButton.addEventListener('click', (e) => { e.stopPropagation(); onPrev(); });
        nextButton.addEventListener('click', (e) => { e.stopPropagation(); onNext(); });
    }
};
