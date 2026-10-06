import { ERRORS, OLD_GAME_URL } from '../config.js';
import { logClient } from '../net/clientlog.js';

// Экран ошибки и глобальные обработчики сбоев.
export function createErrorUi({ onFatal, notice }) {
    const root = document.getElementById('wfError');
    const title = document.getElementById('wfErrorTitle');
    const text = document.getElementById('wfErrorText');
    const detail = document.getElementById('wfErrorDetail');
    const actions = document.getElementById('wfErrorActions');
    const recent = [];
    let fatalShown = false;

    function makeAction(label, handler, href) {
        const el = document.createElement(href ? 'a' : 'button');
        el.className = 'wf-btn';
        el.textContent = label;
        if (href) {
            el.href = href;
            el.style.textDecoration = 'none';
            el.style.display = 'inline-flex';
            el.style.alignItems = 'center';
        } else {
            el.type = 'button';
            el.addEventListener('click', handler);
        }
        return el;
    }

    function showFatal({ heading, message, technical = '', withReload = true, withOldGame = true }) {
        fatalShown = true;
        title.textContent = heading;
        text.textContent = message;
        detail.textContent = technical;
        detail.hidden = !technical;
        actions.textContent = '';
        if (withReload) actions.appendChild(makeAction('Перезагрузить', () => location.reload()));
        if (withOldGame) {
            const link = makeAction('Старый кликер', null, OLD_GAME_URL);
            link.classList.add('is-ghost');
            actions.appendChild(link);
        }
        root.classList.remove('is-hidden');
        if (onFatal) onFatal(heading);
    }

    function report(kind, error) {
        const message = error && error.message ? error.message : String(error || kind);
        const stack = error && error.stack ? error.stack : '';
        logClient(kind, message, stack);

        const now = Date.now();
        recent.push(now);
        while (recent.length && now - recent[0] > ERRORS.windowMs) recent.shift();

        if (recent.length >= ERRORS.fatalCount && !fatalShown) {
            showFatal({
                heading: 'Игра сломалась',
                message: 'Слишком много ошибок подряд. Попробуй перезагрузить страницу.',
                technical: message,
            });
            return;
        }
        if (!fatalShown && notice) {
            notice.show({ text: 'Небольшой сбой в игре. Если повторяется — перезагрузи страницу.', danger: true, ttlMs: 4000 });
        }
    }

    function install() {
        window.addEventListener('error', (event) => report('error', event.error || event.message));
        window.addEventListener('unhandledrejection', (event) => report('promise', event.reason));
    }

    return {
        install,
        showFatal,
        report,
        get fatalShown() { return fatalShown; },
        noWebGL() {
            showFatal({
                heading: 'Нет поддержки 3D',
                message: 'Ваше устройство или браузер не поддерживает 3D-графику (WebGL), поэтому Дикий Лес не запустится. Можно поиграть в старый Бобёр Кликер.',
                withReload: false,
            });
        },
    };
}
