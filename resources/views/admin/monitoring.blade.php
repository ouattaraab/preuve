@extends('admin.layout')

@section('contenu')
    {{--
        Supervision. Les trois contrôles de la sonde sont mis en avant parce
        qu'ils portent des défauts SILENCIEUX : rien ne casse, les utilisateurs
        ne voient rien, et la plateforme cesse pourtant de tenir sa promesse.
    --}}
    <div id="sonde" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:26px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;margin-bottom:14px">Promesses produit</h2>
    <div id="telemetrie" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:26px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;margin-bottom:14px">Signaux anti-fraude</h2>
    <div id="fraude" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        Aucun signal n'est une accusation. Un acheteur de bonne foi qui enregistre
        le bien qu'il vient d'acquérir déclenche exactement le même signal qu'un
        fraudeur : ce tableau prépare une décision humaine, il n'en prend aucune.
    </p>
@endsection
