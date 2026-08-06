@extends('admin.layout')

@section('contenu')
    {{--
        Valider une société, c'est ouvrir l'espace loueur à un client.

        L'API existait depuis EP-07 ; il n'y avait aucun écran. Un loueur qui
        s'inscrivait restait « en attente » indéfiniment, et personne dans
        l'équipe n'avait de moyen de le savoir — la verticale de lancement était
        bloquée par une porte manquante, pas par une fonctionnalité manquante.
    --}}
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px">
        @foreach ([
            'pending' => 'En attente',
            'validated' => 'Validées',
            'rejected' => 'Refusées',
        ] as $cle => $libelle)
            <button type="button" data-statut="{{ $cle }}"
                    style="border:2px solid #E4DBC8;background:#FFF;border-radius:999px;padding:9px 18px;font-size:14px;font-weight:700;cursor:pointer">
                {{ $libelle }}
            </button>
        @endforeach
        <span id="compte-societes" style="margin-left:auto;font-size:14px;color:#7A6A55"></span>
    </div>

    <div id="table-societes"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        {{-- CE QUE LA VALIDATION ENGAGE, dit avant de cliquer. Un bouton qui ne
             dit pas sa conséquence se clique sans y penser. --}}
        🏢 Valider une société lui ouvre l'espace loueur&nbsp;: import de parc, marquage
        « en location » en masse, tableau de bord. <strong>Vérifiez le numéro RCCM</strong> —
        une société validée agit sur des biens au nom d'une personne morale, et chaque
        décision est inscrite dans la chaîne d'audit avec votre identifiant.
    </p>
@endsection
