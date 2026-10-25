// E-001 (2026-10-24) — un instantané Livewire refusé par le serveur (autre session, page restée
// trop longtemps sans échange, nouvelle version mise en ligne) répond 419. À la place de la
// fenêtre anglaise de Livewire, la page se recharge avec un message en français.
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, content, preventDefault }) => {
            if (status !== 419) return;
            preventDefault();

            let message = 'Cette page a expiré. Elle va se recharger.';
            try {
                const parsed = JSON.parse(content);
                if (parsed && typeof parsed.message === 'string') message = parsed.message;
            } catch (e) {}

            const notice = document.createElement('div');
            notice.setAttribute('role', 'status');
            notice.textContent = message;
            Object.assign(notice.style, {
                position: 'fixed', bottom: '16px', left: '50%', transform: 'translateX(-50%)', zIndex: '50',
                background: '#1c1917', color: '#fff', font: '500 14px/1.4 system-ui, sans-serif',
                padding: '12px 16px', borderRadius: '10px', boxShadow: '0 8px 24px rgba(0,0,0,.25)', maxWidth: '90vw',
            });
            document.body.appendChild(notice);

            setTimeout(() => window.location.reload(), 1200);
        });
    });
});
