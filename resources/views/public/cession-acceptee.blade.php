@extends('public.layout')

@section('pastille', $acheve ? 'Cession confirmée' : 'Accord enregistré')

@section('contenu')
    {{-- LA CESSION EXIGE LES DEUX CONFIRMATIONS. Annoncer « c'est à vous »
         quand le vendeur n'a pas encore confirmé de son côté serait faux, et
         l'acheteur repartirait convaincu d'un enregistrement qui n'a pas eu
         lieu — c'est-à-dire exactement le litige que le registre existe pour
         éviter. --}}
    @if ($acheve)
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
            C'est fait.<br><span style="color:#1F7A4C">Le bien est à vous.</span>
        </h1>
    @else
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
            Votre accord<br><span style="color:#D97706">est enregistré.</span>
        </h1>
    @endif

    <article style="margin-top:22px;border:3px solid {{ $acheve ? '#1F7A4C' : '#2B1D12' }};border-radius:16px;padding:20px;background:#FFF;box-shadow:4px 4px 0 #2B1D12">
        <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:26px;letter-spacing:.5px">
            {{ $bien['numero'] }}
        </span>
        <p style="margin-top:8px;font-size:17px">
            {{ $bien['marque'] !== '' ? $bien['marque'] : $bien['categorie'] }}
            <span style="color:#7A6A55">· {{ $bien['categorie'] }}</span>
        </p>
        <p style="margin-top:10px;font-size:16px;font-weight:700;color:{{ $acheve ? '#1F7A4C' : '#D97706' }}">
            {{ $acheve ? 'Enregistré à votre nom' : 'En attente de la confirmation du vendeur' }}
        </p>
    </article>

    @unless ($acheve)
        <p style="margin-top:16px;font-size:17px;color:#5C4A33;line-height:1.6">
            Une cession se confirme des deux côtés. Le vendeur doit valider la sienne
            avec son propre code ; tant qu'il ne l'a pas fait, le bien reste à son nom.
            Vous n'avez plus rien à faire — prévenez-le simplement.
        </p>
    @endunless

    <p style="margin-top:16px;font-size:17px;color:#5C4A33;line-height:1.6">
        Un compte a été ouvert avec l'adresse à laquelle vous avez reçu l'invitation.
        Vous y accédez à tout moment, sans mot de passe : un code vous est envoyé à
        chaque connexion.
    </p>

    @if ($acheve)
        {{-- CE QUI RESTE À FAIRE, DIT MAINTENANT. Une page de fin qui ne dit
             rien laisse croire que tout est terminé — et le bien reste « non
             vérifié » indéfiniment, ce qui vaut moins cher à la revente. --}}
        <h2 style="margin-top:26px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:23px">
            Ce qui reste à faire
        </h2>
        <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.6">
            Votre bien est en « déclaré, non vérifié ». Déposez votre carte grise ou
            votre facture depuis l'application : un bien vérifié rassure un acheteur,
            et se revend mieux.
        </p>

        @if ($publicRef !== null)
            <div class="bandeau" style="margin-top:24px">
                <span style="font-size:26px;line-height:1">🔎</span>
                <span>
                    <a href="/b/{{ $publicRef }}" style="color:#2B1D12;font-weight:700">Voir la fiche publique</a>
                    de votre bien, telle qu'un acheteur la verrait — gratuite et sans compte.
                </span>
            </div>
        @endif
    @endif
@endsection
