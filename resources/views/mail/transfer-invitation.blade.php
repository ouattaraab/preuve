{{-- Corps TEXTE : `{!! !!}` et non `{{ }}`, Blade échappant en HTML par défaut.
     Le libellé du bien vient d'un enregistrement, jamais d'une saisie libre du
     vendeur au moment de l'invitation. --}}
Quelqu'un vous cède un bien sur PREUVE, et attend votre confirmation.

    {!! $bien !!}
    Numéro : {!! $numero !!}

Si vous ne reconnaissez pas ce bien, ignorez ce message : sans votre
confirmation, rien ne change et la cession s'annulera d'elle-même dans
{!! $jours !!} jours.

CE QU'IL Y A À FAIRE

Ouvrez ce lien, il décrit le bien et vous laisse accepter :

    {!! $lien !!}

Vous y confirmerez avec le code que vous recevez dans un message séparé.
Le lien seul ne suffit pas : c'est ce qui fait qu'un courriel transféré ou
lu par un tiers ne peut pas vous prendre votre place.

Vous pouvez aussi confirmer depuis l'application PREUVE, si vous l'avez.

CE QUE CELA CHANGE

Une fois confirmé, le bien est enregistré à votre nom : toute personne qui
vérifie son numéro verra qu'il est enregistré, et vous seul pourrez le
déclarer volé ou le céder à votre tour. L'ancien détenteur ne peut plus rien
en faire.

Le bien repart en « déclaré, non vérifié » : les justificatifs du vendeur
prouvaient SA propriété, pas la vôtre. Vous pourrez déposer les vôtres.

--
PREUVE — registre déclaratif de propriété et de statut des biens.
Aucun agent de PREUVE ne vous demandera jamais votre code, ni par
téléphone, ni par message.
