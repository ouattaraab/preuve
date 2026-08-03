@extends('admin.layout')

@section('contenu')
    {{--
        Piste d'audit.

        AUCUNE ACTION D'ÉCRITURE N'EST OFFERTE, et pas seulement parce que les
        déclencheurs de base l'interdisent : proposer un bouton qui échouerait
        toujours enseignerait qu'une modification est concevable.
    --}}
    <form id="filtres-audit" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px" onsubmit="return false">
        <input id="fa-action" placeholder="Action (ex. asset.registered)" autocomplete="off"
               style="flex:1;min-width:220px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <input id="fa-entite" placeholder="Entité (ex. asset)" autocomplete="off"
               style="width:170px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <input id="fa-du" type="date" title="À partir du"
               style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <input id="fa-au" type="date" title="Jusqu'au"
               style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <button id="exporter"
                style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer">
            Exporter en CSV
        </button>
    </form>

    <div id="table-audit"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>
    <div id="pagination-audit" style="display:flex;align-items:center;gap:12px;margin-top:16px"></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Journal <em>append-only</em> : aucune mise à jour ni suppression n'est
        possible, quel que soit le chemin emprunté — y compris en SQL direct.
        L'empreinte de chaînage accompagne chaque ligne : c'est elle qui permet
        de rapprocher le journal d'un ancrage publié à l'extérieur.
    </p>
@endsection
