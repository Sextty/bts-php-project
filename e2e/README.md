# Isolated BTS E2E suite

Run `npm run e2e:isolated` with XAMPP MariaDB listening on `127.0.0.1:3306`.

The runner creates a uniquely named `bts_e2e_<pid>` database, applies migrations, loads only
synthetic fixtures, starts the API/worker/Reverb/four portals on ports 8100/6101/3100-3103,
runs Playwright serially, stops its child processes, then drops only that validated database.
Use `-KeepDatabase` only for diagnosis. It never runs `migrate:fresh` against the normal database.

Google login is intentionally not automated: it requires an external Google session and would
make the local suite unsafe and non-reproducible. Password + OTP login, refresh, logout,
sessionStorage and legacy migration are covered without weakening authentication.
