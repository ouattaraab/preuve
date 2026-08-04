@extends('public.layout')

@section('contenu')
    {{--
        Deux interactions, pas trois (CT-01) : on saisit, on valide. Aucun
        choix de type de bien à faire d'abord — la normalisation reconnaît
        seule un châssis, une plaque ou un IMEI, et demander à l'acheteur de
        trancher lui ferait porter une erreur qui n'est pas la sienne.
    --}}
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:34px;line-height:1.15">
        Ce bien est-il volé&nbsp;?
    </h1>
    <p style="margin-top:10px;font-size:19px">
        Vérifie avant de payer. C'est gratuit, anonyme, et personne ne saura que tu as cherché.
    </p>

    @isset($erreur)
        <p role="alert" style="margin-top:16px;padding:14px 16px;background:#FDE7E4;border:3px solid #C62F21;border-radius:12px;font-weight:700">
            {{ $erreur }}
        </p>
    @endisset

    <form method="GET" action="/verifier" style="margin-top:22px">
        <label for="q" style="display:block;font-weight:700;margin-bottom:8px">
            Numéro de châssis, plaque ou IMEI
        </label>
        <input id="q" name="q" class="champ" required autofocus
               autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="Ex. 1M8GDM9AXKP042788">
        <button type="submit" class="bouton">Vérifier</button>
    </form>

    <div style="margin-top:34px;border-top:3px solid #2B1D12;padding-top:20px">
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px">
            Où trouver le numéro&nbsp;?
        </h2>
        <ul style="margin-top:10px;padding-left:22px">
            <li><strong>Voiture, moto</strong> — le numéro de châssis (VIN) est sur la carte grise, et gravé sur le cadre.</li>
            <li><strong>Téléphone</strong> — compose <strong>*#06#</strong> pour afficher l'IMEI.</li>
            <li>La plaque d'immatriculation fonctionne aussi.</li>
        </ul>

        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:24px">
            Un numéro inconnu n'est pas un feu vert
        </h2>
        <p style="margin-top:8px">
            Si le bien n'est pas enregistré, cela ne veut pas dire qu'il est propre :
            cela veut dire que personne ne l'a encore déclaré. Demande au vendeur
            de l'enregistrer devant toi — un vendeur honnête n'a rien à y perdre.
        </p>
    </div>
@endsection
