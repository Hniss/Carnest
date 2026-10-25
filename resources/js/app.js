// E-001 (2026-10-24) — un instantané Livewire refusé par le serveur (autre session, page restée
// trop longtemps sans échange, nouvelle version mise en ligne) répond 419. À la place de la
// fenêtre anglaise de Livewire, la page se recharge avec un message en français.
//
// Espace élève : le message en cours d'écriture n'est jamais perdu par un rechargement ou une
// reconnexion. Il est gardé dans le sessionStorage de l'onglet (effacé à la fermeture de
// l'onglet), rattaché à l'élève par une clé opaque (data-draft-key) : un autre élève qui se
// connecte dans le même onglet ne le voit jamais. Effacé dès l'envoi réussi, à la remise dans
// la case, à la déconnexion, et ignoré au-delà de 30 minutes.
const DRAFT_KEY = 'carenest:brouillon-chat';
const DRAFT_MAX_AGE_MS = 30 * 60 * 1000;

const chatInput = () => document.querySelector('[data-chat-input]');

const storage = {
    get() { try { return JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) { return null; } },
    set(v) { try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(v)); } catch (e) {} },
    clear() { try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) {} },
};

const keepDraft = () => {
    const input = chatInput();
    if (input && input.value.trim() !== '') {
        storage.set({ owner: input.dataset.draftKey || '', text: input.value, at: Date.now() });
    }
};

const showNotice = (message, atTop = false) => {
    const notice = document.createElement('div');
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');
    notice.textContent = message;
    Object.assign(notice.style, {
        position: 'fixed', [atTop ? 'top' : 'bottom']: atTop ? '88px' : '16px', left: '50%', transform: 'translateX(-50%)', zIndex: '50',
        background: '#1c1917', color: '#fff', font: '500 15px/1.45 system-ui, sans-serif', textAlign: 'center',
        padding: '12px 18px', borderRadius: '12px', boxShadow: '0 8px 24px rgba(0,0,0,.25)', maxWidth: '90vw',
    });
    document.body.appendChild(notice);
};

const restoreDraft = () => {
    const input = chatInput();
    if (!input) return; // page de connexion après une session perdue : le brouillon attend la case du chat
    const draft = storage.get();
    storage.clear();
    if (!draft || draft.owner !== (input.dataset.draftKey || '') || Date.now() - draft.at > DRAFT_MAX_AGE_MS) return;
    if (input.value.trim() !== '') return;
    input.value = draft.text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
};

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ succeed, fail }) => {
        // Avant chaque échange : si la session est perdue, le serveur renvoie vers la connexion
        // et le navigateur quitte la page sans que rien ne puisse s'exécuter après.
        keepDraft();

        succeed(() => setTimeout(() => {
            const input = chatInput();
            if (input && input.value.trim() === '') storage.clear();
        }, 0));

        fail(({ status, content, preventDefault }) => {
            if (status !== 419) return;
            preventDefault();

            if (chatInput()) {
                keepDraft();
                showNotice('Oups, la page s’est endormie. On la recharge, ton message est gardé.', true);
                setTimeout(() => window.location.reload(), 3500);
                return;
            }

            let message = 'Cette page a expiré. Elle va se recharger.';
            try {
                const parsed = JSON.parse(content);
                if (parsed && typeof parsed.message === 'string') message = parsed.message;
            } catch (e) {}
            showNotice(message);
            setTimeout(() => window.location.reload(), 2500);
        });
    });
});

document.addEventListener('livewire:initialized', restoreDraft);
// La connexion élève mène au chat par navigation Livewire (sans rechargement complet).
document.addEventListener('livewire:navigated', restoreDraft);

// Déconnexion volontaire de l'élève : rien ne reste dans l'onglet.
document.addEventListener('submit', (event) => {
    const action = event.target && event.target.getAttribute ? event.target.getAttribute('action') || '' : '';
    if (/\/(child\/)?logout$/.test(action)) storage.clear();
}, true);
