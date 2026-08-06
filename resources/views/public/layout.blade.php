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
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
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
        /*
         * LE RELIEF DE LA MAQUETTE DJASSA, le même que l'application : une
         * ombre pleine et décalée, sans flou. Ce n'est pas un ornement — c'est
         * ce qui fait qu'un champ et un bouton se distinguent du fond à deux
         * mètres, en plein soleil, sur un écran d'entrée de gamme.
         */
        .champ {
            width: 100%; padding: 16px 18px; font-size: 20px; min-height: 64px;
            border: 3px solid #2B1D12; border-radius: 16px; background: #fff; color: #2B1D12;
            font-family: inherit; font-weight: 700;
            box-shadow: 4px 4px 0 #2B1D12;
        }
        .champ::placeholder { color: #8A7358; font-weight: 700; }
        /* L'ANNEAU DE FOCUS EST REMPLACÉ, JAMAIS SUPPRIMÉ. Celui du navigateur
           jure avec la charte, mais l'enlever rendrait le site impraticable au
           clavier — et c'est exactement le public d'Atkinson Hyperlegible. */
        .champ:focus-visible, .bouton:focus-visible, a:focus-visible {
            outline: 3px solid #D97706; outline-offset: 3px;
        }
        .bouton {
            width: 100%; margin-top: 14px; padding: 18px; min-height: 64px;
            background: #D97706; color: #FFF6E8; border: 3px solid #2B1D12; border-radius: 16px;
            font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 21px;
            letter-spacing: .3px; cursor: pointer; box-shadow: 4px 4px 0 #2B1D12;
        }
        .bouton.secondaire { background: #FFF; color: #2B1D12; }
        /* Un lien qui porte l'allure d'un bouton doit se comporter comme lui :
           pleine largeur, centré, et sans le soulignement des liens de texte. */
        a.bouton { display: block; text-decoration: none; text-align: center; }
        /* L'ENFONCEMENT PLUTÔT QU'UN CHANGEMENT DE COULEUR : le bouton bouge
           de la hauteur de son ombre, et le doigt sent qu'il a appuyé. */
        .bouton:active { transform: translate(4px, 4px); box-shadow: none; }
        .bouton:hover { filter: brightness(1.05); }
        /* La pastille de la maquette : elle répond à la première question que
           se pose quelqu'un à qui l'on propose une moto sur un parking. */
        .pastille {
            display: inline-block; background: #2B1D12; color: #FFF6E8;
            padding: 8px 16px; border-radius: 999px; font-size: 14px; font-weight: 700;
        }
        /* Le bandeau sombre de l'application, pour la phrase qui vend le
           produit : ce que PREUVE fait, en une ligne. */
        .bandeau {
            display: flex; gap: 14px; align-items: center;
            background: #2B1D12; color: #FFF6E8; border-radius: 16px;
            padding: 18px 20px; font-size: 16px; font-weight: 700; line-height: 1.45;
        }
        .surtitre {
            font-size: 13px; font-weight: 700; letter-spacing: .8px;
            color: #8A7358; text-transform: uppercase;
        }
        .note { font-size: 15px; color: #5C4A33; }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }
    </style>
</head>
<body>
<header style="padding:18px 20px;border-bottom:3px solid #2B1D12">
    <div style="max-width:720px;margin:0 auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <a href="/" style="text-decoration:none" class="marque">Preuve<span class="point">.</span></a>
        {{-- LA PASTILLE DIT LA VÉRITÉ DE LA PAGE OÙ ELLE SE TROUVE. « Sans
             compte » au-dessus d'un formulaire de connexion se lit comme un
             mensonge, et une promesse démentie une fois n'est plus crue
             ailleurs.
             UNE SECTION ET NON UNE VARIABLE : une variable posée par `@php`
             dans une vue enfant est locale à cette vue et n'atteint jamais son
             gabarit. La section, si. --}}
        <span class="pastille">@yield('pastille', 'Gratuit · Sans compte')</span>
    </div>
</header>

<main>
    @yield('contenu')
</main>

<footer style="border-top:3px solid #2B1D12;padding:18px 20px">
    <div style="max-width:720px;margin:0 auto" class="note">
        La consultation est <strong>gratuite, anonyme et sans compte</strong>.
        Nous ne disons jamais qui a enregistré un bien, ni qui l'a consulté.
        <br>
        {{-- LES AUTRES PORTES DU SITE. Deux pages ont été livrées sans lien
             depuis nulle part — un espace loueur et un rapport payé — et
             personne ne pouvait les atteindre. Une capacité sans porte
             d'entrée n'existe pas. --}}
        <a href="/verifier">Vérifier un bien</a> ·
        <a href="/flotte/connexion">Espace loueur</a> ·
        <a href="/conditions">Conditions</a> ·
        <a href="/confidentialite">Confidentialité</a>
        <br>Édité par BookMi · Côte d'Ivoire.
    </div>
</footer>
</body>
</html>
