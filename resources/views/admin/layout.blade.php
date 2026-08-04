{{--
    Coquille de l'espace administrateur.

    Le balisage et les couleurs sont repris tels quels de la maquette
    (docs/Preuve - Admin.html) : styles en ligne, palette #FAF6EE / #2B1D12 /
    #D97706, polices Atkinson Hyperlegible et Bricolage Grotesque. La maquette
    n'utilisait aucun framework CSS — la reprendre à l'identique coûte donc
    moins qu'une réinterprétation, et ne dérive pas.

    Les polices sont servies en fichiers woff2 plutôt qu'en base64 : 154 Ko mis
    en cache par le navigateur, contre 362 Ko rechargés à chaque page.

    ELLES VIVENT SOUS `/console/` ET NON `/admin/`, et ce n'est pas cosmétique :
    « admin » est le préfixe des ROUTES. Tant qu'un dossier du même nom existait
    dans la racine servie, le serveur y résolvait `/admin/` — sans index et sans
    listage autorisé — et rendait un 403 avant que Laravel ne voie la requête.
    Un préfixe de route et un dossier de ressources ne doivent jamais porter le
    même nom.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titre ?? 'Administration' }} — Preuve</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/console/fonts.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #EFE9DC; color: #2B1D12; font-family: 'Atkinson Hyperlegible', sans-serif; }
        a { color: #2B1D12; }
        a:hover { color: #D97706; }
        button { font-family: 'Atkinson Hyperlegible', sans-serif; }
        /* Respecte le réglage système : une console d'exploitation se consulte
           parfois des heures durant. */
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<div style="min-height:100vh;background:#FAF6EE;color:#2B1D12;display:flex">

    <nav style="width:250px;flex-shrink:0;background:#2B1D12;color:#FFF6E8;display:flex;flex-direction:column;padding:22px 14px;min-height:100vh">
        <div style="display:flex;align-items:baseline;gap:8px;padding:0 10px 22px">
            <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px">Preuve<span style="color:#D97706">.</span></span>
            <span style="font-size:11px;font-weight:700;color:#B9A98E">ADMIN</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:4px">
            @foreach ($navigation as $item)
                @php $actif = ($item['key'] ?? null) === ($vue ?? null); @endphp
                <a href="{{ $item['url'] }}"
                   style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:{{ $actif ? '#D97706' : 'transparent' }};border-radius:10px;padding:12px 14px;min-height:46px;text-decoration:none;color:{{ $actif ? '#2B1D12' : '#FFF6E8' }};font-size:15px;font-weight:700">
                    {{ $item['nom'] }}
                    @isset($item['badge'])
                        <span style="background:#D97706;color:#2B1D12;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px">{{ $item['badge'] }}</span>
                    @endisset
                </a>
            @endforeach
        </div>

        <div style="margin-top:auto;padding:14px 10px 4px;border-top:2px solid rgba(255,246,232,.15);display:flex;flex-direction:column;gap:10px">
            <div style="display:flex;align-items:center;gap:8px">
                <span id="etat-pastille" style="width:9px;height:9px;border-radius:50%;background:#8A7A62"></span>
                <span id="etat-texte" style="font-size:13px;font-weight:700">Vérification…</span>
            </div>

            <form method="POST" action="{{ route('admin.logout') }}" style="display:flex;align-items:center;gap:10px;background:rgba(255,246,232,.06);border-radius:12px;padding:10px;min-height:56px">
                @csrf
                <span style="width:38px;height:38px;border-radius:50%;background:#D97706;color:#2B1D12;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid #FFF6E8">{{ $initiales }}</span>
                <span style="flex:1;min-width:0">
                    <span style="display:block;font-size:14px;font-weight:700;color:#FFF6E8">{{ $nomAffiche }}</span>
                    <span style="display:block;font-size:11px;font-weight:700;color:#B9A98E">{{ $profil }}</span>
                </span>
                <button type="submit" title="Déconnexion"
                        style="background:none;border:none;color:#B9A98E;font-size:16px;cursor:pointer;padding:4px">⏻</button>
            </form>
        </div>
    </nav>

    <main style="flex:1;min-width:0;display:flex;flex-direction:column">
        <header style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 28px;border-bottom:2px solid #E4DBC8;background:#FAF6EE">
            <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:24px">{{ $titre }}</h1>
            <span style="font-size:13px;font-weight:700;color:#7A6A55">{{ now()->translatedFormat('D j F Y · H\\hi') }}</span>
        </header>

        <div style="flex:1;padding:24px 28px 40px;overflow:auto">
            @yield('contenu')
        </div>
    </main>
</div>

<script src="/console/app.js" defer></script>
</body>
</html>
