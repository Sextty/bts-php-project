# BTS Bank — roadmap de mise à niveau

> Mise à jour du 23 août 2026: les bloqueurs P0/P1 et le lot applicatif Phase 2 ont été corrigés et
> validés. Voir `docs/audit/P0_P1_CORRECTION_REPORT.md` et
> `docs/audit/PHASE_2_SECURITY_COMPLIANCE_REPORT.md`. Cela autorise la poursuite de la roadmap,
> pas une mise en production bancaire.

## Règle de séquencement

Ne pas démarrer une phase tant que les critères d'acceptation de la précédente ne sont pas atteints. La roadmap privilégie intégrité, sécurité et testabilité avant fonctionnalité ou infrastructure. Les changements seront implémentés uniquement après approbation explicite.

## Vue d'ensemble

| Phase | Objectif | Priorité | Dépend de |
|---|---|---|---|
| 0 | corriger les bloqueurs de cohérence et geler le faux core-banking | P0 | aucune |
| 1 | rendre la qualité reproductible et testée | P1 | phase 0 |
| 2 | durcir auth, droits, documents et audit | P1 | phases 0–1 |
| 3 | supprimer les voies synchrones coûteuses et mesurer | P1/P2 | phases 0–2 |
| 4 | observabilité, sauvegarde et exploitation | P2 | phase 3 partielle |
| 5 | données synthétiques et charge réaliste | P2 | phases 1–4 |
| 6 | BI, recherche et stockage avancé si prouvé | P3 | mesures phase 5 |

## Phase 0 — cohérence critique et garde-fous financiers

**Objectif.** La base réinstallable MariaDB doit être identique au domaine réellement exécuté. Le ledger doit être clairement isolé du traitement de fonds réels.

| Élément | Plan futur |
|---|---|
| Changements exacts | migration additive explicite de `credit_applications.status`; suppression de toute dépendance à DDL manuel; catalogue unique de statuts; contrôle que chaque migration fraîche autorise la chaîne complète. Retirer `status` de mass assignment, borner/supprimer les sauts illégitimes et centraliser les préconditions. Écrire une note de statut « experimental / no real money » du ledger. |
| Modules/fichiers concernés | `backend/database/migrations`, `CreditApplication`, `CreditApplicationStateMachine`, services crédit, tests Feature/Database/StateMachine, documentation ledger. |
| Dépendances | MariaDB de test CI. Pas de package externe requis. |
| Risques | migration de données existantes, incompatibilité SQLite/MariaDB, régression frontend qui comptait sur un saut. |
| Tests requis | MariaDB vierge `migrate`, chaque statut, API validation négative, migration upgrade depuis schéma local contrôlé, rollback planifié. |
| Acceptation | pas de drift entre `migrate:fresh` MariaDB et schéma attendu; aucun status impossible; aucune écriture monétaire exposée comme production. |

**Impact estimé :** complexité moyenne, impact DB moyen, bénéfice sécurité/intégrité très élevé, coût de maintenance faible.

**Acceptations prouvées le 23 août 2026 :** migration MariaDB vierge et upgrade contrôlé sans
perte; tous les statuts réels restent accessibles par transitions valides; état métier et audit
obligatoire sont atomiques; rendez-vous et chaîne d'audit résistent aux tests concurrents MariaDB.
Le ledger reste explicitement documenté comme non autorisé pour des fonds réels.

## Phase 1 — pipeline qualité et tests manquants

**Objectif.** Toute modification doit être analysée/testée pour backend, les quatre portails et MariaDB.

| Élément | Plan futur |
|---|---|
| Changements exacts | CI : Pint, Larastan avec baseline puis niveau renforcé, MariaDB intégration, `sc` lint/type/build/test, Playwright isolé. Ajouter tests rôles/agences, rendez-vous concurrence, statut suspendu, CORS SC, documents/quarantaine contract future, ledger failure modes. Ajouter Vitest/Testing Library et tests composants/formulaires pour client/staff/admin. |
| Outils | Larastan, Playwright existant, Vitest, Gitleaks, Syft ; Composer/npm audit conservés. |
| Modules/fichiers concernés | `.github/workflows/ci.yml`, `backend/tests`, `e2e`, package scripts des 4 apps, config analyse statique. |
| Dépendances | phase 0, service MariaDB CI, fixtures synthétiques séparées. |
| Risques | faux positifs Larastan, E2E flaky, durée CI. |
| Tests requis | preuve CI sur branche de test, aucune base locale partagée, rapports JUnit/SARIF/SBOM. |
| Acceptation | 4 portails + backend contrôlés; E2E rerunnable; migration MariaDB obligatoire; scan secrets/SBOM présent. |

**Impact estimé :** complexité moyenne, impact architecture faible, bénéfice fiabilité très élevé, maintenance moyenne.

**Acceptations prouvées le 23 août 2026 :** backend complet, lint/types/build des quatre portails,
Vitest SC, Playwright isolé 9/9 sur base éphémère, migrations et concurrence MariaDB réelles,
audits Composer/npm et scan local de secrets. L'exécution du workflow GitHub, les tests composants
client/staff/admin et l'automatisation CI complète E2E/concurrence restent à renforcer; la phase
n'est donc pas marquée entièrement terminée.

## Phase 2 — hardening sécurité et conformité

**Objectif.** Réduire les risques de session, privilège, upload et audit.

| Élément | Plan futur |
|---|---|
| Changements exacts | concevoir BFF/cookies HttpOnly ou tokens en mémoire/rotation; CSP/headers frontend; état suspended vérifié partout; agence obligatoire par défaut; capacités cohérentes `admin/super_admin/security`; MFA/JIT pour accès global. Pipeline document quarantine + ClamAV privé + statut et accès bloqué avant clean. Audit hors bande/scellé avec droits DB minimaux et rétention. |
| Outils | OWASP ASVS/Cheat Sheets, ClamAV, Gitleaks, Syft. |
| Modules/fichiers concernés | `bootstrap/app.php`, auth/Sanctum, front token helpers/Next configs, middleware/policies, storage/document services/jobs, audit services/migrations, deployment/runbooks. |
| Dépendances | phase 1; décision de session; environnements avec TLS. |
| Risques | changement auth force reconnexion; scanner peut causer faux positifs; audit externalisé introduit une dépendance op. |
| Tests requis | sessions/XSS/CORS, permission matrix, suspension atomique, EICAR/quarantine, audit tamper test, access reviews. |
| Acceptation | aucun bearer sensible dans stockage persistant web; document non clean inaccessible; compte non affecté refusé par défaut; audit finalisé non modifiable par l'identité applicative. |

**Impact estimé :** complexité élevée, impact sécurité très élevé, impact UX moyen, maintenance moyenne/élevée.

**Acceptations applicatives prouvées le 23 août 2026 :** aucun bearer n'est conservé dans un
stockage web persistant; les sessions sont limitées à l'onglet et les comptes suspendus sont
révoqués; l'isolation agence/ownership et les canaux privés sont testés; les documents infectés
n'entrent pas dans le stockage et les fichiers non clean sont bloqués lorsque la politique requise
est active; la chaîne d'audit concurrente est vérifiable, versionnée et expurgée des secrets;
Gitleaks, SBOM et audits de dépendances sont intégrés. Les quatre portails compilent et le parcours
Playwright isolé passe 9/9. Le BFF/cookie HttpOnly, MFA/JIT, les droits DB empêchant UPDATE/DELETE
sur l'audit, le scellement hors bande et le test réel ClamAV/EICAR restent des portes de déploiement
avant production; ils ne sont pas présentés comme réalisés localement.

## Phase 3 — asynchrone et performance mesurée

**Objectif.** Éviter que notifications, mail, scan et broadcast dégradent les transactions métier.

| Élément | Plan futur |
|---|---|
| Changements exacts | introduire outbox transactionnelle, jobs après commit, distribution de notification par audience/agence, déduplication/idempotence, exports asynchrones. Ajouter pagination client/messages, budgets de requêtes, `EXPLAIN` et indexes seulement justifiés. Corriger les verrous/contraintes de rendez-vous. |
| Outils | Laravel queues; Valkey + Horizon uniquement dès que backlog justifie; Reverb existant; Pulse à évaluer. |
| Modules/fichiers concernés | `NotificationService`, events/jobs, services document, export SC, controllers listes, `appointments`, migrations index/contraintes, config queue/cache/broadcast. |
| Dépendances | phase 0 correctness, phase 2 document security. |
| Risques | outbox/worker à opérer, double livraison, ordering. |
| Tests requis | outbox commit/rollback, retry, idempotence, charge notification, concurrence rendez-vous, performance dataset synthétique. |
| Acceptation | aucune diffusion réseau lourde dans transaction critique; backlog visible; réponse API stable sous charge cible; aucune double place/décision. |

**Impact estimé :** complexité élevée, impact architecture moyen, bénéfice performance/résilience élevé, maintenance moyenne.

**Acceptations prouvées le 23 août 2026 :** une outbox transactionnelle conserve les intentions
de notification, livraison fournisseur et broadcast Reverb dans la transaction métier, puis les
traite après commit avec déduplication, retry, reprise planifiée et état d'échec visible. Le fan-out
staff est asynchrone, ciblé par agence/rôle et chunké. Les listes client et messages sont bornées.
Trois indexes seulement ont été retenus après plans MariaDB. Sur le dataset medium, les lectures
mesurées restent sous 2 ms en moyenne; 1 000 jobs DB ont été drainés par un worker à 109,8 jobs/s,
donc Valkey/Horizon n'est pas justifié. Les migrations vierge/upgrade, les concurrences rendez-vous
et audit, 405 tests backend, quatre builds et lints, et Playwright 9/9 passent. Les métriques/SLO de
production, l'exercice prolongé de panne, la charge large/massive et k6 restent respectivement aux
phases 4 et 5.

## Phase 4 — opérations, sauvegarde et observabilité

**Objectif.** Détecter, diagnostiquer et restaurer plutôt que découvrir l'incident après coup.

| Élément | Plan futur |
|---|---|
| Changements exacts | health/readiness, logs JSON sans PII inutile, métriques API/jobs/DB/documents, dashboards/alertes, rétention. Backups MariaDB + documents chiffrés, test restore, comptes DB séparés, runbooks incident. Renommer/extraire la télémétrie osquery selon réalité. |
| Outils | MariaDB Backup, Prometheus, Grafana, Loki; Pulse dev/ops; OTel seulement si flux distribués. |
| Modules/fichiers concernés | config logging/health, deployment, scripts backup/restore, dashboards as code, SC. |
| Dépendances | phase 3 pour workers; hébergement/stockage choisi. |
| Risques | collecte PII, coût disque, accès Grafana trop large. |
| Tests requis | alerte synthétique, backup+restore, exercice RPO/RTO, panne worker/Reverb, revue accès dashboard. |
| Acceptation | alertes actionnables, rollback/documented restore réussi, logs/audit séparés, RPO/RTO mesurés. |

**Impact estimé :** complexité moyenne/élevée, bénéfice exploitation élevé, maintenance moyenne.

**Acceptations Phase 4 prouvées le 24 août 2026 :** liveness et readiness testées, logs JSON
redacted/corrélés, télémétrie HTTP/queue minimale, backup MariaDB + documents avec SHA-256, et
restore MariaDB isolé vérifié (migrations, comptes représentatifs, audit). Pulse,
Prometheus/Grafana/Loki et OpenTelemetry sont différés avec déclencheurs documentés. Voir
`docs/audit/PHASE_4_OBSERVABILITY_OPERATIONS_REPORT.md`.

## Phase 5 — big data synthétique et charge

**Objectif.** Valider 100K/500K/1M sur infrastructure isolée avant toute promesse de capacité.

| Élément | Plan futur |
|---|---|
| Changements exacts | protocole benchmark générateur existant; capacité disque/index/binlog; checkpoints/resume sous interruption; projet k6 avec seed, VUs, durée, distributions et assertions d'intégrité. |
| Outils | générateur `bts:generate-data` existant, Grafana k6, Prometheus/Grafana si phase 4. |
| Modules/fichiers concernés | docs synthétiques, config profiles, nouveau répertoire load-tests hors application, CI/nightly séparée. |
| Dépendances | phases 1–4 ; MariaDB/infra éphémère dédiée. |
| Risques | saturation disque, coûts d'exécution, tests mal isolés. |
| Tests requis | small→massive progressif, comparaison seed, recovery, test endpoints/business invariants sous VUs. |
| Acceptation | rapport de capacité avec paramètres/matériel, zéro corruption/violation FK, seuils de service décidés, aucune charge sur production. |

**Impact estimé :** complexité moyenne, impact DB élevé seulement en environnement test, bénéfice performance élevé, maintenance moyenne.

**Acceptations Phase 5 prouvées le 24 août 2026 :** générateur existant préservé et validé,
k6 et interface Tkinter installables sans service payant, bases MariaDB strictement isolées,
workflows API authentifiés, seuils et exports reproductibles, arrêt sûr, vérification métier post-charge
et smoke CI à 5 utilisateurs. Les campagnes locales 20/20, 30/30, 100/20, rendez-vous mono-agence
et burst chat/Reverb sont consignées dans `docs/audit/PHASE_5_SYNTHETIC_LOAD_TEST_REPORT.md`.
Ces mesures ne sont pas une promesse de capacité de production.

## Phase 6 — analytics, recherche et stockage avancé sous conditions

**Objectif.** Ajouter de l'infrastructure seulement si les mesures/obligations la justifient.

| Signal | Réponse possible | Garde-fou |
|---|---|---|
| BI récurrente dégrade OLTP | read model/réplica + Superset **ou** Metabase | DB readonly, données minimisées, RBAC, un seul outil |
| recherche textuelle échoue avec MariaDB indexé | benchmark Meilisearch | index séparé, sync/rebuild, PII évaluée |
| volume/résilience documents dépasse disque | S3-compatible/SeaweedFS après étude | chiffrement, lifecycle, restore et coût op |
| besoins de flux fortement découplés | architecture modulaire/queue avancée | pas de microservices sans frontière/ownership clair |

**Impact estimé :** complexité/maintenance élevées ; bénéfice conditionnel. Aucun de ces composants ne doit démarrer avant preuve de besoin.

**Acceptations Phase 6 prouvées le 24 août 2026 :** couche analytics native bornée et
agrégée, droits dédiés, isolation agence forcée côté serveur, tableaux Admin/Staff, exports CSV/JSON
audités, dix contrôles qualité non destructifs et migration d'indexes issue de mesures réelles. Le
snapshot synthétique moyen (50 000 clients/65 000 demandes) passe de 33,08 s à 3,51 s sur XAMPP
MariaDB local, soit environ 89,4 % d'amélioration; ce n'est pas un SLA de production. Metabase est
différé comme candidat BI futur, Superset est écarté, et recherche externe, object storage,
réplica/reporting DB, warehouse et ETL restent différés sans signal mesuré. Détails et validation
finale : `docs/audit/PHASE_6_BI_ANALYTICS_REPORT.md`.

## Top 10 des changements à approuver d'abord

1. migration de statuts MariaDB et check de drift;
2. rendre la machine de statut non contournable;
3. déclarer/encadrer le ledger expérimental;
4. branch isolation deny-by-default et matrice de droits complète;
5. session browser protégée + CSP;
6. quarantine/antivirus documents;
7. audit probant, hors bande, droits DB minimaux;
8. CI MariaDB + Larastan + SC + Playwright + secrets/SBOM;
9. outbox/queue pour notifications, broadcasts, scans et exports;
10. backups/restores mesurés, metrics/alerting, puis k6 isolé.

## Premier lot d'implémentation recommandé

**Phase 0 complète + fondations Phase 1.** Aucun nouveau produit d'infrastructure nécessaire : migration de statut, invariants de machine, tests MariaDB, isolation d'agence, checks CI et documentation de non-usage réel du ledger. C'est le plus fort ratio risque réduit / complexité et débloque des mesures fiables pour la suite.
