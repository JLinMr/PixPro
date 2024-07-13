document.getElementById('generateToken').addEventListener('click', function(event) {
    event.preventDefault(); // 阻止默认的链接行为
    var token = generateToken(32); // 生成32位的token
    document.getElementById('reset_token').value = token; // 将生成的token填充到输入框中
});

function generateToken(length) {
    var charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    var token = '';
    for (var i = 0; i < length; i++) {
        var randomIndex = Math.floor(Math.random() * charset.length);
        token += charset.charAt(randomIndex);
    }
    return token;
}