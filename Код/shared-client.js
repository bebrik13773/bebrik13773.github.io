(function(window) {
    'use strict';

    var DEVICE_BAN_STORAGE_KEY = 'bober_device_ban_info';
    var DEFAULT_FLY_COINS_PER_SCORE = 500;

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

    window.BoberSharedClient = Object.freeze({
        DEVICE_BAN_STORAGE_KEY: DEVICE_BAN_STORAGE_KEY,
        FLY_COINS_PER_SCORE: DEFAULT_FLY_COINS_PER_SCORE,
        normalizeBanInfo: normalizeBanInfo,
        getStoredDeviceBanEndTimestamp: getStoredDeviceBanEndTimestamp,
        isStoredDeviceBanActive: isStoredDeviceBanActive,
        clearStoredDeviceBanInfo: clearStoredDeviceBanInfo,
        readStoredDeviceBanInfo: readStoredDeviceBanInfo,
        persistStoredDeviceBanInfo: persistStoredDeviceBanInfo,
        extractBanPayload: extractBanPayload,
        requestJson: requestJson,
        calculateFlyRewardCoins: calculateFlyRewardCoins
    });
})(window);
