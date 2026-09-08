@extends('admin.layout')

@section('contenu')
    {{--
        Vue d'ensemble.

        L'ordre des blocs EST le message : ce qui attend une décision humaine
        d'abord, les volumes ensuite. Un tableau de bord qui ouvre sur
        « 12 400 biens enregistrés » apprend une chose agréable et inutile.
    --}}
    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin-bottom:12px">
        À traiter
    </h2>
    <div id="files-attente" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin:28px 0 12px">
        Promesses tenues
    </h2>
    <div id="promesses" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px"></div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:17px;margin:28px 0 12px">
        Le registre
    </h2>
    <div id="volumes" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px"></div>
    <div id="repartition-statuts" style="margin-top:14px"></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Ces chiffres sont des dénombrements : aucun ne désigne une personne ni
        un bien en particulier. Une vue d'ensemble n'a pas besoin de savoir qui
        possède quoi pour dire ce qui reste à faire.
    </p>
@endsection
