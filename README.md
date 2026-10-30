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

## Producción (Forge / VPS)
Requisitos: PHP 8.4, PostgreSQL 17, Node 22 solo para compilar (`npm ci && npm run build`).

1. **Variables de entorno obligatorias** (`.env`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_TIMEZONE=America/Santo_Domingo` (si falta, Laravel usa UTC y todas las fechas se desplazan), `SESSION_SECURE_COOKIE=true`, `DB_*`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://…/auth/google/callback`, `GOOGLE_WORKSPACE_DOMAIN`, `SUPER_ADMIN_EMAILS`, `MAIL_MAILER=smtp` con `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
2. **Proxies**: si la app está detrás de Cloudflare o un balanceador, define `TRUSTED_PROXIES` con las IP/CIDR del proxy separadas por coma (o `*` si el proxy es el único acceso); vacío = ninguno. Sin esto la bitácora registra la IP del proxy en vez de la del usuario.
3. **Despliegue**: `composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan db:seed --force && php artisan storage:link && php artisan optimize && npm ci && npm run build`.
4. **Cola** (obligatoria: tasas de cambio, correos de invitación y alertas corren en cola): un daemon `php artisan queue:work --queue=default --tries=3 --timeout=120` supervisado (Forge → Daemons / Supervisor). Tras cada despliegue: `php artisan queue:restart`.
5. **Programador** (obligatorio: obtención diaria de tasas): cron `* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1`.
6. **Respaldos**: base de datos y `storage/app` diarios, fuera del servidor.

## Documentación
Diseño: `docs/superpowers/specs/`. Planes por fase: `docs/superpowers/plans/`.

## Fase 2 — Ventas con NCF
- Antes de facturar, registra las secuencias NCF en Administración → Secuencias NCF.
- Cada usuario conecta su Gmail desde el menú de usuario para enviar documentos por correo.
- Programador (entradas diarias): `CheckNcfSequences` 07:30, `CheckStockAlerts` 07:35, `ExpireProformas` 00:10.
- Tras cada despliegue ejecuta `php artisan db:seed --force` (secuencias de documentos y plantillas de correo).
