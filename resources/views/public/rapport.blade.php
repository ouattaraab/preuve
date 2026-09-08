@extends('public.layout')

@section('pastille', 'Rapport détaillé')

@php
    $bien = $rapport['asset'] ?? [];
    $propriete = $rapport['ownership'] ?? [];
    $incidents = $rapport['incidents'] ?? [];
    $acces = $rapport['access'] ?? [];
    $historique = $rapport['history'] ?? [];

    $statut = $bien['life_status'] ?? [];
    $fiabilite = $bien['trust_level'] ?? [];
    $alerte = ($statut['warning'] ?? false) === true;

    $jour = static fn (?string $iso): string => $iso === null
        ? '—'
        : \Illuminate\Support\Carbon::parse($iso)->translatedFormat('j F Y');
@endphp

@section('contenu')
    {{-- LE VERDICT D'ABORD, comme sur la consultation gratuite : quelqu'un qui
         a payé ne doit pas avoir à lire un tableau pour savoir s'il achète. --}}
    <div style="background:{{ $alerte ? '#C62F21' : '#1F7A4C' }};color:#FFF6E8;border:3px solid #2B1D12;border-radius:16px;padding:28px 24px;text-align:center">
        <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.05">
            {{ $alerte ? 'ATTENTION' : 'RIEN À SIGNALER' }}
        </span>
        <span style="display:block;margin-top:8px;font-size:20px;font-weight:700">{{ $statut['label'] ?? '—' }}</span>
    </div>

    <p style="margin-top:18px;font-size:19px">
        Rapport détaillé du bien <strong>{{ $bien['public_ref'] ?? '—' }}</strong>.
        {{-- LA RÉFÉRENCE PUBLIQUE, JAMAIS LE NUMÉRO RÉEL : ce lien se transfère
             à un garagiste ou à une banque, et il ne doit rien livrer de plus
             que ce qui a été acheté. --}}
    </p>

    <div style="margin-top:26px;border:3px solid #2B1D12;border-radius:14px;padding:20px 22px;background:#FFF">
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px;margin-bottom:14px">Le bien</h2>
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:8px 18px;font-size:17px;margin:0">
            <dt style="font-weight:700;color:#7A6A55">Catégorie</dt>
            <dd style="margin:0">{{ $bien['category'] ?? '—' }}</dd>
            <dt style="font-weight:700;color:#7A6A55">Statut</dt>
            <dd style="margin:0">{{ $statut['label'] ?? '—' }}</dd>
            <dt style="font-weight:700;color:#7A6A55">Fiabilité</dt>
            <dd style="margin:0">{{ $fiabilite['label'] ?? '—' }}</dd>
            <dt style="font-weight:700;color:#7A6A55">Enregistré le</dt>
            <dd style="margin:0">{{ $jour($bien['registered_at'] ?? null) }}</dd>
        </dl>
    </div>

    {{-- CE QUE LE RAPPORT APPORTE ET QUE LA CONSULTATION GRATUITE NE DIT PAS :
         combien de fois ce bien a changé de mains, et quand. C'est la question
         qu'un acheteur se pose vraiment. Le NOMBRE et les DATES, jamais les
         personnes — règle métier absolue n° 4. --}}
    <div style="margin-top:18px;border:3px solid #2B1D12;border-radius:14px;padding:20px 22px;background:#FFF">
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px;margin-bottom:6px">Combien de mains</h2>
        <p style="font-size:30px;font-weight:800;font-family:'Bricolage Grotesque',sans-serif;margin:6px 0">
            {{ $propriete['holders_count'] ?? 1 }}
            <span style="font-size:17px;font-weight:700;color:#7A6A55">détenteur(s) depuis l'enregistrement</span>
        </p>
        <p style="font-size:16px;color:#5C4A33">
            Premier enregistrement : {{ $jour($propriete['first_registered_at'] ?? null) }}.
        </p>
        @if (! empty($propriete['changed_at']))
            <p style="font-size:16px;color:#5C4A33;margin-top:6px">
                Changements de main :
                @foreach ($propriete['changed_at'] as $date){{ $jour($date) }}@if (! $loop->last), @endif @endforeach
            </p>
        @endif
        <p style="font-size:14px;color:#7A6A55;margin-top:10px;line-height:1.5">
            Les personnes ne sont jamais nommées, ni ici ni ailleurs. Un bien qui
            change quatre fois de mains en un an se juge sur ce rythme, pas sur des noms.
        </p>
    </div>

    @if (($incidents['stolen_declared_at'] ?? null) !== null || ($incidents['currently_disputed'] ?? false))
        <div style="margin-top:18px;border:3px solid #C62F21;border-radius:14px;padding:20px 22px;background:#FFF">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px;color:#C62F21;margin-bottom:10px">Incidents</h2>
            @if (($incidents['stolen_declared_at'] ?? null) !== null)
                <p style="font-size:17px">
                    Vol déclaré le <strong>{{ $jour($incidents['stolen_declared_at']) }}</strong>@if ($incidents['stolen_consolidated'] ?? false), avec dépôt de plainte constaté@endif.
                </p>
            @endif
            @if ($incidents['currently_disputed'] ?? false)
                <p style="font-size:17px;margin-top:6px">Une réclamation est en cours d'arbitrage sur ce bien.</p>
            @endif
        </div>
    @endif

    @if (! empty($historique))
        <div style="margin-top:18px;border:3px solid #2B1D12;border-radius:14px;padding:20px 22px;background:#FFF">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px;margin-bottom:12px">Ce qui est arrivé à ce bien</h2>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:16px;min-width:340px">
                    <thead>
                        <tr style="text-align:left;color:#7A6A55">
                            <th style="padding:6px 10px 6px 0;font-weight:700">Date</th>
                            <th style="padding:6px 10px 6px 0;font-weight:700">Devenu</th>
                            <th style="padding:6px 0;font-weight:700">À la suite de</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historique as $ligne)
                            <tr style="border-top:2px solid #E8DFCE">
                                <td style="padding:8px 10px 8px 0;white-space:nowrap">{{ $jour($ligne['at'] ?? null) }}</td>
                                <td style="padding:8px 10px 8px 0;font-weight:700">{{ $ligne['to_status_label'] ?? '—' }}</td>
                                {{-- L'ORIGINE, JAMAIS L'AUTEUR : « décision d'arbitrage »
                                     informe, « décidé par Awa Koné » dénonce. --}}
                                <td style="padding:8px 0;color:#5C4A33">{{ $ligne['trigger_label'] ?? $ligne['trigger'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <p style="margin-top:22px;font-size:15px;color:#7A6A55;line-height:1.6">
        Ce lien reste ouvert jusqu'au <strong>{{ $jour($acces['expires_at'] ?? null) }}</strong>.
        Il s'ouvre sur n'importe quel appareil, sans compte — garde-le, ou transmets-le
        à qui t'accompagne dans l'achat. Quiconque l'a peut lire ce rapport&nbsp;:
        ne le publie pas.
    </p>

    <p style="margin-top:14px;font-size:15px;color:#7A6A55;line-height:1.6">
        Le détenteur du bien a été prévenu qu'un rapport a été acheté, sans savoir par qui.
        <a href="/verifier" style="color:#2B1D12">Vérifier un autre bien, gratuitement</a>.
    </p>
@endsection
