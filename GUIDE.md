# BTS Bank — Guide de démarrage

## 1. Lancer le projet

Prérequis : MySQL/XAMPP démarré, la base `bts_php_backend` créée.

**Backend (Laravel — API)**
```bash
cd backend
php artisan serve --host 127.0.0.1 --port 8000
```
→ tourne sur `http://localhost:8000`

**Frontend (Next.js)**
```bash
cd frontend
npm run dev
```
→ tourne sur `http://localhost:3000`

**Websockets (chat "Report" — optionnel, seulement si tu utilises le chat)**
```bash
cd backend
php artisan reverb:start
```

Les deux premières commandes suffisent pour utiliser l'application normalement (client, staff, admin).

---

## 2. Entrer en tant que Client (customer)

1. Ouvrir `http://localhost:3000`
2. Cliquer sur **Create an account** (`/register`)
3. Remplir le formulaire d'inscription (email, téléphone, mot de passe)
4. Un code OTP est envoyé par email (via Resend) — le saisir sur `/register/verify-otp`
5. Une fois vérifié, tu es connecté et redirigé vers `/dashboard`
6. De là : créer une nouvelle demande de crédit, remplir les 4 étapes (Client → Crédit → Projet → Validation)

Reconnexion ensuite : `http://localhost:3000/login` (email + mot de passe, puis OTP par email).

---

## 3. Entrer en tant que Staff (agence)

Il n'y a **pas d'auto-inscription** pour les comptes internes (staff/admin) — un compte doit être créé depuis le terminal, côté backend.

**Créer un compte staff :**
```bash
cd backend
php artisan staff:make staff@btsbank.tn --first-name=Ahmed --last-name=Ben Salah --role=staff --password=motdepasse123
```
- `--password` optionnel : si omis, un mot de passe est généré et affiché une seule fois dans le terminal.
- `--role=staff` (le rôle par défaut si non précisé)

**Se connecter :**
`http://localhost:3000/staff/login` → email + mot de passe → redirige automatiquement vers `/staff/dashboard`

Le staff voit les demandes soumises, peut les approuver/rejeter (1ère validation), et répondre au chat "Report" des dossiers verrouillés.

---

## 4. Entrer en tant qu'Admin

Même commande que le staff, avec `--role=admin` :

```bash
cd backend
php artisan staff:make admin@btsbank.tn --first-name=Sonia --last-name=Trabelsi --role=admin --password=motdepasse123
```

**Se connecter :**
Même page que le staff → `http://localhost:3000/staff/login` → email + mot de passe.
Le rôle du compte détermine automatiquement la redirection : un compte `admin` va vers `/staff/admin`, un compte `staff` va vers `/staff/dashboard`.

L'admin approuve/rejette après le staff (2ème validation) — c'est cette approbation admin qui déclenche la proposition automatique de rendez-vous en agence.

---

## Résumé rapide

| Rôle    | Inscription                                   | Connexion              |
|---------|------------------------------------------------|-------------------------|
| Client  | `/register` (auto, avec OTP email)             | `/login`                |
| Staff   | `php artisan staff:make <email> --role=staff`  | `/staff/login`          |
| Admin   | `php artisan staff:make <email> --role=admin`  | `/staff/login`          |
