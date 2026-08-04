@extends('public.layout')

@php
    $bien = $resultat->asset;
    $statut = $bien?->life_status;
    // Un mot qui tranche, lisible à deux mètres : c'est la signature de la
    // direction DJASSA, et c'est aussi la seule chose qu'on lit debout dans un
    // marché. Le détail vient après, pour qui veut le lire.
    $enseigne = match (true) {
        $resultat->rateLimited => 'PATIENTE',
        $resultat->invalidIdentifier => 'NUMÉRO ILLISIBLE',
        ! $resultat->found => 'PAS ENREGISTRÉ',
        $statut?->isPublicWarning() => 'ATTENTION',
        default => 'RIEN À SIGNALER',
    };
    $couleur = match (true) {
        $resultat->rateLimited, $resultat->invalidIdentifier => '#5C6470',
        ! $resultat->found => '#C77700',
        default => $statut?->color() ?? '#5C6470',
    };
@endphp

@section('titre')
    @if ($resultat->found)
        {{ $statut?->label() }} — bien {{ $bien?->public_ref }} · Preuve
    @else
        Vérification d'un bien · Preuve
    @endif
@endsection

@section('description')
    @if ($resultat->found)
        Statut déclaré du bien {{ $bien?->public_ref }} au registre Preuve : {{ $statut?->publicMessage() }}
    @else
        Vérifiez gratuitement si un véhicule ou un téléphone est déclaré volé, en litige ou en location.
    @endif
@endsection

@if ($indexable ?? false)
    @section('canonique', url('/b/'.$bien?->public_ref))
@endif

@section('contenu')
    {{-- L'enseigne, pleine largeur. Le fond porte la couleur du statut : le
         verdict se lit avant d'être lu. --}}
    <div style="background:{{ $couleur }};color:#FFF6E8;border:3px solid #2B1D12;border-radius:16px;padding:30px 24px;text-align:center">
        <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:40px;line-height:1.05;letter-spacing:-.5px">
            {{ $enseigne }}
        </span>
        @if ($resultat->found)
            <span style="display:block;margin-top:8px;font-size:20px;font-weight:700">{{ $statut?->label() }}</span>
        @endif
    </div>

    <p style="margin-top:18px;font-size:19px">{{ $resultat->message }}</p>

    @if ($resultat->found && $bien !== null)
        <dl style="margin-top:22px;border:3px solid #2B1D12;border-radius:14px;overflow:hidden">
            @php
                $lignes = [
                    'Référence publique' => $bien->public_ref,
                    'Type de bien' => $bien->asset_category_key,
                    'Niveau de vérification' => $bien->trust_level->label(),
                    'Enregistré le' => $bien->registered_at->translatedFormat('j F Y'),
                ];
            @endphp
            @foreach ($lignes as $intitule => $valeur)
                <div style="display:flex;gap:14px;padding:12px 16px;{{ $loop->first ? '' : 'border-top:2px solid #E4DBC8;' }}background:#FFF6E8">
                    <dt style="flex:0 0 46%;font-weight:700">{{ $intitule }}</dt>
                    <dd>{{ $valeur }}</dd>
                </div>
            @endforeach
        </dl>

        {{--
            Ce que la page ne dira jamais. Le dire explicitement vaut mieux que
            de laisser chercher : c'est la promesse d'anonymat, et elle vaut
            dans les deux sens (règle métier absolue n° 4).
        --}}
        <p class="note" style="margin-top:16px">
            Nous ne disons pas qui a enregistré ce bien, ni son numéro complet.
            Le propriétaire ne saura pas non plus qui l'a consulté.
        </p>

        <div style="margin-top:24px;border:3px solid #2B1D12;border-radius:14px;padding:18px 20px">
            <p style="font-weight:700">Partager cette page</p>
            <p class="note" style="margin-top:6px">
                Ce lien ne contient pas le numéro du bien, seulement sa référence publique :
                <br><code style="word-break:break-all;font-size:15px">{{ url('/b/'.$bien->public_ref) }}</code>
            </p>
        </div>
    @endif

    @if ($resultat->rateLimited)
        <div style="margin-top:20px;border:3px solid #2B1D12;border-radius:14px;padding:18px 20px">
            @if ($captcha !== null)
                {{-- Le script du défi n'est chargé QU'ICI, après un refus : le
                     chemin nominal ne fait appel à aucun tiers. --}}
                <p style="font-weight:700">Prouve que tu n'es pas un robot pour continuer</p>
                <form method="GET" action="/verifier" style="margin-top:12px">
                    <input type="hidden" name="q" value="{{ $saisie }}">
                    <div class="cf-turnstile" data-sitekey="{{ $captcha }}"></div>
                    <button type="submit" class="bouton">Réessayer</button>
                </form>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            @else
                <p>
                    Trop de vérifications depuis cette connexion dans l'heure. C'est une protection
                    contre le balayage automatique du registre — réessaie dans un moment.
                </p>
            @endif
        </div>
    @endif

    <form method="GET" action="/verifier" style="margin-top:28px;border-top:3px solid #2B1D12;padding-top:20px">
        <label for="q" style="display:block;font-weight:700;margin-bottom:8px">Vérifier un autre bien</label>
        <input id="q" name="q" class="champ" required autocomplete="off"
               autocapitalize="characters" spellcheck="false" placeholder="Châssis, plaque ou IMEI">
        <button type="submit" class="bouton">Vérifier</button>
    </form>
@endsection
