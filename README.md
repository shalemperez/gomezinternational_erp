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
4. **Cola** (obligatoria: tasas de cambio, correos de invitación y alertas corren en cola): un daemon `php artisan queue:work --queue=default --tries=3 --timeout=150` supervisado (Forge → Daemons / Supervisor). Tras cada despliegue: `php artisan queue:restart`. Los envíos a la DGII pueden tardar hasta 150 s: mantén `DB_QUEUE_RETRY_AFTER` (180 por defecto) por encima de ese valor.
5. **Programador** (obligatorio: obtención diaria de tasas): cron `* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1`.
6. **Respaldos**: base de datos y `storage/app` diarios, fuera del servidor.

## Documentación
Diseño: `docs/superpowers/specs/`. Planes por fase: `docs/superpowers/plans/`.

## Fase 2 — Ventas con NCF
- Antes de facturar, registra las secuencias NCF en Administración → Secuencias NCF.
- Cada usuario conecta su Gmail desde el menú de usuario para enviar documentos por correo.
- Programador (entradas diarias): `CheckNcfSequences` 07:30, `CheckStockAlerts` 07:35, `ExpireProformas` 00:10.
- Tras cada despliegue ejecuta `php artisan db:seed --force` (secuencias de documentos y plantillas de correo).

## Facturación electrónica (e-CF)

**Cambio de modo fiscal.** Administración → Facturación electrónica (`fiscal.mode`): `ncf` (comprobantes tradicionales) o `ecf` (electrónicos), con una fecha de cambio (`fiscal.ecf_cutover_date`). Los documentos emitidos conservan su modo para siempre. En modo `ecf` no se emite nada sin un certificado activo y las secuencias `E31`…`E34` cargadas; un intento fallido no consume e-NCF.

**Ambientes.** `ecf.environment`: `test` (TesteCF), `cert` (CerteCF), `prod` (eCF). Una base de datos sólo puede tener e-CF de un ambiente: cambiarlo se rechaza si ya existen comprobantes de otro. La certificación con la DGII se hace en un despliegue de staging con su propia base de datos; producción cambia a `prod` antes de la primera emisión electrónica.

**Certificado.** Archivo `.p12` del Usuario Administrador e-CF (RNC en el subject serialNumber) y su contraseña; se guardan cifrados. Si OpenSSL 3 no lo abre, conviértelo con:

```bash
openssl pkcs12 -in viejo.p12 -nodes -legacy | openssl pkcs12 -export -out nuevo.p12 -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256
```

**Receptor.** Registra en la DGII (postulación) estas URLs HTTPS públicas:

- `{APP_URL}/fe/recepcion/api/ecf`
- `{APP_URL}/fe/aprobacioncomercial/api/ecf`

**Procesos.** Requeridos en producción:

- Cola: `php artisan queue:work --tries=1`.
- Programador: cron cada minuto con `php artisan schedule:run`. Incluye el reintento de e-CF pendientes cada 5 minutos y la alerta diaria a las 07:40.

**Archivos.** Los XML firmados y las respuestas de la DGII se guardan de forma inmutable en `storage/app/private/ecf/...` y deben incluirse en los respaldos.

**Certificación.** Sigue la lista de verificación en `/fiscal/certification`.

**Datos que debe aportar la empresa** (bloquean el paso a producción, no el desarrollo):

- Certificado `.p12` con su contraseña.
- Decisión entre DGII directo y PSFE (Alanube). Si es PSFE: contrato, API keys de sandbox y producción, y webhook en el registro de la empresa en Alanube.
- Procedimientos DGII: solicitud FI-GDF-016 en la Oficina Virtual, credenciales del Portal de Certificación, postulación con las URLs del receptor, set de pruebas, validación del PDF de RI, declaración jurada y autorización.
- Autorizaciones de secuencias e-NCF por tipo, con rangos de producción y fechas de vencimiento, cargadas en Administración → Secuencias NCF.
- HTTPS público para las URLs del receptor (Forge/VPS) y una instalación de staging para la certificación.
- Confirmación de la contadora del plazo de la Ley 32-23 que aplica a la empresa.
- Datos de la empresa para el XML: razón social, RNC, dirección, códigos de municipio y provincia, correo, código DGII de cada impuesto adicional del catálogo y códigos de unidad de medida fuera de los predeterminados en `config('ecf.unit_codes')`.
