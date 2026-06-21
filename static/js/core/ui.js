const MAX = 5;
const DURATION = { success: 3000, error: 4000 };
const LEAVE = 300;

let root;
let seq = 0;
const items = [];

function getRoot() {
    if (!root) {
        root = document.createElement('div');
        root.id = 'toast-stack';
        root.className = 'toast-stack';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        document.body.appendChild(root);
    }
    return root;
}

function dismiss(id) {
    const item = items.find((t) => t.id === id && !t.leaving);
    if (!item) return;

    item.leaving = true;
    clearTimeout(item.timer);

    let done = false;
    const finish = () => {
        if (done) return;
        done = true;
        item.el.remove();
        const i = items.findIndex((t) => t.id === id);
        if (i !== -1) items.splice(i, 1);
    };

    item.el.classList.add('is-leaving');
    item.el.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, LEAVE + 50);
}

export function toast(message, type = 'success') {
    while (items.filter((t) => !t.leaving).length >= MAX) {
        dismiss(items.find((t) => !t.leaving)?.id);
    }

    const id = ++seq;
    const el = document.createElement('div');
    el.className = `toast-item msg msg-${type === 'error' ? 'red' : 'green'}`;
    el.textContent = message;
    el.addEventListener('click', () => dismiss(id));
    getRoot().appendChild(el);

    items.push({
        id,
        el,
        leaving: false,
        timer: setTimeout(() => dismiss(id), DURATION[type] ?? DURATION.success)
    });
}

export function dialog({
    message,
    onConfirm,
    confirmText = '确认',
    cancelText = '取消',
    type = 'success'
} = {}) {
    document.querySelector('.custom-confirm')?.remove();

    const box = document.createElement('div');
    box.className = 'custom-confirm glass-dialog';
    box.innerHTML = `
        <div class="confirm-message">${message}</div>
        <div class="confirm-buttons">
            <button type="button" class="glass-btn btn-${type}">${confirmText}</button>
            <button type="button" class="glass-btn btn-cancel">${cancelText}</button>
        </div>`;
    document.body.appendChild(box);
    setTimeout(() => box.classList.add('visible'), 10);

    const finish = (confirmed) => {
        box.classList.remove('visible');
        document.removeEventListener('keydown', onKeydown);
        setTimeout(() => {
            box.remove();
            if (confirmed) onConfirm?.();
        }, 400);
    };

    const onKeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            finish(true);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            finish(false);
        }
    };

    document.addEventListener('keydown', onKeydown);
    box.querySelector(`.btn-${type}`).addEventListener('click', () => finish(true));
    box.querySelector('.btn-cancel').addEventListener('click', () => finish(false));
}
