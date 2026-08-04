@extends('public.layout')

@section('contenu')
    {{--
        Confidentialité et mentions légales (Loi ivoirienne n° 2013-450).

        ELLE DÉCRIT CE QUE LE CODE FAIT, pas ce qu'il serait souhaitable qu'il
        fasse. Chaque paragraphe correspond à un mécanisme vérifiable dans le
        dépôt : le hachage salé quotidien, l'empreinte SHA-256 du numéro de
        pièce, le registre des levées d'anonymat. Une politique qui promettrait
        au-delà du code serait une déclaration mensongère de plus.
    --}}
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:30px;line-height:1.15">
        Confidentialité
    </h1>
    <p class="note" style="margin-top:8px">Loi ivoirienne n° 2013-450 relative à la protection des données à caractère personnel.</p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Vérifier un bien ne demande rien de vous
    </h2>
    <p style="margin-top:8px">
        La consultation est gratuite, anonyme et sans compte. Ces pages ne déposent
        <strong>aucun cookie</strong>, n'ouvrent aucune session et ne chargent aucune
        ressource extérieure : personne d'autre que nous ne sait ce que vous avez vérifié.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Ce que nous conservons d'une consultation
    </h2>
    <ul style="margin-top:8px;padding-left:22px">
        <li>l'identifiant recherché, la date, et si la recherche venait du site ou de l'application ;</li>
        <li><strong>une empreinte de votre adresse IP, jamais l'adresse elle-même.</strong>
            Cette empreinte est calculée avec un secret qui change chaque jour :
            deux consultations faites à deux jours d'intervalle ne peuvent pas être rapprochées,
            même par nous.</li>
    </ul>
    <p style="margin-top:8px">
        Cette empreinte sert à deux choses, et à rien d'autre : limiter le nombre de
        consultations par heure — sans quoi le registre serait recopié en entier par
        des automates — et prévenir un propriétaire quand son bien est consulté
        anormalement souvent. <strong>Les consultations sont effacées au bout de douze mois.</strong>
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Si vous créez un compte
    </h2>
    <p style="margin-top:8px">
        Enregistrer un bien, le transférer, le déclarer volé ou contester une propriété
        demande un compte. Nous conservons alors votre numéro de téléphone, votre adresse
        électronique et votre nom. Il n'y a <strong>pas de mot de passe</strong> : vous vous
        connectez par un code à usage unique.
    </p>
    <p style="margin-top:8px">
        Si vous faites vérifier votre identité, les photos de votre pièce sont
        <strong>chiffrées</strong> sur nos serveurs. Le numéro de la pièce, lui,
        n'est conservé que sous forme d'empreinte : <strong>il n'est pas restituable</strong>,
        y compris par nous, y compris sur réquisition d'une autorité.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Nous ne disons jamais qui est qui
    </h2>
    <p style="margin-top:8px">
        Celui qui consulte un bien n'apprend pas qui l'a enregistré. Celui qui a
        enregistré un bien n'apprend pas qui l'a consulté. Cela vaut dans les deux sens,
        sans exception, <strong>y compris pour un rapport payant</strong> et y compris
        pour nos propres agents.
    </p>
    <p style="margin-top:8px">
        Une identité ne peut être divulguée que sur réquisition d'une autorité. Chaque
        divulgation est inscrite, avec son fondement, dans un registre que
        <strong>personne ne peut modifier ni effacer</strong> — pas même nous.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Vos droits
    </h2>
    <p style="margin-top:8px">
        Vous pouvez demander l'accès à vos données, leur rectification, leur effacement,
        et vous opposer à leur traitement.
    </p>
    <p style="margin-top:8px">
        Une limite, dite franchement : le journal qui atteste des actions faites sur le
        registre est <strong>inaltérable par construction</strong> — c'est ce qui lui donne
        sa valeur de preuve. Il ne contient ni nom, ni numéro, ni adresse : rien à y
        effacer, et c'est précisément pour cela qu'il peut être inaltérable.
    </p>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Nous écrire
    </h2>
    @if ($contact !== null)
        <p style="margin-top:8px">
            Pour exercer ces droits : <strong><a href="mailto:{{ $contact }}">{{ $contact }}</a></strong>
        </p>
    @else
        {{-- Afficher une adresse qui ne répondrait pas serait pire que n'en
             afficher aucune : la personne croirait avoir saisi le responsable,
             et le silence passerait pour un refus. --}}
        <p style="margin-top:8px">
            Le point de contact pour l'exercice de vos droits est en cours d'ouverture.
            Il sera publié sur cette page.
        </p>
    @endif

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:22px;margin-top:26px">
        Éditeur
    </h2>
    <p style="margin-top:8px">
        PREUVE est édité par <strong>OVERNETFLOW</strong>, Côte d'Ivoire.
    </p>

    <p style="margin-top:28px;border-top:3px solid #2B1D12;padding-top:18px">
        <a href="/" style="font-weight:700">← Vérifier un bien</a>
    </p>
@endsection
