@extends('public.layout')

@section('pastille', 'Biens volés')

@php
    $jour = static fn (?string $iso): string => $iso === null
        ? '—'
        : \Illuminate\Support\Carbon::parse($iso)->translatedFormat('j F Y');
@endphp

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:38px;line-height:1.05">
        Biens déclarés<br><span style="color:#C62F21">volés</span>
    </h1>
    <p style="margin-top:12px;font-size:18px;color:#5A4632;line-height:1.5">
        Ces biens ont été signalés par leur détenteur. Si l'on t'en propose un,
        <strong>n'achète pas</strong> — et si tu en reconnais un, préviens la police.
    </p>

    {{-- LA RECHERCHE D'ABORD : on arrive ici avec un numéro en tête, pas pour
         parcourir. Et elle accepte un FRAGMENT, parce que quelqu'un qui croit
         reconnaître une moto n'a souvent qu'un bout de plaque. --}}
    <form method="GET" action="/voles" style="margin-top:22px">
        <label for="q" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
            Chercher un numéro
        </label>
        <input id="q" name="q" class="champ" value="{{ $recherche }}"
               autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="Un numéro, ou juste un bout">
        <button type="submit" class="bouton">CHERCHER</button>
    </form>

    @if ($recherche !== '')
        <p style="margin-top:16px;font-size:16px;color:#7A6A55">
            {{ $pagination->total() }} résultat(s) pour « {{ $recherche }} » ·
            <a href="/voles" style="color:#2B1D12">voir toute la liste</a>
        </p>
    @endif

    @if ($biens === [])
        <div style="margin-top:26px;border:3px dashed #B9A98E;border-radius:16px;padding:28px 22px;text-align:center">
            <p style="font-size:19px;font-weight:700">
                {{ $recherche === '' ? 'Aucun bien publié pour l\'instant.' : 'Rien ne correspond.' }}
            </p>
            <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.55">
                {{-- ON NE RASSURE PAS SUR UNE ABSENCE. Un bien qui ne figure pas
                     ici n'est pas un bien sain : son détenteur n'a peut-être
                     simplement pas demandé la publication. --}}
                Cette liste ne montre que les biens dont le détenteur a demandé la publication.
                <strong>Un bien absent d'ici peut très bien être volé.</strong>
                <a href="/verifier" style="color:#2B1D12">Vérifie son numéro</a> — c'est gratuit.
            </p>
        </div>
    @else
        <div style="margin-top:22px">
            @foreach ($biens as $bien)
                <article style="border:3px solid #2B1D12;border-radius:16px;padding:18px 20px;background:#FFF;margin-bottom:14px;box-shadow:4px 4px 0 #2B1D12">
                    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                        <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:24px;letter-spacing:.5px">
                            {{ $bien['identifier'] }}
                        </span>
                        <span style="background:#C62F21;color:#FFF6E8;padding:5px 12px;border-radius:999px;font-size:13px;font-weight:700">
                            VOLÉ
                        </span>
                    </div>
                    <p style="margin-top:8px;font-size:17px">
                        {{ $bien['brand_model'] ?? ucfirst($bien['category']) }}
                        <span style="color:#7A6A55">· {{ $bien['category'] }}</span>
                    </p>
                    <p style="margin-top:6px;font-size:15px;color:#5C4A33">
                        Déclaré le <strong>{{ $jour($bien['stolen_declared_at']) }}</strong>
                        @if (($bien['days_since'] ?? null) !== null)
                            <span style="color:#7A6A55">· il y a {{ $bien['days_since'] }} jour(s)</span>
                        @endif
                        @if ($bien['consolidated'])
                            <br><span style="color:#1F7A4C;font-weight:700">Plainte déposée et constatée</span>
                        @endif
                    </p>
                    <p style="margin-top:10px;font-size:14px">
                        <a href="/b/{{ $bien['public_ref'] }}" style="color:#2B1D12;font-weight:700">
                            Voir la fiche publique →
                        </a>
                    </p>
                </article>
            @endforeach
        </div>

        @if ($pagination->hasPages())
            <div style="margin-top:20px;display:flex;justify-content:space-between;gap:12px">
                @if ($pagination->currentPage() > 1)
                    <a href="{{ $pagination->previousPageUrl() }}" class="bouton secondaire" style="width:auto;padding:12px 20px">← Précédent</a>
                @else
                    <span></span>
                @endif
                <span style="align-self:center;font-size:15px;color:#7A6A55">
                    Page {{ $pagination->currentPage() }} / {{ $pagination->lastPage() }}
                </span>
                @if ($pagination->hasMorePages())
                    <a href="{{ $pagination->nextPageUrl() }}" class="bouton secondaire" style="width:auto;padding:12px 20px">Suivant →</a>
                @else
                    <span></span>
                @endif
            </div>
        @endif
    @endif

    <div class="bandeau" style="margin-top:28px">
        <span style="font-size:26px;line-height:1">⚡</span>
        <span>
            Ton bien a été volé&nbsp;? Déclare-le depuis l'application : il devient
            invendable pour quiconque vérifie son numéro, gratuitement.
        </span>
    </div>

    <p style="margin-top:18px;font-size:15px;color:#7A6A55;line-height:1.6">
        Le détenteur d'un bien n'est jamais nommé ici, ni ailleurs. Cette liste sert à
        retrouver des biens, pas à désigner des victimes.
    </p>
@endsection
