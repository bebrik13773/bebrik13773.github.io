import { BETA, BETA_NOTICE_TTL_MS } from './config.js';
import { renderBuildId, startAutoUpdate } from './update.js';

const buildEl = document.getElementById('wfBuild');
const statusEl = document.getElementById('wfStatus');
const modal = document.getElementById('wfBetaModal');
const openBtn = document.getElementById('wfBetaOpen');
const ackBtn = document.getElementById('wfBetaAck');
const ACK_KEY = 'wf.betaAckAt';

renderBuildId(buildEl, statusEl);
startAutoUpdate(buildEl, statusEl);

function setModal(open) {
    modal.classList.toggle('is-open', open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (open) ackBtn.focus();
}

function betaNoticeIsFresh() {
    try {
        const at = Number(localStorage.getItem(ACK_KEY) || 0);
        return at > 0 && Date.now() - at < BETA_NOTICE_TTL_MS;
    } catch (e) {
        return false;
    }
}

ackBtn.addEventListener('click', () => {
    try {
        localStorage.setItem(ACK_KEY, String(Date.now()));
    } catch (e) { /* без хранилища окно просто покажется снова */ }
    setModal(false);
});
openBtn.addEventListener('click', () => setModal(true));
modal.addEventListener('click', (event) => {
    if (event.target === modal) setModal(false);
});

if (!BETA) {
    openBtn.hidden = true;
    document.querySelector('.wf-badge').hidden = true;
} else if (!betaNoticeIsFresh()) {
    setModal(true);
}
