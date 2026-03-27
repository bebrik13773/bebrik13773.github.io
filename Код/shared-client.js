(function(window) {
    'use strict';

    var DEVICE_BAN_STORAGE_KEY = 'bober_device_ban_info';
    var DEFAULT_FLY_COINS_PER_SCORE = 500;
    var USER_SETTINGS_CACHE_KEY = 'bober_user_settings_cache';
    var DEFAULT_CLIENT_LOG_CONFIG = {
        dbName: 'bober_main_client_log',
        storeName: 'pending_events',
        dbVersion: 1,
        batchSize: 250,
        summaryStorageKey: 'bober_client_log_summary',
        deviceIdStorageKey: 'bober_client_log_device_id'
    };

    function defaultUserSettings() {
        return {
            audio: {
                musicEnabled: true,
                effectsEnabled: true,
                flyVolume: 100
            },
            vibration: {
                enabled: true,
                intensity: 'medium'
            },
            animations: {
                mode: 'full'
            },
            notifications: {
                enabled: true
            },
            effects: {
                quality: 'high'
            }
        };
    }

    function normalizeUserSettings(rawSettings) {
        var defaults = defaultUserSettings();
        var raw = rawSettings && typeof rawSettings === 'object' ? rawSettings : {};
        var vibrationIntensity = String((((raw.vibration || {}).intensity) || defaults.vibration.intensity)).trim();
        var animationsMode = String((((raw.animations || {}).mode) || defaults.animations.mode)).trim();
        var effectsQuality = String((((raw.effects || {}).quality) || defaults.effects.quality)).trim();

        if (['low', 'medium', 'high'].indexOf(vibrationIntensity) === -1) {
            vibrationIntensity = defaults.vibration.intensity;
        }

        if (['full', 'reduced', 'off'].indexOf(animationsMode) === -1) {
            animationsMode = defaults.animations.mode;
        }

        if (['low', 'medium', 'high'].indexOf(effectsQuality) === -1) {
            effectsQuality = defaults.effects.quality;
        }

        return {
            audio: {
                musicEnabled: !Object.prototype.hasOwnProperty.call(raw.audio || {}, 'musicEnabled')
                    ? defaults.audio.musicEnabled
                    : Boolean((raw.audio || {}).musicEnabled),
                effectsEnabled: !Object.prototype.hasOwnProperty.call(raw.audio || {}, 'effectsEnabled')
                    ? defaults.audio.effectsEnabled
                    : Boolean((raw.audio || {}).effectsEnabled),
                flyVolume: Math.max(0, Math.min(100, Number((((raw.audio || {}).flyVolume) || defaults.audio.flyVolume)) || 0))
            },
            vibration: {
                enabled: !Object.prototype.hasOwnProperty.call(raw.vibration || {}, 'enabled')
                    ? defaults.vibration.enabled
                    : Boolean((raw.vibration || {}).enabled),
                intensity: vibrationIntensity
            },
            animations: {
                mode: animationsMode
            },
            notifications: {
                enabled: !Object.prototype.hasOwnProperty.call(raw.notifications || {}, 'enabled')
                    ? defaults.notifications.enabled
                    : Boolean((raw.notifications || {}).enabled)
            },
            effects: {
                quality: effectsQuality
            }
        };
    }

    function readCachedUserSettings() {
        try {
            var raw = localStorage.getItem(USER_SETTINGS_CACHE_KEY);
            if (!raw) {
                return defaultUserSettings();
            }

            return normalizeUserSettings(JSON.parse(raw));
        } catch (error) {
            return defaultUserSettings();
        }
    }

    function persistCachedUserSettings(settings) {
        var normalized = normalizeUserSettings(settings);
        try {
            localStorage.setItem(USER_SETTINGS_CACHE_KEY, JSON.stringify(normalized));
        } catch (error) {
            console.warn('Не удалось сохранить кэш пользовательских настроек.', error);
        }

        return normalized;
    }

    function clearCachedUserSettings() {
        try {
            localStorage.removeItem(USER_SETTINGS_CACHE_KEY);
        } catch (error) {
            console.warn('Не удалось очистить кэш пользовательских настроек.', error);
        }
    }

    function normalizeBanInfo(rawBanInfo) {
        if (!rawBanInfo || typeof rawBanInfo !== 'object') {
            return null;
        }

        var banUntil = typeof rawBanInfo.banUntil === 'string' ? rawBanInfo.banUntil.trim() : '';
        var durationDays = Number.isFinite(Number(rawBanInfo.durationDays)) ? Math.max(0, Number(rawBanInfo.durationDays)) : 0;
        var storedAt = Number.isFinite(Number(rawBanInfo.storedAt)) ? Math.max(0, Number(rawBanInfo.storedAt)) : 0;
        var type = rawBanInfo.type === 'ip' ? 'ip' : 'account';
        var isPermanent = Boolean(rawBanInfo.isPermanent) || (banUntil !== '' && String(banUntil).startsWith('2099-'));

        return {
            type: type,
            reason: String(rawBanInfo.reason || (type === 'ip' ? 'Блокировка по IP' : 'Блокировка аккаунта')).trim(),
            message: String(rawBanInfo.message || '').trim(),
            banUntil: banUntil,
            durationDays: durationDays,
            isRepeat: Boolean(rawBanInfo.isRepeat),
            isPermanent: isPermanent,
            ipAddress: String(rawBanInfo.ipAddress || '').trim(),
            userAgent: String(rawBanInfo.userAgent || '').trim(),
            storedAt: storedAt
        };
    }

    function getStoredDeviceBanEndTimestamp(banInfo) {
        var normalizedBan = normalizeBanInfo(banInfo);
        if (!normalizedBan) {
            return 0;
        }

        if (normalizedBan.isPermanent) {
            return Number.POSITIVE_INFINITY;
        }

        if (normalizedBan.banUntil) {
            var parsedBanUntil = Date.parse(String(normalizedBan.banUntil).replace(' ', 'T'));
            if (Number.isFinite(parsedBanUntil) && parsedBanUntil > 0) {
                return parsedBanUntil;
            }
        }

        if (normalizedBan.durationDays > 0 && normalizedBan.storedAt > 0) {
            return normalizedBan.storedAt + normalizedBan.durationDays * 24 * 60 * 60 * 1000;
        }

        return 0;
    }

    function isStoredDeviceBanActive(banInfo) {
        var endTimestamp = getStoredDeviceBanEndTimestamp(banInfo);
        if (!endTimestamp) {
            return false;
        }

        return endTimestamp === Number.POSITIVE_INFINITY || endTimestamp > Date.now();
    }

    function clearStoredDeviceBanInfo() {
        try {
            localStorage.removeItem(DEVICE_BAN_STORAGE_KEY);
        } catch (error) {
            console.warn('Не удалось удалить локальную метку бана.', error);
        }
    }

    function readStoredDeviceBanInfo() {
        try {
            var rawBan = localStorage.getItem(DEVICE_BAN_STORAGE_KEY);
            if (!rawBan) {
                return null;
            }

            var parsedBan = JSON.parse(rawBan);
            var normalizedBan = normalizeBanInfo(parsedBan);
            if (!normalizedBan || !isStoredDeviceBanActive(normalizedBan)) {
                clearStoredDeviceBanInfo();
                return null;
            }

            return normalizedBan;
        } catch (error) {
            console.warn('Не удалось прочитать локальную метку бана.', error);
            clearStoredDeviceBanInfo();
            return null;
        }
    }

    function persistStoredDeviceBanInfo(banInfo) {
        var normalizedBan = normalizeBanInfo(banInfo);
        if (!normalizedBan) {
            return;
        }

        var storedBan = {
            type: normalizedBan.type,
            reason: normalizedBan.reason,
            message: normalizedBan.message,
            banUntil: normalizedBan.banUntil,
            durationDays: normalizedBan.durationDays,
            isRepeat: normalizedBan.isRepeat,
            isPermanent: normalizedBan.isPermanent,
            ipAddress: normalizedBan.ipAddress,
            userAgent: normalizedBan.userAgent || navigator.userAgent || '',
            storedAt: Date.now()
        };

        try {
            localStorage.setItem(DEVICE_BAN_STORAGE_KEY, JSON.stringify(storedBan));
        } catch (error) {
            console.warn('Не удалось сохранить локальную метку бана.', error);
        }
    }

    function extractBanPayload(errorOrPayload) {
        var payload = errorOrPayload && errorOrPayload.payload && typeof errorOrPayload.payload === 'object'
            ? errorOrPayload.payload
            : errorOrPayload;

        if (!payload || typeof payload !== 'object') {
            return null;
        }

        if (payload.ban && typeof payload.ban === 'object') {
            return normalizeBanInfo({
                type: payload.ban.type || 'account',
                reason: payload.ban.reason || 'Блокировка аккаунта',
                message: payload.ban.message || '',
                banUntil: payload.ban.banUntil || '',
                durationDays: Number(payload.ban.durationDays) > 0 ? Number(payload.ban.durationDays) : 0,
                isRepeat: Boolean(payload.ban.isRepeat),
                isPermanent: Boolean(payload.ban.isPermanent),
                ipAddress: payload.ban.ipAddress || '',
                userAgent: payload.ban.userAgent || ''
            });
        }

        if (payload.ipBan && typeof payload.ipBan === 'object') {
            return normalizeBanInfo({
                type: 'ip',
                reason: payload.ipBan.reason || 'Блокировка по IP',
                message: payload.ipBan.message || 'С этого IP временно ограничен доступ.',
                banUntil: payload.ipBan.banUntil || '',
                durationDays: Number(payload.ipBan.durationDays) > 0 ? Number(payload.ipBan.durationDays) : 0,
                isRepeat: Boolean(payload.ipBan.isRepeat),
                isPermanent: Boolean(payload.ipBan.isPermanent),
                ipAddress: payload.ipBan.ipAddress || '',
                userAgent: payload.ipBan.userAgent || ''
            });
        }

        return null;
    }

    function requestJson(url, options) {
        return fetch(url, Object.assign({
            credentials: 'include'
        }, options || {})).then(async function(response) {
            var data = null;

            try {
                data = await response.json();
            } catch (error) {
                throw new Error('Сервер вернул некорректный ответ.');
            }

            if (!response.ok) {
                var requestError = new Error(data && data.message ? data.message : 'HTTP ' + response.status);
                requestError.status = response.status;
                requestError.payload = data;
                throw requestError;
            }

            return data;
        });
    }

    function calculateFlyRewardCoins(scoreValue, coinsPerScore) {
        var normalizedScore = Math.max(0, Number(scoreValue) || 0);
        var normalizedRate = Math.max(0, Number(coinsPerScore) || DEFAULT_FLY_COINS_PER_SCORE);
        return normalizedScore * normalizedRate;
    }

    function formatSessionDateTime(value) {
        var normalizedValue = typeof value === 'string' ? value.trim() : '';
        if (!normalizedValue) {
            return 'неизвестно';
        }

        var parsedDate = new Date(normalizedValue.replace(' ', 'T'));
        if (Number.isNaN(parsedDate.getTime())) {
            return normalizedValue;
        }

        return parsedDate.toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function normalizeGameSessionInfo(rawSession) {
        if (!rawSession || typeof rawSession !== 'object') {
            return null;
        }

        var sessionId = Number(rawSession.sessionId);
        if (!Number.isFinite(sessionId) || sessionId < 1) {
            return null;
        }

        var browserLabel = String(rawSession.browserLabel || '').trim();
        var platformLabel = String(rawSession.platformLabel || '').trim();
        var deviceLabel = String(rawSession.deviceLabel || ((browserLabel + ' ' + platformLabel).trim()) || 'Устройство').trim();

        return {
            sessionId: sessionId,
            deviceLabel: deviceLabel || 'Устройство',
            browserLabel: browserLabel || 'Неизвестный браузер',
            platformLabel: platformLabel || 'Неизвестная платформа',
            ipAddress: String(rawSession.ipAddress || '').trim(),
            createdAt: String(rawSession.createdAt || '').trim(),
            lastSeenAt: String(rawSession.lastSeenAt || '').trim(),
            isCurrent: Boolean(rawSession.isCurrent)
        };
    }

    function normalizeSessionConflictPayload(payload) {
        if (!payload || typeof payload !== 'object' || !Array.isArray(payload.sessions)) {
            return null;
        }

        var hasSessions = payload.sessions.length > 0;
        var hasCurrentSession = payload.currentSession && typeof payload.currentSession === 'object';
        var hasIncomingSession = payload.incomingSession && typeof payload.incomingSession === 'object';
        var explicitConflict = payload.sessionConflict === true || payload.authenticatedConflict === true;

        if (!explicitConflict && !hasSessions) {
            return null;
        }

        if (!explicitConflict && !hasCurrentSession && !hasIncomingSession) {
            return null;
        }

        var isAuthenticatedConflict = payload.authenticatedConflict === true || (hasCurrentSession && !hasIncomingSession);
        var currentOrIncomingSession = isAuthenticatedConflict
            ? (hasCurrentSession ? payload.currentSession : null)
            : (hasIncomingSession ? payload.incomingSession : null);

        return {
            message: String(payload.message || 'Аккаунт уже открыт на другом устройстве.').trim(),
            mode: isAuthenticatedConflict ? 'authenticated' : 'login',
            sessions: payload.sessions.map(normalizeGameSessionInfo).filter(Boolean),
            incomingSession: {
                sessionId: Number(currentOrIncomingSession && currentOrIncomingSession.sessionId) > 0
                    ? Number(currentOrIncomingSession.sessionId)
                    : 0,
                deviceLabel: String((currentOrIncomingSession && currentOrIncomingSession.deviceLabel) || 'Это устройство').trim(),
                browserLabel: String((currentOrIncomingSession && currentOrIncomingSession.browserLabel) || '').trim(),
                platformLabel: String((currentOrIncomingSession && currentOrIncomingSession.platformLabel) || '').trim(),
                ipAddress: String((currentOrIncomingSession && currentOrIncomingSession.ipAddress) || '').trim(),
                createdAt: String((currentOrIncomingSession && currentOrIncomingSession.createdAt) || '').trim(),
                lastSeenAt: String((currentOrIncomingSession && currentOrIncomingSession.lastSeenAt) || '').trim()
            }
        };
    }

    function normalizeSessionEndedPayload(payload) {
        if (!payload || typeof payload !== 'object' || payload.sessionEnded !== true) {
            return null;
        }

        return {
            message: String(payload.message || 'Эта сессия завершена на другом устройстве. Войдите заново.').trim(),
            terminatedSession: normalizeGameSessionInfo(payload.terminatedSession)
        };
    }

    function safeCopyTextToClipboard(text) {
        var normalizedText = String(text || '');
        if (!normalizedText) {
            return Promise.resolve(false);
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(normalizedText)
                .then(function() {
                    return true;
                })
                .catch(function() {
                    return fallbackCopyText(normalizedText);
                });
        }

        return Promise.resolve(fallbackCopyText(normalizedText));
    }

    function fallbackCopyText(text) {
        try {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            var copied = document.execCommand('copy');
            textarea.remove();
            return copied;
        } catch (error) {
            return false;
        }
    }

    function createAntiCheatUtils() {
        function checkMobileDevice() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
                'ontouchstart' in window ||
                navigator.maxTouchPoints > 0;
        }

        function average(values) {
            if (!Array.isArray(values) || values.length === 0) {
                return 0;
            }

            return values.reduce(function(sum, value) {
                return sum + value;
            }, 0) / values.length;
        }

        function median(values) {
            if (!Array.isArray(values) || values.length === 0) {
                return 0;
            }

            var sorted = values.slice().sort(function(left, right) {
                return left - right;
            });
            var middle = Math.floor(sorted.length / 2);
            return sorted.length % 2 === 0
                ? (sorted[middle - 1] + sorted[middle]) / 2
                : sorted[middle];
        }

        function standardDeviation(values, averageValue) {
            if (!Array.isArray(values) || values.length === 0) {
                return 0;
            }

            var variance = values.reduce(function(sum, value) {
                return sum + Math.pow(value - averageValue, 2);
            }, 0) / values.length;

            return Math.sqrt(variance);
        }

        function calculateStableRatio(intervals, medianInterval, toleranceMs) {
            if (!Array.isArray(intervals) || intervals.length === 0) {
                return 0;
            }

            var stableIntervals = intervals.filter(function(interval) {
                return Math.abs(interval - medianInterval) <= toleranceMs;
            });
            return stableIntervals.length / intervals.length;
        }

        function calculateLongestStableStreak(intervals, medianInterval, toleranceMs) {
            var longest = 0;
            var current = 0;

            (intervals || []).forEach(function(interval) {
                if (Math.abs(interval - medianInterval) <= toleranceMs) {
                    current += 1;
                    longest = Math.max(longest, current);
                    return;
                }

                current = 0;
            });

            return longest;
        }

        function calculateDominantRatio(intervals) {
            if (!Array.isArray(intervals) || intervals.length === 0) {
                return 0;
            }

            var buckets = new Map();
            intervals.forEach(function(interval) {
                var bucket = Math.round(interval / 5) * 5;
                buckets.set(bucket, (buckets.get(bucket) || 0) + 1);
            });

            var dominant = 0;
            buckets.forEach(function(count) {
                dominant = Math.max(dominant, count);
            });

            return dominant / intervals.length;
        }

        function calculateRangeRatio(intervals, minimum, maximum) {
            if (!Array.isArray(intervals) || intervals.length === 0) {
                return 0;
            }

            var matched = intervals.filter(function(interval) {
                return interval >= minimum && interval <= maximum;
            });
            return matched.length / intervals.length;
        }

        return Object.freeze({
            checkMobileDevice: checkMobileDevice,
            average: average,
            median: median,
            standardDeviation: standardDeviation,
            calculateStableRatio: calculateStableRatio,
            calculateLongestStableStreak: calculateLongestStableStreak,
            calculateDominantRatio: calculateDominantRatio,
            calculateRangeRatio: calculateRangeRatio
        });
    }

    function createClientLogManager(rawConfig) {
        var config = Object.assign({}, DEFAULT_CLIENT_LOG_CONFIG, rawConfig || {});
        var state = {
            supported: typeof indexedDB !== 'undefined',
            dbPromise: null,
            readyPromise: null,
            memoryQueue: [],
            memoryIndex: new Set(),
            deviceId: '',
            clientSessionId: '',
            sequence: 0,
            lastBatchNumber: 0
        };

        function createRandomId(prefix) {
            var safePrefix = String(prefix || 'evt').replace(/[^a-z0-9_-]+/gi, '-').toLowerCase() || 'evt';
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return safePrefix + '-' + window.crypto.randomUUID();
            }

            var randomChunk = Math.random().toString(36).slice(2, 10);
            return safePrefix + '-' + Date.now().toString(36) + '-' + randomChunk;
        }

        function getDeviceId() {
            if (state.deviceId) {
                return state.deviceId;
            }

            try {
                var storedId = localStorage.getItem(config.deviceIdStorageKey);
                if (storedId) {
                    state.deviceId = storedId;
                    return storedId;
                }
            } catch (error) {
                console.warn('Не удалось прочитать deviceId клиентского лога.', error);
            }

            var generatedId = createRandomId('device');
            state.deviceId = generatedId;

            try {
                localStorage.setItem(config.deviceIdStorageKey, generatedId);
            } catch (error) {
                console.warn('Не удалось сохранить deviceId клиентского лога.', error);
            }

            return generatedId;
        }

        function getSessionId() {
            if (!state.clientSessionId) {
                state.clientSessionId = createRandomId('session');
            }

            return state.clientSessionId;
        }

        function updateSummaryDraft() {
            try {
                var pendingCount = state.memoryQueue.length;
                if (pendingCount < 1) {
                    localStorage.removeItem(config.summaryStorageKey);
                    return;
                }

                var lastItem = state.memoryQueue[state.memoryQueue.length - 1] || null;
                localStorage.setItem(config.summaryStorageKey, JSON.stringify({
                    pendingCount: pendingCount,
                    lastEventTs: lastItem ? Number(lastItem.clientTs || lastItem.createdAt || Date.now()) : Date.now()
                }));
            } catch (error) {
                console.warn('Не удалось обновить сводку клиентского лога.', error);
            }
        }

        function rememberEventInMemory(eventItem) {
            if (!eventItem || !eventItem.eventUid || state.memoryIndex.has(eventItem.eventUid)) {
                return;
            }

            state.memoryQueue.push(eventItem);
            state.memoryIndex.add(eventItem.eventUid);
            updateSummaryDraft();
        }

        function forgetEventsInMemory(eventUids) {
            var uidSet = new Set(Array.isArray(eventUids) ? eventUids.filter(Boolean) : []);
            if (uidSet.size === 0) {
                return;
            }

            state.memoryQueue = state.memoryQueue.filter(function(eventItem) {
                return !uidSet.has(eventItem.eventUid);
            });
            uidSet.forEach(function(eventUid) {
                state.memoryIndex.delete(eventUid);
            });
            updateSummaryDraft();
        }

        function openDatabase() {
            if (!state.supported) {
                return Promise.resolve(null);
            }

            if (state.dbPromise) {
                return state.dbPromise;
            }

            state.dbPromise = new Promise(function(resolve, reject) {
                var request = indexedDB.open(config.dbName, config.dbVersion);

                request.onupgradeneeded = function() {
                    var db = request.result;
                    if (!db.objectStoreNames.contains(config.storeName)) {
                        var store = db.createObjectStore(config.storeName, { keyPath: 'eventUid' });
                        store.createIndex('userIdHint', 'userIdHint', { unique: false });
                        store.createIndex('createdAt', 'createdAt', { unique: false });
                    }
                };

                request.onsuccess = function() {
                    resolve(request.result);
                };

                request.onerror = function() {
                    reject(request.error || new Error('Не удалось открыть IndexedDB для клиентского лога.'));
                };
            }).catch(function(error) {
                console.warn('IndexedDB для клиентского лога недоступна, работаем без устойчивой очереди.', error);
                state.supported = false;
                return null;
            });

            return state.dbPromise;
        }

        function readAllEventsFromDb(db) {
            if (!db) {
                return Promise.resolve([]);
            }

            return new Promise(function(resolve, reject) {
                var transaction = db.transaction(config.storeName, 'readonly');
                var store = transaction.objectStore(config.storeName);

                if (typeof store.getAll === 'function') {
                    var request = store.getAll();
                    request.onsuccess = function() {
                        resolve(Array.isArray(request.result) ? request.result : []);
                    };
                    request.onerror = function() {
                        reject(request.error || new Error('Не удалось прочитать клиентский лог из IndexedDB.'));
                    };
                    return;
                }

                var items = [];
                var cursorRequest = store.openCursor();
                cursorRequest.onsuccess = function() {
                    var cursor = cursorRequest.result;
                    if (!cursor) {
                        resolve(items);
                        return;
                    }

                    items.push(cursor.value);
                    cursor.continue();
                };
                cursorRequest.onerror = function() {
                    reject(cursorRequest.error || new Error('Не удалось пройтись по IndexedDB клиентского лога.'));
                };
            });
        }

        function ensureReady() {
            if (state.readyPromise) {
                return state.readyPromise;
            }

            state.readyPromise = (async function() {
                getDeviceId();
                getSessionId();

                if (!state.supported) {
                    updateSummaryDraft();
                    return true;
                }

                var db = await openDatabase();
                var storedItems = await readAllEventsFromDb(db);
                storedItems
                    .sort(function(left, right) {
                        var leftSequence = Number(left.sequence || 0);
                        var rightSequence = Number(right.sequence || 0);
                        if (leftSequence !== rightSequence) {
                            return leftSequence - rightSequence;
                        }

                        return Number(left.createdAt || 0) - Number(right.createdAt || 0);
                    })
                    .forEach(function(eventItem) {
                        rememberEventInMemory(eventItem);
                    });

                return true;
            })();

            return state.readyPromise;
        }

        function persistEvent(eventItem) {
            return openDatabase().then(function(db) {
                if (!db) {
                    return false;
                }

                return new Promise(function(resolve, reject) {
                    var transaction = db.transaction(config.storeName, 'readwrite');
                    var store = transaction.objectStore(config.storeName);
                    var request = store.put(eventItem);

                    request.onsuccess = function() {
                        resolve(true);
                    };
                    request.onerror = function() {
                        reject(request.error || new Error('Не удалось сохранить событие клиентского лога.'));
                    };
                });
            });
        }

        function removeEventsFromDb(eventUids) {
            return openDatabase().then(function(db) {
                if (!db) {
                    return false;
                }

                var normalizedUids = Array.isArray(eventUids) ? eventUids.filter(Boolean) : [];
                if (normalizedUids.length === 0) {
                    return true;
                }

                return new Promise(function(resolve, reject) {
                    var transaction = db.transaction(config.storeName, 'readwrite');
                    var store = transaction.objectStore(config.storeName);

                    normalizedUids.forEach(function(eventUid) {
                        store.delete(eventUid);
                    });

                    transaction.oncomplete = function() {
                        resolve(true);
                    };
                    transaction.onerror = function() {
                        reject(transaction.error || new Error('Не удалось удалить отправленные события клиентского лога.'));
                    };
                });
            });
        }

        function createEvent(group, type, payload, options) {
            var safeOptions = options && typeof options === 'object' ? options : {};
            var nextSequence = Math.max(1, state.sequence + 1);
            var safePayload = payload && typeof payload === 'object' ? payload : {};

            state.sequence = nextSequence;

            return {
                eventUid: getDeviceId() + '-' + getSessionId() + '-' + nextSequence + '-' + Date.now().toString(36),
                createdAt: Date.now(),
                deviceId: getDeviceId(),
                clientSessionId: getSessionId(),
                userIdHint: Math.max(0, Number(safeOptions.userIdHint) || 0),
                loginHint: String(safeOptions.loginHint || '').trim(),
                sequence: nextSequence,
                clientTs: Math.max(0, Number(safeOptions.clientTs || Date.now()) || Date.now()),
                page: String(safeOptions.page || 'main_clicker'),
                group: String(group || 'general'),
                type: String(type || 'event'),
                source: String(safeOptions.source || 'client'),
                description: typeof safeOptions.description === 'string' ? safeOptions.description : '',
                scoreSnapshot: Math.max(0, Math.floor(Number(safeOptions.scoreSnapshot) || 0)),
                energySnapshot: Math.max(0, Math.floor(Number(safeOptions.energySnapshot) || 0)),
                plusSnapshot: Math.max(0, Math.floor(Number(safeOptions.plusSnapshot) || 0)),
                payload: safePayload
            };
        }

        function logEvent(group, type, payload, options) {
            var eventItem = createEvent(group, type, payload, options);
            rememberEventInMemory(eventItem);

            if (state.supported) {
                persistEvent(eventItem).catch(function(error) {
                    console.warn('Не удалось записать событие клиентского лога в IndexedDB.', error);
                });
            }

            return eventItem;
        }

        function getEligibleEvents(mode, loginValue, currentUserId) {
            var normalizedLogin = String(loginValue || '').trim().toLowerCase();
            var safeUserId = Math.max(0, Number(currentUserId) || 0);

            return state.memoryQueue
                .slice()
                .sort(function(left, right) {
                    var leftSequence = Number(left.sequence || 0);
                    var rightSequence = Number(right.sequence || 0);
                    if (leftSequence !== rightSequence) {
                        return leftSequence - rightSequence;
                    }

                    return Number(left.createdAt || 0) - Number(right.createdAt || 0);
                })
                .filter(function(eventItem) {
                    var eventUserId = Math.max(0, Number(eventItem.userIdHint || 0));
                    var eventLogin = String(eventItem.loginHint || '').trim().toLowerCase();

                    if (mode === 'login') {
                        return eventUserId === 0 && (normalizedLogin === '' || eventLogin === '' || eventLogin === normalizedLogin);
                    }

                    if (safeUserId < 1) {
                        return false;
                    }

                    if (eventUserId > 0) {
                        return eventUserId === safeUserId;
                    }

                    return normalizedLogin === '' || eventLogin === '' || eventLogin === normalizedLogin;
                });
        }

        function prepareBatchFromMemory(options) {
            var safeOptions = options && typeof options === 'object' ? options : {};
            var mode = String(safeOptions.mode || 'auth');
            var loginValue = String(safeOptions.login || '');
            var currentUserId = Math.max(0, Number(safeOptions.currentUserId) || 0);
            var limit = Math.max(1, Math.min(1000, Number(safeOptions.limit || config.batchSize) || config.batchSize));
            var eligibleEvents = getEligibleEvents(mode, loginValue, currentUserId);

            if (eligibleEvents.length === 0) {
                return null;
            }

            var selectedEvents = eligibleEvents.slice(0, limit);
            state.lastBatchNumber += 1;

            return {
                eventUids: selectedEvents.map(function(eventItem) {
                    return eventItem.eventUid;
                }),
                hasMore: eligibleEvents.length > selectedEvents.length,
                payload: {
                    deviceId: getDeviceId(),
                    clientSessionId: getSessionId(),
                    batchId: getDeviceId() + '-batch-' + state.lastBatchNumber + '-' + Date.now().toString(36),
                    events: selectedEvents.map(function(eventItem) {
                        return {
                            eventUid: eventItem.eventUid,
                            deviceId: eventItem.deviceId,
                            clientSessionId: eventItem.clientSessionId,
                            sequence: Number(eventItem.sequence || 0),
                            clientTs: Number(eventItem.clientTs || 0),
                            page: String(eventItem.page || 'main_clicker'),
                            group: String(eventItem.group || 'general'),
                            type: String(eventItem.type || 'event'),
                            source: String(eventItem.source || 'client'),
                            description: String(eventItem.description || ''),
                            scoreSnapshot: Math.max(0, Number(eventItem.scoreSnapshot || 0)),
                            energySnapshot: Math.max(0, Number(eventItem.energySnapshot || 0)),
                            plusSnapshot: Math.max(0, Number(eventItem.plusSnapshot || 0)),
                            payload: eventItem.payload && typeof eventItem.payload === 'object' ? eventItem.payload : {}
                        };
                    })
                }
            };
        }

        async function prepareBatch(options) {
            await ensureReady();
            return prepareBatchFromMemory(options);
        }

        async function acknowledgeBatch(preparedBatch) {
            if (!preparedBatch || !Array.isArray(preparedBatch.eventUids) || preparedBatch.eventUids.length === 0) {
                return false;
            }

            forgetEventsInMemory(preparedBatch.eventUids);
            if (!state.supported) {
                return true;
            }

            try {
                await removeEventsFromDb(preparedBatch.eventUids);
                return true;
            } catch (error) {
                console.warn('Не удалось удалить уже отправленные события клиентского лога из IndexedDB.', error);
                return false;
            }
        }

        async function clearPendingEvents() {
            state.memoryQueue = [];
            state.memoryIndex.clear();
            updateSummaryDraft();

            if (!state.supported) {
                return true;
            }

            var db = await openDatabase();
            if (!db) {
                return true;
            }

            return new Promise(function(resolve, reject) {
                var transaction = db.transaction(config.storeName, 'readwrite');
                var store = transaction.objectStore(config.storeName);
                var request = store.clear();
                request.onsuccess = function() {
                    resolve(true);
                };
                request.onerror = function() {
                    reject(request.error || new Error('Не удалось очистить локальную очередь клиентского лога.'));
                };
            });
        }

        return {
            config: config,
            state: state,
            ensureReady: ensureReady,
            getDeviceId: getDeviceId,
            getSessionId: getSessionId,
            createRandomId: createRandomId,
            createEvent: createEvent,
            logEvent: logEvent,
            prepareBatchFromMemory: prepareBatchFromMemory,
            prepareBatch: prepareBatch,
            acknowledgeBatch: acknowledgeBatch,
            clearPendingEvents: clearPendingEvents
        };
    }

    var antiCheatUtils = createAntiCheatUtils();

    window.BoberSharedClient = Object.freeze({
        DEVICE_BAN_STORAGE_KEY: DEVICE_BAN_STORAGE_KEY,
        USER_SETTINGS_CACHE_KEY: USER_SETTINGS_CACHE_KEY,
        FLY_COINS_PER_SCORE: DEFAULT_FLY_COINS_PER_SCORE,
        DEFAULT_CLIENT_LOG_CONFIG: Object.freeze(Object.assign({}, DEFAULT_CLIENT_LOG_CONFIG)),
        defaultUserSettings: defaultUserSettings,
        normalizeUserSettings: normalizeUserSettings,
        readCachedUserSettings: readCachedUserSettings,
        persistCachedUserSettings: persistCachedUserSettings,
        clearCachedUserSettings: clearCachedUserSettings,
        normalizeBanInfo: normalizeBanInfo,
        getStoredDeviceBanEndTimestamp: getStoredDeviceBanEndTimestamp,
        isStoredDeviceBanActive: isStoredDeviceBanActive,
        clearStoredDeviceBanInfo: clearStoredDeviceBanInfo,
        readStoredDeviceBanInfo: readStoredDeviceBanInfo,
        persistStoredDeviceBanInfo: persistStoredDeviceBanInfo,
        extractBanPayload: extractBanPayload,
        requestJson: requestJson,
        calculateFlyRewardCoins: calculateFlyRewardCoins,
        formatSessionDateTime: formatSessionDateTime,
        normalizeGameSessionInfo: normalizeGameSessionInfo,
        normalizeSessionConflictPayload: normalizeSessionConflictPayload,
        normalizeSessionEndedPayload: normalizeSessionEndedPayload,
        safeCopyTextToClipboard: safeCopyTextToClipboard,
        antiCheatUtils: antiCheatUtils,
        createClientLogManager: createClientLogManager
    });
})(window);
