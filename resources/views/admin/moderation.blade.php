@extends('admin.layout')

@section('contenu')
    {{--
        File de revue. Trois sources distinctes — justificatifs, dossiers KYC,
        réclamations — présentées ensemble parce qu'un agent les traite dans le
        même mouvement, mais chacune conserve son étiquette : la décision et ses
        conséquences ne sont pas les mêmes.
    --}}
    <div style="display:flex;gap:10px;margin-bottom:20px" role="tablist">
        @foreach (['tous' => 'Tous', 'documents' => 'Justificatifs', 'kyc' => 'Identités', 'claims' => 'Réclamations'] as $cle => $libelle)
            <button type="button" data-filtre="{{ $cle }}"
                    style="background:{{ $cle === 'tous' ? '#2B1D12' : 'transparent' }};color:{{ $cle === 'tous' ? '#FFF6E8' : '#2B1D12' }};border:2px solid #2B1D12;border-radius:999px;padding:8px 16px;font-size:14px;font-weight:700;cursor:pointer">
                {{ $libelle }}
            </button>
        @endforeach
    </div>

    {{--
        Second axe : l'ÉTAT. Sans lui, un dossier tranché disparaissait sans
        laisser de trace consultable — l'agent qui venait de le valider ne
        pouvait ni le revoir, ni relire le motif qu'il avait écrit. « En
        attente » reste le défaut : c'est la file de travail.
    --}}
    <div style="display:flex;gap:10px;margin-bottom:20px" role="tablist">
        @foreach (['pending' => 'En attente', 'verified' => 'Validés', 'rejected' => 'Refusés'] as $cle => $libelle)
            <button type="button" data-etat="{{ $cle }}"
                    style="background:{{ $cle === 'pending' ? '#2B1D12' : 'transparent' }};color:{{ $cle === 'pending' ? '#FFF6E8' : '#2B1D12' }};border:2px solid #2B1D12;border-radius:999px;padding:8px 16px;font-size:14px;font-weight:700;cursor:pointer">
                {{ $libelle }}
            </button>
        @endforeach
    </div>

    <div id="file-revue" style="display:flex;flex-direction:column;gap:12px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Les données personnelles des déclarants sont masquées. L'accès complet
        n'est possible que sur réquisition judiciaire, et il est lui-même journalisé.
    </p>
@endsection
