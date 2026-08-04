{{--
    Connexion à l'espace administrateur, en deux temps : numéro puis code.

    La maquette prévoyait email + mot de passe + second facteur. La plateforme
    n'a pas de mot de passe, et le code à usage unique tient ce rôle. L'écran
    reprend la palette et les polices du design ; seuls les champs diffèrent.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — Preuve Admin</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/console/fonts.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #2B1D12; color: #FFF6E8; font-family: 'Atkinson Hyperlegible', sans-serif;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        input { font-family: 'Atkinson Hyperlegible', sans-serif; }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }
    </style>
</head>
<body>
<main style="width:100%;max-width:400px">
    <div style="display:flex;align-items:baseline;gap:8px;justify-content:center;margin-bottom:28px">
        <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:30px">Preuve<span style="color:#D97706">.</span></span>
        <span style="font-size:12px;font-weight:700;color:#B9A98E">ADMIN</span>
    </div>

    <div style="background:#FAF6EE;color:#2B1D12;border-radius:16px;padding:26px">

        @if ($errors->any())
            <div role="alert" style="background:#FBE7E7;border-left:4px solid #B23A3A;padding:12px 14px;border-radius:8px;margin-bottom:18px;font-size:14px;font-weight:700">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('message'))
            <div style="background:#FFF6E8;border-left:4px solid #D97706;padding:12px 14px;border-radius:8px;margin-bottom:18px;font-size:14px">
                {{ session('message') }}
            </div>
        @endif

        @if (session('etape') === 'code')
            <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:20px;margin-bottom:6px">Votre code</h1>
            <p style="font-size:14px;color:#7A6A55;margin-bottom:20px">
                Saisissez le code à six chiffres qui vient de vous être envoyé.
            </p>

            <form method="POST" action="{{ route('admin.login.verify') }}">
                @csrf
                <input type="hidden" name="phone" value="{{ session('phone') }}">

                <label for="code" style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">Code reçu</label>
                {{-- `one-time-code` : le navigateur et le téléphone proposent
                     alors le code sans qu'il soit recopié à la main. --}}
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus
                       maxlength="6" placeholder="000000"
                       style="width:100%;padding:14px;border:2px solid #E4DBC8;border-radius:10px;font-size:20px;letter-spacing:6px;text-align:center;font-weight:700;background:#fff;color:#2B1D12">

                <button type="submit"
                        style="width:100%;margin-top:16px;background:#D97706;color:#2B1D12;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer">
                    Entrer
                </button>
            </form>

            <a href="{{ route('admin.login') }}" style="display:block;text-align:center;margin-top:14px;font-size:13px;font-weight:700">Recommencer</a>
        @else
            <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:20px;margin-bottom:6px">Connexion</h1>
            <p style="font-size:14px;color:#7A6A55;margin-bottom:20px">
                Un code à usage unique vous sera envoyé. Aucun mot de passe n'est demandé, ici comme ailleurs.
            </p>

            <form method="POST" action="{{ route('admin.login.request') }}">
                @csrf
                <label for="phone" style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">Numéro de téléphone</label>
                <input id="phone" name="phone" type="tel" autocomplete="tel" required autofocus
                       placeholder="+225 01 01 18 16 86"
                       style="width:100%;padding:14px;border:2px solid #E4DBC8;border-radius:10px;font-size:16px;background:#fff;color:#2B1D12">

                <button type="submit"
                        style="width:100%;margin-top:16px;background:#D97706;color:#2B1D12;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer">
                    Recevoir un code
                </button>
            </form>
        @endif
    </div>

    <p style="text-align:center;margin-top:20px;font-size:12px;color:#B9A98E;line-height:1.6">
        Accès réservé. Chaque action menée depuis cet espace est journalisée
        dans une chaîne d'audit inaltérable.
    </p>
</main>
</body>
</html>
