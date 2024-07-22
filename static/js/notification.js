document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    document.body.appendChild(overlay);

    const notificationContainer = document.createElement('div');
    notificationContainer.className = 'notification-container';
    notificationContainer.innerHTML = `
        <div class="notification-content">
            <h1>站点公告</h1>
            <ul>
                <li>由于本站持续进行更新和维护，站点内容可能与GitHub上的内容不同步。</li>
                <li>当前站点为演示站点，不保证服务的稳定性和持续性。请上传者注意数据备份。</li>
                <li>请不要把本站上传的图片用于生产环境，因为后台是公开的，大众都是可以进行删除操作的</li>
                <li>禁止上传任何违反中国法律的图片内容。我们保留对违规内容进行删除的权利，并可能采取进一步的法律行动。</li>
            </ul>
            <div class="button-container">
                <button class="privacy-button">隐私协议</button>
                <button class="github-button">GitHub</button>
                <button class="close-no-show-button">我知道了</button>
            </div>
        </div>
    `;

    document.body.appendChild(notificationContainer);

    const buttons = notificationContainer.querySelectorAll('button');
    buttons.forEach(button => {
        button.addEventListener('click', function() {
            switch (button.className) {
                case 'privacy-button':
                    window.open('https://blog.bsgun.cn/privacy/', '_blank');
                    break;
                case 'github-button':
                    window.open('https://github.com/JLinMr/PixPro/', '_blank');
                    break;
                case 'close-no-show-button':
                    notificationContainer.style.display = 'none';
                    overlay.style.display = 'none';
                    const now = new Date().getTime();
                    localStorage.setItem('lastNotificationAcknowledged', now);
                    break;
            }
        });
    });

    const lastAcknowledged = localStorage.getItem('lastNotificationAcknowledged');
    if (lastAcknowledged) {
        const now = new Date().getTime();
        const sevenDaysInMillis = 7 * 24 * 60 * 60 * 1000;
        if (now - lastAcknowledged < sevenDaysInMillis) {
            notificationContainer.style.display = 'none';
            overlay.style.display = 'none';
        }
    }
});