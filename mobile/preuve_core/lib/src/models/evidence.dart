/// Natures de preuve d'une réclamation, telles que le serveur les nomme.
///
/// FIGÉES ICI, CONTRAIREMENT AUX CATÉGORIES DE BIENS. La différence n'est pas
/// une incohérence : une catégorie est une donnée d'exploitation qu'on ajoute
/// sans livrer de version, tandis que cette liste EST la grille d'arbitrage
/// (systemPatterns §3) — un ensemble fermé, pondéré, sur lequel repose une
/// décision qui change la propriété d'un bien. La saisir en chaînes libres
/// exposait à une faute de frappe qui ne se serait vue qu'en 422, au moment où
/// une victime verse sa carte grise.
///
/// LES POIDS SONT INDIQUÉS PARCE QU'ILS CHANGENT LA CONDUITE. Quelqu'un qui
/// ignore qu'une carte grise pèse près de trois fois une facture versera ce
/// qu'il a sous la main et perdra un dossier qu'il aurait pu gagner. Le poids
/// exact est appliqué côté serveur ; celui rappelé ici sert à ORDONNER et à
/// expliquer, jamais à calculer.
///
/// SI LA GRILLE CHANGE CÔTÉ SERVEUR, CETTE LISTE DOIT CHANGER AVEC ELLE.
enum EvidenceKind {
  officialNamedDoc(
    'official_named_doc',
    'Document officiel à ton nom (carte grise, ACD)',
    'La pièce la plus décisive, de loin. Elle suffit à rendre le dossier recevable.',
  ),
  policeReport(
    'police_report',
    'Récépissé de plainte ou document judiciaire',
    'Compte fortement, et suffit aussi à ouvrir le dossier.',
  ),
  invoice(
    'invoice',
    'Facture d\'achat à ton nom',
    'Compte, sans être décisive seule : elle prouve un achat, pas l\'absence de revente.',
  ),
  anteriority(
    'anteriority',
    'Document plus ancien que l\'enregistrement contesté',
    'Les dates portées sur les pièces priment sur la date d\'enregistrement.',
  ),
  photoContext(
    'photo_context',
    'Photos horodatées, éléments de contexte',
    'Complète un dossier, ne le porte pas à lui seul.',
  ),
  accountHistory(
    'account_history',
    'Ancienneté de ton compte',
    'Pèse le moins, délibérément : sans quoi l\'inscrit de longue date l\'emporterait '
        'mécaniquement sur la victime qui découvre la plateforme le jour du vol.',
  );

  const EvidenceKind(this.wire, this.label, this.explication);

  final String wire;
  final String label;
  final String explication;

  /// Vrai si cette pièce suffit à rendre la réclamation recevable (ST-0502).
  ///
  /// Le filtre écarte les dossiers vides sans écarter les victimes : l'écran
  /// peut donc dire à quelqu'un que ce qu'il a en main ouvre déjà son dossier.
  bool get grantsAdmissibility =>
      this == EvidenceKind.officialNamedDoc || this == EvidenceKind.policeReport;
}
