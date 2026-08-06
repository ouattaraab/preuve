@extends('public.layout')

@section('pastille', 'Paiement')

@section('contenu')
    @php
        use App\Enums\PaymentStatus;
        $abouti = $etat === PaymentStatus::Succeeded;
        $echoue = $etat === PaymentStatus::Failed;
    @endphp

    @if ($abouti)
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
            Paiement reçu.<br><span style="color:#1F7A4C">C'est bon.</span>
        </h1>
    @elseif ($echoue)
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
            Le paiement<br><span style="color:#C62F21">n'a pas abouti.</span>
        </h1>
    @else
        {{-- NI RÉUSSI NI ÉCHOUÉ : le rappel de l'opérateur peut arriver après le
             navigateur. Annoncer un échec ici ferait repayer quelqu'un qui a
             déjà payé — la pire chose qu'une page de retour puisse faire. --}}
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
            En attente de<br><span style="color:#D97706">confirmation.</span>
        </h1>
    @endif

    @if ($intitule !== null)
        <p style="margin-top:14px;font-size:18px;color:#5A4632;line-height:1.5">
            {{ $intitule }}@if ($montant) — <strong>{{ number_format($montant, 0, ',', ' ') }} FCFA</strong>@endif
        </p>
    @endif

    @if ($abouti)
        @if ($suite !== null)
            <div style="margin-top:20px;border:3px solid #1F7A4C;border-radius:16px;padding:18px 20px;background:#FFF;box-shadow:4px 4px 0 #2B1D12">
                <p style="font-size:17px;line-height:1.55">{{ $suite }}</p>
            </div>
        @endif

        @if ($lienRapport !== null)
            {{-- LE LIEN EST REMIS ICI, pas dans l'adresse : le jeton est une
                 capacité au porteur, et une URL fuit par l'historique et par
                 l'en-tête `Referer`. --}}
            <div style="margin-top:16px">
                <a href="{{ $lienRapport }}" class="bouton" style="display:block;text-align:center;text-decoration:none">
                    OUVRIR MON RAPPORT
                </a>
                <p style="margin-top:10px;font-size:15px;color:#7A6A55;line-height:1.55">
                    Ce lien vaut sans compte : gardez-le, ou envoyez-le à votre garagiste.
                    Il expire au bout de 30 jours.
                </p>
            </div>
        @endif
    @elseif ($echoue)
        <p style="margin-top:16px;font-size:17px;color:#5C4A33;line-height:1.6">
            Rien n'a été prélevé. Vous pouvez recommencer depuis l'application, ou choisir
            un autre moyen de paiement.
        </p>
    @else
        <p style="margin-top:16px;font-size:17px;color:#5C4A33;line-height:1.6">
            L'opérateur ne nous a pas encore confirmé le règlement. Cela prend en général
            quelques secondes. <strong>Ne payez pas une seconde fois</strong> : rafraîchissez
            cette page dans un instant, ou revenez dans l'application, qui relira l'état
            toute seule.
        </p>
        <div style="margin-top:16px">
            <a href="{{ request()->fullUrl() }}" class="bouton secondaire"
               style="display:block;text-align:center;text-decoration:none">
                RELIRE L'ÉTAT
            </a>
        </div>
    @endif

    <div class="bandeau" style="margin-top:26px">
        <span style="font-size:26px;line-height:1">📱</span>
        <span>
            Vous pouvez fermer cette page et revenir dans l'application Preuve :
            elle est au courant.
        </span>
    </div>
@endsection
