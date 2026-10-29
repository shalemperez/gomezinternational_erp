# Gomez ERP

Sistema de gestión para Gomez International (República Dominicana). Laravel 13 · Inertia 3 · React 19 · TypeScript · Tailwind 4 · PostgreSQL 17.

## Desarrollo local
1. `docker compose up -d db` (PostgreSQL en 127.0.0.1:5435; bases `gomez` y `gomez_test`).
2. `composer install && npm install`
3. `cp .env.example .env && php artisan key:generate` — completa `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_WORKSPACE_DOMAIN` y `SUPER_ADMIN_EMAILS`.
4. `php artisan migrate --seed && php artisan storage:link`
5. `composer dev` y abre http://gomezintsystem.test (Herd).

## Pruebas y calidad
`composer test` (Pint + PHPStan + Pest sobre PostgreSQL) · `npm run check` · `npm run types:check`.

## Documentación
Diseño: `docs/superpowers/specs/`. Planes por fase: `docs/superpowers/plans/`.
