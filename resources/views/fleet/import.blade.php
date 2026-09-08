@extends('public.layout')

@section('pastille', 'Espace loueur')

@section('contenu')
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
        <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-size:32px;line-height:1.1">
            Importer un parc
        </h1>
        <form method="POST" action="{{ route('fleet.logout') }}">
            @csrf
            <button type="submit" style="background:none;border:none;font:inherit;color:#7A6A55;text-decoration:underline;cursor:pointer;padding:0">
                Se déconnecter
            </button>
        </form>
    </div>

    @foreach ($errors->all() as $erreur)
        <p style="margin-top:18px;border:3px solid #C62F21;border-radius:12px;padding:14px 16px;background:#FFF;color:#C62F21;font-weight:700">
            {{ $erreur }}
        </p>
    @endforeach

    @if (session('rapport'))
        {{-- PETIT FICHIER : le résultat est rendu tout de suite, ligne à ligne.
             Renvoyer un identifiant de suivi pour douze véhicules serait une
             régression d'usage déguisée en progrès. --}}
        @php $r = session('rapport'); @endphp
        <div style="margin-top:18px;border:3px solid #1F7A4C;border-radius:14px;padding:20px 22px;background:#FFF">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px;color:#1F7A4C">Import terminé</h2>
            <p style="margin-top:8px;font-size:17px">
                {{ (int) ($r['imported'] ?? 0) }} enregistré(s) ·
                {{ (int) ($r['skipped'] ?? 0) }} déjà connu(s) ·
                {{ (int) ($r['failed'] ?? 0) }} refusé(s)
                @if (session('societe')) — {{ session('societe') }} @endif
            </p>
            @if (! empty($r['errors']))
                <ul style="margin:12px 0 0 18px;font-size:16px;color:#5C4A33;line-height:1.6">
                    @foreach (array_slice($r['errors'], 0, 20) as $erreur)
                        <li>{{ is_array($erreur) ? implode(' — ', $erreur) : $erreur }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (session('suivi'))
        <p style="margin-top:18px;border:3px solid #2B1D12;border-radius:12px;padding:14px 16px;background:#FFF">
            Fichier accepté. L'import se déroule en arrière-plan —
            <a href="{{ route('fleet.import.status', ['import' => session('suivi')]) }}" style="color:#2B1D12;font-weight:700">suivre son avancement</a>.
        </p>
    @endif

    @if ($societes === [])
        <p style="margin-top:20px;font-size:18px">
            Ton compte ne dirige aucune flotte.
        </p>
    @else
        <form method="POST" action="{{ route('fleet.import.store') }}" enctype="multipart/form-data" style="margin-top:24px">
            @csrf

            @if (count($societes) > 1)
                <label for="company" style="display:block;font-weight:700;margin-bottom:8px">La société</label>
                <select id="company" name="company" class="champ" required>
                    @foreach ($societes as $societe)
                        <option value="{{ $societe->id }}">{{ $societe->legal_name }}</option>
                    @endforeach
                </select>
            @else
                {{-- UNE SEULE SOCIÉTÉ : on ne fait pas cocher l'unique option
                     disponible. C'est un geste de plus pour rien. --}}
                <input type="hidden" name="company" value="{{ $societes[0]->id }}">
                <p style="font-size:17px;margin-bottom:14px">
                    Parc de <strong>{{ $societes[0]->legal_name }}</strong>.
                </p>
            @endif

            <label for="file" style="display:block;font-weight:700;margin:16px 0 8px">Le fichier</label>
            <input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required
                   style="width:100%;font:inherit;padding:12px;border:3px solid #2B1D12;border-radius:12px;background:#FFF">
            {{-- LES COLONNES SONT NOMMÉES AVANT L'ENVOI. Le service les
                 réclame dans son message d'erreur, mais découvrir le format
                 APRÈS un refus fait recommencer — et personne ne devine trois
                 noms de colonnes en anglais. --}}
            <p style="margin-top:8px;font-size:15px;color:#7A6A55;line-height:1.55">
                Un CSV, une ligne par véhicule. La première ligne doit porter exactement
                ces trois colonnes&nbsp;:
            </p>
            <pre style="margin-top:8px;padding:12px 14px;border:2px solid #E8DFCE;border-radius:10px;background:#FFF;overflow-x:auto;font-size:15px">category,identifier,brand_model
voiture,1234AB01,Toyota Hilux
moto,AA123BC,Yamaha XTZ 125</pre>
            <p style="margin-top:8px;font-size:15px;color:#7A6A55;line-height:1.55">
                Au-delà de deux cents lignes, l'import se poursuit en arrière-plan et tu peux
                fermer la page. Plafond absolu&nbsp;: dix mille lignes — au-delà, c'est une
                reprise de données, écris-nous.
            </p>

            <button type="submit" class="bouton" style="margin-top:16px">Importer</button>
        </form>

        <p style="margin-top:20px;font-size:15px;color:#7A6A55;line-height:1.6">
            Un véhicule déjà enregistré n'est pas recréé : il est compté comme « déjà connu ».
            Tu peux donc corriger ton fichier et réimporter sans rien casser.
            Le fichier est effacé du serveur dès l'import terminé — il porte l'inventaire
            complet de ton parc.
        </p>
    @endif

    @if ($imports->isNotEmpty())
        <div style="margin-top:30px;border-top:3px solid #2B1D12;padding-top:18px">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-size:22px">Derniers imports</h2>
            <ul style="margin:12px 0 0;list-style:none;padding:0">
                @foreach ($imports as $import)
                    <li style="padding:10px 0;border-bottom:2px solid #E8DFCE;font-size:16px">
                        <a href="{{ route('fleet.import.status', ['import' => $import->id]) }}" style="color:#2B1D12;font-weight:700">
                            {{ $import->filename }}
                        </a>
                        — {{ (int) $import->processed_rows }} / {{ (int) $import->total_rows }} lignes
                        · {{ $import->status }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
