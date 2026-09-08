@extends('public.layout')

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:32px;line-height:1.1">
        Conditions d'utilisation
    </h1>
    <p style="margin-top:10px;font-size:16px;color:#7A6A55">
        En vigueur au {{ $miseAJour }}. Éditeur&nbsp;: BookMi, Côte d'Ivoire.
    </p>

    {{-- CE QUE PREUVE EST, ET CE QU'IL N'EST PAS. C'est la section la plus
         importante de cette page, et elle vient en premier : la méprise la plus
         coûteuse serait qu'un acheteur croie avoir acquis une garantie de
         propriété. --}}
    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Ce que PREUVE est, et ce qu'il n'est pas
    </h2>
    <p style="margin-top:8px">
        PREUVE est un <strong>registre déclaratif</strong>. Il conserve ce que des
        personnes ont déclaré, avec la date de leur déclaration, et le rend consultable.
    </p>
    <p style="margin-top:8px">
        Il <strong>n'est pas un titre de propriété</strong>, ni un certificat administratif,
        ni un substitut à la carte grise ou au dépôt de plainte. Un enregistrement sur
        PREUVE ne prouve pas qu'une personne est propriétaire&nbsp;: il prouve qu'elle
        l'a déclaré, à une date donnée, et que cette déclaration n'a pas été
        contestée. C'est utile, et ce n'est pas la même chose.
    </p>
    <p style="margin-top:8px">
        Un bien <strong>non enregistré n'est pas un bien sain</strong>. L'absence
        d'information n'est pas une information rassurante.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        La consultation
    </h2>
    <p style="margin-top:8px">
        Elle est <strong>gratuite, anonyme et sans compte</strong>. Aucune inscription
        n'est requise, aucune n'est proposée pour ce seul usage.
    </p>
    <p style="margin-top:8px">
        Un plafond horaire s'applique par connexion. Il ne vise pas à doser l'usage
        mais à empêcher le <strong>balayage automatique</strong> du registre&nbsp;:
        seuls les numéros différents comptent, et revérifier un même bien est libre.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Ce que vous déclarez vous engage
    </h2>
    <p style="margin-top:8px">
        Enregistrer un bien, déclarer un vol ou déposer une réclamation sont des
        <strong>déclarations sur l'honneur</strong>. Une déclaration de vol rend un
        bien invendable&nbsp;: la faire sciemment à tort cause un préjudice réel à
        quelqu'un, et peut engager votre responsabilité civile et pénale.
    </p>
    <p style="margin-top:8px">
        Chaque action sensible est <strong>horodatée et scellée</strong> dans une
        chaîne d'écritures que personne ne peut réécrire, y compris l'éditeur. Sur
        réquisition d'une autorité compétente, cette trace peut être produite.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Les litiges entre utilisateurs
    </h2>
    <p style="margin-top:8px">
        Quand deux personnes revendiquent le même bien, PREUVE ouvre une procédure
        de réclamation et <strong>arbitre sur les pièces</strong> versées, selon une
        grille publiée. Cet arbitrage détermine ce que le registre affiche&nbsp;;
        <strong>il ne tranche pas la propriété</strong>, qui relève des tribunaux
        ivoiriens.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Les services payants
    </h2>
    <p style="margin-top:8px">
        Le rapport détaillé, les places d'enregistrement au-delà du quota gratuit et
        les frais de dossier d'une réclamation sont payants. <strong>Les montants
        sont affichés avant tout paiement</strong>, et peuvent être portés à zéro par
        l'éditeur&nbsp;: dans ce cas le service est gratuit et l'écran l'indique.
    </p>
    <p style="margin-top:8px">
        Le lien d'un rapport acheté reste ouvert trente jours et s'ouvre sur n'importe
        quel appareil. <strong>Quiconque détient ce lien peut lire le rapport</strong>&nbsp;:
        ne le publiez pas.
    </p>
    <p style="margin-top:8px">
        Les frais de dossier d'une réclamation sont <strong>remboursés si elle
        aboutit</strong>.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Ce que l'éditeur ne garantit pas
    </h2>
    <p style="margin-top:8px">
        PREUVE restitue des déclarations&nbsp;; il ne les vérifie pas toutes, et ne
        peut pas garantir qu'un bien volé y soit déclaré. <strong>Un verdict
        « rien à signaler » ne remplace ni l'examen du bien, ni celui des papiers,
        ni la pièce d'identité du vendeur.</strong>
    </p>
    <p style="margin-top:8px">
        Le service peut être interrompu pour maintenance ou pour cause extérieure.
        La consultation n'est jamais bloquée par une mise à jour de l'application.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Vos données
    </h2>
    <p style="margin-top:8px">
        Elles sont traitées conformément à la <strong>loi n° 2013-450</strong> relative
        à la protection des données à caractère personnel. Le détail figure sur la
        page <a href="/confidentialite">Confidentialité</a>&nbsp;: ce qui est conservé,
        combien de temps, et comment exercer vos droits.
    </p>
    <p style="margin-top:8px">
        Deux règles ne souffrent aucune exception&nbsp;: <strong>l'identité du
        détenteur d'un bien n'est jamais divulguée</strong> à qui le consulte, et
        <strong>l'identité de qui consulte n'est jamais divulguée</strong> au
        détenteur — y compris lorsqu'un rapport est acheté.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Modification et droit applicable
    </h2>
    <p style="margin-top:8px">
        Ces conditions peuvent évoluer&nbsp;; la date en vigueur figure en haut de
        cette page. Elles sont soumises au <strong>droit ivoirien</strong>, et les
        tribunaux d'Abidjan sont compétents à défaut de règlement amiable.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Nous joindre
    </h2>
    @if ($contact !== null)
        <p style="margin-top:8px">
            <a href="mailto:{{ $contact }}" style="font-weight:700">{{ $contact }}</a>
        </p>
    @else
        {{-- ON NE PROMET PAS UN GUICHET QUI N'EXISTE PAS. Une adresse qui ne
             répond pas ferait passer le silence pour un refus. --}}
        <p style="margin-top:8px">
            Le point de contact est en cours d'ouverture. Il sera publié ici.
        </p>
    @endif

    <p style="margin-top:28px;border-top:3px solid #2B1D12;padding-top:18px">
        <a href="/" style="font-weight:700">← Vérifier un bien</a>
    </p>
@endsection
