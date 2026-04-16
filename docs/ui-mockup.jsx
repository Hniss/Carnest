import { useState, useEffect, useRef } from "react";

// ─── Mock Data ────────────────────────────────────────────────────────────────
const MOCK_ACCOUNTS = [
  { email: "enfant@carenest.ma", password: "demo123", role: "child", name: "Yassine", age: 10, classe: "CM2" },
  { email: "admin@carenest.ma",  password: "admin123", role: "admin", name: "Mme Benali", school: "École Agdal" },
];

const MOCK_ALERTS = [
  { id: 1, child: "Amina", classe: "CE2", age: 8,  date: "2026-04-13", time: "09:14", level: "critical", type: "Harcèlement", summary: "Signaux de harcèlement répété détectés lors de la session. L'enfant a évoqué des moqueries régulières à l'heure du déjeuner.", status: "unread" },
  { id: 2, child: "Omar",  classe: "5ème", age: 11, date: "2026-04-13", time: "11:32", level: "critical", type: "Détresse",    summary: "Expression d'un sentiment d'isolement social intense et de tristesse persistante. Vocabulaire émotionnel négatif dominant.", status: "unread" },
  { id: 3, child: "Sara",  classe: "CM1", age: 9,  date: "2026-04-12", time: "14:05", level: "moderate", type: "Stress",       summary: "Stress élevé lié aux examens, anxiété exprimée concernant les résultats scolaires.", status: "read" },
  { id: 4, child: "Karim", classe: "6ème", age: 12, date: "2026-04-11", time: "10:20", level: "moderate", type: "Tristesse",   summary: "Tristesse exprimée suite à un conflit avec des camarades de classe.", status: "resolved" },
];

const EMOTION_DATA = [
  { label: "Positif", value: 42, color: "#2ECC9A" },
  { label: "Neutre", value: 31, color: "#94A3B8" },
  { label: "Légèrement négatif", value: 18, color: "#F59E0B" },
  { label: "Négatif", value: 9, color: "#EF4444" },
];

// ─── Styles (injected once) ───────────────────────────────────────────────────
const STYLES = `
  @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --teal:       #1A7F6B;
    --teal-mid:   #2ECC9A;
    --teal-light: #E8F5F2;
    --teal-xlight:#F0FAF7;
    --red:        #EF4444;
    --orange:     #F59E0B;
    --bg:         #F7FAFA;
    --card:       #FFFFFF;
    --text:       #1A2E2A;
    --muted:      #64748B;
    --border:     #E2EDE9;
    --shadow:     0 4px 24px rgba(26,127,107,0.10);
    --shadow-sm:  0 2px 8px rgba(26,127,107,0.07);
    --radius:     16px;
    --radius-sm:  10px;
    font-family: 'DM Sans', sans-serif;
  }

  body { background: var(--bg); color: var(--text); min-height: 100vh; }

  /* ── Login ── */
  .login-wrap {
    min-height: 100vh;
    background: linear-gradient(135deg, #1A7F6B 0%, #2ECC9A 100%);
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
  }
  .login-card {
    background: white; border-radius: 24px;
    padding: 40px 36px; width: 100%; max-width: 420px;
    box-shadow: 0 20px 60px rgba(26,127,107,0.25);
    animation: fadeUp .4s ease both;
  }
  .login-logo { font-family:'Nunito',sans-serif; font-size:28px; font-weight:800; color:var(--teal); text-align:center; }
  .login-tagline { text-align:center; color:var(--muted); font-style:italic; margin:6px 0 28px; font-size:14px; }
  .login-label { font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px; display:block; }
  .login-input {
    width:100%; padding:12px 14px; border:2px solid var(--border); border-radius:var(--radius-sm);
    font-size:15px; outline:none; transition:.2s; font-family:inherit; color:var(--text);
  }
  .login-input:focus { border-color:var(--teal); }
  .login-field { margin-bottom:18px; position:relative; }
  .eye-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--muted); font-size:18px; padding:4px; }
  .login-btn {
    width:100%; padding:14px; background:var(--teal); color:white; border:none; border-radius:var(--radius-sm);
    font-size:16px; font-weight:700; cursor:pointer; transition:.2s; font-family:inherit; margin-top:4px;
    display:flex; align-items:center; justify-content:center; gap:8px;
  }
  .login-btn:hover { background:#15674f; }
  .login-btn:disabled { opacity:.7; cursor:not-allowed; }
  .login-error { background:#FEF2F2; border:1px solid #FECACA; color:#DC2626; border-radius:8px; padding:10px 14px; font-size:14px; margin-top:12px; }
  .demo-hint { margin-top:20px; padding:14px; background:var(--teal-xlight); border-radius:var(--radius-sm); font-size:13px; color:var(--muted); line-height:1.6; }
  .demo-hint b { color:var(--teal); }

  /* ── Chat ── */
  .chat-wrap { min-height:100vh; background:var(--bg); display:flex; flex-direction:column; }
  .chat-header {
    background:white; border-bottom:1px solid var(--border);
    padding:14px 20px; display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:10; box-shadow:var(--shadow-sm);
  }
  .chat-logo { font-family:'Nunito',sans-serif; font-weight:800; font-size:20px; color:var(--teal); }
  .logout-btn { background:none; border:1px solid var(--border); padding:7px 14px; border-radius:8px; color:var(--muted); font-size:13px; cursor:pointer; transition:.2s; font-family:inherit; }
  .logout-btn:hover { border-color:var(--teal); color:var(--teal); }
  .chat-messages { flex:1; overflow-y:auto; padding:24px 20px 100px; max-width:720px; width:100%; margin:0 auto; }
  .msg-row { display:flex; margin-bottom:16px; gap:10px; animation:fadeUp .3s ease both; }
  .msg-row.child { flex-direction:row-reverse; }
  .avatar { width:36px; height:36px; border-radius:50%; flex-shrink:0; background:var(--teal); display:flex; align-items:center; justify-content:center; font-size:16px; }
  .bubble {
    max-width:70%; padding:12px 16px; border-radius:18px; font-size:15px; line-height:1.6;
    font-family:'Nunito',sans-serif;
  }
  .bubble.bot { background:var(--teal-light); color:var(--text); border-bottom-left-radius:4px; }
  .bubble.child { background:var(--teal); color:white; border-bottom-right-radius:4px; }
  .typing-dots { display:flex; gap:5px; padding:14px 16px; }
  .dot { width:8px; height:8px; border-radius:50%; background:var(--teal); animation:bounce 1.2s infinite; }
  .dot:nth-child(2) { animation-delay:.2s; }
  .dot:nth-child(3) { animation-delay:.4s; }
  .chat-input-bar {
    position:fixed; bottom:0; left:0; right:0; background:white; border-top:1px solid var(--border);
    padding:16px 20px; display:flex; gap:10px; max-width:720px; margin:0 auto; left:50%; transform:translateX(-50%); width:100%;
  }
  .chat-input {
    flex:1; padding:12px 16px; border:2px solid var(--border); border-radius:50px; font-size:15px;
    outline:none; font-family:'Nunito',sans-serif; transition:.2s;
  }
  .chat-input:focus { border-color:var(--teal); }
  .send-btn {
    width:46px; height:46px; border-radius:50%; background:var(--teal); border:none; color:white;
    font-size:18px; cursor:pointer; transition:.2s; flex-shrink:0; display:flex; align-items:center; justify-content:center;
  }
  .send-btn:hover:not(:disabled) { background:#15674f; transform:scale(1.05); }
  .send-btn:disabled { opacity:.5; cursor:not-allowed; }

  /* ── Admin ── */
  .admin-wrap { display:flex; min-height:100vh; }
  .sidebar {
    width:240px; background:var(--teal); flex-shrink:0;
    display:flex; flex-direction:column; position:sticky; top:0; height:100vh;
  }
  .sidebar-logo { padding:28px 20px 20px; font-family:'Nunito',sans-serif; font-weight:800; font-size:22px; color:white; border-bottom:1px solid rgba(255,255,255,.15); }
  .sidebar-subtitle { font-size:12px; font-weight:400; opacity:.7; display:block; }
  .sidebar-nav { flex:1; padding:16px 12px; }
  .nav-item {
    display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:10px;
    color:rgba(255,255,255,.75); font-size:14px; font-weight:500; cursor:pointer; transition:.15s; margin-bottom:4px;
  }
  .nav-item:hover { background:rgba(255,255,255,.12); color:white; }
  .nav-item.active { background:rgba(255,255,255,.18); color:white; font-weight:600; }
  .nav-badge { background:#EF4444; color:white; border-radius:50px; font-size:11px; padding:1px 7px; margin-left:auto; font-weight:700; }
  .sidebar-bottom { padding:16px 12px; border-top:1px solid rgba(255,255,255,.15); }
  .admin-content { flex:1; overflow-y:auto; padding:32px; background:var(--bg); }
  .page-title { font-family:'Nunito',sans-serif; font-size:24px; font-weight:800; color:var(--text); margin-bottom:6px; }
  .page-sub { color:var(--muted); font-size:14px; margin-bottom:28px; }

  /* Cards */
  .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:28px; }
  .stat-card { background:white; border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm); border:1px solid var(--border); }
  .stat-icon { font-size:24px; margin-bottom:10px; }
  .stat-val { font-family:'Nunito',sans-serif; font-size:32px; font-weight:800; color:var(--teal); }
  .stat-label { font-size:13px; color:var(--muted); margin-top:4px; }

  /* Climate bar */
  .climate-card { background:white; border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm); border:1px solid var(--border); margin-bottom:24px; }
  .climate-title { font-weight:700; font-size:15px; margin-bottom:16px; color:var(--text); }
  .climate-score { font-family:'Nunito',sans-serif; font-size:48px; font-weight:800; color:var(--teal); line-height:1; }
  .climate-bar-wrap { background:#E2EDE9; border-radius:50px; height:10px; margin:14px 0 8px; overflow:hidden; }
  .climate-bar-fill { height:100%; background:linear-gradient(90deg,#1A7F6B,#2ECC9A); border-radius:50px; transition:width .8s ease; }

  /* Donut chart */
  .donut-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px; }
  .donut-wrap { background:white; border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm); border:1px solid var(--border); }
  .donut-legend { margin-top:16px; }
  .legend-item { display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:8px; }
  .legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .legend-pct { margin-left:auto; font-weight:700; font-family:'Nunito',sans-serif; }

  /* Alerts table */
  .alerts-wrap { background:white; border-radius:var(--radius); box-shadow:var(--shadow-sm); border:1px solid var(--border); overflow:hidden; }
  .alerts-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
  .filter-tabs { display:flex; gap:6px; }
  .filter-tab { padding:6px 14px; border-radius:50px; font-size:13px; font-weight:600; cursor:pointer; border:2px solid var(--border); background:none; color:var(--muted); transition:.15s; font-family:inherit; }
  .filter-tab.active { border-color:var(--teal); background:var(--teal-light); color:var(--teal); }
  .alert-row { display:flex; align-items:center; padding:16px 24px; border-bottom:1px solid var(--border); gap:14px; cursor:pointer; transition:.15s; }
  .alert-row:last-child { border-bottom:none; }
  .alert-row:hover { background:var(--teal-xlight); }
  .alert-badge { padding:4px 10px; border-radius:50px; font-size:11px; font-weight:700; flex-shrink:0; }
  .badge-critical { background:#FEF2F2; color:#DC2626; }
  .badge-moderate { background:#FFFBEB; color:#D97706; }
  .badge-resolved { background:#F0FAF7; color:var(--teal); }
  .alert-child { font-weight:700; font-size:14px; }
  .alert-meta { font-size:12px; color:var(--muted); }
  .alert-summary { font-size:13px; color:var(--muted); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .alert-time { font-size:12px; color:var(--muted); flex-shrink:0; }
  .unread-dot { width:8px; height:8px; border-radius:50%; background:#EF4444; flex-shrink:0; }

  /* Alert detail modal */
  .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:100; display:flex; align-items:center; justify-content:center; padding:24px; animation:fadeIn .2s; }
  .modal-box { background:white; border-radius:24px; width:100%; max-width:580px; box-shadow:0 24px 80px rgba(0,0,0,.2); animation:fadeUp .25s ease both; overflow:hidden; }
  .modal-header { padding:24px 28px; border-bottom:1px solid var(--border); display:flex; align-items:flex-start; justify-content:space-between; }
  .modal-body { padding:24px 28px; }
  .modal-summary { background:var(--teal-xlight); border-radius:12px; padding:16px; font-size:14px; line-height:1.7; color:var(--text); margin-bottom:20px; }
  .modal-actions { display:flex; gap:10px; flex-wrap:wrap; }
  .action-btn { padding:10px 18px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; transition:.15s; font-family:inherit; border:2px solid; }
  .action-btn.primary { background:var(--teal); color:white; border-color:var(--teal); }
  .action-btn.primary:hover { background:#15674f; }
  .action-btn.outline { background:none; color:var(--teal); border-color:var(--teal); }
  .action-btn.outline:hover { background:var(--teal-xlight); }
  .action-btn.danger { background:none; color:#DC2626; border-color:#FECACA; }
  .close-btn { background:none; border:none; font-size:22px; cursor:pointer; color:var(--muted); line-height:1; padding:0; }
  .notes-area { width:100%; margin-top:16px; padding:12px; border:2px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; resize:vertical; min-height:80px; outline:none; }
  .notes-area:focus { border-color:var(--teal); }

  /* Settings */
  .settings-card { background:white; border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm); border:1px solid var(--border); margin-bottom:20px; }
  .settings-title { font-weight:700; font-size:15px; margin-bottom:20px; color:var(--text); border-bottom:1px solid var(--border); padding-bottom:12px; }
  .settings-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; gap:20px; }
  .settings-label { font-size:14px; font-weight:500; color:var(--text); }
  .settings-sub { font-size:12px; color:var(--muted); }
  .settings-input { padding:9px 14px; border:2px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; outline:none; width:100px; text-align:center; }
  .settings-input:focus { border-color:var(--teal); }
  .toggle { width:44px; height:24px; border-radius:50px; background:var(--teal); border:none; cursor:pointer; position:relative; transition:.2s; flex-shrink:0; }
  .toggle::after { content:''; position:absolute; top:3px; right:3px; width:18px; height:18px; border-radius:50%; background:white; transition:.2s; }
  .children-table { width:100%; border-collapse:collapse; font-size:13px; }
  .children-table th { text-align:left; padding:10px 14px; color:var(--muted); font-weight:600; font-size:12px; border-bottom:2px solid var(--border); }
  .children-table td { padding:12px 14px; border-bottom:1px solid var(--border); }

  /* Animations */
  @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
  @keyframes fadeIn { from{opacity:0} to{opacity:1} }
  @keyframes bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-8px)} }
  @keyframes spin { to{transform:rotate(360deg)} }
  .spinner { width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;animation:spin .7s linear infinite; }

  @media(max-width:768px){
    .sidebar{display:none;}
    .admin-content{padding:20px;}
    .donut-grid{grid-template-columns:1fr;}
    .stat-grid{grid-template-columns:1fr 1fr;}
    .alert-summary{display:none;}
  }
`;

// ─── Helpers ──────────────────────────────────────────────────────────────────
function getAgeGroup(age) {
  if (age <= 7)  return "5-7";
  if (age <= 11) return "8-11";
  return "12-14";
}

function getWelcomeMessage(age) {
  const g = getAgeGroup(age);
  if (g === "5-7")  return "Salut ! 😊 Moi c'est Care ! Comment tu vas aujourd'hui ?";
  if (g === "8-11") return "Hey ! Contente de te voir 😊 Tu peux me dire comment s'est passée ta journée ?";
  return "Bonjour ! Je suis là pour toi. Comment tu te sens en ce moment ?";
}

async function callCareNestAI(messages, childAge) {
  const ageGroup = getAgeGroup(childAge);
  const systemPrompt = `Tu es Care, l'assistant IA bienveillant de CareNest pour les enfants de ${childAge} ans (groupe d'âge: ${ageGroup} ans).
Ton rôle: détecter les émotions et soutenir l'enfant avec douceur.

RÈGLES ABSOLUES:
- Ne pose JAMAIS deux questions en même temps
- Adapte ton langage à l'âge: ${ageGroup === "5-7" ? "très simple, émojis, phrases courtes" : ageGroup === "8-11" ? "amical, naturel, quelques émojis" : "respectueux, mature, peu d'émojis"}
- Sois empathique, chaleureux, jamais clinique
- Ne mentionne JAMAIS que tu analyses des émotions
- Si l'enfant exprime quelque chose de grave (harcèlement, pensées négatives, danger), réponds avec encore plus de douceur mais inclus [ALERTE_CRITIQUE] en tout début de ta réponse (invisible pour l'enfant)
- Garde tes réponses courtes (2-4 phrases max)
- Si l'enfant exprime du stress léger, tu peux proposer un mini exercice de respiration

Réponds uniquement en français.`;

  const response = await fetch("https://api.anthropic.com/v1/messages", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      model: "claude-sonnet-4-20250514",
      max_tokens: 1000,
      system: systemPrompt,
      messages: messages.map(m => ({ role: m.role, content: m.content })),
    }),
  });
  const data = await response.json();
  const text = data.content?.[0]?.text || "Je suis là pour toi. Dis-moi ce que tu ressens.";
  const isAlert = text.startsWith("[ALERTE_CRITIQUE]");
  const cleanText = text.replace("[ALERTE_CRITIQUE]", "").trim();
  return { text: cleanText, isAlert };
}

// ─── DonutChart ───────────────────────────────────────────────────────────────
function DonutChart({ data }) {
  const total = data.reduce((s, d) => s + d.value, 0);
  let offset = 0;
  const r = 60, cx = 80, cy = 80;
  const circumference = 2 * Math.PI * r;
  const segments = data.map(d => {
    const pct = d.value / total;
    const dash = pct * circumference;
    const seg = { ...d, dash, offset: circumference - offset * circumference / 100, pct: Math.round(pct * 100) };
    offset += d.value / total * 100;
    return seg;
  });

  return (
    <div className="donut-wrap">
      <div className="climate-title">Émotions du jour</div>
      <div style={{ display: "flex", alignItems: "center", gap: 20 }}>
        <svg width="160" height="160" viewBox="0 0 160 160">
          {(() => {
            let cumulative = 0;
            return data.map((d, i) => {
              const pct = d.value / total;
              const startAngle = cumulative * 2 * Math.PI - Math.PI / 2;
              cumulative += pct;
              const endAngle = cumulative * 2 * Math.PI - Math.PI / 2;
              const x1 = cx + r * Math.cos(startAngle);
              const y1 = cy + r * Math.sin(startAngle);
              const x2 = cx + r * Math.cos(endAngle);
              const y2 = cy + r * Math.sin(endAngle);
              const largeArc = pct > 0.5 ? 1 : 0;
              return (
                <path
                  key={i}
                  d={`M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`}
                  fill={d.color}
                  stroke="white" strokeWidth="2"
                />
              );
            });
          })()}
          <circle cx={cx} cy={cy} r={38} fill="white" />
          <text x={cx} y={cy - 6} textAnchor="middle" fontSize="18" fontWeight="800" fill="#1A7F6B" fontFamily="Nunito">{data[0].value}%</text>
          <text x={cx} y={cy + 12} textAnchor="middle" fontSize="10" fill="#64748B" fontFamily="DM Sans">positif</text>
        </svg>
        <div className="donut-legend" style={{ flex: 1 }}>
          {data.map((d, i) => (
            <div key={i} className="legend-item">
              <span className="legend-dot" style={{ background: d.color }} />
              <span style={{ fontSize: 12 }}>{d.label}</span>
              <span className="legend-pct" style={{ color: d.color }}>{d.value}%</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ─── Alert Detail Modal ───────────────────────────────────────────────────────
function AlertModal({ alert, onClose, onResolve }) {
  const [note, setNote] = useState("");
  if (!alert) return null;
  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="modal-box">
        <div className="modal-header">
          <div>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
              <span style={{ fontFamily: "Nunito", fontWeight: 800, fontSize: 18 }}>{alert.child}</span>
              <span className={`alert-badge ${alert.level === "critical" ? "badge-critical" : alert.status === "resolved" ? "badge-resolved" : "badge-moderate"}`}>
                {alert.level === "critical" ? "🚨 Critique" : "⚠️ Modérée"}
              </span>
              <span style={{ background: "#EEF2FF", color: "#6366F1", padding: "3px 10px", borderRadius: 50, fontSize: 11, fontWeight: 700 }}>{alert.type}</span>
            </div>
            <div style={{ fontSize: 13, color: "var(--muted)" }}>{alert.classe} · {alert.age} ans · {alert.date} à {alert.time}</div>
          </div>
          <button className="close-btn" onClick={onClose}>×</button>
        </div>
        <div className="modal-body">
          <div style={{ fontSize: 12, fontWeight: 600, color: "var(--muted)", marginBottom: 8, textTransform: "uppercase", letterSpacing: ".05em" }}>Résumé IA</div>
          <div className="modal-summary">{alert.summary}</div>
          <div style={{ fontSize: 11, color: "var(--muted)", marginBottom: 16, display: "flex", gap: 6, alignItems: "center" }}>
            <span>🔒</span> Le contenu exact des messages de l'enfant n'est pas accessible — conformité loi 09-08 / RGPD
          </div>
          <div className="modal-actions">
            <button className="action-btn primary" onClick={() => { onResolve(alert.id); onClose(); }}>✓ Marquer comme traitée</button>
            <button className="action-btn outline">📞 Contacter la famille</button>
            <button className="action-btn danger">⟶ Transmettre psychologue</button>
          </div>
          <textarea className="notes-area" placeholder="Notes internes (actions prises, observations...)" value={note} onChange={e => setNote(e.target.value)} />
        </div>
      </div>
    </div>
  );
}

// ─── LOGIN ────────────────────────────────────────────────────────────────────
function Login({ onLogin }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleSubmit = async () => {
    setLoading(true); setError("");
    await new Promise(r => setTimeout(r, 800));
    const acc = MOCK_ACCOUNTS.find(a => a.email === email && a.password === password);
    if (acc) { onLogin(acc); }
    else { setError("Email ou mot de passe incorrect"); setLoading(false); }
  };

  return (
    <div className="login-wrap">
      <div className="login-card">
        <div className="login-logo">🌿 CareNest</div>
        <div className="login-tagline">« Ton espace pour te sentir mieux »</div>
        <div className="login-field">
          <label className="login-label">Adresse e-mail</label>
          <input className="login-input" type="email" placeholder="exemple@carenest.ma"
            value={email} onChange={e => { setEmail(e.target.value); setError(""); }}
            onKeyDown={e => e.key === "Enter" && handleSubmit()} />
        </div>
        <div className="login-field">
          <label className="login-label">Mot de passe</label>
          <input className="login-input" type={show ? "text" : "password"} placeholder="••••••••"
            value={password} onChange={e => { setPassword(e.target.value); setError(""); }}
            onKeyDown={e => e.key === "Enter" && handleSubmit()} style={{ paddingRight: 44 }} />
          <button className="eye-btn" onClick={() => setShow(!show)}>{show ? "🙈" : "👁️"}</button>
        </div>
        <button className="login-btn" onClick={handleSubmit} disabled={loading || !email || !password}>
          {loading ? <><div className="spinner" /> Connexion...</> : "Se connecter"}
        </button>
        {error && <div className="login-error">⚠️ {error}</div>}
        <div className="demo-hint">
          <b>Compte enfant :</b> enfant@carenest.ma / demo123<br />
          <b>Compte admin :</b> admin@carenest.ma / admin123
        </div>
      </div>
    </div>
  );
}

// ─── CHAT ─────────────────────────────────────────────────────────────────────
function Chat({ user, onLogout, onAlert }) {
  const welcome = getWelcomeMessage(user.age);
  const [messages, setMessages] = useState([{ role: "assistant", content: welcome, id: 0 }]);
  const [input, setInput] = useState("");
  const [typing, setTyping] = useState(false);
  const bottomRef = useRef(null);
  const apiMessages = useRef([]);

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages, typing]);

  const send = async () => {
    if (!input.trim() || typing) return;
    const text = input.trim();
    setInput("");
    const userMsg = { role: "user", content: text, id: Date.now() };
    setMessages(prev => [...prev, userMsg]);
    apiMessages.current.push({ role: "user", content: text });
    setTyping(true);
    try {
      const { text: reply, isAlert } = await callCareNestAI(apiMessages.current, user.age);
      if (isAlert) onAlert({ child: user.name, classe: user.classe, time: new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" }) });
      apiMessages.current.push({ role: "assistant", content: reply });
      setMessages(prev => [...prev, { role: "assistant", content: reply, id: Date.now() + 1 }]);
    } catch {
      setMessages(prev => [...prev, { role: "assistant", content: "Oups, je n'arrive pas à répondre là. Tu peux réessayer ? 🌿", id: Date.now() + 2 }]);
    }
    setTyping(false);
  };

  return (
    <div className="chat-wrap">
      <div className="chat-header">
        <div className="chat-logo">🌿 CareNest</div>
        <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
          <span style={{ fontSize: 13, color: "var(--muted)" }}>Bonjour, {user.name} 👋</span>
          <button className="logout-btn" onClick={onLogout}>Déconnexion</button>
        </div>
      </div>
      <div className="chat-messages">
        {messages.map(m => (
          <div key={m.id} className={`msg-row ${m.role === "user" ? "child" : ""}`}>
            {m.role === "assistant" && <div className="avatar">🌿</div>}
            <div className={`bubble ${m.role === "assistant" ? "bot" : "child"}`}>{m.content}</div>
            {m.role === "user" && <div className="avatar" style={{ background: "#E8F5F2", color: "var(--teal)", fontWeight: 800, fontSize: 14 }}>{user.name[0]}</div>}
          </div>
        ))}
        {typing && (
          <div className="msg-row">
            <div className="avatar">🌿</div>
            <div className="bubble bot"><div className="typing-dots"><div className="dot" /><div className="dot" /><div className="dot" /></div></div>
          </div>
        )}
        <div ref={bottomRef} />
      </div>
      <div className="chat-input-bar" style={{ position: "fixed", bottom: 0, left: 0, right: 0 }}>
        <input className="chat-input" placeholder={`Écris ce que tu ressens, ${user.name}...`}
          value={input} onChange={e => setInput(e.target.value)}
          onKeyDown={e => e.key === "Enter" && send()} />
        <button className="send-btn" onClick={send} disabled={!input.trim() || typing}>✈</button>
      </div>
    </div>
  );
}

// ─── ADMIN ────────────────────────────────────────────────────────────────────
function Admin({ user, onLogout, liveAlerts }) {
  const [tab, setTab] = useState("dashboard");
  const [alertFilter, setAlertFilter] = useState("all");
  const [selectedAlert, setSelectedAlert] = useState(null);
  const [alerts, setAlerts] = useState(MOCK_ALERTS);

  const allAlerts = [...liveAlerts.map((a, i) => ({
    id: 9000 + i, child: a.child, classe: a.classe, age: 10, date: new Date().toISOString().split("T")[0],
    time: a.time, level: "critical", type: "Alerte live", summary: "Signal critique détecté en temps réel lors d'une session active.",
    status: "unread"
  })), ...alerts];

  const unreadCount = allAlerts.filter(a => a.status === "unread").length;
  const filtered = alertFilter === "all" ? allAlerts : allAlerts.filter(a =>
    alertFilter === "unread" ? a.status === "unread" :
    alertFilter === "resolved" ? a.status === "resolved" : a.status !== "resolved"
  );

  const resolveAlert = (id) => setAlerts(prev => prev.map(a => a.id === id ? { ...a, status: "resolved" } : a));

  const NavItem = ({ id, icon, label, badge }) => (
    <div className={`nav-item ${tab === id ? "active" : ""}`} onClick={() => setTab(id)}>
      <span>{icon}</span> {label}
      {badge > 0 && <span className="nav-badge">{badge}</span>}
    </div>
  );

  return (
    <div className="admin-wrap">
      {selectedAlert && <AlertModal alert={selectedAlert} onClose={() => setSelectedAlert(null)} onResolve={resolveAlert} />}
      <div className="sidebar">
        <div className="sidebar-logo">🌿 CareNest <span className="sidebar-subtitle">{user.school}</span></div>
        <div className="sidebar-nav">
          <NavItem id="dashboard" icon="📊" label="Tableau de bord" />
          <NavItem id="alerts" icon="🚨" label="Alertes" badge={unreadCount} />
          <NavItem id="settings" icon="⚙️" label="Paramètres" />
        </div>
        <div className="sidebar-bottom">
          <div className="nav-item" onClick={onLogout}><span>🚪</span> Déconnexion</div>
        </div>
      </div>

      <div className="admin-content">
        {tab === "dashboard" && (
          <>
            <div className="page-title">Tableau de bord</div>
            <div className="page-sub">Bonjour, {user.name} · {new Date().toLocaleDateString("fr-FR", { weekday: "long", year: "numeric", month: "long", day: "numeric" })}</div>

            {unreadCount > 0 && (
              <div style={{ background: "#FEF2F2", border: "1px solid #FECACA", borderRadius: 12, padding: "14px 18px", marginBottom: 20, display: "flex", alignItems: "center", gap: 12, cursor: "pointer" }} onClick={() => setTab("alerts")}>
                <span style={{ fontSize: 20 }}>🚨</span>
                <div><b style={{ color: "#DC2626" }}>{unreadCount} alerte{unreadCount > 1 ? "s" : ""} non traitée{unreadCount > 1 ? "s" : ""}</b> <span style={{ color: "#64748B", fontSize: 13 }}>· Cliquer pour voir</span></div>
              </div>
            )}

            <div className="stat-grid">
              {[
                { icon: "👧", val: 234, label: "Élèves actifs" },
                { icon: "💬", val: 18, label: "Sessions aujourd'hui" },
                { icon: "📈", val: "73%", label: "Taux d'engagement" },
                { icon: "🚨", val: unreadCount, label: "Alertes en attente" },
              ].map((s, i) => (
                <div key={i} className="stat-card">
                  <div className="stat-icon">{s.icon}</div>
                  <div className="stat-val">{s.val}</div>
                  <div className="stat-label">{s.label}</div>
                </div>
              ))}
            </div>

            <div className="donut-grid">
              <div className="climate-card">
                <div className="climate-title">Score Climat Scolaire</div>
                <div style={{ display: "flex", alignItems: "flex-end", gap: 12 }}>
                  <div className="climate-score">74</div>
                  <div style={{ paddingBottom: 8, color: "var(--teal)", fontWeight: 600, fontSize: 14 }}>/ 100 · ↑ +3 cette semaine</div>
                </div>
                <div className="climate-bar-wrap"><div className="climate-bar-fill" style={{ width: "74%" }} /></div>
                <div style={{ fontSize: 12, color: "var(--muted)" }}>Basé sur 18 sessions · Mise à jour automatique</div>
              </div>
              <DonutChart data={EMOTION_DATA} />
            </div>
          </>
        )}

        {tab === "alerts" && (
          <>
            <div className="page-title">Alertes</div>
            <div className="page-sub">{allAlerts.length} alerte{allAlerts.length > 1 ? "s" : ""} au total · {unreadCount} non traitée{unreadCount > 1 ? "s" : ""}</div>
            <div className="alerts-wrap">
              <div className="alerts-header">
                <div className="filter-tabs">
                  {[["all", "Toutes"], ["unread", "Non traitées"], ["resolved", "Résolues"]].map(([v, l]) => (
                    <button key={v} className={`filter-tab ${alertFilter === v ? "active" : ""}`} onClick={() => setAlertFilter(v)}>{l}</button>
                  ))}
                </div>
              </div>
              {filtered.length === 0 && <div style={{ padding: "40px 24px", textAlign: "center", color: "var(--muted)", fontSize: 14 }}>✅ Aucune alerte dans cette catégorie</div>}
              {filtered.map(a => (
                <div key={a.id} className="alert-row" onClick={() => setSelectedAlert(a)}>
                  {a.status === "unread" && <div className="unread-dot" />}
                  <span className={`alert-badge ${a.level === "critical" ? "badge-critical" : a.status === "resolved" ? "badge-resolved" : "badge-moderate"}`}>
                    {a.level === "critical" ? "🚨 Critique" : a.status === "resolved" ? "✓ Résolue" : "⚠️ Modérée"}
                  </span>
                  <div>
                    <div className="alert-child">{a.child}</div>
                    <div className="alert-meta">{a.classe} · {a.type}</div>
                  </div>
                  <div className="alert-summary">{a.summary}</div>
                  <div className="alert-time">{a.date}<br />{a.time}</div>
                  <span style={{ color: "var(--teal)", fontSize: 18 }}>›</span>
                </div>
              ))}
            </div>
          </>
        )}

        {tab === "settings" && (
          <>
            <div className="page-title">Paramètres</div>
            <div className="page-sub">Configuration de l'école · {user.school}</div>
            <div className="settings-card">
              <div className="settings-title">🔔 Seuils d'alerte</div>
              <div className="settings-row">
                <div><div className="settings-label">Seuil alerte climat global</div><div className="settings-sub">% de sessions négatives déclenchant une alerte</div></div>
                <input className="settings-input" type="number" defaultValue={30} min={0} max={100} /> <span style={{ fontSize: 14, color: "var(--muted)" }}>%</span>
              </div>
              <div className="settings-row">
                <div><div className="settings-label">Notifications email</div><div className="settings-sub">Alertes critiques par email</div></div>
                <button className="toggle" />
              </div>
            </div>
            <div className="settings-card">
              <div className="settings-title">👧 Comptes enfants actifs</div>
              <table className="children-table">
                <thead><tr><th>Prénom</th><th>Classe</th><th>Âge</th><th>Dernière connexion</th><th>Statut</th></tr></thead>
                <tbody>
                  {[["Yassine","CM2",10,"Aujourd'hui","✅"],["Amina","CE2",8,"Aujourd'hui","🚨"],["Omar","5ème",11,"Aujourd'hui","🚨"],["Sara","CM1",9,"Hier","⚠️"],["Karim","6ème",12,"12/04","✅"]].map(([n,c,a,d,s],i) => (
                    <tr key={i}><td style={{ fontWeight: 600 }}>{n}</td><td>{c}</td><td>{a} ans</td><td style={{ color: "var(--muted)" }}>{d}</td><td>{s}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="settings-card">
              <div className="settings-title">🌍 Langue de l'interface</div>
              <div style={{ display: "flex", gap: 10 }}>
                {["🇫🇷 Français", "🇲🇦 Arabe", "🇬🇧 Anglais"].map((l, i) => (
                  <button key={i} className={`filter-tab ${i === 0 ? "active" : ""}`} style={{ fontSize: 14 }}>{l}</button>
                ))}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

// ─── APP ROOT ─────────────────────────────────────────────────────────────────
export default function App() {
  const [user, setUser] = useState(null);
  const [liveAlerts, setLiveAlerts] = useState([]);

  const handleAlert = (alertInfo) => {
    setLiveAlerts(prev => [...prev, alertInfo]);
  };

  return (
    <>
      <style>{STYLES}</style>
      {!user && <Login onLogin={setUser} />}
      {user?.role === "child" && <Chat user={user} onLogout={() => setUser(null)} onAlert={handleAlert} />}
      {user?.role === "admin" && <Admin user={user} onLogout={() => setUser(null)} liveAlerts={liveAlerts} />}
    </>
  );
}
