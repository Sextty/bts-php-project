# BTS Bank — Audit et correction des diagrammes

## 1. Verdict

Les dix diagrammes historiques ont été confrontés au code courant, aux 51 migrations, aux 104 routes générées, à la machine d’états, aux services et à l’audit final. Aucun n’était suffisamment exact pour être réutilisé sans modification.

- **Diagrammes existants réutilisés sans modification : 0**.
- **Diagrammes existants corrigés : 10**.
- **Diagrammes nouvellement créés : 4**.
- **Diagrammes actifs intentionnellement supprimés : 0**.
- **Diagrammes recréés avec Mermaid.ai MCP : 0** (outil indisponible).
- **Sources actives finales : 14**.
- **Rendus PNG actifs : 14**.
- **Erreurs de syntaxe Mermaid après correction : 0**.

Les quatorze sources actives sont à la racine de `diagramme/`. Les copies de `diagramme/mm/` sont classées **DUPLICATE / OUTDATED**. Huit anciens PNG horodatés sont rangés dans `diagramme/img/legacy/` et sont classés **OBSOLETE**.

## 2. Méthode

Chaque diagramme a été vérifié selon les critères suivants :

1. composants réellement présents dans le dépôt ;
2. noms de routes et canaux réellement générés ;
3. tables et colonnes issues d’une migration fraîche des 51 migrations ;
4. statuts et transitions définis dans la machine d’états ;
5. responsabilités réelles des quatre portails ;
6. limites et défauts recensés dans `docs/audit/BTS_FINAL_FULL_PROJECT_AUDIT.md` ;
7. rendu Mermaid sans erreur et inspection visuelle sur fond blanc.

L’outil Mermaid.ai MCP demandé n’était pas disponible dans l’environnement. **Aucune correction n’a donc été effectuée via Mermaid.ai MCP.** Les sources ont été corrigées localement puis validées et rendues avec Mermaid CLI 11.12.0. Cette limitation d’outil n’affecte pas la syntaxe Mermaid ni la traçabilité des fichiers sources.

## 3. Inventaire actif

| No | Source active | PNG actif | Statut initial | Action | Statut final | Figure du rapport |
|---:|---|---|---|---|---|---:|
| 01 | `diagramme/01_system_architecture.mmd` | `diagramme/img/01_system_architecture.png` | OUTDATED / INCOMPLETE | Corrigé | VALID | 2 |
| 02 | `diagramme/02_entity_relationship_diagram.mmd` | `diagramme/img/02_entity_relationship_diagram.png` | INCORRECT | Reconstruit depuis les migrations | VALID | 3 |
| 03 | `diagramme/03_use_case_diagram.mmd` | `diagramme/img/03_use_case_diagram.png` | OUTDATED / INCOMPLETE | Corrigé | VALID | 1 |
| 04 | `diagramme/04_state_transition_diagram.mmd` | `diagramme/img/04_state_transition_diagram.png` | INCORRECT | Reconstruit depuis 17 statuts | VALID | 4 |
| 05 | `diagramme/05_sequence_auth_onboarding.mmd` | `diagramme/img/05_sequence_auth_onboarding.png` | OUTDATED | Corrigé | VALID | 5 |
| 06 | `diagramme/06_sequence_credit_application_ai.mmd` | `diagramme/img/06_sequence_credit_application_ai.png` | INCORRECT | Corrigé | VALID | 6 |
| 07 | `diagramme/07_sequence_dual_approval_workflow.mmd` | `diagramme/img/07_sequence_dual_approval_workflow.png` | INCORRECT | Corrigé | VALID | 7 |
| 08 | `diagramme/08_sequence_realtime_chat_websockets.mmd` | `diagramme/img/08_sequence_realtime_chat_websockets.png` | INCORRECT | Corrigé | VALID | 10 |
| 09 | `diagramme/09_class_diagram_backend.mmd` | `diagramme/img/09_class_diagram_backend.png` | OUTDATED / INCOMPLETE | Reconstruit | VALID | 8 |
| 10 | `diagramme/10_deployment_infrastructure.mmd` | `diagramme/img/10_deployment_infrastructure.png` | OUTDATED / UNSUPPORTED | Corrigé | VALID | 9 |
| 11 | `diagramme/11_document_security_pipeline.mmd` | `diagramme/img/11_document_security_pipeline.png` | MISSING | Créé | VALID | 14 |
| 12 | `diagramme/12_async_notifications_observability.mmd` | `diagramme/img/12_async_notifications_observability.png` | MISSING | Créé | VALID | 11 |
| 13 | `diagramme/13_synthetic_load_analytics.mmd` | `diagramme/img/13_synthetic_load_analytics.png` | MISSING | Créé | VALID | 12 |
| 14 | `diagramme/14_ci_cd_backup_restore.mmd` | `diagramme/img/14_ci_cd_backup_restore.png` | MISSING | Créé | VALID | 13 |

## 4. Détail des corrections

### 4.1 Architecture globale — diagramme 01

**Problèmes historiques :** Laravel 11, trois portails seulement, MySQL 8, absence du Security Center, de l’outbox, du ledger et de l’analytics, et présence de fournisseurs ou garanties de stockage non prouvés.

**Correction :** architecture à quatre portails, Laravel 12, MariaDB locale vérifiée, Redis/Reverb conditionnels, services externes explicitement configurables, modules analytics/synthétiques/ledger représentés et limites de production visibles.

### 4.2 Modèle relationnel — diagramme 02

**Problèmes historiques :** nombreuses colonnes inventées ou obsolètes, associations imprécises et absence des tables ajoutées lors des dernières phases.

**Correction :** reconstruction depuis un schéma SQLite isolé obtenu par l’exécution des 51 migrations. Les agrégats client, dossier, documents, rendez-vous, rapport, audit, notifications, outbox et ledger sont alignés sur les clés actuelles. Les colonnes inexistantes `application_number` et `is_locked` ne sont plus attribuées à `credit_applications`.

### 4.3 Cas d’utilisation — diagramme 03

**Problèmes historiques :** trois portails, fonctions de sécurité et d’analytics absentes, ledger pilote non distingué et libellés métier devenus obsolètes.

**Correction :** quatre acteurs portail, services transverses, vues staff/admin distinctes, fonctions du Security Center et ledger marqué « pilote ».

### 4.4 Machine d’états — diagramme 04

**Problèmes historiques :** statuts inventés (`ADMIN_APPROVED`, `UNDER_ADMIN_REVIEW`, `IN_BRANCH_MEETING`, `CONTRACT_SIGNED`), colonne `is_locked` supposée et étapes courantes manquantes.

**Correction :** représentation des 17 statuts exacts et des transitions réellement autorisées. La transition défectueuse permettant le rendez-vous depuis `STAFF_APPROVED` est conservée en rouge et reliée au finding F-001 afin de documenter le comportement observé.

### 4.5 Authentification — diagramme 05

**Problèmes historiques :** champ `email_verified_at` pris comme pivot alors que le parcours utilise `phone_verified_at`, détails de pré-auth obsolètes et défaut Google ignoré.

**Correction :** OTP téléphone, login, pré-auth du téléphone manquant après Google et point de contrôle du statut. Le risque F-015 — émission possible de jeton à un utilisateur suspendu — est signalé.

### 4.6 Dossier et IA — diagramme 06

**Problèmes historiques :** verbes HTTP erronés, IA supposée se déclencher automatiquement à chaque upload, colonnes inexistantes et validation 2 décrite comme une soumission automatique.

**Correction :** `PUT` des étapes, stockage documentaire séparé, analyse IA consultative, validation structurée et soumission explicitement distincte. Le scan malware et le contrôle humain sont visibles.

### 4.7 Double décision — diagramme 07

**Problèmes historiques :** anciennes routes `/review`, `/final-approval` et `/confirm`, statut `ADMIN_APPROVED`, délai fixe de trois jours et logique de tentatives inexacte.

**Correction :** décisions staff/admin, recherche à partir du prochain jour ouvré, capacité d’agence, acceptation/refus du client et maximum de cinq tentatives. L’écart F-001 est signalé.

### 4.8 Rapport temps réel — diagramme 08

**Problèmes historiques :** canal `credit-application.{id}` inexistant, absence d’outbox et mauvais contrat d’événement.

**Correction :** transaction, outbox durable, traitement après commit, canal `private-application.{id}.report` et événement `.report.message`. Les autorisations client/staff sont représentées.

### 4.9 Classes backend — diagramme 09

**Problèmes historiques :** champs obsolètes, services récents absents et dépendances trop simplifiées.

**Correction :** modèles racine, state machine, scheduling, documents, audit, notification, outbox, analytics et ledger. Le diagramme reste volontairement un modèle de classes principales, pas une liste des 60 services.

### 4.10 Déploiement — diagramme 10

**Problèmes historiques :** Laravel 11, MySQL 8, trois portails, absence du port 3003, ajout non prouvé de Nginx/conteneurs/chiffrement et traitement IA supposé asynchrone.

**Correction :** environnement local réellement observé, quatre ports frontend, API/XAMPP, MariaDB, Redis/Reverb selon configuration et intégrations externes optionnelles. Les éléments de haute disponibilité sont explicitement indiqués comme non prouvés.

## 5. Diagrammes ajoutés

### 5.1 Pipeline documentaire — diagramme 11

Ce diagramme manquait alors que le traitement des fichiers est un enjeu central. Il sépare contrôle d’entrée, stockage privé, scan malware, analyse IA structurée, revue humaine et téléchargement autorisé. Il rend visibles F-005 et F-013.

### 5.2 Notifications et observabilité — diagramme 12

Ce diagramme relie notification persistée, livraisons, queue, outbox, Reverb et contrôles de santé. Il distingue le canal rapport correctement isolé du canal staff partagé concerné par F-002.

### 5.3 Données synthétiques, charge et analytics — diagramme 13

Ce diagramme montre la porte de sécurité, les profils synthétiques, la base isolée, k6, le testeur desktop et les mesures analytics. Les profils non exécutés ne sont pas présentés comme validés.

### 5.4 CI/CD, backup et restore — diagramme 14

Ce diagramme montre les gates présents dans la CI, les gates probants encore absents et la preuve locale de sauvegarde/restauration sans la qualifier de DR bancaire.

## 6. Diagrammes évalués mais non séparés

Les variantes suivantes ont été évaluées puis consolidées afin d’éviter une collection redondante et difficile à lire sur A4 :

| Diagramme envisagé | Décision | Justification / couverture retenue |
|---|---|---|
| Cas d’utilisation client, staff, admin et security séparés | Non créé | Le diagramme 03 possède quatre zones d’acteurs lisibles ; les matrices des chapitres 1 et 5 détaillent les permissions |
| Une séquence par étape client | Non créée | Les étapes client/crédit/projet/documents/validation/soumission sont regroupées dans la séquence 06 |
| Workflow de rendez-vous autonome | Non créé | Couvert par la machine d’états 04 et la séquence de décision 07 |
| Séquence de suspension security | Non créée | Flux court couvert par le cas d’utilisation 03, les routes Security Center et l’explication 3.8 ; un diagramme séparé apporterait peu d’information |
| Chaîne d’intégrité d’audit autonome | Non créée | Relations visibles dans l’ERD 02 ; algorithme HMAC et limite d’immutabilité expliqués en 5.3 |
| Architecture analytics et k6 séparées | Non créées | Consolidées dans le diagramme 13 avec la génération synthétique et les mesures |
| Backup et restore séparés | Non créés | Consolidés avec les gates CI et leurs limites dans le diagramme 14 |

Aucun diagramme actif correct n’a été supprimé. Les dix concepts historiques ont été corrigés dans leurs fichiers principaux. Seuls huit anciens **rendus** horodatés ont été déplacés vers `img/legacy/` ; ils restent récupérables et ne sont pas utilisés.

## 7. Archives et doublons

| Emplacement | Quantité | Classement | Règle |
|---|---:|---|---|
| `diagramme/mm/*.mmd` | 10 | DUPLICATE / OUTDATED | Ne pas importer ni modifier pour le rapport |
| `diagramme/img/legacy/*-2026-08-21-*.png` | 8 | OBSOLETE | Conserver uniquement comme historique |
| `diagramme/*.mmd` | 14 | ACTIVE / VALID | Sources de vérité graphiques |
| `diagramme/img/*.png` | 14 | ACTIVE / VALID | Images utilisées dans le rapport |

Le fichier `diagramme/README.md` inventorie les sources actives. `diagramme/mm/README.md` avertit explicitement que les copies historiques ne doivent plus être utilisées.

## 8. Validation technique et visuelle

Les quatorze sources ont été rendues avec Mermaid CLI 11.12.0, fond blanc, largeur cible 2 200 pixels et échelle 1,5. Toutes ont produit un PNG sans erreur de parse. Une planche contact a servi au contrôle global ; les diagrammes de cas d’utilisation et de classes, plus verticaux, ont également été inspectés à leur résolution originale.

Critères de validation :

- texte lisible et absence de débordement manifeste ;
- fonds clairs compatibles avec l’impression ;
- direction de lecture stable ;
- légendes des limites et défauts distinctes des fonctions nominales ;
- absence de versions, routes, statuts ou colonnes obsolètes dans les sources actives ;
- correspondance univoque entre source, PNG et figure du rapport.

## 9. Commande de régénération

Depuis la racine du dépôt, un diagramme peut être régénéré avec la version validée de Mermaid CLI :

```powershell
npx --yes @mermaid-js/mermaid-cli@11.12.0 -i diagramme/01_system_architecture.mmd -o diagramme/img/01_system_architecture.png -b white -w 2200 -s 1.5
```

Pour une release, il est recommandé d’automatiser la génération des quatorze sources et d’échouer si un PNG manque ou si Mermaid retourne une erreur.

## 10. Limites restantes

- Les huit captures UI du rapport sont absentes ; elles ne peuvent pas être remplacées par des diagrammes.
- Les informations d’infrastructure de production n’existent pas encore ; le diagramme 10 ne représente que le local vérifié.
- Les correctifs décrits dans les constats F-001 à F-036 ne sont pas réputés implémentés parce qu’ils apparaissent dans un diagramme.
- Toute évolution future des routes, migrations ou statuts doit déclencher un nouvel audit de cohérence.
