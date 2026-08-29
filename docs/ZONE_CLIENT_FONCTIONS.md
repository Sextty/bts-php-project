# Zone client — guide fonctionnel détaillé

> Documentation basée sur l’implémentation actuelle du dossier `client/` et sur les règles exposées par l’API Laravel dans `backend/`.
>
> Dernière vérification : 29 août 2026.

## 1. Objectif et périmètre

La zone client permet à un demandeur de :

- découvrir les offres de financement et effectuer une simulation indicative ;
- créer un compte ou se connecter avec une authentification renforcée par OTP ;
- créer et compléter une demande de crédit en cinq étapes ;
- téléverser les justificatifs requis et suivre leur contrôle ;
- suivre l’état de chaque dossier ;
- accepter ou reprogrammer un rendez-vous proposé ;
- échanger des messages et pièces jointes avec un conseiller ;
- consulter ses notifications et les informations de son profil.

La zone client ne permet pas actuellement de consulter le solde d’un compte de dépôt, d’effectuer des opérations bancaires, ni de modifier directement les informations du profil.

## 2. Architecture fonctionnelle

| Couche | Technologie | Responsabilité principale |
|---|---|---|
| Interface client | Next.js / React | Pages, formulaires, navigation, validation immédiate et affichage des états |
| API | Laravel | Authentification, autorisations, règles métier, stockage des dossiers et documents |
| Authentification | Laravel Sanctum | Jeton d’accès associé à la session du navigateur |
| Temps réel | Laravel Echo | Réception des nouveaux messages du conseiller |
| Documents | Stockage protégé | Téléversement, analyse et téléchargement contrôlé des justificatifs |

Toutes les fonctions métier de la zone privée utilisent l’API authentifiée. Un client ne peut accéder qu’à ses propres demandes.

## 3. Carte des pages

| Route | Fonction |
|---|---|
| `/` | Accueil public, présentation des produits et simulateur de crédit |
| `/register` | Création de compte |
| `/register/verify-otp` | Validation du compte par code OTP |
| `/login` | Connexion par e-mail ou téléphone et mot de passe |
| `/login/verify-otp` | Deuxième étape de connexion par OTP |
| `/auth/google/phone` | Saisie du téléphone manquant après une connexion Google |
| `/auth/google/verify-otp` | Vérification du téléphone après une connexion Google |
| `/forgot-password` | Demande de lien de réinitialisation |
| `/reset-password` | Choix d’un nouveau mot de passe |
| `/dashboard` | Tableau de bord client |
| `/applications` | Liste et création des demandes de crédit |
| `/applications/[id]` | Détail et suivi d’une demande |
| `/applications/[id]/client` | Étape 1 : renseignements client |
| `/applications/[id]/credit` | Étape 2 : caractéristiques du crédit |
| `/applications/[id]/project` | Étape 3 : description et données financières du projet |
| `/applications/[id]/validation` | Étapes 4 et 5 : contrôle puis confirmation définitive |
| `/applications/[id]/appointment` | Gestion du rendez-vous lié au dossier |
| `/applications/[id]/report` | Messagerie sécurisée avec le conseiller |
| `/appointments` | Vue globale des rendez-vous éligibles |
| `/profile` | Profil, sécurité du compte et synthèse des demandes |

## 4. Parcours client global

Le parcours principal suit cinq étapes :

1. le client découvre les offres, effectue éventuellement une simulation, puis crée un compte ou se connecte avec OTP ;
2. il ouvre un brouillon et complète successivement ses informations personnelles, le crédit, les justificatifs et le projet ;
3. il lance le premier contrôle de conformité, corrige les éléments signalés ou, pour un rejet provenant uniquement de l’analyse automatique, demande un examen humain ;
4. il confirme définitivement le dossier, qui devient non modifiable et est transmis au personnel ;
5. après décision, il consulte le résultat et, en cas d’accord, accepte le rendez-vous proposé ou demande un autre créneau.

Les notifications, la messagerie avec le conseiller et le profil restent accessibles depuis la zone privée pendant ce parcours.

## 5. Fonctions détaillées

### 5.1 Accueil public et simulateur

La page d’accueil présente notamment les catégories suivantes :

- crédit professionnel ;
- crédit d’investissement ;
- crédit de gestion ;
- fonds de roulement ;
- crédit TIC ;
- finance islamique.

Le simulateur public permet de choisir un type de financement, un montant et une durée. Il calcule une mensualité estimée selon une formule d’annuité, le coût total du crédit et les intérêts estimés.

Paramètres actuellement utilisés par l’interface :

| Produit du simulateur | Taux indicatif |
|---|---:|
| Professionnel | 8,5 % |
| Investissement | 8,0 % |
| Gestion | 9,0 % |
| Mourabaha | 8,2 % |

- montant : de 5 000 à 150 000 TND, par pas de 1 000 TND ;
- durée : de 12 à 84 mois, par pas de 6 mois ;
- le résultat est indicatif et ne constitue pas une offre contractuelle ;
- le bouton de poursuite transmet le type, le montant et la durée vers l’inscription afin de faciliter le début du parcours.

### 5.2 Inscription

Le formulaire d’inscription collecte :

- prénom ;
- nom ;
- adresse e-mail ;
- numéro de téléphone ;
- mot de passe ;
- confirmation du mot de passe.

Règles principales :

- l’e-mail et le téléphone doivent être uniques ;
- le téléphone est contrôlé au format international E.164 ;
- le mot de passe contient au minimum 10 caractères ;
- les deux saisies du mot de passe doivent correspondre ;
- un code OTP à six chiffres est ensuite requis pour finaliser la création du compte.

### 5.3 Connexion et sécurité OTP

La connexion standard s’effectue avec :

- un identifiant, qui peut être l’e-mail ou le téléphone ;
- le mot de passe ;
- un code OTP à six chiffres lors de la deuxième étape.

Après validation, l’API retourne un jeton Sanctum. Il est conservé dans `sessionStorage` : la session est limitée à l’onglet courant, résiste à son actualisation, mais disparaît lorsque l’onglet ou la fenêtre est fermé. Elle ne constitue donc pas une connexion permanente.

En cas de réponse API `401`, l’interface supprime la session locale invalide et redirige l’utilisateur vers la page de connexion.

### 5.4 Connexion Google

Le parcours Google peut produire trois résultats :

1. le compte possède toutes les données nécessaires et accède directement au tableau de bord ;
2. le numéro de téléphone manque et l’utilisateur est dirigé vers `/auth/google/phone` ;
3. le numéro est fourni mais doit être validé par OTP dans `/auth/google/verify-otp`.

Cette étape garantit que le téléphone du client est disponible et vérifié même lorsque l’identité initiale provient de Google.

### 5.5 Mot de passe oublié

Le client peut :

1. saisir son e-mail dans `/forgot-password` ;
2. recevoir un lien sécurisé contenant un jeton de réinitialisation ;
3. ouvrir `/reset-password` ;
4. définir et confirmer son nouveau mot de passe.

Les demandes d’inscription, de connexion, d’OTP et de réinitialisation sont limitées par des règles de fréquence côté serveur afin de réduire les abus.

### 5.6 Tableau de bord

Le tableau de bord charge en parallèle :

- les informations du client connecté ;
- ses demandes de crédit ;
- ses notifications.

Les données sont actualisées périodiquement, environ toutes les dix secondes lorsque la page est visible et active.

Fonctions visibles :

- nombre de demandes actives ;
- total des montants sollicités, hors dossiers annulés ou refusés ;
- état de vérification du téléphone ;
- résumé des demandes récentes et de leurs statuts ;
- résumé des rendez-vous ;
- notifications non lues ;
- accès rapide à une nouvelle demande, aux dossiers, rendez-vous, documents, messages et au profil.

Le montant « total sollicité » est un agrégat des crédits demandés. Ce n’est pas le solde d’un compte bancaire.

### 5.7 Liste et création des demandes

La page `/applications` affiche les dossiers appartenant au client et permet d’en créer un nouveau.

Lors de la création :

- le serveur génère la référence de la demande ;
- le dossier commence à l’état brouillon ;
- le client est redirigé vers la première étape ;
- les identifiants métier tels que le numéro de demande ou le code projet sont générés côté serveur et ne sont pas acceptés depuis le navigateur.

La liste sert aussi de point d’entrée pour reprendre une demande incomplète ou ouvrir le détail d’un dossier déjà soumis.

Le client peut supprimer définitivement un dossier encore inachevé lorsque l’API expose `can_be_deleted: true`. Cette possibilité disparaît dès que le dossier a franchi la validation qui le rend non supprimable. L’interface masque alors l’action et le serveur refuse également toute tentative directe.

### 5.8 Étape 1 — Informations client

Cette étape collecte l’identité et la situation personnelle du demandeur :

- civilité : Monsieur, Madame ou Mademoiselle ;
- nom, prénom et éventuel deuxième nom/nom du conjoint ;
- date, lieu et pays de naissance ;
- nationalité et pays de résidence ;
- situation matrimoniale et nombre d’enfants ;
- type de pièce d’identité : CIN, passeport ou carte de séjour ;
- numéro, date et lieu de délivrance de la pièce ;
- profession ;
- date de début de relation, lorsqu’elle est applicable.

La CIN tunisienne doit comporter exactement huit chiffres. Les dates et champs obligatoires sont également contrôlés côté serveur.

Documents personnels pouvant être ajoutés :

- CIN ;
- passeport ;
- carte de séjour ;
- diplôme.

Formats admis pour ces documents : PDF, JPG, JPEG, PNG, WEBP, DOC et DOCX. La taille maximale est de 10 Mo par fichier.

### 5.9 Étape 2 — Crédit et plan de financement

Types de crédit disponibles dans le formulaire :

- création ;
- extension ;
- fonds de roulement ;
- professionnel ou artisan.

Informations principales :

- origine du dossier ;
- agence ou unité de dépôt ;
- nom de l’activité ou de l’affaire ;
- dénomination complémentaire ;
- montant global demandé en TND ;
- nombre de crédits ;
- dates de dépôt et de réception ;
- ventilation du financement.

La ventilation utilise les catégories suivantes :

| Code | Signification |
|---|---|
| EQP | Équipements professionnels |
| FDR | Fonds de roulement |
| AMG | Aménagements ou travaux |
| CHP | Cheptel |

Si une ventilation est renseignée, la somme de ses postes doit correspondre au montant global, avec une tolérance technique de 0,01 TND.

Pièces de financement prises en charge :

- justificatifs EQP, FDR, AMG et CHP ;
- devis ou facture pro forma ;
- bail ou justificatif du local ;
- CIN ;
- diplôme ;
- autres documents utiles.

Les formats acceptés incluent PDF, images, Word et Excel, avec une limite de 10 Mo par fichier.

### 5.10 Gestion des documents

Le client peut téléverser, lister et supprimer un document tant que le dossier reste modifiable.

Les contrôles comprennent :

- extension autorisée ;
- type MIME déclaré ;
- détection du type réel du contenu ;
- taille maximale de 10 Mo ;
- analyse anti-programme malveillant selon la configuration du serveur ;
- appartenance du document à une demande possédée par le client.

Certains documents d’identité peuvent faire l’objet d’une comparaison automatique entre les données lues dans le fichier et celles saisies dans le formulaire. Les champs comparables incluent notamment le nom, le prénom, la date de naissance, le numéro d’identité et la date de délivrance.

Une non-conformité explicite, un document altéré ou une incohérence critique peut bloquer la validation. En revanche, l’indisponibilité du fournisseur d’analyse ne doit pas rejeter automatiquement le dossier : le document est signalé pour contrôle humain.

### 5.11 Étape 3 — Projet

Cette étape décrit le projet à financer :

- nom du projet ou de l’entreprise ;
- nom commercial ;
- nature : création, extension ou modernisation ;
- secteur et activité ;
- objectif du projet ;
- description détaillée ;
- adresse, ville, délégation et code postal ;
- coordonnées géographiques facultatives ;
- coût total du projet ;
- apport personnel ;
- financement demandé ;
- chiffre d’affaires mensuel prévisionnel ;
- charges mensuelles prévisionnelles.

Le financement demandé peut être initialisé depuis le montant global du crédit ou calculé à partir de la différence entre le coût total et l’apport personnel. Les valeurs finales restent soumises aux validations métier du serveur.

### 5.12 Étape 4 — Premier contrôle

La première validation vérifie que le dossier est suffisamment complet pour poursuivre :

- informations client enregistrées ;
- informations du crédit enregistrées ;
- informations du projet enregistrées ;
- présence des documents requis ;
- résultat acceptable des contrôles d’intégrité documentaire.

En cas d’erreur, l’interface affiche les champs ou documents à corriger. Le client peut revenir aux étapes précédentes et remplacer les justificatifs tant que le dossier n’est pas verrouillé.

Lorsque tous les justificatifs obligatoires sont présents mais que le blocage provient uniquement d’un rejet de l’analyse automatique, l’interface propose **Forcer la validation**. Cette action ne valide pas le document et ne contourne pas le personnel : elle place le dossier dans un état nécessitant obligatoirement un contrôle humain. Elle ne permet pas de continuer si un document requis manque.

### 5.13 Étape 5 — Confirmation et soumission

La dernière validation demande au client de confirmer les informations avant envoi.

Lors de la confirmation :

- les derniers contrôles sont exécutés ;
- la transition est enregistrée de manière atomique ;
- le dossier est verrouillé ;
- la demande est soumise au personnel pour étude.

Après le verrouillage final, les formulaires et documents ne sont plus modifiables et le dossier ne peut plus être supprimé par le client. Il faut donc corriger toute erreur avant cette étape.

### 5.14 Détail et suivi d’un dossier

La page `/applications/[id]` affiche :

- la référence du dossier ;
- le statut courant et son explication ;
- les dates principales ;
- le chemin de reprise lorsque le dossier est encore incomplet ;
- un résumé du client, du crédit, du projet et de l’agence ;
- la liste des documents ;
- les résultats de contrôle documentaire, par exemple conforme, non conforme ou simplement enregistré ;
- la chronologie des changements de statut.

Le dossier reste modifiable pendant les étapes préparatoires, jusqu’à la fin de la première validation. Sa suppression définitive est limitée aux états inachevés explicitement autorisés par l’API. L’annulation est une opération distincte, disponible dans les états autorisés par le cycle métier ; un dossier annulé est terminal et ne reprend pas son parcours.

### 5.15 États d’une demande

| État technique | Libellé fonctionnel | Signification |
|---|---|---|
| `DRAFT` | Brouillon | Demande créée, première étape à compléter |
| `STEP_1_COMPLETED` | Étape client terminée | Identité et situation du client enregistrées |
| `STEP_2_COMPLETED` | Étape crédit terminée | Crédit, financement et pièces enregistrés |
| `STEP_3_COMPLETED` | Étape projet terminée | Projet complété |
| `READY_FOR_VALIDATION_1` | Prêt pour contrôle | Dossier prêt pour le premier contrôle |
| `VALIDATION_1_COMPLETED` | Premier contrôle terminé | Complétude et justificatifs contrôlés |
| `VALIDATION_2` | Confirmation finale | Attente de la confirmation du client |
| `FINAL_LOCKED` | Dossier verrouillé | Données figées pour soumission |
| `SUBMITTED` | Soumis | Dossier transmis au personnel |
| `STAFF_APPROVED` | Accord du personnel | Première décision favorable interne |
| `STAFF_REJECTED` | Refus du personnel | Première décision défavorable interne |
| `APPROVED` | Approuvé | Crédit approuvé |
| `REJECTED` | Refusé | Crédit refusé |
| `APPOINTMENT_PROPOSED` | Rendez-vous proposé | Un créneau attend la réponse du client |
| `APPOINTMENT_CONFIRMED` | Rendez-vous confirmé | Le client a accepté le créneau |
| `APPOINTMENT_LOCKED` | Rendez-vous verrouillé | Reprogrammations épuisées ou traitement manuel requis |
| `CANCELLED` | Annulé | Demande arrêtée par le client ou le processus autorisé |

Le backend impose une machine à états : un client ne peut pas sauter une étape, revenir arbitrairement en arrière ni effectuer une transition réservée au personnel.

### 5.16 Rendez-vous

Les rendez-vous apparaissent pour les dossiers dans les états `APPOINTMENT_PROPOSED`, `APPOINTMENT_CONFIRMED` ou `APPOINTMENT_LOCKED`.

Le client peut consulter :

- la date et l’heure ;
- l’agence concernée ;
- un lien cartographique lorsqu’une localisation est disponible ;
- le nombre de reprogrammations déjà utilisées.

Pour un rendez-vous proposé, il peut :

- accepter le créneau ;
- refuser le créneau et demander automatiquement le prochain créneau disponible.

La planification automatique recherche un jour ouvrable du lundi au vendredi et respecte la capacité de l’agence, la disponibilité du créneau et l’historique des créneaux déjà refusés.

Le client dispose au maximum de quatre reprogrammations après la proposition initiale, soit jusqu’à cinq propositions au total. Une fois la limite atteinte, le rendez-vous passe à l’état verrouillé et le dossier nécessite un échange avec le personnel.

La page `/appointments` permet de passer d’un dossier éligible à l’autre lorsque plusieurs demandes possèdent un rendez-vous.

### 5.17 Messagerie avec le conseiller

La page `/applications/[id]/report` fournit un fil de discussion privé associé à une demande.

Fonctions :

- afficher l’historique des messages ;
- envoyer un texte de 2 000 caractères maximum ;
- envoyer un message avec pièce jointe ;
- télécharger une pièce jointe via une route protégée ;
- recevoir les nouveaux messages en temps réel ;
- afficher l’état de connexion temps réel ;
- empêcher les doublons lors de la réception d’événements.

Une pièce jointe ou un texte est requis. Les formats admis couvrent PDF, images, Word, Excel, CSV, TXT et PowerPoint, dans la limite de 10 Mo.

Un fil fermé devient consultable en lecture seule. La messagerie est particulièrement utile pour un dossier annulé nécessitant une explication ou un rendez-vous verrouillé nécessitant une intervention humaine.

### 5.18 Notifications

Le client peut consulter jusqu’à 50 notifications récentes et :

- voir le nombre de notifications non lues ;
- marquer une notification comme lue ;
- marquer toutes les notifications comme lues ;
- suivre le lien vers le dossier concerné lorsqu’un identifiant de demande est fourni.

Le tableau de bord actualise régulièrement ces informations pour refléter les changements de statut, rendez-vous et communications.

### 5.19 Profil

Le profil affiche :

- nom complet ;
- e-mail ;
- téléphone et état de vérification ;
- méthode d’authentification, mot de passe/e-mail ou Google ;
- état de la sécurité OTP ;
- total des financements sollicités ;
- liste et statut des demandes ;
- commande de déconnexion.

Le profil est actuellement en lecture seule : aucune fonction de modification des données personnelles n’est exposée par cette page.

La déconnexion tente de révoquer le jeton côté serveur, puis supprime la session locale même si la requête réseau échoue.

## 6. Principales routes API utilisées

### Authentification

| Méthode | Route | Usage |
|---|---|---|
| `POST` | `/api/auth/register` | Créer un compte |
| `POST` | `/api/auth/verify-registration-otp` | Valider l’inscription |
| `POST` | `/api/auth/login` | Vérifier les identifiants et lancer l’OTP |
| `POST` | `/api/auth/login/verify-otp` | Finaliser la connexion |
| `POST` | `/api/auth/google` | Connexion Google |
| `POST` | `/api/auth/google/set-phone` | Ajouter le téléphone d’un compte Google |
| `POST` | `/api/auth/google/verify-otp` | Valider le téléphone Google |
| `POST` | `/api/auth/password/forgot` | Envoyer le lien de réinitialisation |
| `POST` | `/api/auth/password/reset` | Enregistrer le nouveau mot de passe |
| `GET` | `/api/user` | Charger le client connecté |
| `POST` | `/api/auth/logout` | Révoquer la session |

### Demandes et documents

| Méthode | Route | Usage |
|---|---|---|
| `GET` | `/api/applications` | Lister les demandes du client |
| `POST` | `/api/applications` | Créer une demande |
| `GET` | `/api/applications/{id}` | Charger le détail |
| `DELETE` | `/api/applications/{id}` | Supprimer un dossier encore inachevé et autorisé |
| `PUT` | `/api/applications/{id}/client` | Enregistrer l’étape client |
| `PUT` | `/api/applications/{id}/credit` | Enregistrer l’étape crédit |
| `PUT` | `/api/applications/{id}/project` | Enregistrer l’étape projet |
| `POST` | `/api/applications/{id}/documents` | Téléverser un document |
| `GET` | `/api/applications/{id}/documents/{document}` | Télécharger un document autorisé |
| `DELETE` | `/api/applications/{id}/documents/{document}` | Supprimer un document modifiable |
| `POST` | `/api/applications/{id}/validation-1` | Lancer le premier contrôle |
| `POST` | `/api/applications/{id}/validation-2` | Confirmer la validation finale |
| `POST` | `/api/applications/{id}/submit` | Soumettre selon le cycle autorisé |
| `POST` | `/api/applications/{id}/cancel` | Annuler une demande autorisée |

### Rendez-vous, messages et notifications

| Méthode | Route | Usage |
|---|---|---|
| `GET` | `/api/applications/{id}/appointment` | Charger le rendez-vous |
| `POST` | `/api/applications/{id}/appointment/accept` | Accepter le créneau |
| `POST` | `/api/applications/{id}/appointment/reject` | Demander le prochain créneau |
| `GET` | `/api/applications/{id}/report/messages` | Charger la conversation |
| `POST` | `/api/applications/{id}/report/messages` | Envoyer un message |
| `GET` | `/api/applications/{id}/report/messages/{message}/attachment` | Télécharger la pièce jointe d’un message |
| `GET` | `/api/notifications` | Charger les notifications |
| `POST` | `/api/notifications/mark-read?id={notification}` | Marquer une notification comme lue |
| `POST` | `/api/notifications/mark-read` | Marquer toutes les notifications comme lues |

## 7. Sécurité et règles transversales

- Les pages privées nécessitent une session client valide.
- Les routes métier sont protégées par Sanctum, le rôle client, les permissions et des limites de fréquence.
- La politique d’autorisation vérifie que la demande appartient au client connecté.
- Les références métier sont générées par le serveur.
- Les transitions de statut sont contrôlées par une machine à états et sont auditées.
- Les documents sont soumis à une liste blanche de formats, une limite de taille, un contrôle de contenu et, selon la configuration, une analyse anti-programme malveillant.
- Le serveur refuse une modification après le verrouillage définitif.
- La demande de contrôle humain après un rejet automatique ne contourne ni les justificatifs obligatoires ni la décision du personnel.
- Les erreurs de validation peuvent être retournées champ par champ pour être affichées dans les formulaires.
- Le client transmet des informations techniques limitées sur le navigateur et l’appareil afin d’aider à la sécurité et à la télémétrie de session.

## 8. Limites fonctionnelles actuelles

- Le simulateur est indicatif ; il ne constitue ni une décision ni une offre de crédit.
- Le profil ne propose pas encore la modification des informations personnelles.
- La zone client n’affiche pas le solde du compte de dépôt ni un relevé bancaire. Ces informations restent gérées par les canaux prévus par l’établissement.
- Un dossier verrouillé ne peut plus être corrigé depuis l’espace client.
- La suppression définitive est réservée aux dossiers inachevés pour lesquels le serveur retourne `can_be_deleted: true`.
- Après quatre demandes de reprogrammation, le rendez-vous nécessite une intervention du personnel.
- Une panne du service d’analyse documentaire entraîne un contrôle humain plutôt qu’un rejet automatique.

## 9. Repères dans le code source

| Élément | Emplacement |
|---|---|
| Pages de la zone client | `client/app/` |
| Composants partagés | `client/components/` |
| Appels API | `client/lib/api/` |
| Libellés des statuts | `client/lib/status-labels.ts` |
| Routes Laravel | `backend/routes/` |
| Contrôleurs API | `backend/app/Http/Controllers/` |
| Validations des requêtes | `backend/app/Http/Requests/` |
| Modèles et relations | `backend/app/Models/` |
| Autorisations | `backend/app/Policies/` |
| Services métier | `backend/app/Services/` |

## 10. Résumé des fonctions principales

La zone client couvre le cycle complet d’une demande de financement : acquisition et simulation, identité sécurisée, constitution progressive du dossier, dépôt et contrôle des justificatifs, soumission, suivi de la décision, organisation du rendez-vous, notifications et échanges avec un conseiller. Les opérations sensibles restent vérifiées côté serveur et limitées au propriétaire du dossier.
