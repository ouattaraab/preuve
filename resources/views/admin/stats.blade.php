@extends('admin.layout')

@section('contenu')
    {{--
        Statistiques d'usage.

        La maquette annonçait des « téléchargements » : ce chiffre appartient
        aux magasins d'applications. Le fabriquer à partir des comptes créés
        donnerait un nombre plausible et faux — et on déciderait dessus. L'écran
        montre donc le parc d'appareils, que la plateforme connaît vraiment, et
        le dit.
    --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px">
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px">Parc installé</h2>
        <select id="fenetre-stats"
                style="padding:9px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <option value="7">7 derniers jours</option>
            <option value="30" selected>30 derniers jours</option>
            <option value="90">90 derniers jours</option>
        </select>
    </div>

    <div id="parc" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin:28px 0 6px">
        Quand consulte-t-on ?
    </h2>
    <p style="font-size:13px;color:#5C4A33;margin-bottom:12px">
        Consultations par jour et par heure. C'est la seule statistique de cet écran
        dont on fait quelque chose le lendemain : elle dit quand tenir la permanence
        d'instruction, et quand ne pas déployer.
    </p>
    <div id="carte-chaleur" style="overflow-x:auto"></div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin:28px 0 12px">
        D'où consulte-t-on ?
    </h2>
    <div id="sources" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px"></div>

    {{--
        Forçage de mise à jour. Placé en bas et bordé de rouge : relever la
        version minimale met hors service une part du parc installé.
    --}}
    <section style="margin-top:34px;border:2px solid #B23A3A;border-radius:12px;padding:20px 22px;background:#FFF6E8">
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;color:#B23A3A">
            Forçage de mise à jour
        </h2>
        <p id="etat-version" style="font-size:13px;color:#5C4A33;line-height:1.6;margin-top:8px">Chargement…</p>

        <form id="form-version" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:16px" onsubmit="return false">
            <input id="vr-minimum" placeholder="Version minimale exigée (ex. 1.4.0)" autocomplete="off"
                   style="min-width:240px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <input id="vr-derniere" placeholder="Dernière version publiée" autocomplete="off"
                   style="min-width:220px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <button id="vr-appliquer"
                    style="background:#B23A3A;color:#FFF6E8;border:none;border-radius:10px;padding:11px 20px;font-size:14px;font-weight:700;cursor:pointer">
                Appliquer
            </button>
            <button id="vr-lever"
                    style="background:transparent;color:#2B1D12;border:2px solid #2B1D12;border-radius:10px;padding:9px 18px;font-size:14px;font-weight:700;cursor:pointer">
                Lever l'exigence
            </button>
        </form>

        <p style="font-size:13px;color:#5C4A33;line-height:1.6;margin-top:14px">
            ⚠️ Le forçage ne porte que sur les <strong>écritures</strong> — enregistrer,
            transférer, réclamer, déclarer un vol. <strong>La consultation d'un
            identifiant reste ouverte à toutes les versions</strong> : un verdict est
            gratuit et sans condition, y compris depuis un vieux téléphone. Chaque
            changement est journalisé avec l'ancienne et la nouvelle exigence.
        </p>
    </section>
@endsection
