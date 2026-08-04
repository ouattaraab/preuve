{{--
    Coquille du front public.

    Direction DJASSA : chaleureuse, tutoyante, lisible à deux mètres en plein
    soleil — au marché et au parking, pas au bureau. Bricolage Grotesque pour
    trancher, Atkinson Hyperlegible pour lire (dessinée pour la basse vision et
    la basse littératie).

    SUR LE CHEMIN NOMINAL : AUCUN SCRIPT, AUCUNE RESSOURCE TIERCE, AUCUN
    COOKIE. CT-05 impose l'utilisabilité en 3G, et une page qui n'appelle
    personne d'autre ne peut divulguer à personne d'autre ce que le visiteur
    est venu vérifier. Le seul appel extérieur du site est le défi anti-automate,
    chargé uniquement APRÈS un refus pour plafond atteint — jamais avant.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre ?? 'Preuve — vérifier un bien avant d\'acheter' }}</title>
    <meta name="description" content="{{ $description ?? 'Vérifiez gratuitement, sans compte et en deux gestes si un véhicule ou un téléphone est déclaré volé, en litige ou en location.' }}">
    @hasSection('canonique')
        <link rel="canonical" href="@yield('canonique')">
    @endif
    @unless ($indexable ?? false)
        {{-- L'URL de résultat porte l'identifiant réel : l'indexer publierait
             l'annuaire des numéros enregistrés, moteur après moteur. --}}
        <meta name="robots" content="noindex, nofollow">
    @endunless
    <meta name="theme-color" content="#FAF6EE">
    <link rel="stylesheet" href="/fonts.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #FAF6EE; color: #2B1D12;
            font-family: 'Atkinson Hyperlegible', system-ui, sans-serif;
            font-size: 18px; line-height: 1.55;
            display: flex; flex-direction: column; min-height: 100vh;
        }
        a { color: #2B1D12; }
        main { flex: 1; width: 100%; max-width: 720px; margin: 0 auto; padding: 28px 20px 48px; }
        .marque { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 26px; }
        .point { color: #D97706; }
        /* Cible de 56 px : on saisit debout, une main sur le guidon. */
        .champ {
            width: 100%; padding: 16px 18px; font-size: 20px; min-height: 56px;
            border: 3px solid #2B1D12; border-radius: 12px; background: #fff; color: #2B1D12;
            font-family: inherit;
        }
        .bouton {
            width: 100%; margin-top: 12px; padding: 16px 18px; min-height: 56px;
            background: #D97706; color: #2B1D12; border: 3px solid #2B1D12; border-radius: 12px;
            font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 20px;
            cursor: pointer;
        }
        .bouton:hover { background: #2B1D12; color: #FFF6E8; }
        .note { font-size: 15px; color: #5C4A33; }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }
    </style>
</head>
<body>
<header style="padding:18px 20px;border-bottom:3px solid #2B1D12">
    <div style="max-width:720px;margin:0 auto;display:flex;align-items:baseline;gap:10px">
        <a href="/" style="text-decoration:none" class="marque">Preuve<span class="point">.</span></a>
        <span class="note">Registre des biens · Côte d'Ivoire</span>
    </div>
</header>

<main>
    @yield('contenu')
</main>

<footer style="border-top:3px solid #2B1D12;padding:18px 20px">
    <div style="max-width:720px;margin:0 auto" class="note">
        La consultation est <strong>gratuite, anonyme et sans compte</strong>.
        Nous ne disons jamais qui a enregistré un bien, ni qui l'a consulté.
        <br><a href="/confidentialite">Confidentialité et mentions légales</a> · Édité par OVERNETFLOW.
    </div>
</footer>
</body>
</html>
