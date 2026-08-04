@extends('admin.layout')

@section('contenu')
    {{--
        Tous les montants de la plateforme, en un seul endroit.

        ZÉRO EST UNE VALEUR, PAS UN VIDE : il rend la chose gratuite. C'est ce
        qui permet d'ouvrir le rapport détaillé pendant un lancement, ou de lever
        les frais de dossier pour une population qui ne peut pas les payer, sans
        qu'aucune de ces décisions demande une mise en production.
    --}}
    <div id="etat-paystack" style="margin-bottom:20px"></div>

    <form id="form-tarifs" style="display:flex;flex-direction:column;gap:16px">
        <div id="liste-tarifs"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>

        <div>
            <button type="submit"
                    style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:13px 22px;font-size:15px;font-weight:700;cursor:pointer">
                Enregistrer les tarifs
            </button>
            <span id="retour-tarifs" style="margin-left:14px;font-size:14px;font-weight:700"></span>
        </div>
    </form>

    <section style="margin-top:32px;padding-top:24px;border-top:1px solid #E4DBC8">
        <h2 style="margin:0 0 6px;font-size:18px">Clé secrète Paystack</h2>
        <p style="margin:0 0 14px;font-size:14px;color:#5C4A33;line-height:1.6">
            Elle est stockée chiffrée et n'est <strong>jamais réaffichée</strong> : une clé
            qu'on peut relire est une clé qui fuit au premier accès indu à cette console.
            La saisir à nouveau la remplace.
        </p>
        <form id="form-paystack" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input id="cle-paystack" type="password" placeholder="sk_live_…" autocomplete="off"
                   style="flex:1;min-width:280px;padding:12px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px">
            <button type="submit"
                    style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Enregistrer la clé
            </button>
            <span id="retour-paystack" style="font-size:14px;font-weight:700"></span>
        </form>
    </section>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Chaque changement de tarif est journalisé dans la chaîne d'audit avec
        l'<strong>ancien</strong> et le <strong>nouveau</strong> montant : savoir qu'un prix a bougé
        ne suffit pas à juger s'il a ouvert ou restreint l'accès au service.
    </p>
@endsection
