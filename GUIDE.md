# BTS Bank — Guide de démarrage

## 1. Structure du projet

```
backend/   → API Laravel          → http://localhost:8000
client/    → App Client (Next.js) → http://localhost:3000
staff/     → App Staff (Next.js)  → http://localhost:3001
admin/     → App Admin (Next.js)  → http://localhost:3002
```

Chaque app frontend est **indépendante** (mêmes composants, pages propres à son rôle) et
communique avec la même API. Une session staff peut coexister avec une session client dans le
même navigateur (clés de stockage distinctes).

## 2. Lancer le projet

Prérequis : MySQL/XAMPP démarré, la base `bts_php_backend` créée.

**Backend (Laravel — API, e-mails, queue, scheduler et WebSocket)**
```bash
cd backend
composer run dev
```

Utiliser cette commande complète plutôt que `php artisan serve` seul. Les e-mails de décision
(`admin.approved`, `admin.rejected`, `staff.rejected`) passent par la queue : sans worker, ils
restent enregistrés mais ne sont pas envoyés.

**4 apps frontend — une par terminal :**
```bash
cd client   && npm install && npm run dev   # → http://localhost:3000
cd staff    && npm install && npm run dev   # → http://localhost:3001
cd admin    && npm install && npm run dev   # → http://localhost:3002
cd sc       && npm install && npm run dev   # → http://localhost:3003
```

**Websockets (chat "Report" — optionnel, seulement si tu utilises le chat)**
```bash
cd backend
php artisan reverb:start
```

**Bouton "Localiser" sur Étape 2 (Crédit)** : gratuit et sans clé API — recherche
d'adresses via Photon (OpenStreetMap) et carte Leaflet/OSM.

---

## 3. Entrer en tant que Client (customer)

1. Ouvrir `http://localhost:3000`
2. Cliquer sur **Create an account** (`/register`)
3. Remplir le formulaire d'inscription (email, téléphone, mot de passe)
4. Un code OTP est envoyé par 
send) — le saisir sur `/register/verify-otp`
5. Une fois vérifié, tu es connecté et redirigé vers `/dashboard`
6. De là : créer une nouvelle demande de crédit, remplir les 4 étapes (Client → Crédit → Projet → Validation)

Reconnexion ensuite : `http://localhost:3000/login` (email + mot de passe, puis OTP par email).

---

## 4. Entrer en tant que Staff (agence)

Il n'y a **pas d'auto-inscription** pour les comptes internes (staff/admin) — un compte doit être créé depuis le terminal, côté backend.

**Créer un compte staff :**
```bash
cd backend
php artisan staff:make staff@btsbank.tn --first-name=Ahmed --last-name=Ben Salah --role=staff --password=motdepasse123
```
- `--password` optionnel : si omis, un mot de passe est généré et affiché une seule fois dans le terminal.
- `--role=staff` (le rôle par défaut si non précisé)

**Se connecter (app staff séparée) :**
`http://localhost:3001/login` → email + mot de passe → redirige vers `/dashboard`

Le staff voit les demandes soumises, peut les approuver/rejeter (1ère validation), et répondre au chat "Report" des dossiers verrouillés.

---

## 5. Entrer en tant qu'Admin

Même commande que le staff, avec `--role=admin` :

```bash
cd backend
php artisan staff:make admin@btsbank.tn --first-name=Sonia --last-name=Trabelsi --role=admin --password=motdepasse123
```

**Se connecter (app admin séparée) :**
`http://localhost:3002/login` → email + mot de passe → redirige vers l'overview admin `/`

L'admin voit les statistiques globales, approuve/rejette après le staff (2ème validation) — c'est cette approbation admin qui déclenche la proposition automatique de rendez-vous en agence.

> Les deux portails sont cloisonnés : `staff` ne peut pas se connecter via l'app admin (403) et `admin` ne peut pas se connecter via l'app staff (403).

---

## Résumé rapide

| Rôle    | App        | Port  | Inscription                                  | Connexion |
|---------|------------|-------|----------------------------------------------|-----------|
| Client  | `/client`  | 3000  | `/register` (auto, avec OTP email)           | `/login`  |
| Staff   | `/staff`   | 3001  | `php artisan staff:make <email> --role=staff`| `/login`  |
| Admin   | `/admin`   | 3002  | `php artisan staff:make <email> --role=admin`| `/login`  |
