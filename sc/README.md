# BTS Bank Security Center

Interface SOC dédiée aux comptes `security` et `admin`. Elle regroupe le tableau de bord, la télémétrie réseau, les alertes, Osquery, le journal d’audit, la gestion des sessions et l’audit des données métier.

## Démarrage

```bash
cp .env.example .env.local
npm install
npm run dev
```

L’interface démarre sur [http://localhost:3003](http://localhost:3003). Le backend Laravel doit être disponible sur le port `8000`, sauf configuration différente dans `NEXT_PUBLIC_API_URL`.

## Validation

```bash
npm run lint
npm test
npm run build
```

Les routes protégées exigent un jeton Sanctum portant le rôle `security` ou `admin` et les permissions associées.
