@extends('admin.layout')

@section('contenu')
    {{--
        Équipe et habilitations.

        La maquette montrait des pages cochables une à une ; la plateforme tient
        ses accès par des middlewares posés sur chaque route. Une table
        d'habilitations créerait une seconde source de vérité à côté de la
        première, et le jour où elles divergeraient, l'écran afficherait un
        droit que le code refuse — ou pire, l'inverse. Les pages sont donc
        déduites du rôle et montrées en lecture.
    --}}
    <div id="liste-equipe"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>

    <div id="matrice-roles" style="margin-top:28px"></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        ⚠️ Promouvoir est l'opération la plus sensible de cette console : elle
        fabrique les comptes qui voient les pièces d'identité et lèvent
        l'anonymat. Chaque changement est journalisé avec l'ancien et le nouveau
        rôle, et une rétrogradation révoque immédiatement les jetons en cours.
    </p>
@endsection
