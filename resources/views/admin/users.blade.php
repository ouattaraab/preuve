@extends('admin.layout')

@section('contenu')
    {{--
        Annuaire des comptes. Colonnes de la maquette : UTILISATEUR,
        CONTACT (MASQUÉ), NIVEAU KYC, BIENS, INSCRIT, ACTION.
    --}}
    <form id="filtres-comptes" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px" onsubmit="return false">
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
        relève de la réquisition judiciaire et se fait hors de cet écran.<br>
        Suspendre un compte <strong>ne suspend jamais la protection de ses biens</strong> :
        un bien déclaré volé le reste, et l'acheteur qui le vérifie reçoit le même verdict.
    </p>
@endsection
