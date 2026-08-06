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

/**
 * Neutralise l'envoi des formulaires de filtrage.
 *
 * Posé ICI et non par un attribut `onsubmit` dans le gabarit : une politique de
 * sécurité du contenu qui autorise les gestionnaires en ligne doit autoriser
 * `'unsafe-inline'` pour les scripts, ce qui rouvre la porte que la politique
 * ferme. Cette console affiche des pièces d'identité et des cartes grises —
 * elle n'a pas les moyens de cette concession.
 */
function neutraliserEnvoi(id) {
  const formulaire = document.getElementById(id);
  if (formulaire) formulaire.addEventListener('submit', e => e.preventDefault());
}

document.addEventListener('DOMContentLoaded', () => {
  ['filtres-registre', 'filtres-comptes', 'filtres-audit', 'form-levee', 'form-version']
    .forEach(neutraliserEnvoi);
});

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
      // DEUX AXES : la SOURCE (justificatif, identité, réclamation) et l'ÉTAT
      // (en attente, validé, refusé). Sans le second, un dossier tranché
      // disparaissait sans laisser de trace consultable : l'agent qui venait de
      // le valider ne pouvait plus ni le revoir, ni vérifier ce qu'il avait
      // décidé, ni relire le motif qu'il avait écrit.
      const etat = this.etat || 'pending';

      const [docs, docs2, kyc, claims] = await Promise.all([
        Api.get('/api/v1/admin/documents?status=' + (etat === 'pending' ? 'pending' : etat === 'verified' ? 'accepted' : 'rejected'))
          .catch(() => ({ documents: [] })),
        // Une pièce refusée et une falsification suspectée sont deux états
        // distincts : les confondre ferait disparaître les secondes, qui sont
        // précisément celles qu'on relit.
        etat === 'rejected'
          ? Api.get('/api/v1/admin/documents?status=suspected_forgery').catch(() => ({ documents: [] }))
          : Promise.resolve({ documents: [] }),
        Api.get('/api/v1/admin/kyc?status=' + etat).catch(() => ({ submissions: [] })),
        // Les réclamations n'ont pas d'état « validé » au sens de cette file :
        // elles suivent leur propre instruction. On ne les charge donc qu'en
        // file d'attente, plutôt que d'en donner une vue fausse.
        etat === 'pending'
          ? Api.get('/api/v1/admin/claims').catch(() => ({ claims: [] }))
          : Promise.resolve({ claims: [] }),
      ]);

      this.elements = [
        ...[...(docs.documents || []), ...(docs2.documents || [])].map(d => ({
          source: 'documents', tag: 'JUSTIFICATIF',
          titre: `${d.doc_type_label || d.doc_type} — bien #${d.asset_id}`,
          meta: `Déposé le ${d.submitted_at ? new Date(d.submitted_at).toLocaleDateString('fr-FR') : '—'} · empreinte ${(d.file_sha256 || '').slice(0, 12)}…`,
          lien: d.file_url, id: d.id,
          decide: (d.review_status || 'pending') !== 'pending',
          decision: d.review_status_label || d.review_status,
          decideLe: d.reviewed_at, motif: d.review_reason,
        })),
        ...(kyc.submissions || []).map(k => ({
          source: 'kyc', tag: 'IDENTITÉ',
          titre: `Dossier d'identité #${k.id}`,
          meta: `Déposé le ${k.submitted_at ? new Date(k.submitted_at).toLocaleDateString('fr-FR') : '—'}`,
          id: k.id,
          // LES TROIS IMAGES SONT LE DOSSIER. Sans elles, l'agent ne peut ni
          // valider ni refuser : il ne lui reste qu'un numéro de ligne, et une
          // décision prise sur un numéro de ligne ne vaut rien.
          images: k.images || {},
          extraction: k.extraction || null,
          liveness: k.liveness_score,
          // CE QUE LA PERSONNE A DÉCLARÉ : sans lui, l'agent ne peut que
          // constater qu'une image existe. Il ne peut pas VÉRIFIER.
          declare: k.holder || {},
          decide: (k.status || 'pending') !== 'pending',
          decision: k.status === 'verified' ? 'Identité validée' : k.status === 'rejected' ? 'Dossier refusé' : '',
          decideLe: k.reviewed_at, motif: k.review_reason,
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
      const vide = this.etat === 'verified'
        ? 'Aucun dossier validé pour l\'instant.'
        : this.etat === 'rejected'
          ? 'Aucun dossier refusé pour l\'instant.'
          : 'Rien en attente. La file est vide.';
      zone.innerHTML = `<p style="padding:26px;background:#FFF6E8;border-radius:12px;font-size:15px;font-weight:700;text-align:center">${txt(vide)}</p>`;
      return;
    }

    zone.innerHTML = vus.map(e => `
      <article style="background:#FFF6E8;border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:14px">
        <div style="display:flex;align-items:center;gap:16px">
          <span style="background:#2B1D12;color:#FFF6E8;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;flex-shrink:0">${txt(e.tag)}</span>
          <span style="flex:1;min-width:0">
            <span style="display:block;font-size:15px;font-weight:700">${txt(e.titre)}</span>
            <span style="display:block;font-size:13px;color:#7A6A55;margin-top:2px">${txt(e.meta)}</span>
          </span>
          ${e.lien ? `<a href="${txt(e.lien)}" target="_blank" rel="noopener"
               style="font-size:13px;font-weight:700;text-decoration:underline;flex-shrink:0">Voir la pièce</a>` : ''}
        </div>
        ${e.source === 'kyc' ? this.dossierIdentite(e) : ''}
        ${this.decisions(e)}
      </article>`).join('');
  },

  /**
   * Une image du dossier, ou la place qu'elle occuperait.
   *
   * L'ABSENCE EST MONTRÉE, pas passée sous silence : un dossier auquel il
   * manque une face se refuse, encore faut-il le voir.
   */
  vignette(url, libelle) {
    if (!url) {
      return `<figure style="margin:0;flex:1;min-width:0">
           <div style="width:100%;height:150px;border:2px dashed #B9A98E;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#B9A98E">Absente</div>
           <figcaption style="font-size:12px;font-weight:700;color:#7A6A55;margin-top:4px">${txt(libelle)}</figcaption>
         </figure>`;
    }

    return `<figure style="margin:0;flex:1;min-width:0">
           <a href="${txt(url)}" target="_blank" rel="noopener">
             <img src="${txt(url)}" alt="${txt(libelle)}" loading="lazy"
                  style="width:100%;height:150px;object-fit:cover;border:2px solid #2B1D12;border-radius:10px;background:#FFF">
           </a>
           <figcaption style="font-size:12px;font-weight:700;color:#7A6A55;margin-top:4px">${txt(libelle)}</figcaption>
         </figure>`;
  },

  /**
   * Le dossier d'identité : recto, verso, selfie, et ce que l'extraction a lu.
   *
   * LES IMAGES SONT SERVIES PAR UNE ROUTE AUTHENTIFIÉE, jamais par un lien
   * signé : elles sont chiffrées au repos, et un lien signé est une capacité au
   * porteur — quiconque le recopie ouvre la pièce d'identité de quelqu'un. Ici
   * le rôle est revérifié à chaque requête, et rien n'est mis en cache.
   *
   * LE NUMÉRO DE PIÈCE N'EST NULLE PART, et ne peut pas y être : le serveur n'en
   * garde qu'une empreinte. C'est la CONCORDANCE entre le visage et le document
   * que l'agent apprécie, pas un numéro qu'il recopierait.
   */
  dossierIdentite(e) {
    const extraits = e.extraction && typeof e.extraction === 'object'
      ? Object.entries(e.extraction).map(([c, v]) => `${txt(c)} : <strong>${txt(v)}</strong>`).join(' · ')
      : null;

    const declare = e.declare || {};
    const anciennete = declare.account_created_at
      ? new Date(declare.account_created_at).toLocaleDateString('fr-FR')
      : '—';

    return `
      <div style="background:#FFF;border-radius:10px;padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#7A6A55;letter-spacing:.4px">DÉCLARÉ PAR LA PERSONNE</div>
        <div style="font-size:20px;font-weight:700;margin-top:4px">${txt(declare.full_name || '— aucun nom renseigné —')}</div>
        <div style="font-size:13px;color:#5C4A33;margin-top:4px">Compte ouvert le ${txt(anciennete)}</div>
        <div style="font-size:12px;color:#7A6A55;margin-top:8px;line-height:1.5">
          ↓ Compare ce nom avec celui imprimé sur la pièce. S'ils diffèrent, refuse en le disant :
          c'est le motif qui permettra à la personne de corriger.
        </div>
      </div>
      <div style="display:flex;gap:10px">
        ${Moderation.vignette(e.images.id_front, 'Pièce — recto')}
        ${Moderation.vignette(e.images.id_back, 'Pièce — verso')}
        ${Moderation.vignette(e.images.selfie, 'Selfie')}
      </div>
      ${Moderation.sequence(e.liveness)}
      <p style="margin:0;font-size:13px;color:#5C4A33;line-height:1.6">
        ${extraits ? extraits : 'Aucune extraction automatique : apprécie la concordance à l\'œil.'}
      </p>
      <p style="margin:0;font-size:12px;color:#7A6A55;line-height:1.5">
        Le numéro de la pièce n'est pas conservé en clair, il ne peut pas t'être montré.
      </p>`;
  },

  /**
   * La séquence de vivacité — et ce qu'elle vaut, dit sans détour.
   *
   * CE N'EST PAS UNE VÉRIFICATION AUTOMATIQUE, et l'écran doit le marteler.
   * Le téléphone a guidé la prise de vue ; il n'a rien certifié, et une
   * application modifiée enverrait ce qu'elle veut. Ces images servent à ce que
   * l'agent VOIE le visage tourner — une photo imprimée ne tourne pas la tête.
   * Un agent qui croirait à un contrôle automatique cesserait de regarder, et
   * ce serait pire que de ne rien afficher du tout.
   */
  sequence(liveness) {
    const consignes = { left: 'Tête tournée à gauche', right: 'Tête tournée à droite' };
    const prises = (liveness && liveness.frames) || [];

    if (!prises.length) {
      return `
        <p style="margin:0;font-size:12px;color:#A33;line-height:1.5;font-weight:700">
          ⚠️ Aucune séquence de vivacité : ce dossier ne porte qu'un selfie, et une photo
          de photo peut passer. Regarde la cohérence entre le visage, le document et
          l'éclairage.
        </p>`;
    }

    return `
      <div>
        <div style="font-size:12px;font-weight:700;color:#7A6A55;letter-spacing:.4px;margin-bottom:6px">
          SÉQUENCE DE VIVACITÉ
        </div>
        <div style="display:flex;gap:10px">
          ${prises.map((p) => Moderation.vignette(p.url, consignes[p.label] || p.label)).join('')}
        </div>
        <p style="margin:8px 0 0;font-size:12px;color:#7A6A55;line-height:1.5">
          ⚠️ ${txt((liveness && liveness.notice) || '')}
          <br>Ce que tu cherches : le MÊME visage, sous des angles réellement différents,
          avec un éclairage cohérent. Une photo imprimée ne tourne pas la tête.
        </p>
      </div>`;
  },

  /**
   * Les boutons de décision. SANS EUX, LA FILE NE SERT À RIEN : quelqu'un dépose
   * son dossier, personne ne peut le valider, et la promesse « vérifié sous
   * 48 heures » ne tient à rien.
   *
   * Les libellés disent la CONSÉQUENCE, pas l'action : « Valider l'identité »
   * plutôt que « OK ». Un agent enchaîne des dizaines de dossiers ; c'est
   * exactement là qu'un libellé vague fait cliquer à côté.
   */
  decisions(e) {
    // UN DOSSIER TRANCHÉ NE SE RETRANCHE PAS : le service refuse une seconde
    // décision, et un bouton qui échouerait toujours enseignerait qu'elle est
    // concevable. On rend compte de ce qui a été décidé, et du motif — c'est ce
    // qui rend la décision relisible, et contestable.
    if (e.decide) {
      return `<p style="margin:0;font-size:13px;color:#5C4A33;line-height:1.6">
        <strong>${txt(e.decision)}</strong>${e.decideLe ? ' · le ' + new Date(e.decideLe).toLocaleString('fr-FR') : ''}
        ${e.motif ? '<br>Motif communiqué : ' + txt(e.motif) : ''}
      </p>`;
    }

    const bouton = (action, libelle, fond, encre) =>
      `<button type="button" data-decision="${txt(e.source)}" data-id="${txt(e.id)}" data-action="${txt(action)}"
               style="background:${fond};color:${encre};border:2px solid #2B1D12;border-radius:999px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer">${txt(libelle)}</button>`;

    if (e.source === 'kyc') {
      return `<div style="display:flex;gap:8px;flex-wrap:wrap">
        ${bouton('verified', "Valider l'identité", '#3F8F5B', '#FFF6E8')}
        ${bouton('rejected', 'Refuser — motif obligatoire', '#FFF', '#2B1D12')}
      </div>`;
    }

    if (e.source === 'documents') {
      return `<div style="display:flex;gap:8px;flex-wrap:wrap">
        ${bouton('accepted', 'Accepter la pièce', '#3F8F5B', '#FFF6E8')}
        ${bouton('rejected', 'Refuser — motif obligatoire', '#FFF', '#2B1D12')}
        ${bouton('suspected_forgery', 'Falsification suspectée', '#B23A3A', '#FFF6E8')}
      </div>`;
    }

    // Les réclamations se tranchent sur une grille pondérée, pas sur deux
    // boutons : les y réduire ferait décider d'un transfert de propriété d'un
    // clic. Elles restent listées, et s'instruisent ailleurs.
    return `<p style="margin:0;font-size:12px;color:#7A6A55">Une réclamation se tranche sur la grille d'arbitrage, pas depuis cette file.</p>`;
  },

  /**
   * Applique une décision.
   *
   * LE MOTIF EST EXIGÉ AU REFUS, jamais à l'acceptation : un dossier refusé sans
   * raison se redépose à l'identique, et se fait refuser à l'identique. C'est
   * aussi ce qui rend la décision contestable.
   */
  async decider(source, id, action) {
    let motif = null;

    if (action !== 'verified' && action !== 'accepted') {
      motif = window.prompt('Motif du refus (il sera rendu à la personne) :');

      if (motif === null) return;

      motif = motif.trim();

      if (motif === '') {
        window.alert('Un refus sans motif ne se corrige pas. Indique la raison.');
        return;
      }
    }

    try {
      if (source === 'kyc') {
        await Api.post(`/api/v1/admin/kyc/${id}/review`, { verified: action === 'verified', reason: motif });
      } else {
        await Api.post(`/api/v1/admin/documents/${id}/review`, { status: action, reason: motif });
      }

      // On RECHARGE plutôt que de retirer la ligne : la décision peut avoir fait
      // monter le niveau de fiabilité de plusieurs biens, et un écran qui garde
      // un état deviné finit par mentir.
      await this.charger();
    } catch (err) {
      window.alert(err.message || 'La décision n\'a pas pu être enregistrée.');
    }
  },

  etat: 'pending',

  init() {
    document.querySelectorAll('[data-etat]').forEach(b => {
      b.addEventListener('click', () => {
        this.etat = b.dataset.etat;
        document.querySelectorAll('[data-etat]').forEach(x => {
          const actif = x.dataset.etat === this.etat;
          x.style.background = actif ? '#2B1D12' : 'transparent';
          x.style.color = actif ? '#FFF6E8' : '#2B1D12';
        });
        this.charger();
      });
    });

    // Délégation, et jamais d'attribut `onclick` : la politique de sécurité de
    // la console interdit `script-src 'unsafe-inline'` — une console qui affiche
    // des pièces d'identité n'a pas les moyens d'autoriser ce qu'une faille XSS
    // injecterait.
    const zone = document.getElementById('file-revue');

    if (zone) {
      zone.addEventListener('click', (ev) => {
        const cible = ev.target.closest('[data-decision]');
        if (!cible) return;

        this.decider(cible.dataset.decision, cible.dataset.id, cible.dataset.action);
      });
    }

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
            <tr data-bien="${txt(a.id)}" style="border-top:1px solid #E4DBC8;cursor:pointer">
              <td style="padding:12px 14px;font-size:13px;font-weight:700;font-family:ui-monospace,monospace">${txt(a.identifier)}<br><span style="font-size:11px;color:#7A6A55;font-weight:400">${txt(a.public_ref)}</span></td>
              <td style="padding:12px 14px;font-size:14px">${txt(a.label || '—')}<br><span style="font-size:11px;color:#7A6A55">${txt(a.category)}</span></td>
              <td style="padding:12px 14px">${pastille(a.life_status_label, a.life_status === 'V-VOL' ? '#B23A3A' : '#2B1D12', '#FFF6E8')}</td>
              <td style="padding:12px 14px">${pastille(a.trust_level_label, '#FFF', '#2B1D12')}</td>
              <td style="padding:12px 14px;font-size:13px;color:#5C4A33">${a.registered_at ? new Date(a.registered_at).toLocaleDateString('fr-FR') : '—'}</td>
              <td style="padding:12px 14px;font-size:14px;font-weight:700">${txt(a.lookups_30d)}</td>
            </tr>`).join('')}</tbody>
        </table>
        <div id="dossier-bien" style="margin-top:20px"></div>`;

      pagination('pagination-registre', r.pagination, n => { this.page = n; this.charger(); });
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  /**
   * Le dossier complet d'un bien.
   *
   * IL NE DIT RIEN DU DÉTENTEUR, et l'écran le DIT plutôt que de laisser
   * chercher : la règle métier absolue n° 4 ne connaît pas d'exception interne,
   * et un champ absent sans explication se lit comme un défaut. Le chemin prévu
   * — la levée d'anonymat sur réquisition — est nommé à sa place.
   */
  async ouvrir(id) {
    const zone = document.getElementById('dossier-bien');
    if (!zone) return;

    zone.innerHTML = `<p style="color:#7A6A55;font-size:14px">Chargement du dossier…</p>`;

    try {
      const r = await Api.get('/api/v1/admin/assets/' + encodeURIComponent(id));
      const a = r.asset;

      const ligne = (c, v) => `<div style="display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-top:1px solid #E4DBC8"><span style="color:#7A6A55;font-size:13px">${txt(c)}</span><strong style="font-size:14px;text-align:right">${txt(v)}</strong></div>`;

      const attributs = a.attributes && typeof a.attributes === 'object'
        ? Object.entries(a.attributes).map(([c, v]) => ligne(c, v)).join('')
        : '';

      // Les pièces sont servies par une route authentifiée : jamais un lien
      // signé, qui serait une capacité au porteur sur un titre de propriété.
      const pieces = (r.documents || []).length
        ? (r.documents || []).map(d => `
            <figure style="margin:0">
              <a href="${txt(d.file_url)}" target="_blank" rel="noopener">
                <img src="${txt(d.file_url)}" alt="${txt(d.doc_type_label)}" loading="lazy"
                     style="width:100%;height:130px;object-fit:cover;border:2px solid #2B1D12;border-radius:10px;background:#FFF">
              </a>
              <figcaption style="font-size:12px;font-weight:700;color:#7A6A55;margin-top:4px">${txt(d.doc_type_label)} · ${txt(d.review_status_label)}</figcaption>
            </figure>`).join('')
        : `<p style="color:#7A6A55;font-size:14px">Aucune pièce déposée.</p>`;

      const histoire = (r.history || []).length
        ? (r.history || []).map(h => `
            <div style="display:flex;gap:12px;padding:8px 0;border-top:1px solid #E4DBC8;font-size:13px">
              <span style="color:#7A6A55;white-space:nowrap">${h.at ? new Date(h.at).toLocaleDateString('fr-FR') : '—'}</span>
              <span style="flex:1"><strong>${txt(h.to_status_label)}</strong> ${h.reason ? '· ' + txt(h.reason) : ''}</span>
              <span style="color:#7A6A55">${txt(h.trigger_type)}</span>
            </div>`).join('')
        : `<p style="color:#7A6A55;font-size:14px">Aucun changement de statut.</p>`;

      zone.innerHTML = `
        <section style="background:#FFF6E8;border-radius:12px;padding:20px 22px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:8px">
            <h2 style="margin:0;font-size:20px">${txt(a.identifier)}</h2>
            <button type="button" data-fermer-dossier
                    style="background:transparent;border:2px solid #2B1D12;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:700;cursor:pointer">Fermer</button>
          </div>
          ${ligne('Référence publique', a.public_ref)}
          ${ligne('Catégorie', a.category)}
          ${attributs}
          ${ligne('Statut', a.life_status_label)}
          ${ligne('Fiabilité', a.trust_level_label)}
          ${ligne('Enregistré le', a.registered_at ? new Date(a.registered_at).toLocaleString('fr-FR') : '—')}
          ${a.stolen_declared_at ? ligne('Vol déclaré le', new Date(a.stolen_declared_at).toLocaleString('fr-FR')) : ''}
          ${ligne('Enregistrement actif', a.is_active ? 'oui' : 'non — archivé')}
          ${ligne('Consultations / 30 j', a.lookups_30d)}

          <h3 style="margin:20px 0 10px;font-size:16px">Pièces déposées</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">${pieces}</div>

          <h3 style="margin:20px 0 10px;font-size:16px">Historique</h3>
          ${histoire}

          <p style="margin:20px 0 0;padding:14px 16px;background:#FFF;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
            🔒 ${txt((r.holder || {}).notice || '')}
          </p>
        </section>`;

      zone.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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

    // Délégation : le tableau est réécrit à chaque page, et la politique de
    // sécurité interdit les attributs `onclick`.
    const table = document.getElementById('table-registre');

    if (table) {
      table.addEventListener('click', (ev) => {
        if (ev.target.closest('[data-fermer-dossier]')) {
          const zone = document.getElementById('dossier-bien');
          if (zone) zone.innerHTML = '';
          return;
        }

        const ligne = ev.target.closest('[data-bien]');
        if (ligne) this.ouvrir(ligne.dataset.bien);
      });
    }

    this.charger();
  },
};

/**
 * Tarifs de la plateforme.
 *
 * ZÉRO EST AFFICHÉ COMME « GRATUIT », pas comme un champ vide : c'est une
 * décision, et elle doit se lire comme telle. Un administrateur qui voit « 0 »
 * sans explication se demandera s'il a oublié de remplir.
 */
const Tarifs = {
  async charger() {
    const zone = document.getElementById('liste-tarifs');
    if (!zone) return;

    try {
      const r = await Api.get('/api/v1/admin/pricing');

      zone.innerHTML = (r.pricing || []).map(t => `
        <div style="background:#FFF6E8;border-radius:12px;padding:16px 18px;margin-bottom:12px">
          <label for="tarif-${txt(t.key)}" style="display:block;font-size:15px;font-weight:700">${txt(t.label)}</label>
          <p style="margin:6px 0 12px;font-size:13px;color:#5C4A33;line-height:1.6">${txt(t.help)}</p>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input id="tarif-${txt(t.key)}" data-tarif="${txt(t.key)}" type="number" min="0" step="50"
                   value="${txt(t.amount_fcfa)}" inputmode="numeric"
                   style="width:160px;padding:11px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:15px;font-weight:700">
            <span style="font-size:14px;color:#5C4A33">FCFA</span>
            <span data-libelle-tarif="${txt(t.key)}" style="font-size:13px;font-weight:700;color:${t.free ? '#3F8F5B' : '#7A6A55'}">
              ${t.free ? '→ gratuit pour tout le monde' : ''}
            </span>
          </div>
        </div>`).join('');

      // Le libellé « gratuit » suit la saisie, avant même l'enregistrement :
      // c'est la conséquence du chiffre, elle doit se voir en le tapant.
      zone.querySelectorAll('[data-tarif]').forEach(champ => {
        champ.addEventListener('input', () => {
          const marque = zone.querySelector(`[data-libelle-tarif="${champ.dataset.tarif}"]`);
          if (!marque) return;
          const gratuit = Number(champ.value) === 0;
          marque.textContent = gratuit ? '→ gratuit pour tout le monde' : '';
          marque.style.color = gratuit ? '#3F8F5B' : '#7A6A55';
        });
      });

      const bandeau = document.getElementById('etat-paystack');
      const p = r.paystack || {};

      if (bandeau) {
        bandeau.innerHTML = p.configured
          ? `<p style="padding:12px 16px;background:#E7F3EA;border-radius:10px;font-size:14px;font-weight:700;color:#2B1D12">✓ Paystack est configuré : les tarifs supérieurs à zéro peuvent être encaissés.</p>`
          : `<p style="padding:12px 16px;background:#FDE7E4;border-radius:10px;font-size:14px;font-weight:700;color:#2B1D12;line-height:1.6">⚠️ ${txt(p.notice || '')}</p>`;
      }
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  init() {
    const form = document.getElementById('form-tarifs');

    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const retour = document.getElementById('retour-tarifs');
        const pricing = {};

        document.querySelectorAll('[data-tarif]').forEach(c => {
          pricing[c.dataset.tarif] = Math.max(0, Number(c.value) || 0);
        });

        try {
          const r = await Api.put('/api/v1/admin/pricing', { pricing });
          const n = Object.keys(r.changed || {}).length;
          retour.textContent = n === 0 ? 'Aucun changement.' : `${n} tarif(s) enregistré(s).`;
          retour.style.color = '#3F8F5B';
          await this.charger();
        } catch (e) {
          retour.textContent = e.message;
          retour.style.color = '#B23A3A';
        }
      });
    }

    const cle = document.getElementById('form-paystack');

    if (cle) {
      cle.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const champ = document.getElementById('cle-paystack');
        const retour = document.getElementById('retour-paystack');

        try {
          await Api.put('/api/v1/admin/pricing/paystack', { secret_key: champ.value });
          // Le champ est vidé aussitôt : une clé secrète ne reste pas à l'écran.
          champ.value = '';
          retour.textContent = 'Clé enregistrée.';
          retour.style.color = '#3F8F5B';
          await this.charger();
        } catch (e) {
          retour.textContent = e.message;
          retour.style.color = '#B23A3A';
        }
      });
    }

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

/* ------------------------------------------------------------------ */
/* Levée d'anonymat sur réquisition (greffée sur l'annuaire des comptes) */
/* ------------------------------------------------------------------ */

const Levee = {
  async registre() {
    const zone = document.getElementById('lv-registre');
    if (!zone) return;

    try {
      const r = await Api.get('/api/v1/admin/disclosures');

      if (!r.disclosures.length) {
        zone.innerHTML = `<p style="font-size:13px;color:#7A6A55">Aucune levée d'anonymat à ce jour.</p>`;
        return;
      }

      zone.innerHTML = `
        <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden">
          <thead><tr style="background:#2B1D12;color:#FFF6E8">
            ${['DATE', 'SUJET', 'DEMANDÉ PAR', 'AUTORITÉ', 'RÉFÉRENCE', 'OBJET']
              .map(h => `<th style="text-align:left;padding:10px 12px;font-size:11px;font-weight:700;letter-spacing:.4px">${h}</th>`).join('')}
          </tr></thead>
          <tbody>${r.disclosures.map(d => `
            <tr style="border-top:1px solid #E4DBC8;vertical-align:top">
              <td style="padding:10px 12px;font-size:13px;color:#5C4A33;white-space:nowrap">${new Date(d.disclosed_at).toLocaleString('fr-FR')}</td>
              <td style="padding:10px 12px;font-size:13px;font-weight:700">#${txt(d.subject_user_id)}</td>
              <td style="padding:10px 12px;font-size:13px">#${txt(d.requested_by)}</td>
              <td style="padding:10px 12px;font-size:13px">${txt(d.authority)}</td>
              <td style="padding:10px 12px;font-size:13px;font-family:ui-monospace,monospace">${txt(d.reference)}<br><span style="font-size:11px;color:#7A6A55">${txt(d.issued_on)}</span></td>
              <td style="padding:10px 12px;font-size:12px;color:#5C4A33;max-width:280px">${txt(d.purpose)}</td>
            </tr>`).join('')}</tbody>
        </table>`;
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  async soumettre() {
    const zone = document.getElementById('lv-resultat');
    const v = id => ((document.getElementById(id) || {}).value || '').trim();

    // Une dernière confirmation explicite : c'est l'unique opération de la
    // console qui rend une identité, et elle ne doit jamais partir d'un clic
    // machinal.
    if (!window.confirm(
      "Confirmer la levée d'anonymat ?\n\n" +
      "Elle sera consignée définitivement dans la chaîne d'audit et au registre des levées, " +
      "sous votre compte. Le sujet n'en sera pas informé."
    )) return;

    try {
      const r = await Api.post('/api/v1/admin/disclosures', {
        user_id: Number(v('lv-user')),
        authority: v('lv-autorite'),
        reference: v('lv-reference'),
        issued_on: v('lv-date'),
        purpose: v('lv-objet'),
      });

      const i = r.identity;

      zone.innerHTML = `
        <div style="background:#fff;border:2px solid #B23A3A;border-radius:10px;padding:16px 18px">
          <p style="font-size:12px;color:#B23A3A;font-weight:700;margin-bottom:10px">
            IDENTITÉ DIVULGUÉE — ${new Date(r.disclosed_at).toLocaleString('fr-FR')}
          </p>
          <p style="font-size:15px;font-weight:700">${txt(i.full_name || '—')}</p>
          <p style="font-size:13px;color:#5C4A33;font-family:ui-monospace,monospace;margin-top:4px">${txt(i.phone || '—')} · ${txt(i.email || '—')}</p>
          <p style="font-size:13px;color:#5C4A33;margin-top:6px">
            Compte ${txt(i.account_status)} · KYC ${txt(i.kyc_status)} ·
            inscrit le ${i.registered_at ? new Date(i.registered_at).toLocaleDateString('fr-FR') : '—'}
          </p>
          <p style="font-size:13px;font-weight:700;margin-top:12px">Biens rattachés (${i.assets.length})</p>
          ${i.assets.map(a => `<p style="font-size:13px;font-family:ui-monospace,monospace;color:#5C4A33">${txt(a.identifier)} · ${txt(a.public_ref)} · ${txt(a.life_status)}</p>`).join('') || '<p style="font-size:13px;color:#7A6A55">Aucun.</p>'}
          <p style="font-size:12px;color:#7A6A55;margin-top:12px;line-height:1.6">${txt(i.id_number_note)}</p>
          <p style="font-size:12px;color:#7A6A55;margin-top:6px;line-height:1.6">${txt(r.notice)}</p>
        </div>`;

      this.registre();
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  init() {
    const bouton = document.getElementById('lv-soumettre');
    if (bouton) bouton.addEventListener('click', () => this.soumettre());
    this.registre();
  },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('liste-tarifs')) Tarifs.init();
  if (document.getElementById('table-registre')) Registre.init();
  if (document.getElementById('table-comptes')) Comptes.init();
  if (document.getElementById('form-levee')) Levee.init();
});

/* ------------------------------------------------------------------ */
/* Écran : Catégories & champs                                         */
/* ------------------------------------------------------------------ */

const Categories = {
  async charger() {
    const zone = document.getElementById('liste-categories');
    if (!zone) return;

    try {
      const r = await Api.get('/api/v1/admin/categories');

      const version = document.getElementById('version-catalogue');
      if (version) version.textContent = r.version || '—';

      zone.innerHTML = r.categories.map(c => `
        <section style="background:#FFF6E8;border-radius:12px;padding:18px 20px;margin-bottom:14px;${c.is_active ? '' : 'opacity:.62'}">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:10px;min-width:0">
              <span style="font-size:22px">${txt(c.icon || '📦')}</span>
              <span>
                <span style="display:block;font-size:16px;font-weight:700">${txt(c.name)}</span>
                <span style="display:block;font-size:12px;color:#7A6A55;font-family:ui-monospace,monospace">${txt(c.key)}</span>
              </span>
              ${c.is_active ? '' : pastille('DÉSACTIVÉE', '#B23A3A', '#FFF6E8')}
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <span style="font-size:13px;color:#5C4A33">${txt(c.assets)} bien(s) rattaché(s)</span>
              <button data-champ="${txt(c.id)}"
                      style="background:transparent;color:#2B1D12;border:2px solid #2B1D12;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer">
                Ajouter un champ
              </button>
              <button data-cat="${txt(c.id)}" data-actif="${c.is_active ? '0' : '1'}" data-biens="${txt(c.assets)}" data-nom="${txt(c.name)}"
                      style="background:${c.is_active ? 'transparent' : '#2B1D12'};color:${c.is_active ? '#B23A3A' : '#FFF6E8'};border:2px solid ${c.is_active ? '#B23A3A' : '#2B1D12'};border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer">
                ${c.is_active ? 'Désactiver' : 'Réactiver'}
              </button>
            </div>
          </div>

          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
            ${c.fields.map(f => `
              <span style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #E4DBC8;border-radius:8px;padding:6px 10px;font-size:13px">
                <strong>${txt(f.label)}</strong>
                <span style="color:#7A6A55;font-family:ui-monospace,monospace;font-size:11px">${txt(f.key)} · ${txt(f.type)}</span>
                ${f.required ? pastille('obligatoire', '#2B1D12', '#FFF6E8') : ''}
                ${f.canonical ? pastille('identifiant', '#D97706', '#2B1D12') : ''}
              </span>`).join('') || '<span style="font-size:13px;color:#7A6A55">Aucun champ.</span>'}
          </div>
        </section>`).join('');

      zone.querySelectorAll('[data-cat]').forEach(b =>
        b.addEventListener('click', () => this.basculer(b.dataset)));
      zone.querySelectorAll('[data-champ]').forEach(b =>
        b.addEventListener('click', () => this.ajouterChamp(b.dataset.champ)));
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  async basculer(d) {
    const activer = d.actif === '1';

    // Le nombre de biens rattachés est rappelé AVANT la confirmation : une
    // désactivation sur une catégorie peuplée n'a pas la même portée que sur
    // une catégorie vide, et l'écart ne se voit qu'ici.
    if (!activer && !window.confirm(
      `Désactiver « ${d.nom} » ?\n\n` +
      `${d.biens} bien(s) y sont rattachés : ils resteront enregistrés et consultables, ` +
      `mais la catégorie disparaîtra des nouveaux enregistrements dès la publication.`
    )) return;

    try {
      const r = await Api.post(`/api/v1/admin/categories/${d.cat}/active`, { active: activer });
      window.alert(r.message);
      this.charger();
    } catch (e) {
      window.alert(e.message);
    }
  },

  async ajouterChamp(id) {
    const cle = window.prompt("Clé technique du champ (minuscules, chiffres et « _ ») :");
    if (!cle || !cle.trim()) return;

    const libelle = window.prompt('Libellé affiché aux utilisateurs :');
    if (!libelle || !libelle.trim()) return;

    const type = window.prompt('Type : text, number, date, identifier ou select', 'text');
    if (!type || !type.trim()) return;

    try {
      const r = await Api.post(`/api/v1/admin/categories/${id}/fields`, {
        key: cle.trim(),
        label: libelle.trim(),
        type: type.trim(),
        required: window.confirm('Ce champ est-il obligatoire ?'),
      });
      window.alert(r.message);
      this.charger();
    } catch (e) {
      window.alert(e.message);
    }
  },

  init() {
    const publier = document.getElementById('publier');

    if (publier) {
      publier.addEventListener('click', async () => {
        try {
          const r = await Api.post('/api/v1/admin/categories/publish');
          window.alert(r.message);
          this.charger();
        } catch (e) {
          window.alert(e.message);
        }
      });
    }

    this.charger();
  },
};

/* ------------------------------------------------------------------ */
/* Écran : Piste d'audit                                               */
/* ------------------------------------------------------------------ */

const Audit = {
  page: 1,

  parametres() {
    const p = new URLSearchParams();
    const v = id => (document.getElementById(id) || {}).value || '';
    if (v('fa-action')) p.set('action', v('fa-action'));
    if (v('fa-entite')) p.set('entity_type', v('fa-entite'));
    if (v('fa-du')) p.set('from', v('fa-du'));
    if (v('fa-au')) p.set('to', v('fa-au'));
    return p;
  },

  async charger() {
    const zone = document.getElementById('table-audit');
    if (!zone) return;

    const p = this.parametres();
    p.set('page', String(this.page));

    try {
      const r = await Api.get('/api/v1/admin/audit-trail?' + p.toString());

      if (!r.entries.length) {
        zone.innerHTML = `<p style="padding:26px;background:#FFF6E8;border-radius:12px;font-size:15px;font-weight:700;text-align:center">Aucune entrée ne correspond.</p>`;
        pagination('pagination-audit', r.pagination, () => {});
        return;
      }

      zone.innerHTML = `
        <table style="width:100%;border-collapse:collapse;background:#FFF6E8;border-radius:12px;overflow:hidden">
          <thead><tr style="background:#2B1D12;color:#FFF6E8">
            ${['HORODATAGE', 'ACTEUR', 'ACTION', 'ENTITÉ', 'CHARGE UTILE', 'EMPREINTE']
              .map(h => `<th style="text-align:left;padding:12px 14px;font-size:11px;font-weight:700;letter-spacing:.4px">${h}</th>`).join('')}
          </tr></thead>
          <tbody>${r.entries.map(e => `
            <tr style="border-top:1px solid #E4DBC8;vertical-align:top">
              <td style="padding:12px 14px;font-size:13px;color:#5C4A33;white-space:nowrap">${new Date(e.at).toLocaleString('fr-FR')}</td>
              <td style="padding:12px 14px;font-size:13px">${txt(e.actor_type)}${e.actor_id ? ' #' + txt(e.actor_id) : ''}</td>
              <td style="padding:12px 14px;font-size:13px;font-weight:700;font-family:ui-monospace,monospace">${txt(e.action)}</td>
              <td style="padding:12px 14px;font-size:13px">${txt(e.entity_type)} #${txt(e.entity_id)}</td>
              <td style="padding:12px 14px;font-size:12px;color:#5C4A33;max-width:340px;word-break:break-word;font-family:ui-monospace,monospace">${txt(JSON.stringify(e.payload))}</td>
              <td style="padding:12px 14px;font-size:11px;color:#7A6A55;font-family:ui-monospace,monospace" title="${txt(e.chain_hash)}">${txt((e.chain_hash || '').slice(0, 12))}…</td>
            </tr>`).join('')}</tbody>
        </table>`;

      pagination('pagination-audit', r.pagination, n => { this.page = n; this.charger(); });
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  init() {
    const relancer = differer(() => { this.page = 1; this.charger(); });
    ['fa-action', 'fa-entite', 'fa-du', 'fa-au'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener(el.type === 'date' ? 'change' : 'input', relancer);
    });

    const exporter = document.getElementById('exporter');

    if (exporter) {
      // Navigation directe plutôt que `fetch` : l'export est diffusé en flux et
      // peut peser des dizaines de mégaoctets — le rassembler en mémoire pour
      // fabriquer un lien local ferait échouer l'export précisément sur les
      // journaux volumineux, les seuls pour lesquels il compte.
      exporter.addEventListener('click', () => {
        window.location.href = '/api/v1/admin/audit-trail/export?' + this.parametres().toString();
      });
    }

    this.charger();
  },
};

/* ------------------------------------------------------------------ */
/* Écran : Équipe & rôles                                              */
/* ------------------------------------------------------------------ */

const Equipe = {
  async charger() {
    const zone = document.getElementById('liste-equipe');
    if (!zone) return;

    try {
      const r = await Api.get('/api/v1/admin/team');

      zone.innerHTML = `
        <table style="width:100%;border-collapse:collapse;background:#FFF6E8;border-radius:12px;overflow:hidden">
          <thead><tr style="background:#2B1D12;color:#FFF6E8">
            ${['MEMBRE', 'ADRESSE', 'RÔLE', 'PAGES ATTEINTES', 'ACTION']
              .map(h => `<th style="text-align:left;padding:12px 14px;font-size:11px;font-weight:700;letter-spacing:.4px">${h}</th>`).join('')}
          </tr></thead>
          <tbody>${r.members.map(m => `
            <tr style="border-top:1px solid #E4DBC8;vertical-align:top">
              <td style="padding:12px 14px;font-size:14px;font-weight:700">${txt(m.name || '—')}${m.status === 'suspended' ? ' ' + pastille('SUSPENDU', '#B23A3A', '#FFF6E8') : ''}</td>
              <td style="padding:12px 14px;font-size:13px;font-family:ui-monospace,monospace;color:#7A6A55">${txt(m.email || '—')}</td>
              <td style="padding:12px 14px">${pastille(m.role_label, m.role === 'admin' ? '#D97706' : '#FFF', '#2B1D12')}</td>
              <td style="padding:12px 14px;font-size:12px;color:#5C4A33;max-width:320px">${txt(m.pages.join(' · ')) || '—'}</td>
              <td style="padding:12px 14px">
                <button data-membre="${txt(m.id)}" data-nom="${txt(m.name || '')}" data-role="${txt(m.role)}"
                        style="background:transparent;color:#2B1D12;border:2px solid #2B1D12;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer">
                  Changer le rôle
                </button>
              </td>
            </tr>`).join('')}</tbody>
        </table>`;

      zone.querySelectorAll('[data-membre]').forEach(b =>
        b.addEventListener('click', () => this.changer(b.dataset)));

      const matrice = document.getElementById('matrice-roles');

      if (matrice) {
        matrice.innerHTML = `
          <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin-bottom:10px">Ce que chaque rôle atteint</h2>
          <p style="font-size:13px;color:#5C4A33;margin-bottom:12px">${txt(r.notice)}</p>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            ${r.roles.map(role => `
              <div style="flex:1;min-width:260px;background:#FFF6E8;border-radius:12px;padding:16px 18px">
                <span style="display:block;font-size:15px;font-weight:700;margin-bottom:8px">${txt(role.label)}</span>
                ${role.pages.map(p => `<span style="display:block;font-size:13px;color:#5C4A33;padding:2px 0">• ${txt(p)}</span>`).join('')}
              </div>`).join('')}
          </div>`;
      }
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  async changer(d) {
    const role = window.prompt(
      `Nouveau rôle pour ${d.nom || 'ce compte'} — actuellement « ${d.role} ».\n\n` +
      "user : aucun accès au back-office\nagent : instruction des dossiers\nadmin : configuration et levée d'anonymat",
      d.role
    );

    if (!role || !role.trim()) return;

    const motif = window.prompt(
      "Motif du changement d'habilitation.\n\n" +
      "Il entre dans la chaîne d'audit avec l'ancien et le nouveau rôle : savoir " +
      "qu'un changement a eu lieu ne suffit pas à juger s'il a élargi ou restreint l'accès."
    );

    if (!motif || !motif.trim()) return;

    try {
      const r = await Api.post(`/api/v1/admin/team/${d.membre}/role`, { role: role.trim(), reason: motif.trim() });
      window.alert(r.message);
      this.charger();
    } catch (e) {
      window.alert(e.message);
    }
  },

  init() { this.charger(); },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('liste-categories')) Categories.init();
  if (document.getElementById('table-audit')) Audit.init();
  if (document.getElementById('liste-equipe')) Equipe.init();
});

/* ------------------------------------------------------------------ */
/* Écran : Vue d'ensemble                                              */
/* ------------------------------------------------------------------ */

/** Ancienneté en langage courant : « depuis 3 jours » se lit, pas une date ISO. */
function anciennete(iso) {
  if (!iso) return '';

  const heures = Math.floor((Date.now() - new Date(iso.replace(' ', 'T')).getTime()) / 3600000);

  if (heures < 1) return "depuis moins d'une heure";
  if (heures < 24) return `depuis ${heures} h`;

  const jours = Math.floor(heures / 24);
  return `depuis ${jours} jour${jours > 1 ? 's' : ''}`;
}

const Ensemble = {
  async charger() {
    const zone = document.getElementById('files-attente');
    if (!zone) return;

    try {
      const r = await Api.get('/api/v1/admin/overview');

      zone.innerHTML = r.queues.map(f => {
        // Le nombre seul ne suffit pas : trois dossiers déposés ce matin et
        // trois oubliés depuis douze jours donnent le même compteur.
        const alerte = f.over_threshold || (f.count > 0 && anciennete(f.oldest_at).includes('jour'));

        return `
          <div style="background:#FFF6E8;border-radius:12px;padding:18px 20px;border-left:6px solid ${alerte ? '#B23A3A' : '#3F8F5B'}">
            <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:30px">${txt(f.count)}</span>
            <span style="display:block;font-size:14px;font-weight:700;margin-top:2px">${txt(f.label)}</span>
            <span style="display:block;font-size:12px;color:${alerte ? '#B23A3A' : '#7A6A55'};margin-top:4px">
              ${f.count === 0 ? 'Rien en attente' : txt('La plus ancienne ' + anciennete(f.oldest_at))}
            </span>
          </div>`;
      }).join('');

      this.promesses(r.promises);
      this.volumes(r.registry);
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  promesses(p) {
    const zone = document.getElementById('promesses');
    if (!zone) return;

    const bloc = (titre, m, unite) => {
      // `meets_target` vaut null quand rien n'a été mesuré : afficher « tenu »
      // sur zéro échantillon serait une promesse auto-décernée.
      const sansMesure = m.p95_ms === null || m.samples === 0;
      const tenu = m.meets_target === true;
      const couleur = sansMesure ? '#7A6A55' : (tenu ? '#3F8F5B' : '#B23A3A');

      return `
        <div style="background:#FFF6E8;border-radius:12px;padding:18px 20px;border-left:6px solid ${couleur}">
          <span style="display:block;font-size:14px;font-weight:700">${txt(titre)}</span>
          <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:26px;margin-top:6px">
            ${sansMesure ? '—' : txt(unite(m.p95_ms))}
          </span>
          <span style="display:block;font-size:12px;color:#5C4A33;margin-top:4px">
            ${sansMesure
              ? 'Aucune mesure sur la période'
              : `95<sup>e</sup> centile sur ${txt(m.samples)} mesures · seuil ${txt(unite(m.target_ms))}`}
          </span>
          ${sansMesure ? '' : `<span style="display:block;font-size:12px;font-weight:700;color:${couleur};margin-top:4px">${tenu ? 'Promesse tenue' : 'Promesse non tenue'}</span>`}
        </div>`;
    };

    zone.innerHTML =
      bloc('CT-01 — verdict de consultation', p.ct01_lookup, ms => `${ms} ms`) +
      bloc("CT-02 — enregistrement d'un bien", p.ct02_registration, ms => `${Math.round(ms / 1000)} s`);
  },

  volumes(r) {
    const zone = document.getElementById('volumes');
    if (!zone) return;

    const part = r.lookups_7d === 0 ? null : Math.round((r.unknown_lookups_7d / r.lookups_7d) * 100);

    zone.innerHTML =
      carte('Biens enregistrés actifs', r.active_assets, '', '#2B1D12') +
      carte('Comptes', r.accounts, '', '#2B1D12') +
      carte('Consultations · 7 j', r.lookups_7d, '', '#2B1D12') +
      // Une part d'identifiants inconnus qui grimpe signale un balayage du
      // registre, pas un afflux d'acheteurs.
      carte('Dont sans correspondance', r.unknown_lookups_7d,
        part === null ? '' : `${part} % des consultations`,
        part !== null && part > 50 ? '#B23A3A' : '#2B1D12');

    const statuts = document.getElementById('repartition-statuts');

    if (statuts) {
      statuts.innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          ${r.by_status.map(s => `
            <span style="display:inline-flex;align-items:center;gap:8px;background:#FFF6E8;border-radius:10px;padding:8px 14px;font-size:13px">
              <strong>${txt(s.count)}</strong>
              <span style="color:#5C4A33">${txt(s.label)}</span>
            </span>`).join('')}
        </div>`;
    }
  },

  init() { this.charger(); },
};

/* ------------------------------------------------------------------ */
/* Écran : Statistiques app                                            */
/* ------------------------------------------------------------------ */

const Stats = {
  async charger() {
    const zone = document.getElementById('parc');
    if (!zone) return;

    const jours = (document.getElementById('fenetre-stats') || {}).value || '30';

    try {
      const r = await Api.get('/api/v1/admin/app-stats?days=' + encodeURIComponent(jours));

      const dormants = r.devices.total - r.devices.active_30d;

      zone.innerHTML =
        carte('Appareils annoncés', r.devices.total, '', '#2B1D12') +
        // Un parc qui grossit pendant que la part active fond est une
        // application qu'on installe et qu'on abandonne.
        carte('Vus dans les 30 jours', r.devices.active_30d, '', '#3F8F5B') +
        carte('Sans signe de vie', dormants, '', dormants > r.devices.active_30d ? '#B23A3A' : '#7A6A55') +
        r.devices.by_platform.map(p =>
          carte(p.platform === 'ios' ? 'iOS' : 'Android', p.total, `${p.active_30d} actifs`, '#2B1D12')).join('');

      this.chaleur(r.heatmap);
      this.sources(r.sources);
      this.version(r.release);
    } catch (e) {
      zone.innerHTML = `<p style="color:#B23A3A;font-weight:700">${txt(e.message)}</p>`;
    }
  },

  chaleur(h) {
    const zone = document.getElementById('carte-chaleur');
    if (!zone) return;

    if (h.max === 0) {
      zone.innerHTML = `<p style="padding:22px;background:#FFF6E8;border-radius:12px;font-size:14px;text-align:center">Aucune consultation sur la période.</p>`;
      return;
    }

    const cellule = (n) => {
      // Racine plutôt que proportion linéaire : sur une distribution où l'heure
      // de pointe écrase tout, une échelle linéaire rendrait toutes les autres
      // heures identiquement pâles et la carte ne dirait plus rien.
      const intensite = n === 0 ? 0 : Math.sqrt(n / h.max);
      const fond = n === 0 ? '#FFF6E8' : `rgba(217,119,6,${(0.15 + intensite * 0.85).toFixed(2)})`;

      return `<td title="${txt(n)} consultation(s)" style="background:${fond};width:26px;height:22px;border:1px solid #EFE9DC"></td>`;
    };

    zone.innerHTML = `
      <table style="border-collapse:collapse">
        <thead><tr>
          <th></th>
          ${Array.from({ length: 24 }, (_, i) => `<th style="font-size:10px;font-weight:700;color:#7A6A55;padding-bottom:4px">${i % 3 === 0 ? i : ''}</th>`).join('')}
        </tr></thead>
        <tbody>${h.grid.map((ligne, i) => `
          <tr>
            <th style="text-align:right;padding-right:8px;font-size:11px;font-weight:700;color:#5C4A33;white-space:nowrap">${txt(h.days[i])}</th>
            ${ligne.map(cellule).join('')}
          </tr>`).join('')}</tbody>
      </table>
      <p style="font-size:12px;color:#7A6A55;margin-top:8px">Heures locales (${txt(h.timezone)}) · maximum observé : ${txt(h.max)} consultations sur une heure.</p>`;
  },

  sources(sources) {
    const zone = document.getElementById('sources');
    if (!zone) return;

    const total = sources.reduce((s, x) => s + x.count, 0);

    zone.innerHTML = sources.map(s =>
      carte(s.label, s.count, total === 0 ? '' : `${Math.round((s.count / total) * 100)} % du total`, '#2B1D12')).join('');
  },

  version(release) {
    const etat = document.getElementById('etat-version');
    if (etat) etat.textContent = release.note;

    const min = document.getElementById('vr-minimum');
    const derniere = document.getElementById('vr-derniere');
    if (min) min.value = release.minimum_version || '';
    if (derniere) derniere.value = release.latest_version || '';
  },

  async appliquer(minimum) {
    if (minimum !== '' && !window.confirm(
      `Exiger la version ${minimum} ?\n\n` +
      "Les applications antérieures ne pourront plus enregistrer, transférer ni réclamer. " +
      "La consultation d'un identifiant leur restera ouverte.\n\n" +
      "Ce changement est journalisé dans la chaîne d'audit."
    )) return;

    try {
      const r = await Api.put('/api/v1/admin/app-release', {
        minimum_version: minimum === '' ? null : minimum,
        latest_version: ((document.getElementById('vr-derniere') || {}).value || '').trim() || null,
      });

      window.alert(r.message);
      this.version(r.release);
    } catch (e) {
      window.alert(e.message);
    }
  },

  init() {
    const fenetre = document.getElementById('fenetre-stats');
    if (fenetre) fenetre.addEventListener('change', () => this.charger());

    const appliquer = document.getElementById('vr-appliquer');
    if (appliquer) appliquer.addEventListener('click', () =>
      this.appliquer(((document.getElementById('vr-minimum') || {}).value || '').trim()));

    const lever = document.getElementById('vr-lever');
    if (lever) lever.addEventListener('click', () => {
      const champ = document.getElementById('vr-minimum');
      if (champ) champ.value = '';
      this.appliquer('');
    });

    this.charger();
  },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('files-attente')) Ensemble.init();
  if (document.getElementById('parc')) Stats.init();
});

/* ------------------------------------------------------------------ */
/* Adresses d'exploitation (écran Supervision)                         */
/* ------------------------------------------------------------------ */

const Adresses = {
  /**
   * Un champ, deux états : réglé ou non. Le second n'est pas un détail de
   * présentation — une adresse manquante est une surveillance qui n'existe que
   * sur le papier, ou un droit qu'on ne peut pas exercer.
   */
  peindre(zoneId, champId, valeur, absent, present) {
    const etat = document.getElementById(zoneId);
    const champ = document.getElementById(champId);

    if (champ) champ.value = valeur || '';

    if (etat) {
      etat.textContent = valeur ? present : absent;
      etat.style.color = valeur ? '#3F8F5B' : '#B23A3A';
      etat.style.fontWeight = '700';
    }
  },

  async charger() {
    if (!document.getElementById('ad-ops')) return;

    try {
      const ops = await Api.get('/api/v1/admin/ops-recipient');
      this.peindre('etat-ops', 'ad-ops', ops.recipient,
        'Aucun destinataire : les rapports d’anomalie ne partent nulle part.',
        'Rapports expédiés à cette adresse.');
    } catch (e) {
      this.peindre('etat-ops', 'ad-ops', null, e.message, '');
    }

    try {
      const legal = await Api.get('/api/v1/admin/legal-contact');
      this.peindre('etat-legal', 'ad-legal', legal.contact,
        'Aucun contact publié : la page de confidentialité annonce un guichet en cours d’ouverture.',
        'Publiée sur la page de confidentialité.');
    } catch (e) {
      this.peindre('etat-legal', 'ad-legal', null, e.message, '');
    }
  },

  async enregistrer(url, champ, cle) {
    const valeur = ((document.getElementById(champ) || {}).value || '').trim();

    if (cle === 'contact' && valeur !== '' && !window.confirm(
      `Publier ${valeur} sur la page de confidentialité ?\n\n` +
      'Cette adresse sera visible de tous et doit recevoir et traiter des demandes ' +
      'd’accès, de rectification et d’effacement.'
    )) return;

    try {
      const r = await Api.put(url, { [cle]: valeur === '' ? null : valeur });
      window.alert(r.message);
      this.charger();
    } catch (e) {
      window.alert(e.message);
    }
  },

  init() {
    const ops = document.getElementById('ad-ops-appliquer');
    if (ops) ops.addEventListener('click', () =>
      this.enregistrer('/api/v1/admin/ops-recipient', 'ad-ops', 'recipient'));

    const legal = document.getElementById('ad-legal-appliquer');
    if (legal) legal.addEventListener('click', () =>
      this.enregistrer('/api/v1/admin/legal-contact', 'ad-legal', 'contact'));

    this.charger();
  },
};

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('ad-ops')) Adresses.init();
});
