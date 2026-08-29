# BTS Bank — audit sécurité

## Cadre et niveau attendu

BTS traite identité, documents, demandes de financement, personnel interne et un début d'écriture comptable. La cible doit être au minimum **OWASP ASVS niveau 3** : l'ASVS réserve ce niveau aux applications à forte valeur ou à haute assurance. L'évaluation est une revue de code/configuration non intrusive, pas un test d'intrusion ni une attestation de conformité.

Références de contrôle : [OWASP ASVS](https://devguide.owasp.org/en/11-security-gap-analysis/01-guides/02-asvs/), [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/IndexASVS.html).

## Résumé exécutif

Les bases défensives sont réelles : hashes de mots de passe Laravel, OTP limité, messages de login non énumérants, validation des requêtes, throttles, CORS à origines explicites, headers API, téléchargement privé, contrôle de rôle/type et canaux WebSocket privés.

Les faiblesses critiques pour une application financière sont la non-reproductibilité du schéma de statuts, le stockage de jetons sensibles dans le navigateur, l'audit non inviolable, le privilège global accordé à un employé non affecté à une agence, et l'absence de barrière malware avant conservation des documents.

## Findings

| ID | Niveau | Constat | Preuve dans le dépôt | Action avant production |
|---|---|---|---|---|
| SEC-01 | **CRITICAL / P0** | Dérive migration/schéma sur le statut d'un dossier | migration initiale `ENUM` ne contient que 9 statuts ; le modèle/machine en utilisent 17 ; base locale inspectée : `varchar(50)` sans migration correspondante | ajouter une migration additive, testée sur MariaDB neuf, qui matérialise l'ensemble exact des statuts ou un modèle de transition contraint ; interdire les modifications manuelles de DDL |
| SEC-02 | **HIGH / P1** | Jetons client/staff/admin/security stockés dans `localStorage` | `client/lib/auth/token.ts`, `staff/admin/lib/auth/staff-token.ts`, `sc/lib/auth/security-token.ts` | BFF/session cookie `HttpOnly; Secure; SameSite` ou stratégie token en mémoire + rotation ; CSP stricte ; réauthentification privilégiée |
| SEC-03 | **HIGH / P1** | Journal d'audit « append-only » seulement par convention applicative | modèle `AuditLog` reste modifiable/supprimable par compte DB applicatif ; pas de hash-chain, WORM, signature, export scellé, rétention | compte DB sans UPDATE/DELETE sur audit, journal de sécurité séparé et append-only, intégrité hachée/signée, tests d'altération et politique de rétention |
| SEC-04 | **HIGH / P1** | Moindre privilège agence par défaut insuffisant | `StaffUser::isBranchRestricted()` ne limite que si `branch_id` est défini ; un staff non affecté est global | refuser la session/les permissions opérationnelles d'un employé sans agence sauf rôle global explicitement approuvé |
| SEC-05 | **HIGH / P1** | Documents acceptés sans antivirus/quarantaine | inspection extension/MIME présente, aucun scanner malware ; fallback possible vers MIME client pour certains contenus vides/génériques | stockage de quarantaine, scan asynchrone, état `pending/clean/rejected`, accès interdit avant `clean`, limites d'archive et scan isolé |
| SEC-06 | **HIGH / P1** | Machine d'état contournable par conception défensive insuffisante | `CreditApplication::$fillable` inclut `status`; la machine autorise les sauts client jusqu'à `SUBMITTED`; règles de complétude hors machine | retirer le statut des entrées mass-assignable, transitionner uniquement via service, version/concurrence optimiste, tests négatifs de saut et état impossible |
| SEC-07 | **HIGH / P1** | Ledger applicatif, pas une plate-forme de fonds réels | double écriture et idempotence présentes, mais somme débit/crédit non imposée par DB, pas de reversal/rapprochement/clôture | geler la mise en production monétaire ; spécification comptable, invariants DB/service indépendants, contre-écritures et revue externe |
| SEC-08 | **MEDIUM / P1** | Portail SC absent de CORS runtime local | configuration effective contient 3000–3002, pas 3003 ; `sc` appelle l'API directement | corriger la configuration d'environnement et ajouter un test CORS pour les quatre origines |
| SEC-09 | **MEDIUM / P1** | Suspension client seulement indirectement défendue | `User::ban()` supprime les jetons, mais `EnsureCustomerUser` ne rejette pas `status=suspended` | vérification d'état sur toute authentification client et gestion atomique de suspension/révocation |
| SEC-10 | **MEDIUM / P1** | Autorisation de transition trop large dans le service | une instance `StaffUser` satisfait une transition exigée `staff`, sans exiger son rôle précis; les routes actuelles compensent par permissions | faire respecter rôle/permission par la machine et passer une capacité explicite, pas seulement le type de modèle |
| SEC-11 | **MEDIUM / P2** | CSP/front headers absents dans les quatre `next.config.ts` | seuls `allowedDevOrigins` et Turbopack sont configurés ; headers API ne couvrent pas les documents HTML | CSP nonce/strict-dynamic ou nonces Next, `frame-ancestors`, HSTS au reverse proxy, COOP/CORP selon besoin |
| SEC-12 | **MEDIUM / P2** | Security Center expose une télémétrie hôte très sensible depuis le processus API | PowerShell/netstat, ports, chemins de process, MAC et serial sont accessibles à security/admin | séparer collecteur/agent et API, RBAC/JIT/MFA, minimisation des champs, audit externe des accès |
| SEC-13 | **MEDIUM / P2** | `osquery` est une émulation locale, pas une télémétrie de parc | moteur PHP et commandes hôte ; dernier lot d'audit limité à 200 | renommer honnêtement la capacité, imposer limite/timeout/allow-list et adopter un agent centralisé seulement si besoin prouvé |
| SEC-14 | **MEDIUM / P2** | Permissions futures incohérentes | `super_admin` reçoit `ALL` dans registry mais `isAdmin()` n'est vrai que pour `admin`; certains chemins état/visibilité en dépendent | remplacer les checks de chaîne par capacités cohérentes, tests pour tous les rôles déclarés |
| SEC-15 | **LOW / P2** | Diagnostics device/hardware collectés côté client | en-têtes plateforme, RAM, CPU, écran et identifiant pseudo-fingerprint | documenter finalité/rétention/consentement, ne jamais les utiliser comme facteur d'authentification |
| SEC-16 | **INFO** | Dépendances runtime auditées sans vulnérabilité haute signalée au moment de l'audit | `composer audit --locked` et `npm audit --omit=dev --audit-level=high` | automatiser dans CI avec SBOM et politique de patch |

## Contrôles positifs constatés

### Authentification et session

* Les mots de passe sont castés `hashed`; les OTP expirent, sont limités et invalidés à la régénération.
* Les réponses de login/reset évitent l'énumération d'un compte connu.
* Les tokens Sanctum expireront au bout de 60 minutes dans l'environnement inspecté ; logout et suspension les révoquent.
* `EnsureStaffUser` bloque tout compte interne suspendu et `EnsureCustomerUser`/`EnsureStaffUser` empêchent le mélange des types de principal.

### API, injection et entrées

* Les payloads métier passent par Form Requests ; les requêtes Eloquent observées sont paramétrées.
* Aucun `dangerouslySetInnerHTML` n'a été trouvé dans les sources des portails.
* Le terminal de télémétrie n'accepte que `SELECT`/`PRAGMA`, interdit les mots destructifs et protège l'argument du binaire natif. Il faut néanmoins imposer limite, timeout et allow-list de tables si ce composant est conservé.
* Les throttles `bts` différencient notamment login/OTP/écriture sensible.

### Documents et données

* Les chemins disque sont générés côté serveur, uniques et jamais retournés comme URL publique.
* Le téléchargement est streamé après contrôle ownership/branche ; les tests couvrent un autre client, une autre agence et MIME incohérent.
* L'IA n'est pas activée par défaut et ne transfère donc pas les documents vers un tiers dans la configuration inspectée.

### Transport, browser et realtime

* CORS est prévu avec une allow-list plutôt qu'un wildcard et `supports_credentials` est explicite.
* L'API renvoie `no-store`, `nosniff`, `DENY`, referrer policy, permissions policy et HSTS en HTTPS.
* Les canaux Reverb sont privés et possèdent des callbacks d'autorisation testés.

## Risques spécifiques aux flux financiers

1. La validation de montant et les décisions de crédit nécessitent traçabilité, double contrôle, version de politique et reproductibilité. Les décisions sont enregistrées, mais l'audit n'est pas probant et le schéma n'est pas reproductible.
2. Les comptes et écritures de grand livre doivent être append-only **dans la base et dans les droits DB**, jamais seulement par `$guarded`/convention Eloquent. Toute correction doit être une contre-écriture liée.
3. Les rôles globaux doivent exiger un justificatif/JIT, MFA et journal externe. Le rôle security ne doit pas devenir une voie indirecte vers toutes les données hôte ou personnelles.
4. Les données de production doivent disposer de sauvegardes chiffrées, de tests de restauration et d'une matrice de rétention légale avant toute mise en service.

## Plan de remédiation sécurité

### P0 — avant toute démo de bout en bout sur MariaDB ou tout mouvement monétaire

1. Éliminer la dérive `credit_applications.status` et tester `migrate:fresh` sur MariaDB, puis le flux jusqu'au rendez-vous.
2. Déclarer le ledger expérimental et bloquer l'usage de données/fonds réels jusqu'à revue comptable et invariants formalisés.
3. Geler les changements DDL manuels ; migrations versionnées uniquement et contrôle de drift en CI/staging.

### P1 — avant production Internet

1. Retirer les secrets de session de `localStorage`, introduire CSP/headers frontend et une stratégie XSS documentée.
2. Corriger branch isolation « deny by default », statut des utilisateurs suspendus, cohérence `super_admin` et la machine de statut.
3. Mettre en quarantaine les documents et intégrer un scanner. ClamAV fournit un daemon multithread mais son socket doit être privé/authentifié : [documentation ClamAV](https://docs.clamav.net/manual/Usage/Scanning.html?highlight=false+positive).
4. Ajouter secret scan, SBOM, dépendances, tests d'autorisation et test CORS dans CI.

### P2 — durcissement et exploitation

1. Émettre audit/logs vers un stockage hors bande, surveillé et à rétention contrôlée.
2. Séparer le collecteur de télémétrie de l'API, réduire les données hôte et ajouter MFA/JIT pour opérations security/globales.
3. Construire un threat model par flux (auth, documents, crédit, rendez-vous, ledger, SC) et une matrice ASVS traçable.
4. Exécuter ZAP en environnement isolé : son Automation Framework est pilotable par YAML et supporte l'authentification et les tests de jobs ([ZAP](https://www.zaproxy.org/docs/automate/automation-framework/)).

## Vérifications obligatoires après correction

* migration MariaDB vierge + tests de tous statuts réels et contraintes;
* tests concurrents : décision de rendez-vous double, double soumission, idempotence ledger;
* tests XSS/session et aucune lecture token depuis script injecté;
* E2E des quatre portails, y compris SC CORS et WebSocket;
* test malware EICAR en quarantaine et refus de téléchargement avant verdict;
* preuve qu'un rôle non affecté n'accède à rien par défaut;
* audit d'altération : l'identité applicative ne peut plus UPDATE/DELETE un événement publié;
* test de restauration de base + documents chiffrés.
