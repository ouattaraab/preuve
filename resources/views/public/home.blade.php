@extends('public.layout')

@section('contenu')
    {{--
        Deux interactions, pas trois (CT-01) : on saisit, on valide. Aucun
        choix de type de bien à faire d'abord — la normalisation reconnaît
        seule un châssis, une plaque ou un IMEI, et demander à l'acheteur de
        trancher lui ferait porter une erreur qui n'est pas la sienne.
    --}}
    {{-- LA MÊME ACCROCHE QUE L'APPLICATION, au mot près. Quelqu'un qui arrive
         par le web puis installe l'application doit reconnaître le même
         produit ; deux promesses différentes pour la même chose font douter
         des deux. La coupure en trois lignes est voulue : à cette taille, le
         moteur couperait « vérifie ! » n'importe où. --}}
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:44px;line-height:1.02;letter-spacing:-.5px">
        Avant<br>d'acheter,<br><span style="color:#D97706">vérifie&nbsp;!</span>
    </h1>
    <p style="margin-top:12px;font-size:19px;color:#5A4632;line-height:1.45">
        Moto, voiture, téléphone… tape le numéro, tu sais tout de suite.
    </p>

    @isset($erreur)
        <p role="alert" style="margin-top:16px;padding:14px 16px;background:#FDE7E4;border:3px solid #C62F21;border-radius:12px;font-weight:700">
            {{ $erreur }}
        </p>
    @endisset

    <form method="GET" action="/verifier" style="margin-top:22px">
        {{-- L'ÉTIQUETTE RESTE POUR LES LECTEURS D'ÉCRAN, mais quitte l'écran :
             elle répétait mot pour mot l'indication du champ, et deux fois la
             même phrase se lit comme une erreur. Un champ sans étiquette du
             tout serait, lui, muet pour qui n'y voit pas. --}}
        <label for="q" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">
            Numéro de châssis, plaque ou IMEI
        </label>
        <input id="q" name="q" class="champ" required autofocus
               autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="Plaque, châssis ou IMEI">
        <button type="submit" class="bouton">JE VÉRIFIE</button>
    </form>

    {{-- CE QUE PREUVE FAIT, EN UNE LIGNE, et c'est la phrase qui vend le
         produit : ce n'est pas un annuaire, c'est ce qui rend un bien volé
         invendable. --}}
    <div class="bandeau" style="margin-top:20px">
        <span style="font-size:26px;line-height:1">⚡</span>
        <span>Une déclaration de vol rend le bien invendable dans la seconde,
              partout en Côte d'Ivoire.</span>
    </div>

    {{-- LES BIENS VOLÉS, JUSTE APRÈS LA VÉRIFICATION. C'est le second geste
         utile : celui qui n'a pas de numéro sous les yeux peut quand même
         reconnaître un bien. Trois seulement, avec le total : une liste sans
         fin sur un accueil se fait ignorer. --}}
    @if (! empty($volesRecents ?? []))
        <div style="margin-top:30px;border-top:3px solid #2B1D12;padding-top:20px">
            <p class="surtitre">Déclarés volés récemment</p>
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:6px">
                {{ $volesTotal }} bien(s) signalé(s)
            </h2>
            <div style="margin-top:12px">
                @foreach ($volesRecents as $vole)
                    <a href="/voles" style="display:block;text-decoration:none;border:2px solid #2B1D12;border-radius:12px;padding:12px 14px;margin-bottom:8px;background:#FFF">
                        <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px">{{ $vole['identifier'] }}</span>
                        <span style="color:#C62F21;font-weight:700;font-size:13px;float:right">VOLÉ</span>
                        <br><span style="font-size:15px;color:#5C4A33">{{ $vole['brand_model'] ?? ucfirst($vole['category']) }}</span>
                    </a>
                @endforeach
            </div>
            <a href="/voles" class="bouton secondaire" style="margin-top:6px">Voir tous les biens volés</a>
        </div>
    @endif

    <div style="margin-top:30px;border-top:3px solid #2B1D12;padding-top:20px">
        <p class="surtitre">Où trouver le numéro</p>
        <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:6px">
            Sur le bien, ou sur ses papiers
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
