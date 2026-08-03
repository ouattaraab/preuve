/**
 * Client d'API de l'espace administrateur.
 *
 * AUCUN JETON N'EST STOCKÉ CÔTÉ NAVIGATEUR. L'authentification passe par le
 * cookie de session, `httpOnly` : une faille XSS ne peut donc pas l'exfiltrer.
 * Cette console affiche des pièces d'identité et des cartes grises — un jeton
 * en `localStorage` y serait lisible par le premier script injecté.
 *
 * Sans dépendance ni étape de construction : l'hébergement cible est un
 * mutualisé sans Node, et la maquette n'utilisait aucun framework.
 */
const Api = {
  jeton() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  },

  async appel(url, options = {}) {
    const reponse = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.jeton(),
        ...(options.headers || {}),
      },
      ...options,
    });

    // La session a expiré : on renvoie à la connexion plutôt que d'afficher un
    // écran vide que l'exploitant prendrait pour une panne.
    if (reponse.status === 401) {
      window.location.href = '/admin/connexion';
      throw new Error('session expirée');
    }

    const corps = await reponse.json().catch(() => ({}));

    if (!reponse.ok) {
      throw new Error(corps.message || `Erreur ${reponse.status}`);
    }

    return corps;
  },

  get(url) { return this.appel(url); },
  post(url, donnees) { return this.appel(url, { method: 'POST', body: JSON.stringify(donnees || {}) }); },
  put(url, donnees) { return this.appel(url, { method: 'PUT', body: JSON.stringify(donnees || {}) }); },
};

/** Échappe avant insertion : rien de ce qui vient de l'API n'est du HTML. */
function txt(valeur) {
  const d = document.createElement('div');
  d.textContent = valeur === null || valeur === undefined ? '' : String(valeur);
  return d.innerHTML;
}

/**
 * Pastille d'état, alimentée par la sonde publique.
 *
 * Elle distingue les trois défauts silencieux que la plateforme sait détecter :
 * base injoignable, chaîne d'audit non ancrée, planificateur arrêté. Un
 * exploitant doit les voir sans les chercher.
 */
async function rafraichirEtat() {
  const pastille = document.getElementById('etat-pastille');
  const texte = document.getElementById('etat-texte');

  if (!pastille || !texte) return;

  try {
    const s = await fetch('/api/v1/health', { headers: { Accept: 'application/json' } }).then(r => r.json());

    const soucis = [];
    if (s.checks?.database !== 'ok') soucis.push('base injoignable');
    if (!s.audit_chain_opposable) soucis.push('chaîne non opposable');
    else if (s.checks?.audit_anchor !== 'ok') soucis.push('ancrage en retard');
    if (s.checks?.scheduler === 'never') soucis.push('planificateur jamais démarré');
    else if (s.checks?.scheduler === 'stale') soucis.push('planificateur arrêté');

    pastille.style.background = soucis.length === 0 ? '#3F8F5B' : '#D97706';
    texte.textContent = soucis.length === 0 ? 'Tout est nominal' : soucis[0];
    texte.title = soucis.join(' · ');
  } catch (e) {
    pastille.style.background = '#B23A3A';
    texte.textContent = 'Sonde injoignable';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  rafraichirEtat();
  setInterval(rafraichirEtat, 60000);
});

/* ------------------------------------------------------------------ */
/* Écran : Modération                                                  */
/* ------------------------------------------------------------------ */

const Moderation = {
  filtre: 'tous',
  elements: [],

  async charger() {
    const zone = document.getElementById('file-revue');
    if (!zone) return;

    try {
      // Trois appels en parallèle : un agent ne doit pas attendre trois
      // aller-retours en série pour voir sa file.
      const [docs, kyc, claims] = await Promise.all([
        Api.get('/api/v1/admin/documents').catch(() => ({ documents: [] })),
        Api.get('/api/v1/admin/kyc').catch(() => ({ submissions: [] })),
        Api.get('/api/v1/admin/claims').catch(() => ({ claims: [] })),
      ]);

      this.elements = [
        ...(docs.documents || []).map(d => ({
          source: 'documents', tag: 'JUSTIFICATIF',
          titre: `${d.doc_type_label || d.doc_type} — bien #${d.asset_id}`,
          meta: `Déposé le ${d.submitted_at ? new Date(d.submitted_at).toLocaleDateString('fr-FR') : '—'} · empreinte ${(d.file_sha256 || '').slice(0, 12)}…`,
          lien: d.file_url, id: d.id,
        })),
        ...(kyc.submissions || []).map(k => ({
          source: 'kyc', tag: 'IDENTITÉ',
          titre: `Dossier d'identité #${k.id}`,
          meta: `Déposé le ${k.submitted_at ? new Date(k.submitted_at).toLocaleDateString('fr-FR') : '—'}`,
          id: k.id,
        })),
        ...(claims.claims || []).map(c => ({
          source: 'claims', tag: 'RÉCLAMATION',
          titre: c.title || `Réclamation #${c.id}`,
          meta: c.meta || `Statut : ${c.status || '—'}`,
          id: c.id,
        })),
      ];

      this.rendre();
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-size:14px;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  rendre() {
    const zone = document.getElementById('file-revue');
    const vus = this.filtre === 'tous' ? this.elements : this.elements.filter(e => e.source === this.filtre);

    if (vus.length === 0) {
      // Une file vide est une bonne nouvelle : le dire vaut mieux qu'un écran
      // blanc, que l'agent prendrait pour une panne.
      zone.innerHTML = `<p style="padding:26px;background:#FFF6E8;border-radius:12px;font-size:15px;font-weight:700;text-align:center">Rien en attente. La file est vide.</p>`;
      return;
    }

    zone.innerHTML = vus.map(e => `
      <article style="background:#FFF6E8;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:16px">
        <span style="background:#2B1D12;color:#FFF6E8;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;flex-shrink:0">${txt(e.tag)}</span>
        <span style="flex:1;min-width:0">
          <span style="display:block;font-size:15px;font-weight:700">${txt(e.titre)}</span>
          <span style="display:block;font-size:13px;color:#7A6A55;margin-top:2px">${txt(e.meta)}</span>
        </span>
        ${e.lien ? `<a href="${txt(e.lien)}" target="_blank" rel="noopener"
             style="font-size:13px;font-weight:700;text-decoration:underline;flex-shrink:0">Voir la pièce</a>` : ''}
      </article>`).join('');
  },

  init() {
    document.querySelectorAll('[data-filtre]').forEach(b => {
      b.addEventListener('click', () => {
        this.filtre = b.dataset.filtre;
        document.querySelectorAll('[data-filtre]').forEach(x => {
          const actif = x.dataset.filtre === this.filtre;
          x.style.background = actif ? '#2B1D12' : 'transparent';
          x.style.color = actif ? '#FFF6E8' : '#2B1D12';
        });
        this.rendre();
      });
    });

    this.charger();
  },
};

/* ------------------------------------------------------------------ */
/* Écran : Supervision                                                 */
/* ------------------------------------------------------------------ */

function carte(libelle, valeur, note, couleur) {
  return `<div style="background:#FFF6E8;border-radius:12px;padding:16px 18px;border-left:5px solid ${couleur}">
      <span style="display:block;font-size:12px;font-weight:700;color:#7A6A55;text-transform:uppercase;letter-spacing:.4px">${txt(libelle)}</span>
      <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:24px;margin:6px 0 2px">${txt(valeur)}</span>
      <span style="display:block;font-size:13px;color:#5C4A33;line-height:1.5">${txt(note)}</span>
    </div>`;
}

const Supervision = {
  async charger() {
    const zoneSonde = document.getElementById('sonde');
    if (!zoneSonde) return;

    try {
      const s = await fetch('/api/v1/health', { headers: { Accept: 'application/json' } }).then(r => r.json());

      const vert = '#3F8F5B', orange = '#D97706', rouge = '#B23A3A';

      zoneSonde.innerHTML = [
        carte('Base de données', s.checks?.database === 'ok' ? 'Joignable' : 'Injoignable',
          s.checks?.database === 'ok' ? 'Les lectures et écritures passent.' : 'La plateforme ne peut plus rien servir.',
          s.checks?.database === 'ok' ? vert : rouge),
        carte("Chaîne d'audit", s.audit_chain_opposable ? 'Opposable' : 'NON opposable',
          s.audit_chain_opposable
            ? "Une empreinte a été publiée hors de la plateforme."
            : "Sa cohérence interne ne prouve rien : l'algorithme est public et reproductible.",
          s.audit_chain_opposable ? (s.checks?.audit_anchor === 'ok' ? vert : orange) : rouge),
        carte('Planificateur',
          { ok: 'En marche', stale: 'Arrêté', never: 'Jamais démarré' }[s.checks?.scheduler] || 'Inconnu',
          s.checks?.scheduler === 'ok'
            ? 'Les tâches de fond tournent.'
            : "Ce qui devait arriver dans le temps n'arrive pas : promotions, expirations, ancrage.",
          s.checks?.scheduler === 'ok' ? vert : rouge),
      ].join('');
    } catch (e) {
      zoneSonde.innerHTML = `<p style="color:#B23A3A;font-weight:700">Sonde injoignable : ${txt(e.message)}</p>`;
    }

    this.chargerTelemetrie();
    this.chargerFraude();
  },

  async chargerTelemetrie() {
    const zone = document.getElementById('telemetrie');
    if (!zone) return;

    try {
      const t = await Api.get('/api/v1/admin/telemetry?days=7');
      const ct01 = t.ct01_lookup || {}, ct02 = t.ct02_registration || {};

      // `meets_target` vaut null sans échantillon : dire « objectif tenu »
      // sans mesure serait pire que de ne rien dire.
      const verdict = m => m.meets_target === null ? '#8A7A62' : (m.meets_target ? '#3F8F5B' : '#B23A3A');
      const libelle = m => m.samples === 0 ? 'Aucune mesure' : `${m.p95_ms} ms au 95e centile`;

      zone.innerHTML = [
        carte('CT-01 · consultation', libelle(ct01),
          `${ct01.samples || 0} mesure(s) · promesse : moins de ${ct01.target_ms} ms`, verdict(ct01)),
        carte('CT-02 · enregistrement', libelle(ct02),
          `${ct02.samples || 0} mesure(s) · promesse : moins de ${(ct02.target_ms || 0) / 1000} s`, verdict(ct02)),
      ].join('');
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  async chargerFraude() {
    const zone = document.getElementById('fraude');
    if (!zone) return;

    try {
      const f = await Api.get('/api/v1/admin/fraud-signals?days=30');
      const c = [];

      for (const [cle, valeur] of Object.entries(f)) {
        if (typeof valeur === 'number') {
          c.push(carte(cle.replace(/_/g, ' '), valeur, 'sur 30 jours', valeur > 0 ? '#D97706' : '#3F8F5B'));
        }
      }

      zone.innerHTML = c.length ? c.join('') : '<p style="color:#7A6A55;font-size:14px">Aucun signal sur la période.</p>';
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('file-revue')) Moderation.init();
  if (document.getElementById('sonde')) Supervision.charger();
});

/* ------------------------------------------------------------------ */
/* Écrans : Registre des biens et Annuaire des comptes                 */
/* ------------------------------------------------------------------ */

/** Attend que l'utilisateur ait fini de taper : une frappe = une requête serait absurde. */
function differer(fn, delai = 350) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delai); };
}

function pastille(texte, fond, encre) {
  return `<span style="background:${fond};color:${encre};font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap">${txt(texte)}</span>`;
}

function pagination(zoneId, p, aller) {
  const zone = document.getElementById(zoneId);
  if (!zone) return;

  if (p.last_page <= 1) { zone.innerHTML = ''; return; }

  const bouton = (libelle, page, actif) => actif
    ? `<button data-page="${page}" style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer">${libelle}</button>`
    : `<span style="color:#B9A98E;font-size:13px;font-weight:700;padding:8px 14px">${libelle}</span>`;

  zone.innerHTML = bouton('← Précédent', p.page - 1, p.page > 1)
    + `<span style="font-size:13px;font-weight:700;color:#5C4A33">Page ${p.page} sur ${p.last_page} · ${p.total} au total</span>`
    + bouton('Suivant →', p.page + 1, p.page < p.last_page);

  zone.querySelectorAll('[data-page]').forEach(b =>
    b.addEventListener('click', () => aller(Number(b.dataset.page))));
}

const Registre = {
  page: 1,

  async charger() {
    const zone = document.getElementById('table-registre');
    if (!zone) return;

    const p = new URLSearchParams({ page: String(this.page) });
    const v = id => (document.getElementById(id) || {}).value || '';
    if (v('q')) p.set('q', v('q'));
    if (v('statut')) p.set('status', v('statut'));
    if (v('fiabilite')) p.set('trust', v('fiabilite'));

    try {
      const r = await Api.get('/api/v1/admin/assets?' + p.toString());

      if (!r.assets.length) {
        zone.innerHTML = `<p style="padding:26px;background:#FFF6E8;border-radius:12px;font-size:15px;font-weight:700;text-align:center">Aucun bien ne correspond.</p>`;
        pagination('pagination-registre', r.pagination, () => {});
        return;
      }

      zone.innerHTML = `
        <table style="width:100%;border-collapse:collapse;background:#FFF6E8;border-radius:12px;overflow:hidden">
          <thead><tr style="background:#2B1D12;color:#FFF6E8">
            ${['IDENTIFIANT', 'BIEN', 'STATUT', 'FIABILITÉ', 'ENREGISTRÉ', 'CONSULT. 30 J']
              .map(h => `<th style="text-align:left;padding:12px 14px;font-size:11px;font-weight:700;letter-spacing:.4px">${h}</th>`).join('')}
          </tr></thead>
          <tbody>${r.assets.map(a => `
            <tr style="border-top:1px solid #E4DBC8">
              <td style="padding:12px 14px;font-size:13px;font-weight:700;font-family:ui-monospace,monospace">${txt(a.identifier)}<br><span style="font-size:11px;color:#7A6A55;font-weight:400">${txt(a.public_ref)}</span></td>
              <td style="padding:12px 14px;font-size:14px">${txt(a.label || '—')}<br><span style="font-size:11px;color:#7A6A55">${txt(a.category)}</span></td>
              <td style="padding:12px 14px">${pastille(a.life_status_label, a.life_status === 'V-VOL' ? '#B23A3A' : '#2B1D12', '#FFF6E8')}</td>
              <td style="padding:12px 14px">${pastille(a.trust_level_label, '#FFF', '#2B1D12')}</td>
              <td style="padding:12px 14px;font-size:13px;color:#5C4A33">${a.registered_at ? new Date(a.registered_at).toLocaleDateString('fr-FR') : '—'}</td>
              <td style="padding:12px 14px;font-size:14px;font-weight:700">${txt(a.lookups_30d)}</td>
            </tr>`).join('')}</tbody>
        </table>`;

      pagination('pagination-registre', r.pagination, n => { this.page = n; this.charger(); });
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  init() {
    const relancer = differer(() => { this.page = 1; this.charger(); });
    ['q', 'statut', 'fiabilite'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', relancer);
    });
    this.charger();
  },
};

const Comptes = {
  page: 1,

  async charger() {
    const zone = document.getElementById('table-comptes');
    if (!zone) return;

    const p = new URLSearchParams({ page: String(this.page) });
    const v = id => (document.getElementById(id) || {}).value || '';
    if (v('qu')) p.set('q', v('qu'));
    if (v('kyc')) p.set('kyc', v('kyc'));
    if (v('statut-compte')) p.set('status', v('statut-compte'));

    try {
      const r = await Api.get('/api/v1/admin/users?' + p.toString());

      if (!r.users.length) {
        zone.innerHTML = `<p style="padding:26px;background:#FFF6E8;border-radius:12px;font-size:15px;font-weight:700;text-align:center">Aucun compte ne correspond.</p>`;
        pagination('pagination-comptes', r.pagination, () => {});
        return;
      }

      zone.innerHTML = `
        <table style="width:100%;border-collapse:collapse;background:#FFF6E8;border-radius:12px;overflow:hidden">
          <thead><tr style="background:#2B1D12;color:#FFF6E8">
            ${['UTILISATEUR', 'CONTACT (MASQUÉ)', 'NIVEAU KYC', 'BIENS', 'INSCRIT', 'ACTION']
              .map(h => `<th style="text-align:left;padding:12px 14px;font-size:11px;font-weight:700;letter-spacing:.4px">${h}</th>`).join('')}
          </tr></thead>
          <tbody>${r.users.map(u => {
            const suspendu = u.status === 'suspended';
            return `
            <tr style="border-top:1px solid #E4DBC8;${suspendu ? 'opacity:.6' : ''}">
              <td style="padding:12px 14px;font-size:14px;font-weight:700">${txt(u.name || '—')}${suspendu ? ' ' + pastille('SUSPENDU', '#B23A3A', '#FFF6E8') : ''}</td>
              <td style="padding:12px 14px;font-size:13px;font-family:ui-monospace,monospace;color:#7A6A55">${txt(u.contact)}</td>
              <td style="padding:12px 14px">${pastille(u.kyc_status_label, u.kyc_status === 'verified' ? '#3F8F5B' : '#FFF', u.kyc_status === 'verified' ? '#FFF6E8' : '#2B1D12')}</td>
              <td style="padding:12px 14px;font-size:14px;font-weight:700">${txt(u.assets)}</td>
              <td style="padding:12px 14px;font-size:13px;color:#5C4A33">${u.joined_at ? new Date(u.joined_at).toLocaleDateString('fr-FR') : '—'}</td>
              <td style="padding:12px 14px">
                <button data-user="${txt(u.id)}" data-suspendre="${suspendu ? '0' : '1'}"
                        style="background:${suspendu ? '#2B1D12' : 'transparent'};color:${suspendu ? '#FFF6E8' : '#B23A3A'};border:2px solid ${suspendu ? '#2B1D12' : '#B23A3A'};border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer">
                  ${suspendu ? 'Rétablir' : 'Suspendre'}
                </button>
              </td>
            </tr>`; }).join('')}</tbody>
        </table>`;

      zone.querySelectorAll('[data-user]').forEach(b =>
        b.addEventListener('click', () => this.basculer(b.dataset.user, b.dataset.suspendre === '1')));

      pagination('pagination-comptes', r.pagination, n => { this.page = n; this.charger(); });
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  async basculer(id, suspendre) {
    let motif = null;

    if (suspendre) {
      // Le motif est exigé par l'API. Le demander ici évite un aller-retour
      // qui reviendrait avec une erreur que l'agent ne comprendrait pas.
      motif = window.prompt(
        "Motif de la suspension.\n\nIl entre dans la chaîne d'audit : c'est lui qui rend la décision contestable.\n\n" +
        "Rappel : les biens de ce compte restent protégés."
      );

      if (!motif || !motif.trim()) return;
    }

    try {
      const r = await Api.post(`/api/v1/admin/users/${id}/status`, { suspended: suspendre, reason: motif });
      window.alert(r.message);
      this.charger();
    } catch (e) {
      window.alert(e.message);
    }
  },

  init() {
    const relancer = differer(() => { this.page = 1; this.charger(); });
    ['qu', 'kyc', 'statut-compte'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', relancer);
    });
    this.charger();
  },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('table-registre')) Registre.init();
  if (document.getElementById('table-comptes')) Comptes.init();
});
