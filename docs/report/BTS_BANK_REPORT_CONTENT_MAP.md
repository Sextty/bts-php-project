# BTS Bank — Cartographie des sources du rapport

## Objet

Ce document relie chaque partie du rapport aux preuves du dépôt. Il permet à un relecteur de distinguer :

- les faits directement observés dans le code ou le schéma ;
- les mesures reprises des audits et campagnes de test ;
- les diagrammes corrigés pour représenter le comportement courant ;
- les captures d’écran encore absentes ;
- les informations académiques ou institutionnelles laissées « À confirmer ».

Le rapport structurel joint par l’utilisateur a uniquement inspiré l’organisation académique générale. Aucune donnée métier, architecture, mesure ou conclusion de cet ancien document n’a été utilisée comme vérité sur BTS Bank.

## Matrice de traçabilité

| Partie du rapport | Preuves principales du dépôt | Diagrammes | Captures / action |
|---|---|---|---|
| Couverture, visa, remerciements | Nom du projet ; aucune identité ou métadonnée institutionnelle fiable dans le dépôt | Aucun | Auteur, établissement, filière, encadrants, jury, lieu/date à confirmer |
| Résumé et abstract | `docs/audit/BTS_FINAL_FULL_PROJECT_AUDIT.md`, manifests backend/frontends | Aucun | Aucune |
| Introduction | Audit final, inventaire du dépôt, schéma et routes | Aucun | Aucune |
| 1.1 Introduction/contexte | `backend/app/`, `client/`, `staff/`, `admin/`, `sc/` | 03 cas d’utilisation | UI-01 possible en illustration complémentaire |
| 1.2 Étude de l’existant | Évolution décrite par les rapports de phases ; contexte manuel explicitement générique | Aucun | Aucune |
| 1.3 Problématique | Machine d’états, autorisations, documents, audit et exploitation | 03 cas d’utilisation | Aucune |
| 1.4 Solution proposée | Quatre portails, API, MariaDB et services temps réel | 01 et 03 | UI-01 à UI-07 |
| 1.5 Acteurs | `backend/app/Enums/Role.php`, registre de permissions, routes et portails | 03 cas d’utilisation | UI-02, UI-04, UI-05, UI-07 |
| 1.6 Exigences fonctionnelles | `backend/routes/api.php`, `backend/routes/api/v1/banking.php`, contrôleurs et services | 03 cas d’utilisation | Aucune |
| 1.7 Exigences non fonctionnelles | audit final, audits sécurité/performance/opérations | Aucun | Aucune |
| 1.8 Périmètre | `docs/audit/BTS_FINAL_FULL_PROJECT_AUDIT.md`, documentation ledger et rapports de phase | Aucun | Aucune |
| 1.9 Méthodologie | 51 migrations, 64 fichiers de tests backend, CI, Playwright et rapports d’audit | Aucun | Aucune |
| 2.1 Méthode de conception | migrations additives, services transactionnels, outbox, audits par phase | 01, 04, 12 | Aucune |
| 2.2 Architecture | `backend/`, quatre frontends, `backend/config/`, `.github/workflows/` | 01 architecture globale | Aucune |
| 2.3 Modèle de données | `backend/database/migrations/` ; schéma SQLite fraîchement migré pour contrôle | 02 ERD | Aucune |
| 2.4 Machine d’états | enum des statuts, `backend/app/Services/CreditApplicationStateMachine.php` | 04 états | Aucune |
| 2.5.1 Authentification | contrôleurs `backend/app/Http/Controllers/Auth/`, `OtpService`, services Google et pré-auth | 05 séquence auth | UI-01 |
| 2.5.2 Dossier et documents | contrôleurs `CreditApplication`, services validation, stockage, malware et Gemini | 06 séquence dossier/IA | UI-03 |
| 2.5.3 Double décision | services de revue, state machine et `AppointmentSchedulingService` | 07 double décision | UI-04, UI-05 |
| 2.6 Classes et services | 21 modèles, 31 contrôleurs et 60 services inventoriés | 09 classes backend | Aucune |
| 2.7 Sécurité de conception | permissions, middleware, policies, canaux, services documentaires et audit | 04, 05, 11 | Aucune |
| 3.1 Technologies | `backend/composer.lock`, manifests npm, commandes de version locales, CI | 10 déploiement | Aucune |
| 3.2 Backend Laravel | `backend/app/`, `backend/routes/`, `backend/database/migrations/`, `backend/tests/` | 01, 09 | Aucune |
| 3.3 Portails | `client/`, `staff/`, `admin/`, `sc/` et leurs manifests | 01, 10 | UI-01 à UI-07 |
| 3.4 Parcours de crédit | routes, contrôleurs, services et tests de workflow | 04, 06, 07 | UI-02 à UI-05 |
| 3.5 Documents | `DocumentSecurity/`, `DocumentStorage/`, services Gemini et contrôleurs documents | 06 et 11 | UI-03 |
| 3.6 Rendez-vous | `AppointmentSchedulingService`, state machine et migrations appointments | 07 | UI-04, UI-05 |
| 3.7 Temps réel | `ReportMessageBroadcastService`, `AsyncOutboxService`, events/jobs, `routes/channels.php`, health checks | 08 et 12 | Éventuelle capture du rapport, non exigée dans les huit principales |
| 3.8 Security Center | `sc/`, contrôleurs Security/Osquery et services d’activité | 03, 10, 12 | UI-07 |
| 3.9 Analytics/synthétique | `backend/app/Services/Analytics/`, `SyntheticData/`, `tools/synthetic-load-tester/` | 13 pipeline synthétique | UI-06, UI-08 |
| 3.10 Ledger pilote | `backend/app/Services/Banking/`, routes banking, migrations ledger | 01, 02, 09 | Capture optionnelle, non requise |
| 3.11 Sauvegarde/opérations | commandes/scripts opérationnels, santé, rapports phase 4 | 10, 12, 14 | Aucune |
| 3.12 Plan de captures | Inventaire des assets ; absence de captures UI réelles | Aucun | Huit captures requises et spécifiées |
| 4.1 Stratégie de validation | tests backend, Playwright, CI, rapports de phase | 13, 14 | UI-08 facultative comme preuve visuelle |
| 4.2 Résultats consolidés | section Validation de l’audit final | 14 CI/CD | Aucune |
| 4.3 Données synthétiques | `PHASE_5_SYNTHETIC_LOAD_TEST_REPORT.md`, preuves small/medium | 13 synthétique | UI-08 |
| 4.4 Performance | `BTS_PERFORMANCE_AUDIT.md`, `PHASE_6_BI_ANALYTICS_REPORT.md`, JSON analytics | 13 synthétique | UI-06 |
| 4.5 Validation de sécurité | audits Composer/npm, CI Gitleaks/SBOM, constats d’autorisation | 14 CI/backup | Aucune |
| 4.6 Sauvegarde/restauration | `PHASE_4_OBSERVABILITY_OPERATIONS_REPORT.md`, audit final | 14 CI/backup | Aucune preuve visuelle à fabriquer |
| 4.7 Limites/lecture critique | audit final, matrice de readiness et constats | 14 CI/backup | Aucune |
| 5.1 Sécurité documentaire | `DocumentSecurity/`, `DocumentStorage/`, services Gemini, contrôleurs documents | 11 pipeline documentaire | UI-03 peut montrer le statut d’une pièce |
| 5.2 Autorisation | rôles, permissions, scopes de branche, routes et constats F-001 à F-004 | 03, 04, 08 | UI-04, UI-05, UI-07 |
| 5.3 Audit et PII | `AuditLogService`, migrations de chaîne d’intégrité, champs KYC | 12 observabilité | Aucune |
| 5.4 Contrôles | audit sécurité, contrôles documentaires, dépendances et secrets | 11, 12, 14 | Aucune |
| 5.5 Exploitation | `HealthCheckService`, battements, queue/outbox, backup/restore | 10, 12, 14 | Aucune |
| 5.6 Backlog | `docs/audit/BTS_FINAL_FULL_PROJECT_AUDIT.md`, P0–P3 | Aucun | Aucune |
| 5.7 Release | `.github/workflows/`, lockfiles, audit final F-009/F-024/F-034/F-036 | 14 CI/backup | Aucune |
| Conclusion | Synthèse des preuves précédentes | Aucun | Aucune |
| Annexe routes | `backend/routes/api.php`, `backend/routes/api/v1/banking.php`, `backend/routes/channels.php`, sortie `route:list --json` | Aucun | Aucune |
| Annexe à confirmer | Absence de preuves institutionnelles, production, contrats externes et captures | Aucun | UI-01 à UI-08 |

## Hiérarchie des sources

En cas de contradiction, l’ordre de confiance appliqué est le suivant :

1. schéma produit par les 51 migrations et code exécutable courant ;
2. routes générées par Laravel ;
3. tests et sorties de commandes reproductibles ;
4. audit final du 24 août 2026 ;
5. rapports spécialisés de phases antérieures ;
6. README et anciens diagrammes, utilisés seulement s’ils concordent avec les niveaux précédents.

## Index des preuves chiffrées

| Affirmation | Source de preuve | Utilisation dans le rapport |
|---|---|---|
| 104 routes, sans doublon | audit final + génération de routes | Résumé, réalisation, annexe A |
| 51 migrations | inventaire + migration fraîche | Résumé, chapitres 2 à 4 |
| 21 modèles, 31 contrôleurs, 60 services | audit final | Résumé et chapitre 3 |
| 427 tests / 3 126 assertions | audit final | Résumé et tableau 13 |
| 3 tests MariaDB / 57 assertions | audit final | Tableau 13 |
| Upgrade de 1 020 utilisateurs / 1 327 demandes | audit final | Chapitres 3 et 4 |
| Quatre gates frontend réussis | audit final | Résumé et tableau 13 |
| 10/10 Playwright | audit final | Résumé et tableau 13 |
| Medium : 50 000 clients, 65 000 demandes | audit final et rapport phase 5 | Tableau 14 |
| Analytics 33,075 s → 3,507 s | audit final et rapport phase 6 | Tableau 15 |
| Charge max observée 30 VU, p95 ≈ 6,8 s | audit final | Tableau 15 |
| Backup/restore et tailles | audit final et rapport phase 4 | Section 4.5 |
| 13 hauts, 16 moyens, 5 faibles, 2 informatifs | audit final | Résumé, conclusion et chapitre 5 |

## Registre des captures manquantes

Les identifiants UI-01 à UI-08 sont définis dans le tableau 12 du rapport. Leur statut est **MANQUANT — À CAPTURER**. Une capture n’est considérée recevable que si :

- l’environnement est isolé et utilise des données synthétiques ;
- la version/commit et la date sont enregistrés ;
- les secrets et PII sont masqués ;
- l’écran correspond au rôle et à l’état métier annoncés ;
- la légende ne transforme pas une donnée simulée en mesure réelle.

## Maintenance de cette cartographie

Toute évolution des migrations, statuts, routes, permissions, versions ou résultats de test doit entraîner la mise à jour simultanée du rapport, du diagramme concerné et de cette matrice. Les sources actives de diagrammes se trouvent directement dans `diagramme/`. Les copies de `diagramme/mm/` sont historiques et explicitement exclues.
