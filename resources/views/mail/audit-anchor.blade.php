{{-- Corps TEXTE : `{!! !!}` et non `{{ }}`. Blade échappe en HTML par défaut,
     et le constat contient des apostrophes (« chaîne d'audit », « Date
     d'ancrage ») qui s'afficheraient « d&#039;audit ». C'est une pièce
     destinée à être archivée, relue des mois plus tard et peut-être imprimée
     pour être versée à un dossier : elle doit se lire telle qu'elle a été
     rédigée. Rien n'y vient d'un utilisateur. --}}
{!! $corps !!}
