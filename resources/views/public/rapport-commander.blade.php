@extends('public.layout')

@section('pastille', 'Rapport détaillé')

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
        Toute l'histoire<br><span style="color:#D97706">de ce bien</span>
    </h1>

    <p style="margin-top:12px;font-size:18px;color:#5A4632;line-height:1.5">
        Le verdict gratuit dit s'il y a une alerte. Le rapport dit le reste :
        <strong>ce qui n'apparaît pas dans un « rien à signaler »</strong>.
    </p>

    {{-- CE QU'ON ACHÈTE, ÉNUMÉRÉ. « Rapport détaillé » ne veut rien dire pour
         quelqu'un qui hésite à sortir 1 000 FCFA au bord d'une route. --}}
    <div style="margin-top:20px;border:3px solid #2B1D12;border-radius:16px;padding:20px;background:#FFF;box-shadow:4px 4px 0 #2B1D12">
        <p style="font-weight:800;font-size:17px">Ce que vous y lirez</p>
        <ul style="margin:10px 0 0;padding-left:20px;font-size:16px;line-height:1.7;color:#2B1D12">
            <li><strong>Combien de fois</strong> ce bien a changé de mains, et à quelles dates</li>
            <li>Depuis quand il est enregistré, et son niveau de vérification</li>
            <li>Les épisodes de son histoire : déclaré volé puis retrouvé, litige, remise en circulation</li>
            <li>Les pièces qu'un agent a contrôlées — carte grise, facture</li>
        </ul>
        <p style="margin-top:12px;font-size:15px;color:#7A6A55;line-height:1.55">
            {{-- L'ANONYMAT EST SYMÉTRIQUE, ET SE DIT AVANT L'ACHAT. Sans cela,
                 on paie en croyant obtenir un nom, et on se sent floué. --}}
            <strong>Jamais de noms.</strong> Ni celui du détenteur, ni ceux des précédents : un
            rapport ne sert pas à retrouver des personnes. Le vendeur ne saura pas non plus que
            vous l'avez acheté.
        </p>
    </div>

    <div style="margin-top:16px;display:flex;gap:14px;flex-wrap:wrap;align-items:center">
        <div style="background:#2B1D12;border-radius:14px;padding:16px 22px">
            <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:28px;color:#D97706">
                {{ $gratuit ? 'Gratuit' : number_format($prix, 0, ',', ' ').' FCFA' }}
            </span>
        </div>
        <div style="flex:1;min-width:200px;font-size:15px;color:#5C4A33;line-height:1.5">
            Bien <code style="background:#FFF6E8;padding:2px 6px;border-radius:5px">{{ $publicRef }}</code>
            · {{ $categorie }}<br>
            Lien valable 30 jours, sur n'importe quel appareil.
        </div>
    </div>

    @if ($erreur !== null)
        <div style="margin-top:20px;border:3px solid #C62F21;border-radius:14px;padding:16px 18px;background:#FFF">
            <p style="font-size:16px;color:#C62F21;font-weight:700;line-height:1.5">{{ $erreur }}</p>
        </div>
    @endif

    @if ($etape === 'code')
        <h2 style="margin-top:26px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:23px">
            Votre code
        </h2>
        <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.55">
            {{-- OÙ LE CODE EST PARTI. Sans cette phrase, on guette un SMS
                 pendant que le message attend dans la boîte aux lettres. --}}
            @if ($parCourriel)
                Un code vient d'être envoyé à <strong>{{ $acheteur['buyer_email'] ?? '' }}</strong>.
                Regardez aussi vos indésirables.
            @else
                Un code vient d'être envoyé au <strong>{{ $acheteur['buyer_phone'] ?? '' }}</strong>.
            @endif
        </p>

        <form method="POST" action="/rapport/commander/{{ $publicRef }}" style="margin-top:14px">
            @csrf
            <label for="code" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
                Code reçu
            </label>
            <input id="code" name="code" class="champ" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" autocapitalize="off" spellcheck="false" placeholder="000000" required>
            <button type="submit" class="bouton">
                {{ $gratuit ? 'OUVRIR LE RAPPORT' : 'PAYER ET OUVRIR LE RAPPORT' }}
            </button>
        </form>

        <form method="POST" action="/rapport/commander/{{ $publicRef }}/code" style="margin-top:10px">
            @csrf
            <input type="hidden" name="buyer_name" value="{{ $acheteur['buyer_name'] ?? '' }}">
            <input type="hidden" name="buyer_email" value="{{ $acheteur['buyer_email'] ?? '' }}">
            <input type="hidden" name="buyer_phone" value="{{ $acheteur['buyer_phone'] ?? '' }}">
            <button type="submit" class="bouton secondaire">RENVOYER UN CODE</button>
        </form>
    @else
        <h2 style="margin-top:26px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:23px">
            Qui êtes-vous&nbsp;?
        </h2>
        <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.55">
            {{-- POURQUOI ON DEMANDE, dit au moment où on demande. Trois champs
                 sans justification, sur un site qui vend l'anonymat par
                 ailleurs, ressemblent à une collecte de données. --}}
            Pas de compte à créer. Ces trois informations restent chez nous&nbsp;: elles existent
            pour qu'un rapport ne soit jamais totalement anonyme — sans quoi il deviendrait
            l'outil de repérage idéal. <strong>Elles ne sont jamais montrées au propriétaire
            du bien.</strong>
        </p>

        <form method="POST" action="/rapport/commander/{{ $publicRef }}/code" style="margin-top:16px">
            @csrf
            <label for="buyer_name" style="display:block;font-weight:700;margin-bottom:6px">Votre nom</label>
            <input id="buyer_name" name="buyer_name" class="champ" required maxlength="150"
                   autocomplete="name" value="{{ $acheteur['buyer_name'] ?? '' }}">

            <label for="buyer_email" style="display:block;font-weight:700;margin:14px 0 6px">Votre e-mail</label>
            <input id="buyer_email" name="buyer_email" class="champ" type="email" required maxlength="150"
                   autocomplete="email" autocapitalize="off" spellcheck="false"
                   value="{{ $acheteur['buyer_email'] ?? '' }}">

            <label for="buyer_phone" style="display:block;font-weight:700;margin:14px 0 6px">Votre téléphone</label>
            <input id="buyer_phone" name="buyer_phone" class="champ" type="tel" required maxlength="30"
                   autocomplete="tel" placeholder="+225 01 01 18 16 86"
                   value="{{ $acheteur['buyer_phone'] ?? '' }}">

            <button type="submit" class="bouton" style="margin-top:16px">RECEVOIR MON CODE</button>
        </form>
    @endif

    <p style="margin-top:22px;font-size:15px;color:#7A6A55;line-height:1.55">
        <a href="/verifier" style="color:#2B1D12">← Revenir à la vérification</a>, qui reste
        gratuite et sans compte.
    </p>
@endsection
