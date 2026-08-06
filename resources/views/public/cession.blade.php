@extends('public.layout')

@section('pastille', 'Cession en cours')

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:36px;line-height:1.08">
        Un bien vous<br><span style="color:#D97706">est cédé</span>
    </h1>
    <p style="margin-top:12px;font-size:18px;color:#5A4632;line-height:1.5">
        Le détenteur de ce bien a ouvert une cession à votre nom. Elle ne prend effet
        que si vous la confirmez.
    </p>

    {{-- LE BIEN D'ABORD, ET EN GRAND. C'est la seule chose qui permet à
         quelqu'un de reconnaître la transaction : celui qui vient d'acheter une
         moto sait laquelle. Le vendeur n'est PAS nommé (règle absolue n° 4). --}}
    <article style="margin-top:22px;border:3px solid #2B1D12;border-radius:16px;padding:20px;background:#FFF;box-shadow:4px 4px 0 #2B1D12">
        <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:26px;letter-spacing:.5px">
            {{ $bien['numero'] }}
        </span>
        <p style="margin-top:8px;font-size:17px">
            {{ $bien['marque'] !== '' ? $bien['marque'] : $bien['categorie'] }}
            <span style="color:#7A6A55">· {{ $bien['categorie'] }}</span>
        </p>
    </article>

    <p style="margin-top:14px;font-size:16px;color:#5C4A33;line-height:1.55">
        {{-- UN REFUS N'EST PAS UN GESTE À FAIRE. Ne rien faire suffit, et le
             dire évite qu'on cherche un bouton « refuser » qui n'existe pas. --}}
        Si vous ne reconnaissez pas ce bien, <strong>ne faites rien</strong> : la cession
        s'annulera d'elle-même
        @if ($expire !== null)
            le {{ $expire->translatedFormat('j F Y') }}
        @endif
        et le bien restera à son détenteur actuel.
    </p>

    @if ($erreur !== null)
        <div style="margin-top:20px;border:3px solid #C62F21;border-radius:14px;padding:16px 18px;background:#FFF">
            <p style="font-size:16px;color:#C62F21;font-weight:700;line-height:1.5">{{ $erreur }}</p>
        </div>
    @endif

    <h2 style="margin-top:26px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:23px">
        Confirmer avec votre code
    </h2>
    <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.55">
        Un code à six chiffres vous a été envoyé par courriel, dans un message séparé.
        Il expire au bout de quelques minutes.
    </p>

    <form method="POST" action="/cession/{{ $jeton }}" style="margin-top:16px">
        @csrf
        <label for="code" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
            Code reçu par courriel
        </label>
        <input id="code" name="code" class="champ" inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" autocapitalize="off" spellcheck="false" placeholder="000000">
        <button type="submit" class="bouton">CONFIRMER LA CESSION</button>
    </form>

    <div class="bandeau" style="margin-top:26px">
        <span style="font-size:26px;line-height:1">📱</span>
        <span>
            Vous avez l'application Preuve&nbsp;? Vous pouvez aussi confirmer depuis
            « Mes biens », avec le même code.
        </span>
    </div>

    <h2 style="margin-top:26px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:23px">
        Ce que la confirmation change
    </h2>
    <p style="margin-top:8px;font-size:16px;color:#5C4A33;line-height:1.6">
        Le bien est enregistré à votre nom. Toute personne qui vérifie son numéro verra
        qu'il est enregistré, et <strong>vous seul</strong> pourrez le déclarer volé ou le
        céder à votre tour — l'ancien détenteur ne peut plus rien en faire.
    </p>
    <p style="margin-top:10px;font-size:16px;color:#5C4A33;line-height:1.6">
        {{-- DIT AVANT, PAS APRÈS. Découvrir « non vérifié » une fois le bien
             reçu se lit comme un défaut ; annoncé ici, c'est une garantie :
             personne n'hérite d'une confiance qu'il n'a pas établie. --}}
        Le bien repart en « déclaré, non vérifié » : les justificatifs du vendeur
        prouvaient <em>sa</em> propriété, pas la vôtre. Vous pourrez déposer les vôtres
        ensuite.
    </p>

    <p style="margin-top:22px;font-size:15px;color:#7A6A55;line-height:1.55">
        Aucun agent de Preuve ne vous demandera jamais ce code, ni par téléphone,
        ni par message.
    </p>
@endsection
