@extends('admin.layout')

@section('contenu')
    {{--
        Annuaire des comptes. Colonnes de la maquette : UTILISATEUR,
        CONTACT (MASQUÉ), NIVEAU KYC, BIENS, INSCRIT, ACTION.
    --}}
    <form id="filtres-comptes" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
        <input id="qu" placeholder="Nom" autocomplete="off"
               style="flex:1;min-width:240px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <select id="kyc" style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <option value="">Tous niveaux</option>
            <option value="none">Non vérifié</option>
            <option value="pending">En cours</option>
            <option value="verified">Vérifié</option>
            <option value="rejected">Rejeté</option>
        </select>
        <select id="statut-compte" style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <option value="">Tous les comptes</option>
            <option value="active">Actifs</option>
            <option value="suspended">Suspendus</option>
        </select>
    </form>

    <div id="table-comptes"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>
    <div id="pagination-comptes" style="display:flex;align-items:center;gap:12px;margin-top:16px"></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Les coordonnées sont masquées, y compris pour un agent. L'accès complet
        relève de la réquisition judiciaire et passe par la procédure ci-dessous,
        réservée aux administrateurs.<br>
        Suspendre un compte <strong>ne suspend jamais la protection de ses biens</strong> :
        un bien déclaré volé le reste, et l'acheteur qui le vérifie reçoit le même verdict.
    </p>

    @if ($estAdministrateur)
        {{--
            Levée d'anonymat sur réquisition (Loi 2013-450).

            ELLE EXISTE PARCE QUE L'ALTERNATIVE EST PIRE : sans chemin prévu,
            une réquisition serait honorée par une requête SQL directe, sans
            fondement consigné et sans trace.

            L'écran est délibérément plus austère que le reste de la console :
            aucune liste, aucun export, aucun état « déverrouillé ». La réponse
            est rendue une fois, et la seconde d'après l'identité est de nouveau
            inaccessible.
        --}}
        <section style="margin-top:34px;border:2px solid #B23A3A;border-radius:12px;padding:20px 22px;background:#FFF6E8">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;color:#B23A3A">
                Levée d'anonymat sur réquisition
            </h2>
            <p style="font-size:13px;color:#5C4A33;line-height:1.6;margin-top:8px">
                Réservée aux réquisitions d'une autorité. Le fondement est exigé champ par champ :
                un motif libre unique se remplirait de « enquête » et ne prouverait rien.
                La divulgation vise <strong>une personne</strong>, n'ouvre <strong>aucun accès durable</strong>
                et est consignée dans deux registres inaltérables.
            </p>

            <form id="form-levee" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px">
                <input id="lv-user" type="number" min="1" placeholder="Identifiant du compte visé"
                       style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                <input id="lv-autorite" placeholder="Autorité requérante"
                       style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                <input id="lv-reference" placeholder="Référence de la réquisition"
                       style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                <input id="lv-date" type="date" title="Date de la réquisition"
                       style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                <textarea id="lv-objet" rows="3" placeholder="Objet de la réquisition (20 caractères minimum)"
                          style="grid-column:1/-1;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12;font-family:inherit;resize:vertical"></textarea>
                <button id="lv-soumettre"
                        style="grid-column:1/-1;justify-self:start;background:#B23A3A;color:#FFF6E8;border:none;border-radius:10px;padding:11px 20px;font-size:14px;font-weight:700;cursor:pointer">
                    Lever l'anonymat
                </button>
            </form>

            <div id="lv-resultat" style="margin-top:16px"></div>

            <h3 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:15px;margin-top:26px">
                Registre des levées passées
            </h3>
            <p style="font-size:12px;color:#7A6A55;line-height:1.6;margin-top:4px">
                Ce registre ne contient aucune coordonnée : seulement qui a levé l'anonymat de qui,
                quand, et sur quel fondement. C'est le document de reddition de comptes.
            </p>
            <div id="lv-registre" style="margin-top:10px"></div>
        </section>
    @endif
@endsection
