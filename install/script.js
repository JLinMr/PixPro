document.getElementById('generateToken').addEventListener('click', function(event) {
    event.preventDefault();
    const tokenLength = 32;
    const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let token = '';
    for (let i = 0; i < tokenLength; i++) {
        const randomIndex = Math.floor(Math.random() * charset.length);
        token += charset[randomIndex];
    }
    document.getElementById('validToken').value = token;
});