import { toast } from '../core/ui.js';

const card = document.querySelector('.auth-card');

async function submitAuthForm(form, onSuccess) {
    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true;

    try {
        const { success, message, redirect } = await fetch(location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        }).then((r) => r.json());

        if (redirect) {
            location.href = redirect;
            return;
        }
        if (success) {
            onSuccess?.();
            if (message) toast(message, 'success');
        } else {
            toast(message || '操作失败', 'error');
        }
    } catch {
        toast('请求失败，请稍后重试', 'error');
    } finally {
        btn.disabled = false;
    }
}

function toggleAuthForm() {
    document.getElementById('login-form')?.classList.toggle('active');
    document.getElementById('reset-form')?.classList.toggle('active');
}

card?.addEventListener('click', (e) => {
    const link = e.target.closest('[data-forgot-password], [data-toggle-form]');
    if (!link) return;

    e.preventDefault();
    if (link.matches('[data-forgot-password]') && !card.hasAttribute('data-allow-reset')) {
        toast('密码重置功能未开启，请在 .env 文件中设置', 'error');
        return;
    }
    toggleAuthForm();
});

for (const form of document.querySelectorAll('#login-form form, #reset-form form')) {
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const onSuccess = form.closest('#reset-form')
            ? () => {
                form.reset();
                toggleAuthForm();
            }
            : undefined;
        submitAuthForm(form, onSuccess);
    });
}

document.querySelectorAll('.toggle-password').forEach((btn) => {
    btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        const use = btn.querySelector('use');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        use?.setAttribute('xlink:href', show ? '#icon-eye-close' : '#icon-eye');
    });
});
