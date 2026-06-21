export function debounce(fn, delay = 300) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

export function scheduleIdle(fn) {
    if ('requestIdleCallback' in window) {
        requestIdleCallback(fn, { timeout: 500 });
    } else {
        setTimeout(fn, 50);
    }
}

export function formatKB(bytes) {
    return `${(bytes / 1024).toFixed(2)} KB`;
}

/** 压缩率与节省空间 */
export function formatCompressionStats(originalBytes, compressedBytes) {
    if (originalBytes <= 0 || compressedBytes <= 0) {
        return { ratio: '-', saved: '-' };
    }
    const originalKB = originalBytes / 1024;
    const compressedKB = compressedBytes / 1024;
    const savedKB = originalKB - compressedKB;
    const ratio = Math.max(0, (savedKB / originalKB) * 100);
    return {
        ratio: `${ratio.toFixed(1)}%`,
        saved: savedKB > 0 ? `${savedKB.toFixed(2)} KB` : '0.00 KB'
    };
}
