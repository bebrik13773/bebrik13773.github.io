// Функции авторизации для Бобер кликер

// Проверка мобильного устройства
function checkMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
           'ontouchstart' in window ||
           navigator.maxTouchPoints > 0;
}

// Функция показа уведомлений
function showNotification(message, type = 'success', duration = 3000) {
    const container = document.getElementById('notifications');
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    
    container.appendChild(notification);
    notification.classList.add('show');
    
    setTimeout(() => {
        notification.style.opacity = 0;
        notification.style.transform = 'translateX(30px)';
        
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, duration);
}