import { S } from './strings.ru.js';
import { createTypewriter, normalizeLine, createChoiceTimer, createLineQueue } from './dialog-logic.js';

// Компонент диалога (ДЛ-09): говорящий, портрет, текст по буквам (тап показывает всё сразу), 2–4 варианта ответа, таймаут, очередь.
// Нужен наставнику (ДЛ-15), торгу (ДЛ-25), помощникам (ДЛ-33) и NPC города (ДЛ-23).
// say() возвращает Promise с выбранным вариантом: { id, label, timedOut }. Реплика без вариантов закрывается тапом по «Дальше».
export function createDialog({ onOpenChange = () => {} } = {}) {
    const $ = (id) => document.getElementById(id);
    const root = $('wfDialog');
    const portraitEl = $('wfDlgPortrait');
    const speakerEl = $('wfDlgSpeaker');
    const textEl = $('wfDlgText');
    const choicesEl = $('wfDlgChoices');
    const timerEl = $('wfDlgTimer');
    const timerFill = $('wfDlgTimerFill');
    const hintEl = $('wfDlgHint');

    const queue = createLineQueue();
    let current = null; // { line, typer, timer, resolve, choicesShown }
    let raf = 0;
    let lastTs = 0;

    function setOpen(open) {
        root.classList.toggle('is-open', open);
        root.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) document.documentElement.dataset.wfModal = 'dialog';
        else if (document.documentElement.dataset.wfModal === 'dialog') delete document.documentElement.dataset.wfModal;
        onOpenChange(open);
    }

    function finish(choice) {
        if (!current) return;
        const { resolve } = current;
        current = null;
        cancelAnimationFrame(raf);
        raf = 0;
        resolve(choice);
        next();
    }

    function showChoices() {
        if (!current || current.choicesShown) return;
        current.choicesShown = true;
        hintEl.hidden = true;
        choicesEl.textContent = '';
        const { line } = current;
        const list = line.choices.length ? line.choices : [{ id: 'next', label: S.dialog.next }];
        list.forEach((choice, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wf-btn wf-choice';
            b.dataset.id = String(choice.id);
            b.textContent = line.choices.length ? `${i + 1}. ${choice.label}` : choice.label;
            b.addEventListener('click', (event) => {
                event.stopPropagation();
                finish({ id: choice.id, label: choice.label, timedOut: false });
            });
            choicesEl.appendChild(b);
        });
        if (line.timeoutMs > 0) {
            timerEl.hidden = false;
            current.timer.start();
        }
        const first = choicesEl.firstChild;
        if (first && first.focus) first.focus({ preventScroll: true });
    }

    function frame(ts) {
        raf = 0;
        if (!current) return;
        const dt = Math.min(0.1, Math.max(0, (ts - (lastTs || ts)) / 1000));
        lastTs = ts;
        if (!current.typer.done) {
            if (current.typer.reveal(dt)) textEl.textContent = current.typer.text;
            if (current.typer.done) showChoices();
        } else if (current.timer.tick(dt)) {
            const { line } = current;
            const pick = line.choices.find((c) => c.id === line.defaultId) || line.choices[line.choices.length - 1];
            finish({ id: pick ? pick.id : 'timeout', label: pick ? pick.label : '', timedOut: true });
            return;
        }
        if (current && current.line.timeoutMs > 0) timerFill.style.width = `${Math.round(current.timer.share * 100)}%`;
        raf = requestAnimationFrame(frame);
    }

    function next() {
        const entry = queue.shift();
        if (!entry) { setOpen(false); return; }
        const line = normalizeLine(entry.line);
        current = { line, typer: createTypewriter(line.text, line.cps), timer: createChoiceTimer(line.timeoutMs), resolve: entry.resolve, choicesShown: false };
        portraitEl.textContent = line.portrait || '🦫';
        speakerEl.textContent = line.speaker;
        speakerEl.hidden = !line.speaker;
        textEl.textContent = '';
        choicesEl.textContent = '';
        timerEl.hidden = true;
        timerFill.style.width = '100%';
        hintEl.hidden = false;
        hintEl.textContent = S.dialog.skipHint;
        setOpen(true);
        lastTs = 0;
        if (!line.text) { current.typer.skip(); showChoices(); }
        raf = requestAnimationFrame(frame);
    }

    // Тап по тексту: показать всё сразу.
    root.addEventListener('click', () => {
        if (current && !current.typer.done) {
            current.typer.skip();
            textEl.textContent = current.typer.text;
            showChoices();
        }
    });
    window.addEventListener('keydown', (event) => {
        if (!current) return;
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (!current.typer.done) { current.typer.skip(); textEl.textContent = current.typer.text; showChoices(); }
            else if (!current.line.choices.length) finish({ id: 'next', label: S.dialog.next, timedOut: false });
        } else if (event.key >= '1' && event.key <= '4' && current.choicesShown) {
            const c = current.line.choices[Number(event.key) - 1];
            if (c) finish({ id: c.id, label: c.label, timedOut: false });
        }
    });

    return {
        say(line) {
            return new Promise((resolve) => {
                queue.push({ line, resolve });
                if (!current) next();
            });
        },
        /** Закрыть всё: текущая реплика и очередь завершаются с { id: 'cancel' }. */
        cancelAll() {
            const waiting = [];
            let entry = queue.shift();
            while (entry) { waiting.push(entry); entry = queue.shift(); }
            waiting.forEach((e) => e.resolve({ id: 'cancel', label: '', timedOut: false }));
            finish({ id: 'cancel', label: '', timedOut: false });
            setOpen(false);
        },
        get isOpen() { return Boolean(current); },
        get queued() { return queue.size; },
    };
}
