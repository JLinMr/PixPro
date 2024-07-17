document.getElementById('generateToken').addEventListener('click', function(event) {
    event.preventDefault(); // 防止表单提交
    const tokenLength = 32; // Token的长度
    const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; // 字符集
    let token = '';
    for (let i = 0; i < tokenLength; i++) {
        const randomIndex = Math.floor(Math.random() * charset.length);
        token += charset[randomIndex];
    }
    document.getElementById('validToken').value = token;
});