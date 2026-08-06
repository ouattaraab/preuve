-- ============================================================================
-- PREUVE — Schéma de base de données MySQL 8.0+
-- Dérivé du PRD v1.0 (§7.2) — BookMi · Juillet 2026
-- Moteur : InnoDB · Charset : utf8mb4 · Collation : utf8mb4_0900_ai_ci
-- Prérequis : MySQL >= 8.0.16 (contraintes CHECK appliquées)
-- Convention Laravel 11 : id BIGINT UNSIGNED AUTO_INCREMENT, created_at/updated_at
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. USERS — comptes particuliers et entreprises (EP-01)
--    L'ancienneté du compte (created_at) et du KYC sont des signaux publics.
-- ----------------------------------------------------------------------------
CREATE TABLE users (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone              VARCHAR(20)  NOT NULL COMMENT 'E.164, ex +2250701020304 — identifiant de connexion (OTP, pas de mot de passe au MVP)',
  phone_verified_at  TIMESTAMP    NULL,
  full_name          VARCHAR(150) NULL,
  account_type       ENUM('individual','company') NOT NULL DEFAULT 'individual',
  kyc_status         ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
  kyc_verified_at    TIMESTAMP    NULL COMMENT 'Ancienneté KYC = signal temporel public',
  kyc_id_number_hash CHAR(64)     NULL COMMENT 'SHA-256 du n° CNI — jamais la valeur en clair',
  kyc_ocr_payload    JSON         NULL COMMENT 'Réponse Mindee (CNI) minimisée',
  locale             VARCHAR(5)   NOT NULL DEFAULT 'fr',
  status             ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  free_assets_quota  TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '3 biens gratuits (particulier)',
  created_at         TIMESTAMP NULL,
  updated_at         TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_kyc (kyc_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 2. COMPANIES — extension entreprise (loueurs B2B, EP-07)
-- ----------------------------------------------------------------------------
CREATE TABLE companies (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id      BIGINT UNSIGNED NOT NULL COMMENT 'Représentant légal (KYC requis)',
  legal_name         VARCHAR(200) NOT NULL,
  rccm_number        VARCHAR(50)  NOT NULL,
  business_type      ENUM('car_rental','dealer','other') NOT NULL DEFAULT 'car_rental',
  validation_status  ENUM('pending','validated','rejected') NOT NULL DEFAULT 'pending' COMMENT 'Validation back-office (ST-0103)',
  validated_at       TIMESTAMP NULL,
  free_fleet_quota   TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '3 véhicules gratuits puis abonnement/véhicule',
  created_at         TIMESTAMP NULL,
  updated_at         TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_rccm (rccm_number),
  KEY idx_companies_owner (owner_user_id),
  CONSTRAINT fk_companies_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 3. ASSETS — biens enregistrés (EP-02, EP-04, EP-06)
--    Unicité MySQL "un identifiant = un enregistrement actif" :
--    active_flag = 1 (actif) ou NULL (archivé/transféré). MySQL ignore les NULL
--    dans les index uniques → un seul actif, historique illimité.
-- ----------------------------------------------------------------------------
CREATE TABLE assets (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_ref            CHAR(12)     NOT NULL COMMENT 'Référence publique opaque (page statut SEO), ex PRV-9F3K2A7Q',
  owner_user_id         BIGINT UNSIGNED NOT NULL,
  company_id            BIGINT UNSIGNED NULL COMMENT 'Renseigné si bien de flotte entreprise',
  asset_type            ENUM('vehicle','motorbike','phone','land') NOT NULL,
  identifier_type       ENUM('vin','plate','imei','serial','lot_number') NOT NULL,
  identifier_raw        VARCHAR(64)  NOT NULL COMMENT 'Tel que saisi/scanné',
  identifier_normalized VARCHAR(64)  NOT NULL COMMENT 'Majuscules, sans espaces/tirets, checksum validé (VIN/Luhn IMEI)',
  active_flag           TINYINT      NULL DEFAULT 1 COMMENT '1=actif, NULL=archivé — support de l unicité partielle',
  brand                 VARCHAR(80)  NULL,
  model                 VARCHAR(80)  NULL,
  trust_level           ENUM('F1','F2','F3') NOT NULL DEFAULT 'F1' COMMENT 'Déclaré / Documenté / Vérifié (§3.1 PRD)',
  life_status           ENUM('V-ACT','V-PRV','V-LOC','V-VTE','V-VOL','V-LIT','V-FDV') NOT NULL DEFAULT 'V-PRV',
  provisional_until     TIMESTAMP    NULL COMMENT 'Fin de la fenêtre de contestation J+30 (ST-0402)',
  stolen_declared_at    TIMESTAMP    NULL,
  stolen_consolidated   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 si récépissé de plainte fourni (§4.3)',
  registered_at         TIMESTAMP    NOT NULL COMMENT 'Signal temporel public — jamais modifiable',
  created_at            TIMESTAMP NULL,
  updated_at            TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assets_public_ref (public_ref),
  UNIQUE KEY uq_assets_identifier_active (identifier_normalized, active_flag),
  KEY idx_assets_owner (owner_user_id, life_status),
  KEY idx_assets_company (company_id),
  KEY idx_assets_lookup (identifier_normalized, life_status, trust_level) COMMENT 'Index couvrant du lookup public <1s',
  CONSTRAINT fk_assets_owner   FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT fk_assets_company FOREIGN KEY (company_id)    REFERENCES companies(id),
  CONSTRAINT chk_assets_active CHECK (active_flag IS NULL OR active_flag = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 4. ASSET_STATUS_HISTORY — journal des transitions (§3.3) 
--    Alimente le rapport détaillé payant (anonymisé) et l audit.
-- ----------------------------------------------------------------------------
CREATE TABLE asset_status_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id      BIGINT UNSIGNED NOT NULL,
  from_status   ENUM('V-ACT','V-PRV','V-LOC','V-VTE','V-VOL','V-LIT','V-FDV') NULL,
  to_status     ENUM('V-ACT','V-PRV','V-LOC','V-VTE','V-VOL','V-LIT','V-FDV') NOT NULL,
  from_trust    ENUM('F1','F2','F3') NULL,
  to_trust      ENUM('F1','F2','F3') NULL,
  trigger_type  ENUM('system','owner','claim','arbitration','transfer','backoffice') NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  reason        VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ash_asset (asset_id, created_at),
  CONSTRAINT fk_ash_asset FOREIGN KEY (asset_id) REFERENCES assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 5. ASSET_DOCUMENTS — justificatifs (EP-02, renforcement F2/F3)
-- ----------------------------------------------------------------------------
CREATE TABLE asset_documents (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id       BIGINT UNSIGNED NOT NULL,
  uploaded_by    BIGINT UNSIGNED NOT NULL,
  doc_type       ENUM('invoice','registration_card','acd','police_report','photo','other') NOT NULL,
  file_ref       VARCHAR(255) NOT NULL COMMENT 'Clé objet MinIO (bucket chiffré au repos)',
  file_sha256    CHAR(64)     NOT NULL COMMENT 'Empreinte du fichier au dépôt',
  ocr_payload    JSON         NULL COMMENT 'Extraction Mindee (carte grise, facture)',
  review_status  ENUM('pending','accepted','rejected','suspected_forgery') NOT NULL DEFAULT 'pending',
  reviewed_by    BIGINT UNSIGNED NULL,
  reviewed_at    TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_docs_asset (asset_id, doc_type),
  KEY idx_docs_review (review_status),
  CONSTRAINT fk_docs_asset    FOREIGN KEY (asset_id)    REFERENCES assets(id),
  CONSTRAINT fk_docs_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 6. TRANSFERS — transferts à double validation + double OTP (§4.4)
--    Machine à états protégée par SELECT ... FOR UPDATE côté Laravel.
-- ----------------------------------------------------------------------------
CREATE TABLE transfers (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id            BIGINT UNSIGNED NOT NULL,
  from_user_id        BIGINT UNSIGNED NOT NULL,
  to_user_id          BIGINT UNSIGNED NULL COMMENT 'NULL tant que l acheteur n a pas créé/lié son compte',
  to_phone            VARCHAR(20) NOT NULL COMMENT 'Téléphone de l acheteur invité',
  status              ENUM('initiated','buyer_confirmed','completed','cancelled','expired') NOT NULL DEFAULT 'initiated',
  seller_otp_at       TIMESTAMP NULL,
  buyer_otp_at        TIMESTAMP NULL,
  expires_at          TIMESTAMP NOT NULL COMMENT 'J+7 après initiation (§4.4)',
  completed_at        TIMESTAMP NULL,
  created_at          TIMESTAMP NULL,
  updated_at          TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_transfers_asset (asset_id, status),
  KEY idx_transfers_to_phone (to_phone),
  CONSTRAINT fk_transfers_asset FOREIGN KEY (asset_id)     REFERENCES assets(id),
  CONSTRAINT fk_transfers_from  FOREIGN KEY (from_user_id) REFERENCES users(id),
  CONSTRAINT fk_transfers_to    FOREIGN KEY (to_user_id)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 7. CLAIMS — réclamations et arbitrage (EP-05, §4.5)
-- ----------------------------------------------------------------------------
CREATE TABLE claims (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id         BIGINT UNSIGNED NOT NULL,
  claimant_user_id BIGINT UNSIGNED NOT NULL COMMENT 'KYC complet obligatoire',
  status           ENUM('draft','submitted','admissible','contradictory','under_review','decided','appealed','closed') NOT NULL DEFAULT 'draft',
  fee_payment_id   BIGINT UNSIGNED NULL COMMENT 'Frais de dossier 2000/5000 FCFA, remboursés si gain',
  fee_refunded     TINYINT(1) NOT NULL DEFAULT 0,
  respondent_deadline TIMESTAMP NULL COMMENT 'Fin du délai contradictoire J+15',
  decision         ENUM('transfer_to_claimant','keep_current','unresolved') NULL,
  decision_reason  TEXT NULL COMMENT 'Motivation obligatoire (ST-0503)',
  claimant_score   SMALLINT UNSIGNED NULL COMMENT 'Score grille §5.2',
  respondent_score SMALLINT UNSIGNED NULL,
  decided_by       BIGINT UNSIGNED NULL COMMENT 'Agent d arbitrage',
  decided_at       TIMESTAMP NULL,
  appeal_of        BIGINT UNSIGNED NULL COMMENT 'Auto-référence : appel interne unique (ST-0505)',
  export_sha256    CHAR(64) NULL COMMENT 'Empreinte de l export PDF horodaté remis aux parties',
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_claims_asset (asset_id, status),
  KEY idx_claims_claimant (claimant_user_id),
  CONSTRAINT fk_claims_asset    FOREIGN KEY (asset_id)         REFERENCES assets(id),
  CONSTRAINT fk_claims_claimant FOREIGN KEY (claimant_user_id) REFERENCES users(id),
  CONSTRAINT fk_claims_appeal   FOREIGN KEY (appeal_of)        REFERENCES claims(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 8. CLAIM_EVIDENCES — pièces des deux parties, grille de pondération appliquée
-- ----------------------------------------------------------------------------
CREATE TABLE claim_evidences (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id       BIGINT UNSIGNED NOT NULL,
  party          ENUM('claimant','respondent') NOT NULL,
  evidence_type  ENUM('official_named_doc','police_report','invoice','anteriority','account_history','photo_context') NOT NULL,
  file_ref       VARCHAR(255) NULL,
  file_sha256    CHAR(64) NULL,
  document_date  DATE NULL COMMENT 'Les dates des pièces priment sur la date plateforme (§5.2)',
  weight_applied TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 si falsification suspectée',
  agent_note     VARCHAR(500) NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_evidences_claim (claim_id, party),
  CONSTRAINT fk_evidences_claim FOREIGN KEY (claim_id) REFERENCES claims(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 9. WATCH_ALERTS — veille sur identifiant (ST-0403)
-- ----------------------------------------------------------------------------
CREATE TABLE watch_alerts (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               BIGINT UNSIGNED NOT NULL,
  identifier_normalized VARCHAR(64) NOT NULL,
  channel               ENUM('push','sms','both') NOT NULL DEFAULT 'push',
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  last_triggered_at     TIMESTAMP NULL,
  created_at            TIMESTAMP NULL,
  updated_at            TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_watch_user_identifier (user_id, identifier_normalized),
  KEY idx_watch_identifier (identifier_normalized, is_active) COMMENT 'Déclenchement rapide sur tentative de doublon/pic',
  CONSTRAINT fk_watch_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 10. LOOKUPS — journal des consultations publiques (anti-profilage + signaux)
--     Table à forte volumétrie : purge/archivage périodique via job planifié.
-- ----------------------------------------------------------------------------
CREATE TABLE lookups (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier_normalized VARCHAR(64) NOT NULL,
  found_asset_id        BIGINT UNSIGNED NULL,
  ip_hash               CHAR(64) NOT NULL COMMENT 'SHA-256(IP + sel quotidien) — jamais l IP en clair (Loi 2013-450)',
  user_id               BIGINT UNSIGNED NULL COMMENT 'NULL si consultation anonyme',
  source                ENUM('app','web') NOT NULL DEFAULT 'app',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lookups_identifier (identifier_normalized, created_at) COMMENT 'Détection de pic de consultations',
  KEY idx_lookups_rate (ip_hash, created_at) COMMENT 'Limitation 10/heure (§4.1)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 11. PAYMENTS — rapports détaillés, frais de réclamation, abonnements (EP-08)
-- ----------------------------------------------------------------------------
CREATE TABLE payments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NULL COMMENT 'NULL si acheteur invité (rapport détaillé) — identité alors obligatoire ci-dessous',
  buyer_name     VARCHAR(150) NULL COMMENT 'Obligatoire si user_id NULL (règle guest checkout)',
  buyer_email    VARCHAR(150) NULL COMMENT 'Obligatoire si user_id NULL',
  buyer_phone    VARCHAR(20)  NULL COMMENT 'Obligatoire si user_id NULL — vérifié par OTP avant paiement',
  purpose        ENUM('detailed_report','claim_fee','asset_slot','fleet_subscription') NOT NULL,
  related_id     BIGINT UNSIGNED NULL COMMENT 'asset_id, claim_id ou company_id selon purpose',
  amount_fcfa    INT UNSIGNED NOT NULL,
  provider       ENUM('paystack','pawapay_wave','pawapay_om','pawapay_momo') NOT NULL,
  provider_ref   VARCHAR(100) NULL,
  status         ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  paid_at        TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_provider_ref (provider, provider_ref),
  KEY idx_payments_user (user_id, purpose),
  KEY idx_payments_buyer_phone (buyer_phone),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT chk_payments_identity CHECK (
    user_id IS NOT NULL
    OR (buyer_name IS NOT NULL AND buyer_email IS NOT NULL AND buyer_phone IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 11b. REPORT_PURCHASES — achats de rapports détaillés (connecté OU invité)
--      Règle produit : personne ne voit le rapport sans être identifié
--      (compte connecté ou nom+email+téléphone vérifiés avant paiement).
--      L accès se fait par jeton à durée limitée, journalisé.
-- ----------------------------------------------------------------------------
CREATE TABLE report_purchases (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id      BIGINT UNSIGNED NOT NULL,
  payment_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NULL COMMENT 'NULL si invité — identité portée par payments.buyer_*',
  access_token  CHAR(40) NOT NULL COMMENT 'Jeton d accès au rapport (lien web/app)',
  expires_at    TIMESTAMP NOT NULL COMMENT 'Accès limité dans le temps, ex 30 jours',
  first_access_at TIMESTAMP NULL,
  access_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_token (access_token),
  KEY idx_report_asset (asset_id),
  KEY idx_report_user (user_id),
  CONSTRAINT fk_report_asset   FOREIGN KEY (asset_id)   REFERENCES assets(id),
  CONSTRAINT fk_report_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_report_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 11c. NOTIFICATIONS — notifications in-app du propriétaire (et autres événements)
--      Règle produit : le propriétaire est notifié des consultations de son bien
--      SANS jamais recevoir l identité du consultant (ni gratuit, ni payant).
--      Anti-spam : les consultations sont agrégées par fenêtre (job périodique).
-- ----------------------------------------------------------------------------
CREATE TABLE notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL COMMENT 'Destinataire',
  type        ENUM(
                'asset_lookup',          -- votre bien a été consulté (agrégé, anonyme)
                'asset_report_purchased',-- un rapport détaillé a été acheté sur votre bien (anonyme)
                'duplicate_attempt',     -- tentative d enregistrement de votre identifiant
                'lookup_spike',          -- pic de consultations détecté
                'claim_opened','claim_decided',
                'transfer_invitation','transfer_completed',
                'status_change','kyc_result','system'
              ) NOT NULL,
  asset_id    BIGINT UNSIGNED NULL,
  claim_id    BIGINT UNSIGNED NULL,
  title       VARCHAR(150) NOT NULL,
  body        VARCHAR(500) NOT NULL COMMENT 'Jamais d identité du consultant dans les types asset_lookup / asset_report_purchased',
  payload     JSON NULL COMMENT 'Données d affichage (ex nb de consultations agrégées, période)',
  channel     ENUM('inapp','push','sms') NOT NULL DEFAULT 'inapp',
  read_at     TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_unread (user_id, read_at, created_at) COMMENT 'Badge non-lus + fil chronologique',
  KEY idx_notif_asset (asset_id),
  CONSTRAINT fk_notif_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT fk_notif_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
  CONSTRAINT fk_notif_claim FOREIGN KEY (claim_id) REFERENCES claims(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------------------------------------------------------
-- 12. AUDIT_LOG — journal inaltérable à hash chaîné SHA-256 (§7.4)
--     Append-only : AUCUN UPDATE/DELETE applicatif. Pas de FK (immutabilité,
--     survit aux archivages). Ancrage quotidien du hash de tête en externe.
-- ----------------------------------------------------------------------------
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type    ENUM('user','agent','system') NOT NULL,
  actor_id      BIGINT UNSIGNED NULL,
  action        VARCHAR(80)  NOT NULL COMMENT 'ex asset.created, claim.decided, transfer.completed',
  entity_type   VARCHAR(40)  NOT NULL,
  entity_id     BIGINT UNSIGNED NOT NULL,
  payload       JSON         NOT NULL COMMENT 'Instantané minimisé de l action',
  payload_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256(payload canonique)',
  prev_hash     CHAR(64)     NOT NULL COMMENT 'Hash de l entrée précédente — chaînage',
  chain_hash    CHAR(64)     NOT NULL COMMENT 'SHA-256(prev_hash || payload_hash)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_chain (chain_hash),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NOTES D IMPLÉMENTATION LARAVEL
-- 1) Unicité active : à l archivage d un bien (transfert/fin de vie), faire
--    UPDATE assets SET active_flag = NULL ... puis créer le nouvel enregistrement
--    actif dans la MÊME transaction, avec SELECT ... FOR UPDATE sur l ancien.
-- 2) Transitions concurrentes : toute écriture sur assets.life_status passe par
--    DB::transaction() + lockForUpdate() (double OTP, gel litige).
-- 3) lookups : prévoir un job de purge (> 12 mois) conforme à la politique de
--    conservation déclarée à l ARTCI.
-- 4) audit_log : écrire via un service unique (AuditChain::append) qui lit le
--    dernier chain_hash sous verrou pour garantir la continuité de la chaîne.
-- 5) Enregistrement d un bien : TOUJOURS authentifié (Sanctum + OTP). La
--    consultation de STATUT reste la seule action sans compte.
-- 6) Notifications de consultation : job planifié (ex toutes les heures) qui
--    agrège lookups par asset et crée UNE notification asset_lookup
--    ("Votre Toyota Corolla a été consultée 3 fois aujourd hui") — jamais en
--    temps réel unitaire (spam) et jamais avec l identité/IP du consultant.
-- 7) Rapport détaillé : middleware d accès exigeant soit auth, soit un
--    paiement invité valide (payments.buyer_* + OTP téléphone) AVANT paiement ;
--    l accès au rapport passe exclusivement par report_purchases.access_token.
--    L achat notifie le propriétaire (asset_report_purchased) sans identité.
-- ============================================================================
