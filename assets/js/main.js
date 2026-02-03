// Основной JavaScript файл для Бобер кликер
// Все основные функции игры находятся здесь

// === КОНФИГУРАЦИЯ ===
const CONFIG = {
    owner: 'bebrik13773',
    repo: 'bebrik13773.github.io',
    updateInterval: 10 * 60 * 1000,
    retryDelay: 30000,
    maxRetries: 3
};

// === ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ===
let retryCount = 0;
let updateTimer = null;

// === ОСНОВНАЯ ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ КОММИТА ===
async function getLatestCommit() {
    const apiUrl = `https://api.github.com/repos/${CONFIG.owner}/${CONFIG.repo}/commits`;
    const commitElement = document.getElementById('comit');
    
    commitElement.classList.remove('error', 'success');
    commitElement.classList.add('loading');
    
    try {
        const response = await fetch(apiUrl);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const commits = await response.json();
        
        if (commits && commits.length > 0) {
            const commitMessage = commits[0].commit.message.split('\n')[0];
            const author = commits[0].commit.author.name;
            const date = new Date(commits[0].commit.author.date).toLocaleDateString('ru-RU');
            
            commitElement.innerHTML = `
                <strong>📌 Последнее изменение:</strong><br>
                ${commitMessage}<br>
                <small>👤 ${author} | 📅 ${date}</small>
            `;
            
            commitElement.classList.remove('loading');
            commitElement.classList.add('success');
            
            retryCount = 0;
            
            console.log(`✅ Информация обновлена: ${new Date().toLocaleTimeString()}`);
        } else {
            throw new Error('Репозиторий пуст или информация не найдена');
        }
        
    } catch (error) {
        handleError(error, commitElement);
    }
}

// === ОБРАБОТКА ОШИБОК ===
function handleError(error, commitElement) {
    console.error('❌ Ошибка при получении коммита:', error);
    
    commitElement.classList.remove('loading');
    commitElement.classList.add('error');
    
    let errorMessage = 'Ошибка загрузки';
    
    if (error.message.includes('403')) {
        errorMessage = '⚠️ Лимит запросов исчерпан. Попробуйте позже.';
    } else if (error.message.includes('404')) {
        errorMessage = '❌ Репозиторий не найден. Проверьте настройки.';
    } else if (error.message.includes('NetworkError')) {
        errorMessage = '📡 Проблема с подключением к интернету';
    }
    
    commitElement.innerHTML = `<strong>${errorMessage}</strong>`;
    
    retryCount++;
    if (retryCount < CONFIG.maxRetries) {
        console.log(`🔄 Повторная попытка ${retryCount}/${CONFIG.maxRetries} через ${CONFIG.retryDelay/1000} сек...`);
        setTimeout(getLatestCommit, CONFIG.retryDelay);
    } else {
        console.log('⏸️ Достигнут лимит повторных попыток. Следующая проверка по расписанию.');
    }
}

// === УПРАВЛЕНИЕ АВТООБНОВЛЕНИЕМ ===
function startAutoUpdate() {
    if (updateTimer) {
        clearInterval(updateTimer);
    }
    
    updateTimer = setInterval(getLatestCommit, CONFIG.updateInterval);
    
    console.log(`🔄 Автообновление запущено: каждые ${CONFIG.updateInterval/60000} минут`);
}

function stopAutoUpdate() {
    if (updateTimer) {
        clearInterval(updateTimer);
        updateTimer = null;
        console.log('⏸️ Автообновление остановлено');
    }
}

// === ИНИЦИАЛИЗАЦИЯ ===
document.addEventListener('DOMContentLoaded', function() {
    getLatestCommit();
    startAutoUpdate();
    addManualRefreshButton();
    
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoUpdate();
        } else {
            getLatestCommit();
            startAutoUpdate();
        }
    });
});

// === ДОПОЛНИТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ РУЧНОГО ОБНОВЛЕНИЯ ===
function addManualRefreshButton() {
    const container = document.createElement('div');
    container.style.marginTop = '10px';
    
    const refreshButton = document.createElement('button');
    refreshButton.textContent = '🔄 Обновить';
    refreshButton.style.cssText = `
        background: linear-gradient(135deg, #8b5cf6, #a855f7);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
        box-shadow: 0 2px 4px rgba(139, 92, 246, 0.3);
    `;
    
    refreshButton.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-2px)';
        this.style.boxShadow = '0 4px 8px rgba(139, 92, 246, 0.4)';
    });
    
    refreshButton.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0)';
        this.style.boxShadow = '0 2px 4px rgba(139, 92, 246, 0.3)';
    });
    
    refreshButton.addEventListener('click', function() {
        this.disabled = true;
        this.textContent = '⏳ Загрузка...';
        
        getLatestCommit();
        
        setTimeout(() => {
            this.disabled = false;
            this.textContent = '🔄 Обновить';
        }, 2000);
    });
    
    const commitElement = document.getElementById('comit');
    container.appendChild(refreshButton);
    commitElement.parentNode.insertBefore(container, commitElement.nextSibling);
}

// === ФУНКЦИЯ ДЛЯ ИЗМЕНЕНИЯ КОНФИГУРАЦИИ "НА ЛЕТУ" ===
function updateConfig(newConfig) {
    Object.assign(CONFIG, newConfig);
    console.log('Конфигурация обновлена:', CONFIG);
    
    stopAutoUpdate();
    startAutoUpdate();
}

// === ЭКСПОРТ ФУНКЦИЙ ДЛЯ ИСПОЛЬЗОВАНИЯ В КОНСОЛИ ===
window.CommitWidget = {
    refresh: getLatestCommit,
    startAutoUpdate: startAutoUpdate,
    stopAutoUpdate: stopAutoUpdate,
    updateConfig: updateConfig,
    getConfig: () => ({ ...CONFIG })
};

console.log('✅ Виджет коммитов GitHub инициализирован');
console.log('ℹ️ Используйте CommitWidget в консоли для управления виджетом');