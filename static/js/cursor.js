class CustomCursor {
    constructor() {
        this.pos = { curr: null, prev: null };
        this.rendering = true;
        this.rafId = null;
        this.checkIntervalId = null;
        this.offset = 8;
        this.lerpAmount = 0.35;
        
        this.create();
        this.init();
        this.render();
        this.startCheckInterval();
    }

    move(x, y) {
        this.cursor.style.left = `${x}px`;
        this.cursor.style.top = `${y}px`;
    }

    create() {
        if (!this.cursor) {
            this.cursor = document.createElement("div");
            this.cursor.id = "cursor";
            this.cursor.classList.add("xs-hidden", "hidden");
            document.body.append(this.cursor);
        }
    }

    init() {
        this.handleMouseMove = (e) => {
            const x = e.clientX - this.offset;
            const y = e.clientY - this.offset;
            
            if (this.pos.curr === null) this.move(x, y);
            this.pos.curr = { x, y };
            this.cursor.classList.remove("hidden");
        };

        this.handleMouseEnter = () => this.cursor.classList.remove("hidden");
        this.handleMouseLeave = () => this.cursor.classList.add("hidden");
        this.handleMouseDown = () => this.cursor.classList.add("active");
        this.handleMouseUp = () => this.cursor.classList.remove("active");

        document.addEventListener('mousemove', this.handleMouseMove);
        document.addEventListener('mouseenter', this.handleMouseEnter);
        document.addEventListener('mouseleave', this.handleMouseLeave);
        document.addEventListener('mousedown', this.handleMouseDown);
        document.addEventListener('mouseup', this.handleMouseUp);

        // 等待 imageInput 元素加载
        const initImageInput = () => {
            const imageInput = document.getElementById('imageInput');
            if (imageInput) {
                imageInput.addEventListener('change', () => {
                    this.cursor.classList.remove("hidden", "active");
                });
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initImageInput);
        } else {
            setTimeout(initImageInput, 100);
        }
    }

    render() {
        if (!this.rendering) return;

        if (this.pos.curr) {
            if (this.pos.prev) {
                this.pos.prev.x = Math.lerp(this.pos.prev.x, this.pos.curr.x, this.lerpAmount);
                this.pos.prev.y = Math.lerp(this.pos.prev.y, this.pos.curr.y, this.lerpAmount);
                this.move(this.pos.prev.x, this.pos.prev.y);
            } else {
                this.pos.prev = { ...this.pos.curr };
            }
        }

        this.rafId = requestAnimationFrame(() => this.render());
    }

    startCheckInterval() {
        this.checkIntervalId = setInterval(() => {
            if (this.pos.curr && !this.isMouseInsideViewport()) {
                this.cursor.classList.add("hidden");
            }
        }, 250);
    }

    isMouseInsideViewport() {
        return this.pos.curr.x >= 0 && 
               this.pos.curr.y >= 0 && 
               this.pos.curr.x <= window.innerWidth && 
               this.pos.curr.y <= window.innerHeight;
    }

    pauseRendering() {
        this.rendering = false;
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }

    resumeRendering() {
        if (!this.rendering) {
            this.rendering = true;
            this.render();
        }
    }

    destroy() {
        this.pauseRendering();
        
        if (this.checkIntervalId) {
            clearInterval(this.checkIntervalId);
            this.checkIntervalId = null;
        }

        document.removeEventListener('mousemove', this.handleMouseMove);
        document.removeEventListener('mouseenter', this.handleMouseEnter);
        document.removeEventListener('mouseleave', this.handleMouseLeave);
        document.removeEventListener('mousedown', this.handleMouseDown);
        document.removeEventListener('mouseup', this.handleMouseUp);

        if (this.cursor) {
            this.cursor.remove();
            this.cursor = null;
        }
    }
}

Math.lerp = (start, end, amt) => (1 - amt) * start + amt * end;

document.addEventListener("DOMContentLoaded", () => {
    const cursorInstance = new CustomCursor();
    
    new IntersectionObserver(entries => {
        entries.forEach(entry => {
            entry.isIntersecting ? cursorInstance.resumeRendering() : cursorInstance.pauseRendering();
        });
    }).observe(document.body);
});