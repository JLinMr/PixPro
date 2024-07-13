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
                <li>本站是一个图床网站，旨在为用户提供图片存储和分享服务。</li>
                <li>当前站点为演示站点，不保证服务的稳定性和持续性。请上传者理解并注意数据备份。</li>
                <li>禁止上传任何违反中国法律的图片内容。我们保留对违规内容进行删除的权利，并可能采取进一步的法律行动。</li>
                <li>由于本站持续进行更新和维护，站点内容可能与GitHub上的内容不同步。</li>
            </ul>
            <div class="button-container">
                <button class="github-button">GitHub</button>
                <button class="no-show-button">不再显示</button>
                <button class="close-button">我知道了</button>
            </div>
        </div>
    `;

    document.body.appendChild(notificationContainer);

    const githubButton = notificationContainer.querySelector('.github-button');
    const closeButton = notificationContainer.querySelector('.close-button');
    const noShowButton = notificationContainer.querySelector('.no-show-button');

    githubButton.addEventListener('click', function() {
        window.open('https://github.com/JLinMr/PixPro/', '_blank');
    });

    closeButton.addEventListener('click', function() {
        notificationContainer.style.display = 'none';
        overlay.style.display = 'none';
    });

    noShowButton.addEventListener('click', function() {
        const now = new Date().getTime();
        localStorage.setItem('lastNotificationAcknowledged', now);
        notificationContainer.style.display = 'none';
        overlay.style.display = 'none';
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