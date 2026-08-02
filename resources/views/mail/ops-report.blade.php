{{-- Corps TEXTE : `{!! !!}` et non `{{ }}`. Blade échappe en HTML par défaut,
     ce qui n'a aucun sens ici — le lecteur verrait « n&#039;a » au lieu de
     « n'a ». Il n'y a pas de contexte HTML à protéger dans un text/plain, et
     le contenu ne vient pas d'un utilisateur mais des commandes de la
     plateforme. --}}
{!! $titre !!}

{!! $anomalie
    ? 'Ce rapport signale des points à traiter. Ils sont détaillés ci-dessous.'
    : "Rien à signaler. Ce message confirme que le contrôle a bien eu lieu :\nson absence, elle, voudrait dire que la tâche ne tourne plus." !!}

{!! $corps !!}
@if ($journal)

Détail complet sur le serveur : {!! $journal !!}
@endif

--
Rapport automatique de la plateforme PREUVE. Le destinataire se règle depuis
l'espace administrateur.
