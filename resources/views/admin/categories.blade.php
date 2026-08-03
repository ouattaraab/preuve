@extends('admin.layout')

@section('contenu')
    {{--
        Catalogue des catégories de biens (décision D6).

        Ce catalogue est servi aux applications mobiles par configuration
        distante : une publication est visible sans passer par les magasins.
        C'est tout l'intérêt — et c'est aussi ce qui rend une erreur
        immédiatement visible par tous. L'écran le rappelle plutôt que de
        laisser croire à un brouillon.
    --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px">
        <p style="font-size:14px;color:#5C4A33">
            Version publiée : <strong id="version-catalogue" style="font-family:ui-monospace,monospace">…</strong>
        </p>
        <button id="publier"
                style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:700;cursor:pointer">
            Republier le catalogue
        </button>
    </div>

    <div id="liste-categories"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        ⚠️ Une catégorie ne se supprime pas, elle se désactive : elle disparaît
        des nouveaux enregistrements, et les biens déjà rattachés restent
        enregistrés et consultables. L'identifiant qui porte l'unicité se fixe à
        la création de la catégorie et ne se déplace jamais ensuite.
    </p>
@endsection
