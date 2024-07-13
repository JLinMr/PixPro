let currentImageIndex = 0;
let images = []; 
let startX = 0;
let isDragging = false;

function setupImageZoom() {
    window.zoomImage = img => {
        const zoomContainer = document.getElementById('img-zoom');
        const zoomedImage = document.getElementById('zoomed-img');
        if (zoomContainer && zoomedImage) {
            zoomedImage.src = img.src;
            zoomContainer.style.display = 'flex';
            setTimeout(() => zoomContainer.classList.add('active'), 0);

            // 获取所有图片元素，排除 action-buttons 里面的图片
            images = Array.from(document.querySelectorAll('.gallery-item img')).filter(image => !image.closest('.action-buttons'));
            currentImageIndex = images.findIndex(image => image.src === img.src);

            // 添加触摸和鼠标事件监听器
            zoomContainer.addEventListener('touchstart', handleStart, false);
            zoomContainer.addEventListener('touchend', handleEnd, false);
            zoomContainer.addEventListener('mousedown', handleStart, false);
            zoomContainer.addEventListener('mouseup', handleEnd, false);
            zoomContainer.addEventListener('mousemove', handleMove, false);
        }
    }

    window.closeZoom = () => {
        const zoomContainer = document.getElementById('img-zoom');
        if (zoomContainer) {
            zoomContainer.classList.remove('active');
            setTimeout(() => zoomContainer.style.display = 'none', 300);
            // 移除触摸和鼠标事件监听器
            zoomContainer.removeEventListener('touchstart', handleStart, false);
            zoomContainer.removeEventListener('touchend', handleEnd, false);
            zoomContainer.removeEventListener('mousedown', handleStart, false);
            zoomContainer.removeEventListener('mouseup', handleEnd, false);
            zoomContainer.removeEventListener('mousemove', handleMove, false);
        }
    }

    window.prevImage = event => {
        event.stopPropagation(); // 阻止事件冒泡
        if (currentImageIndex > 0) {
            currentImageIndex--;
            const zoomedImg = document.getElementById('zoomed-img');
            zoomedImg.classList.add('slide-out-right');
            setTimeout(() => {
                zoomedImg.src = images[currentImageIndex].src;
                zoomedImg.classList.remove('slide-out-right');
                zoomedImg.classList.add('slide-in-left');
                setTimeout(() => zoomedImg.classList.remove('slide-in-left'), 300);
            }, 300);
        }
    }

    window.nextImage = event => {
        event.stopPropagation(); // 阻止事件冒泡
        if (currentImageIndex < images.length - 1) {
            currentImageIndex++;
            const zoomedImg = document.getElementById('zoomed-img');
            zoomedImg.classList.add('slide-out-left');
            setTimeout(() => {
                zoomedImg.src = images[currentImageIndex].src;
                zoomedImg.classList.remove('slide-out-left');
                zoomedImg.classList.add('slide-in-right');
                setTimeout(() => zoomedImg.classList.remove('slide-in-right'), 300);
            }, 300);
        }
    }

    function handleStart(event) {
        if (event.type === 'touchstart') {
            startX = event.touches[0].clientX;
        } else if (event.type === 'mousedown') {
            startX = event.clientX;
        }
        isDragging = true;
    }

    function handleEnd(event) {
        if (!isDragging) return;
        let endX;
        if (event.type === 'touchend') {
            endX = event.changedTouches[0].clientX;
        } else if (event.type === 'mouseup') {
            endX = event.clientX;
        }
        const diff = endX - startX;
        if (diff > 50) {
            // 向右滑动，上一张
            prevImage(event);
        } else if (diff < -50) {
            // 向左滑动，下一张
            nextImage(event);
        }
        isDragging = false;
    }

    function handleMove(event) {
        if (!isDragging) return;
        event.preventDefault();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    setupImageZoom();
});