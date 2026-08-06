@extends('public.layout')

@php
    $fait = (int) ($import->processed_rows ?? 0);
    $total = max(1, (int) ($import->total_rows ?? 1));
    $part = min(100, (int) round($fait * 100 / $total));
    // Les noms sont ceux de la MIGRATION, relevés et non devinés : `imported`,
    // `skipped`, `failed`, et `status` est une chaîne, pas une énumération.
    $termine = in_array($import->status, ['completed', 'failed'], true);
@endphp

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-size:32px;line-height:1.1">
        {{ $termine ? 'Import terminé' : 'Import en cours' }}
    </h1>

    <div style="margin-top:20px;border:3px solid #2B1D12;border-radius:14px;padding:20px 22px;background:#FFF">
        <p style="font-size:30px;font-weight:800;font-family:'Bricolage Grotesque',sans-serif">
            {{ number_format($fait, 0, ',', ' ') }}
            <span style="font-size:18px;color:#7A6A55">/ {{ number_format((int) $import->total_rows, 0, ',', ' ') }} lignes</span>
        </p>
        <div style="margin-top:12px;height:16px;border:2px solid #2B1D12;border-radius:8px;overflow:hidden;background:#FAF6EE">
            <div style="height:100%;width:{{ $part }}%;background:#D97706"></div>
        </div>
        <p style="margin-top:12px;font-size:16px;color:#5C4A33">
            {{ (int) $import->imported }} enregistré(s) ·
            {{ (int) $import->skipped }} déjà connu(s) ·
            {{ (int) $import->failed }} refusé(s)
        </p>
        @if ($termine && ! empty($import->errors))
            {{-- LES LIGNES REFUSÉES SONT NOMMÉES : « 12 refusés » sans dire
                 lesquelles oblige à comparer deux fichiers à la main. --}}
            <div style="margin-top:14px;border-top:2px solid #E8DFCE;padding-top:12px">
                <div style="font-size:13px;font-weight:700;color:#7A6A55;letter-spacing:.4px">
                    CE QUI N'EST PAS PASSÉ
                </div>
                <ul style="margin:8px 0 0 18px;font-size:16px;color:#5C4A33;line-height:1.6">
                    @foreach (array_slice($import->errors, 0, 20) as $erreur)
                        <li>{{ is_array($erreur) ? implode(' — ', $erreur) : $erreur }}</li>
                    @endforeach
                </ul>
                @if (count($import->errors) > 20)
                    <p style="margin-top:8px;font-size:14px;color:#7A6A55">
                        … et {{ count($import->errors) - 20 }} autres. Corrige le fichier et réimporte :
                        les véhicules déjà entrés ne seront pas recréés.
                    </p>
                @endif
            </div>
        @endif
    </div>

    @unless ($termine)
        {{-- RECHARGEMENT PAR EN-TÊTE, sans une ligne de JavaScript : la
             politique de contenu du site interdit le script en ligne, et un
             import de parc n'a pas besoin d'être suivi à la seconde. --}}
        <meta http-equiv="refresh" content="5">
        <p style="margin-top:16px;font-size:16px;color:#7A6A55">
            Cette page se rafraîchit toute seule. Tu peux la fermer : l'import continue
            sans elle, et tu retrouveras son résultat ici.
        </p>
    @endunless

    <p style="margin-top:22px">
        <a href="{{ route('fleet.import') }}" class="bouton">Retour aux imports</a>
    </p>
@endsection
