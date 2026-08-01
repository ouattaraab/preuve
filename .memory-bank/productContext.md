# Product Context — PREUVE

## Pourquoi ce produit existe
En Côte d'Ivoire, l'acheteur d'occasion n'a aucun moyen de vérifier qu'un vendeur est légitime. Conséquences : véhicules de location revendus à des tiers de bonne foi, téléphones volés qui circulent, terrains vendus deux fois. Le recel prospère parce que le bien volé reste liquide. Preuve casse cette liquidité.

## Personas et vérité d'usage
- **L'acheteur prudent** : au marché, sur un parking, en plein soleil, une main occupée, réseau 3G. Il doit comprendre le verdict en 1 seconde à 2 mètres — couleur + symbole + mot, jamais de jargon.
- **Le propriétaire particulier** : protège son téléphone/sa moto en < 90 s. Le KYC ne lui est JAMAIS demandé à l'enregistrement (seulement pour renforcer, transférer, réclamer).
- **Le loueur B2B** : couvre sa flotte en < 30 min (import Excel), dort tranquille grâce aux alertes duplicate_attempt/lookup_spike.
- **La victime d'usurpation** : dépose une réclamation, obtient le gel immédiat, suit un contradictoire équitable.
- **L'agent d'arbitrage** : décide vite et de façon motivée avec la grille pondérée.

## Principes d'expérience (arbitrer TOUJOURS dans ce sens)
1. Consultation = zéro friction (pas de compte, pas de CAPTCHA sous le seuil, résultat < 1 s).
2. Enregistrement = friction minimale (auth OTP 20 s puis 4 gestes ; scan > saisie ; tout le reste différable).
3. Friction forte UNIQUEMENT là où elle protège : KYC, transfert (double OTP), réclamation.
4. Langage courant : « Volé déclaré », « En location », « Litige en cours » — jamais V-VOL/V-LIT côté UI.
5. Sémantique couleur univoque doublée d'un symbole/mot (daltoniens) : vert sûr, ambre prudence, rouge danger, gris non vérifié, bleu documenté.
6. Notifications = réassurance, pas spam : consultations agrégées par heure, identités jamais révélées.

## Ton et langue
Français simple, direct, rassurant. Le produit inspire l'autorité d'un document officiel SANS imiter un sceau ou emblème d'État (risque juridique). Direction design en cours d'arbitrage : Tampon (institutionnel) / Sceau (premium sombre) / Feu Vert (grand public).

## Ce qu'on refuse de faire
- Monétiser la consultation de statut (tue l'effet réseau).
- Révéler une identité, à quiconque, pour quelque paiement que ce soit.
- Se présenter comme registre officiel ou délivrer un « titre ».
- Complexifier le MVP pour le foncier (phase 2, partenariat institutionnel).
