class Ex {
    constructor() {
        this.pos = { curr: null, prev: null };
        this.create();
        this.init();
        this.rendering = true;
        this.render();
        this.startCheckInterval();
    }

    move(e, t) {
        this.cursor.style.left = `${e}px`;
        this.cursor.style.top = `${t}px`;
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
        document.onmousemove = e => {
            if (this.pos.curr == null) this.move(e.clientX - 8, e.clientY - 8);
            this.pos.curr = { x: e.clientX - 8, y: e.clientY - 8 };
            this.cursor.classList.remove("hidden");
        };
        document.onmouseenter = () => this.cursor.classList.remove("hidden");
        document.onmouseleave = () => this.cursor.classList.add("hidden");
        document.onmousedown = () => this.cursor.classList.add("active");
        document.onmouseup = () => this.cursor.classList.remove("active");

        setTimeout(() => {
            document.getElementById('imageInput').addEventListener('change', () => {
                let attempts = 5;
                const checkCursor = () => {
                    this.cursor.classList.remove("hidden", "active");
                    if (attempts-- > 0) setTimeout(checkCursor, 100);
                };
                setTimeout(checkCursor, 50);
            });
        }, 1000);
    }

    render() {
        if (this.rendering) {
            if (this.pos.prev) {
                this.pos.prev.x = Math.lerp(this.pos.prev.x, this.pos.curr.x, .35);
                this.pos.prev.y = Math.lerp(this.pos.prev.y, this.pos.curr.y, .35);
                this.move(this.pos.prev.x, this.pos.prev.y);
            } else {
                this.pos.prev = this.pos.curr;
            }
            requestAnimationFrame(() => this.render());
        }
    }

    startCheckInterval() {
        setInterval(() => {
            if (this.pos.curr && !this.isMouseInsideViewport()) this.cursor.classList.add("hidden");
        }, 100);
    }

    isMouseInsideViewport() {
        return this.pos.curr.x >= 0 && this.pos.curr.y >= 0 && this.pos.curr.x <= window.innerWidth && this.pos.curr.y <= window.innerHeight;
    }

    pauseRendering() { this.rendering = false; }

    resumeRendering() {
        if (!this.rendering) {
            this.rendering = true;
            this.render();
        }
    }
}

Math.lerp = (start, end, amt) => (1 - amt) * start + amt * end;

document.addEventListener("DOMContentLoaded", () => {
    const cursorInstance = new Ex();
    new IntersectionObserver(entries => {
        entries.forEach(entry => {
            entry.isIntersecting ? cursorInstance.resumeRendering() : cursorInstance.pauseRendering();
        });
    }).observe(document.body);
});