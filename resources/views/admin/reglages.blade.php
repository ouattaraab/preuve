@extends('admin.layout')

@section('contenu')
    {{--
        Six configurations qui n'avaient AUCUN écran.

        Passerelle SMS, défi anti-automate, lecteur de pièces, push, ancrage
        d'audit, mode lecture seule : toutes réglables par API, aucune par
        l'interface. Un réglage qui ne se pose qu'en base ou avec un client HTTP
        ne se pose pas — et le mode lecture seule est le pire du lot : on en a
        besoin PENDANT un incident, c'est-à-dire quand personne n'a de terminal
        sous la main.

        REGROUPÉES SUR UN SEUL ÉCRAN, et non éparpillées en six pages : ce sont
        toutes des branchements de service tiers, on les consulte ensemble au
        moment d'une mise en service, et une page par clé aurait fait six
        entrées de menu pour six formulaires de deux champs.
    --}}

    <section style="border:2px solid #B23A3A;border-radius:14px;padding:20px;margin-bottom:26px;background:#FFF">
        <h2 style="margin:0 0 6px;font-size:18px;color:#B23A3A">Mode lecture seule</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            Suspend TOUTES les écritures — enregistrement, transfert, déclaration de vol,
            réclamation. <strong>La consultation reste ouverte</strong>, elle ne se coupe
            jamais&nbsp;: c'est la promesse du produit, et la couper punirait des acheteurs
            pour une panne qui n'est pas la leur.
        </p>
        <div id="rg-etat-plateforme" style="margin-bottom:12px;font-size:15px;font-weight:700"></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input id="rg-motif" placeholder="Motif, montré aux utilisateurs (facultatif)" maxlength="200"
                   style="flex:1;min-width:280px;padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button id="rg-basculer" type="button"
                    style="background:#B23A3A;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Basculer
            </button>
        </div>
    </section>

    <section style="border-top:1px solid #E4DBC8;padding-top:22px;margin-bottom:26px">
        <h2 style="margin:0 0 6px;font-size:18px">Passerelle SMS</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            C'est par elle que partent tous les codes d'accès. Tant qu'aucune passerelle
            réelle n'est choisie, les codes partent <strong>par courriel</strong> — ce qui
            fonctionne, mais ferme la plateforme à qui n'a pas d'adresse.
        </p>
        <div id="rg-etat-sms" style="margin-bottom:12px;font-size:14px"></div>
        <label for="rg-sms" style="display:block;font-weight:700;font-size:14px;margin-bottom:6px">Fournisseur</label>
        <select id="rg-sms" style="width:100%;max-width:520px;padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px"></select>
        <p id="rg-sms-aide" style="margin:8px 0 0;font-size:13px;color:#7A6A55;line-height:1.55"></p>
        <div id="rg-sms-champs" style="margin-top:14px;display:flex;flex-direction:column;gap:12px;max-width:520px"></div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button id="rg-sms-appliquer" type="button"
                    style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Enregistrer la passerelle
            </button>
            <input id="rg-sms-essai" placeholder="Numéro d'essai" maxlength="30"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button id="rg-sms-tester" type="button"
                    style="background:transparent;color:#2B1D12;border:2px solid #2B1D12;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer">
                Envoyer un code d'essai
            </button>
        </div>
    </section>

    <section style="border-top:1px solid #E4DBC8;padding-top:22px;margin-bottom:26px">
        <h2 style="margin:0 0 6px;font-size:18px">Défi anti-automate (Cloudflare Turnstile)</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            Sans clés, un visiteur qui atteint le plafond horaire est refusé une heure
            <strong>sans échappatoire</strong>. C'est un problème réel derrière une connexion
            partagée&nbsp;: tout un cybercafé compte pour un seul visiteur.
        </p>
        <div id="rg-etat-captcha" style="margin-bottom:12px;font-size:14px"></div>
        <div style="display:flex;flex-direction:column;gap:12px;max-width:520px">
            <input id="rg-captcha-site" placeholder="Clé de site (0x4AAA…)" autocomplete="off"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <input id="rg-captcha-secret" type="password" placeholder="Clé secrète" autocomplete="off"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button id="rg-captcha-appliquer" type="button"
                    style="align-self:flex-start;background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Enregistrer les clés
            </button>
        </div>
    </section>

    <section style="border-top:1px solid #E4DBC8;padding-top:22px;margin-bottom:26px">
        <h2 style="margin:0 0 6px;font-size:18px">Lecture automatique des pièces (Mindee)</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            Sans clé, l'extraction n'a pas lieu et chaque dossier est examiné à la main.
            <strong>La vérification d'identité reste possible</strong> — c'est plus lent, pas
            bloqué.
        </p>
        <div id="rg-etat-kyc" style="margin-bottom:12px;font-size:14px"></div>
        <div style="display:flex;flex-direction:column;gap:12px;max-width:520px">
            <input id="rg-kyc-cle" type="password" placeholder="Clé d'API" autocomplete="off"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <input id="rg-kyc-endpoint" placeholder="Adresse du produit « pièce d'identité »"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <input id="rg-kyc-scan" placeholder="Adresse du produit « carte grise »"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button id="rg-kyc-appliquer" type="button"
                    style="align-self:flex-start;background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Enregistrer
            </button>
        </div>
    </section>

    <section style="border-top:1px solid #E4DBC8;padding-top:22px;margin-bottom:26px">
        <h2 style="margin:0 0 6px;font-size:18px">Notifications push (FCM)</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            Sans clé, les notifications restent visibles dans l'application, mais aucune
            alerte n'arrive sur un téléphone fermé.
        </p>
        <div id="rg-etat-push" style="margin-bottom:12px;font-size:14px"></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;max-width:520px">
            <input id="rg-push-cle" type="password" placeholder="Clé serveur FCM" autocomplete="off"
                   style="flex:1;min-width:280px;padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button id="rg-push-appliquer" type="button"
                    style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Enregistrer
            </button>
        </div>
    </section>

    <section style="border-top:1px solid #E4DBC8;padding-top:22px">
        <h2 style="margin:0 0 6px;font-size:18px">Ancrage de la chaîne d'audit</h2>
        <p style="margin:0 0 12px;font-size:14px;color:#5C4A33;line-height:1.6">
            L'empreinte de tête est déposée chaque jour hors de la plateforme.
            <strong>Sans ancrage, la chaîne reste vérifiable — mais seulement par nous</strong>,
            ce qui ne vaut rien devant un tiers&nbsp;: l'algorithme est public, et quiconque
            peut écrire en base peut recalculer une chaîne cohérente.
        </p>
        <div id="rg-etat-ancrage" style="margin-bottom:12px;font-size:14px"></div>
        <div style="display:flex;flex-direction:column;gap:12px;max-width:520px">
            <input id="rg-ancrage-mail" placeholder="Adresse d'archivage (courrier horodaté)"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <input id="rg-ancrage-disque" placeholder="Disque de stockage séparé (ex. r2)"
                   style="padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button id="rg-ancrage-appliquer" type="button"
                        style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                    Enregistrer
                </button>
                <button id="rg-ancrage-verifier" type="button"
                        style="background:transparent;color:#2B1D12;border:2px solid #2B1D12;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer">
                    Vérifier le dernier ancrage
                </button>
            </div>
        </div>
    </section>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Les secrets sont chiffrés au repos et <strong>jamais réaffichés</strong>&nbsp;: un
        champ vide signifie « inchangé », pas « effacé ». Chaque changement de passerelle est
        inscrit dans la chaîne d'audit — rerouter les SMS de la plateforme détournerait tous
        les codes d'accès.
    </p>
@endsection
