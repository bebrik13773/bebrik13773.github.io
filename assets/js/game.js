// Игровая логика для Бобер кликер

// Начало основного кода
const href = window.location.host
if (href == 'bebrik13773.github.io') window.location.replace("https://bober-api.gt.tc")

// Восстановление логина и пароля из localStorage
document.getElementById('password').value = localStorage.getItem('password');
document.getElementById('login').value = localStorage.getItem('login');
document.getElementById('admin').style.display = 'none';
const queryString = window.location.search;
const testm = false
const urlParams = new URLSearchParams(queryString);
const test = urlParams.get('test');
if (test == null && testm == true) {
    window.location.replace("tex.html");
};

// Основные переменные игры
let login = 0;
let score = 0;
let logreg = 0;
let userId = null;
let plus = 1;
let energy = 5000;
let lastEnergyUpdate = Date.now();
let energyLoaded = false;  // ИСПРАВЛЕНИЕ: Флаг для отслеживания, загружена ли энергия

// ИСПРАВЛЕНИЕ: Правильная инициализация energy_max
let energy_max = 5000; // Начальное значение по умолчанию
const ENERGY_REGEN_TIME = 15 * 60 * 1000; // 15 минут на полное восстановление

// ИСПРАВЛЕНИЕ: Правильный расчет регенерации энергии
let ENERGY_REGEN_PER_MS = (energy_max / 2) / ENERGY_REGEN_TIME; // Половина максимума за 15 минут

// Список скинов (не используется, для справки)
let skins = {
    skin1: 'assets/img/skins/bober.png',
    skin2: 'assets/img/skins/bumazny-bober.jpg',
    skin3: 'assets/img/skins/matvey-new-bober.jpg',
    skin4: 'assets/img/skins/klub-smz-bober.jpg',
    skin5: 'assets/img/skins/nosok-bober.jpg',
    skin6 : 'assets/img/skins/Shok-upok-bober.jpg',
    skin7: 'assets/img/skins/strany-bober.jpg',
};
let skin = [];

// ИСПРАВЛЕНИЕ 1: Улучшенная функция сохранения энергии с правильной обработкой ошибок
function saveEnergy() {
    if (!userId) return;
    
    // Логируем для отладки
    console.log('💾 Сохранение энергии:', {
        userId: userId,
        energy: Math.floor(energy),
        energy_max: energy_max,
        timestamp: new Date().toLocaleTimeString()
    });
    
    fetch('api/save-energy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            userId: userId, 
            energy: Math.floor(energy), 
            lastEnergyUpdate: lastEnergyUpdate, 
            ENERGY_MAX: energy_max 
        })
    })
    .then(response => {
        // Сначала проверяем статус ответа
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        // Затем пытаемся спарсить JSON
        return response.text().then(text => {
            if (!text) {
                throw new Error('Пустой ответ от сервера');
            }
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('❌ Ошибка парсинга JSON:', text);
                throw new Error(`Неверный формат JSON: ${text.substring(0, 100)}`);
            }
        });
    })
    .then(data => {
        if (data && data.success) {
            console.log('✅ Энергия успешно сохранена');
        } else {
            console.error('❌ Ошибка сохранения энергии (success=false):', data);
        }
    })
    .catch(error => {
        console.error('❌ Ошибка при сохранении энергии:', {
            message: error.message,
            name: error.name,
            stack: error.stack
        });
    });
}

// ИСПРАВЛЕНИЕ 2: Дебаунсинг для предотвращения спама запросов - 90 секунд
let saveEnergyTimeout = null;

// Функция с дебаунсингом - не сохраняет чаще чем раз в 90 секунд (1 мин 30 сек)
// Сохранение происходит только при клике, а не по таймеру
function saveEnergyDebounced() {
    if (saveEnergyTimeout) {
        clearTimeout(saveEnergyTimeout);
    }
    
    saveEnergyTimeout = setTimeout(() => {
        saveEnergy();
    }, 90000); // Задержка 90 секунд (1 минута 30 секунд)
}

setInterval(() => {
updateEnergy();
updateEnergyUI();
}, 2000)

// Восстановление энергии по времени (работает и оффлайн)
function updateEnergy() {
    const now = Date.now();
    let delta = now - lastEnergyUpdate;
    if (delta > 0) {
        let regen = delta * ENERGY_REGEN_PER_MS;
        energy = Math.min(energy_max, energy + regen);
        lastEnergyUpdate = now;
    }
}

// Функция обновления UI энергии
function updateEnergyUI() {
    document.getElementById('energy').innerText = Math.floor(energy);
}

// Покупка и установка скина
function buySkin(id, sum, tex) {
    if (userId) {
        if (skin[id] == true) {
            let result1 = confirm("Этот скин уже куплен. Хотите установить его?");
            if (result1 == true) {
                skin[0] = tex;
                skinLoad();
                saveskin(); // сохраняем сразу после установки
                showNotification("Скин успешно установлен!", "success");
            }
        } else {
            let result1a = confirm("Этот скин ёще не куплен. Хотите купить его?");
            if (result1a == true) {
                if (score > sum) {
                    skin[id] = true;
                    skin[0] = tex; // сразу устанавливаем купленный скин
                    score = score - sum;
                    skinLoad();
                    saveskin(); // сохраняем сразу после покупки и установки
                    saveScore(score);
                    showNotification("Успешная покука скина!", "success");
                } else {
                    showNotification("Недостаточно средств!", "error");
                }
            }
        }
    } else {
        showNotification("Пожалуйста, войдите в акаунт.", "error");
    }
}

// Установка скина на кнопку
function skinLoad() {
    document.getElementById('clicker').style.backgroundImage = 'url('+skin[0]+')'
};

// ИСПРАВЛЕНИЕ 3: Сохранение энергии при тач-событиях (мобильные)
document.getElementById('clicker').addEventListener('touchstart', function(e) {
    if (userId) {
        e.preventDefault();
        updateEnergy();
        let touches = e.touches.length;
        let totalCost = plus * touches;
        if (energy >= totalCost) {
            score += totalCost;
            energy -= totalCost;
            document.getElementById('score').innerText = 'Счет: ' + score;
            updateEnergyUI();
            
            // Сохраняем энергию с дебаунсингом 90 сек после тапа
            saveEnergyDebounced();
        } else {
            showNotification("Недостаточно энергии!", "error");
        }
    } else {
        showNotification("Пожалуйста, войдите в акаунт.", "error");
    }
});

if(/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
    const shopbutt = document.getElementById('shopButton');
    shopbutt.style.padding = '8px 39%'
}

// Периодическое восстановление энергии (онлайн)
const energyTimer = setInterval(() => {
    if (userId) {
        updateEnergy();
    }
}, 1000);

document.getElementById('shopExit').style.display = 'none';

// Открытие формы входа
document.getElementById('loginButton').onclick = function() {
    document.getElementById('loginForm').style.display = 'block';
    document.getElementById('registerForm').style.display = 'none';
};

// Открытие формы регистрации
document.getElementById('registerButton').onclick = function() {
    document.getElementById('registerForm').style.display = 'block';
    document.getElementById('loginForm').style.display = 'none';
};

// ИСПРАВЛЕНИЕ 4: Сохранение энергии при каждом клике (ПК)
document.getElementById('clicker').onclick = function() {
    if (userId) {
        updateEnergy();
        if (energy >= plus) {
            score += plus;
            energy -= plus;
            document.getElementById('score').innerText = 'Счет: ' + score;
            updateEnergyUI();
            
            // Сохраняем энергию с дебаунсингом 90 сек после клика
            saveEnergyDebounced();
        } else {
            showNotification("Недостаточно энергии!", "error");
        }
    } else {
        showNotification("Пожалуйста, войдите в акаунт.", "error");
    }
};

// Обработка входа пользователя
document.getElementById('submitLogin').onclick = function() {
    if (logreg == 0){
        const login = document.getElementById('login').value;
        logreg = 1
        const password = document.getElementById('password').value;
        fetch('api/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ login: login, password: password })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Данные от сервера при логине:', data);
            if (data.success) {
                userId = data.userId;
                score = data.score !== undefined ? data.score : 0;
                plus = data.plus !== undefined ? data.plus : 1;
                localStorage.setItem('login', login);
                localStorage.setItem('password', password);
                
                // Преобразуем строку skin в массив
                skin = JSON.parse(data.skin);
                
                // ИСПРАВЛЕНИЕ: Правильная загрузка energy_max с сервера
                energy_max = (data.ENERGY_MAX !== undefined && data.ENERGY_MAX > 0) ? data.ENERGY_MAX : 5000;
                
                // ИСПРАВЛЕНИЕ: Пересчитываем регенерацию с новым energy_max
                ENERGY_REGEN_PER_MS = (energy_max / 2) / ENERGY_REGEN_TIME;
                
                skinLoad();
                
                // ИСПРАВЛЕНИЕ: Правильная загрузка энергии - ТОЛЬКО ОДИН РАЗ
                if (!energyLoaded) {
                    if (data.energy !== undefined && data.energy > 0) {
                        energy = data.energy;
                        console.log('🔋 Энергия загружена с сервера:', energy);
                    } else {
                        energy = energy_max;
                        console.log('🔋 Энергия не найдена на сервере, установлена максимальная:', energy_max);
                    }
                    
                    // Восстанавливаем метку времени
                    lastEnergyUpdate = (data.lastEnergyUpdate !== undefined && data.lastEnergyUpdate > 0) 
                        ? data.lastEnergyUpdate 
                        : Date.now();
                    
                    energyLoaded = true;  // ИСПРАВЛЕНИЕ: Устанавливаем флаг, энергия загружена
                }
                
                // Обновляем UI
                document.getElementById('score').innerText = 'Счет: ' + score;
                updateEnergyUI();
                
                showNotification("Успешный вход!", "success");
                loadLeaderboard();
                document.getElementById('registerForm').style.display = 'none';
                document.getElementById('loginForm').style.display = 'none';
                document.getElementById('loginButton').style.display = 'none';
                document.getElementById('registerButton').style.display = 'none';
                if (login == "test"){document.getElementById('admin').style.display = 'block';};  
            } else {
                showNotification(data.message, "error")
            }
        });
    }
    else {
        showNotification('Пожалуйста, подожтите идёт загрузка!', "error");
    }
};

// Обновленная функция открытия магазина
function shopShow() {
    document.getElementById('shop').style.display = 'block';
    document.getElementById('shopButton').style.display = 'none';
    document.getElementById('shopExit').style.display = 'block';
    document.getElementById('clicerMex').style.display = 'none';
    document.getElementById('leaderboard').style.display = 'none';
    
    // Добавляем фиксированное позиционирование для кнопки выхода
    document.getElementById('shopExit').style.position = 'fixed';
    document.getElementById('shopExit').style.bottom = '20px';
    document.getElementById('shopExit').style.right = '20px';
    document.getElementById('shopExit').style.zIndex = '1000';
}

// Обновленная функция закрытия магазина
function shopClose() {
    document.getElementById('shop').style.display = 'none';
    document.getElementById('shopButton').style.display = 'block';
    document.getElementById('shopExit').style.display = 'none';
    document.getElementById('clicerMex').style.display = 'block';
    document.getElementById('leaderboard').style.display = 'block';
    
    // Сбрасываем стили кнопки выхода
    document.getElementById('shopExit').style.position = '';
    document.getElementById('shopExit').style.bottom = '';
    document.getElementById('shopExit').style.right = '';
    document.getElementById('shopExit').style.zIndex = '';
}

// ИСПРАВЛЕНИЕ 5: Обновление функции Save() для явного сохранения
function Save() {
    if (userId) {
        saveScore(score);
        // Принудительное сохранение энергии без дебаунса
        saveEnergy();
        showNotification("Успешное сохранение", "success");
    } else {
        showNotification("Пожалуйста, войдите в акаунт.", "error");
    }
}

// Покупка улучшения 1
function buyUpgrade1()
{
    //Функция покупки улудшения 1
    if (userId) {
        if (score > 5000) {
            plus = plus + 1
            score = score - 5000
            saveplus()
            showNotification("Успешная покупка улучшения!", "success");
        } else {
            showNotification(" Недостаточно средств!", "error");
        }
    } else {
        showNotification('Пожалуйста, войдите в систему!', "error");
    }
};

// Покупка улучшения 2
function buyUpgrade2()
{
    //Функция покупки улудшения 2
    if (userId) {
        if (score > 20000) {
            plus = plus + 5
            score = score - 20000
            saveplus()
            showNotification("Успешная покупка улучшения!", "success");
        } else {
            showNotification(" Недостаточно средств!", "error")
        }
    } else {
        showNotification('Пожалуйста, войдите в систему!', "error");
    }
};

// ИСПРАВЛЕНИЕ: Рабочая функция покупки улучшения энергии
function buyEnergyUpgrade()
{
    if (userId) {
        if (score >= 10000) {
            energy_max = energy_max + 1000; // Увеличиваем максимум
            score = score - 10000;
            
            // Пересчитываем скорость регенерации с новым максимумом
            ENERGY_REGEN_PER_MS = (energy_max / 2) / ENERGY_REGEN_TIME;
            
            // Сохраняем новые значения
            saveEnergy();
            saveScore(score);
            showNotification("Успешная покупка улучшения! Новый максимум: " + energy_max, "success");
        } else {
            showNotification("Недостаточно средств!", "error");
        }
    }
    else {
        showNotification('Пожалуйста, войдите в систему!', "error");
    }
}

// Сохранение значения plus
function saveplus()
{
    //Сохранение значения plus
    saveScore(score)
    fetch('api/save-plus.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ userId: userId, plus: plus })
    })
    .then(response => response.json())
    .then(data => console.log(data))
    .catch(error => console.error('Ошибка:', error));
};

// Сохранение значения skin
function saveskin()
{
    //Сохранение значения skin
    saveScore(score)
    fetch('api/save-skin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ userId: userId, skin: JSON.stringify(skin) }) // всегда строка!
    })
    .then(response => response.json())
    .then(data => console.log(data))
    .catch(error => console.error('Ошибка:', error));
};

// Обработка регистрации пользователя
document.getElementById('submitRegister').onclick = function() {
    const regLogin = document.getElementById('regLogin').value;
    const regPassword = document.getElementById('regPassword').value;
    fetch('api/register.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ login: regLogin, password: regPassword })
    })
    .then(response => response.json())
    .then(data => {
        showNotification(data.message, "success");
    });
};

document.getElementById('shop').style.display = 'none';

// Сохранение счета и скина на сервер
function saveScore(score) {
    fetch('api/save-score.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ userId: userId, score: score, skin: JSON.stringify(skin) }) // сериализация skin
    })
    .then(response => response.json())
    .then(data => console.log(data))
    .catch(error => console.error('Ошибка:', error));
}

// Загрузка таблицы лидеров
function loadLeaderboard() {
    fetch('api/leaderboard.php')
        .then(response => response.json())
        .then(data => {
            const leaderboard = document.getElementById('leaderboard');
            leaderboard.innerHTML = '<h2>Топ игроков</h2>';
            data.forEach(player => {
                leaderboard.innerHTML += `<p>Игрок с именем ${player.login} набрал ${player.score} в бобре,<br>а ты бы смог?</p>`;
            });
        });
}

window.onload = loadLeaderboard();

// ИСПРАВЛЕНИЕ 6: Сохранение энергии при закрытии страницы
// Сохраняем энергию при закрытии страницы или переходе
window.addEventListener('beforeunload', function(e) {
    if (userId) {
        // Принудительное сохранение без дебаунса при закрытии
        saveEnergy();
    }
});

// Дополнительное сохранение при потере фокуса страницы
document.addEventListener('visibilitychange', function() {
    if (document.hidden && userId) {
        saveEnergy();
    }
});