# Tech Context — PREUVE

## Stack verrouillée
| Couche | Choix | Notes |
|---|---|---|
| Backend | Laravel 12 · PHP 8.3+ | API REST versionnée `/api/v1`. Monté de 11 à 12 le 02/08/2026 : la branche 11 n'avait plus de correctif pour 3 avis de sécurité (dont une injection CRLF « high » dans la règle `email`). `composer audit` doit rester vide |
| BDD | MySQL 8.0+ (InnoDB, utf8mb4_0900_ai_ci) | Schéma de référence : `database/preuve_schema_mysql8.sql` (14 tables) |
| Cache/queues | Redis + Horizon | Files : `default`, `ocr`, `uploads`, `notifications`, `payments` |
| Auth | Sanctum + OTP SMS | Pas de mot de passe au MVP |
| Mobile | Flutter | iOS + Android, app < 40 Mo |
| Web public | Front léger SEO | Pages de statut par `public_ref` opaque (PRV-XXXXXXXX) |
| Objets | MinIO | Buckets chiffrés au repos : `documents`, `photos`, `exports` |
| OCR/KYC | Mindee | CNI, carte grise, facture — payloads minimisés en JSON |
| Secrets | HashiCorp Vault | Jamais de secret en .env commité |
| Paiements | Paystack + PawaPay (Wave, OM, MoMo) | **CinetPay interdit.** Webhooks idempotents |
| Push | FCM | via file `notifications` |
| Tests | Pest | + Larastan niveau max, Pint |

## Structure applicative cible
```
app/
  Enums/            LifeStatus, TrustLevel, ClaimStatus, TransferStatus, ...
  Models/           User, Company, Asset, AssetDocument, AssetStatusHistory,
                    Transfer, Claim, ClaimEvidence, WatchAlert, Lookup,
                    Payment, ReportPurchase, Notification
  Services/
    AuditChain.php              # append() sous verrou — TOUTE action sensible
    AssetRegistrationService    # 4 gestes, normalisation, unicité, F1/V-PRV
    IdentifierNormalizer        # VIN checksum, Luhn IMEI, plaques, lots
    StatusTransitionService     # matrice des transitions + history
    TrustLevelEngine            # règles F1/F2/F3 versionnées
    ClaimArbitrationService     # recevabilité, gel, grille, décision, export
    TransferService             # double OTP, lockForUpdate, archivage actif
    LookupService               # lookup public, rate limit, journalisation
    NotificationService         # agrégation horaire, routage push/SMS
    ReportAccessService         # tokens, guest checkout
    PaymentService              # Paystack/PawaPay, webhooks idempotents
  Http/Controllers/Api/V1/      # contrôleurs fins
  Jobs/                         # ProcessOcr, AggregateLookups, PromoteProvisional,
                                # AnchorAuditHead, ExpireTransfers, PurgeLookups
```

## Endpoints principaux
```
GET  /api/v1/lookup/{identifier}          # public, rate-limited, SANS auth
POST /api/v1/auth/otp/request|verify      # inscription/connexion
POST /api/v1/assets                       # enregistrement express (auth)
POST /api/v1/assets/{id}/documents        # renforcement F2
POST /api/v1/assets/{id}/stolen           # vol (OTP) — DELETE pour lever (OTP)
POST /api/v1/assets/{id}/transfer         # initiation
POST /api/v1/transfers/{id}/confirm       # OTP vendeur/acheteur
POST /api/v1/claims                       # réclamation (KYC requis)
POST /api/v1/reports/{identifier}         # achat rapport (auth OU guest identifié)
GET  /api/v1/reports/access/{token}       # lecture du rapport
POST /api/v1/fleet/import                 # import Excel/CSV loueur
GET  /api/v1/notifications                # centre in-app + read
POST /api/v1/watch-alerts                 # veille identifiant
```

## Jobs planifiés
| Job | Fréquence | Rôle |
|---|---|---|
| PromoteProvisionalAssets | horaire | V-PRV → V-ACT à J+30 |
| AggregateLookupNotifications | horaire | notifications asset_lookup agrégées |
| ExpireTransfers | horaire | V-VTE expirés J+7 → état antérieur |
| AnchorAuditHead | quotidien | ancrage externe du hash de tête |
| PurgeLookups | quotidien | purge > 12 mois (ARTCI) |
| DetectLookupSpikes | 15 min | alertes lookup_spike |

## Performance & sécurité (NFR)
Lookup < 1 s P95 en 3G (index couvrant `identifier_normalized, life_status, trust_level`) · création bien < 3 s serveur · 99,5 % dispo avec dégradation gracieuse lecture seule · OTP sur toute action sensible · TLS + chiffrement au repos des documents · rate limiting · CI : Pest + Larastan + Pint obligatoires avant merge.
