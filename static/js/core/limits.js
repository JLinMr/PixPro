const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const MAX_BATCH_FILES = 20;

function summarizeRejections(fileList, limits) {
    const reasons = { type: 0, size: 0 };
    fileList.forEach((file) => {
        const reason = getFileRejectionReason(file, limits);
        if (reason) reasons[reason]++;
    });
    return reasons;
}

export function partitionFiles(files, limits) {
    const fileList = Array.from(files);
    const validFiles = [];
    let rejectedCount = 0;

    for (const file of fileList) {
        if (getFileRejectionReason(file, limits)) rejectedCount++;
        else validFiles.push(file);
    }

    return { fileList, validFiles, rejectedCount };
}

export function readUploadLimits(scriptTag) {
    const maxFileSize = parseInt(scriptTag?.dataset?.maxFileSize, 10) || 0;
    return {
        allowedTypes: ALLOWED_IMAGE_TYPES,
        maxFileSize: maxFileSize > 0 ? maxFileSize : 0
    };
}

export function limitBatchFiles(files) {
    const fileList = Array.from(files);
    if (fileList.length <= MAX_BATCH_FILES) {
        return { fileList, truncated: 0 };
    }
    return { fileList: fileList.slice(0, MAX_BATCH_FILES), truncated: fileList.length - MAX_BATCH_FILES };
}

export function buildBatchLimitMessage(truncated) {
    return truncated > 0
        ? `单次最多上传 ${MAX_BATCH_FILES} 张，已忽略 ${truncated} 张`
        : `单次最多上传 ${MAX_BATCH_FILES} 张图片`;
}

export function maxSizeMessage(maxBytes) {
    const mb = maxBytes > 0 ? Math.floor(maxBytes / (1024 * 1024)) : 0;
    return mb > 0 ? `文件大小超过限制，最大允许 ${mb}MB` : '文件不符合上传要求';
}

export function getFileRejectionReason(file, limits) {
    if (!limits.allowedTypes.includes(file.type)) return 'type';
    if (limits.maxFileSize > 0 && file.size > limits.maxFileSize) return 'size';
    return null;
}

export function getRejectionMessage(reason, limits) {
    if (reason === 'type') return '不支持的文件类型，请上传 JPEG、PNG、GIF 或 WebP 图片';
    if (reason === 'size') return maxSizeMessage(limits.maxFileSize);
    return '文件不符合上传要求';
}

export function buildBatchRejectionMessage(fileList, limits) {
    const reasons = summarizeRejections(fileList, limits);
    const maxMB = limits.maxFileSize > 0 ? Math.floor(limits.maxFileSize / (1024 * 1024)) : 0;

    if (reasons.size > 0 && reasons.type === 0) {
        return fileList.length === 1
            ? getRejectionMessage('size', limits)
            : `有 ${reasons.size} 个文件超过大小限制，最大允许 ${maxMB}MB`;
    }

    if (reasons.type > 0 && reasons.size === 0) {
        return fileList.length === 1
            ? getRejectionMessage('type', limits)
            : `有 ${reasons.type} 个文件类型不支持，请上传图片文件`;
    }

    return '所选文件均不符合上传要求';
}

export function buildPartialSkipMessage(rejectedCount, fileList, limits) {
    const reasons = summarizeRejections(fileList.filter((file) => getFileRejectionReason(file, limits)), limits);
    const maxMB = limits.maxFileSize > 0 ? Math.floor(limits.maxFileSize / (1024 * 1024)) : 0;

    if (reasons.size > 0 && reasons.type === 0) {
        return `已跳过 ${rejectedCount} 个超过 ${maxMB}MB 限制的文件`;
    }
    if (reasons.type > 0 && reasons.size === 0) {
        return `已跳过 ${rejectedCount} 个不支持的文件类型`;
    }
    return `已跳过 ${rejectedCount} 个不符合要求的文件`;
}

export function assertContentLength(contentLength, limits) {
    if (limits.maxFileSize > 0 && contentLength > limits.maxFileSize) {
        throw new Error(maxSizeMessage(limits.maxFileSize));
    }
}

export function validateFile(file, limits, { silent = false, onReject } = {}) {
    const reason = getFileRejectionReason(file, limits);
    if (!reason) return true;
    if (!silent) onReject?.(getRejectionMessage(reason, limits));
    return false;
}
