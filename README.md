# Compartir Archivos (S3 + PHP + MySQL)

Aplicación web para crear **carpetas**, subir **archivos de cualquier tipo** (incluso carpetas completas y archivos de varios GB) y **compartirlas** con personas de cualquier entidad, que pueden ver, previsualizar y descargar su contenido.

- Los archivos viven en **Amazon S3** (bucket privado). El navegador sube y descarga **directo desde S3** con URLs prefirmadas de corta duración: los archivos no pasan por PHP (salvo la descarga de carpetas como ZIP, que se genera en streaming sin tocar el disco).
- Usuarios, permisos y metadatos viven en **MySQL 8**.
- Stack: PHP 8.2 · Slim 4 + PHP-DI · Twig 3 · Phinx · AWS SDK · Symfony Mailer/Console · ZipStream · Tailwind CSS (compilado) · Alpine.js · ES modules sin bundler.

La especificación completa está en [CLAUDE.md](CLAUDE.md).

---

## Contenido

1. [Funcionalidades](#funcionalidades)
2. [Instalación local con XAMPP (Windows)](#instalación-local-con-xampp-windows)
3. [Instalación local con Docker (S3 local, sin AWS)](#instalación-local-con-docker-s3-local-sin-aws)
4. [Configuración (.env)](#configuración-env)
5. [Configuración del bucket S3](#configuración-del-bucket-s3) — política IAM, CORS, ciclo de vida
6. [Despliegue en producción (Nginx + PHP-FPM)](#despliegue-en-producción-nginx--php-fpm)
7. [Comandos de mantenimiento](#comandos-de-mantenimiento)
8. [Pruebas y calidad](#pruebas-y-calidad)
9. [Arquitectura](#arquitectura)
10. [Seguridad](#seguridad)

---

## Funcionalidades

**Cuentas y permisos**
- Registro **solo por invitación**. El invitado recibe un enlace, escribe su nombre, su entidad (opcional) y una contraseña, y entra en menos de un minuto. Cualquier dominio de correo es válido.
- Inicio de sesión con contraseña (argon2id), **recuperación por correo** y **enlace mágico** (acceso sin contraseña, de un solo uso, 20 min; la sesión dura menos y no permite entrar a la administración).
- Permisos por carpeta raíz, heredados por las subcarpetas: **Propietario** (todo), **Editor** (subir, renombrar, eliminar, crear subcarpetas, invitar lectores), **Lector** (ver y descargar).
- **Entidades** (organizaciones) para agrupar usuarios; el dominio es informativo y sirve para asignar la entidad automáticamente.

**Subidas** (el corazón del sistema)
- Arrastrar y soltar archivos **y carpetas completas** (se reproduce la estructura), botones “Subir archivos” y “Subir carpeta”.
- Panel de subidas estilo Google Drive: progreso por archivo y global, velocidad, tiempo restante, **pausar / reanudar / cancelar / reintentar**, minimizable y persistente mientras se navega entre carpetas (la navegación no recarga la página).
- Archivos ≤ 15 MB: PUT prefirmado. Más grandes: **Multipart Upload** (3 archivos y 4 partes en paralelo, reintentos automáticos con espera exponencial).
- **Reanudación tras recargar la página**: el navegador recuerda la subida; al volver a elegir el mismo archivo continúa desde las partes que ya están en S3.
- Nombres repetidos: **Reemplazar** (nueva versión, con historial), **Conservar ambos** (`nombre (2).ext`) u **Omitir**, con opción “aplicar a todos”.
- Pausa automática sin conexión y reanudación al volver; aviso si se intenta cerrar la pestaña con subidas activas.

**Descargas y visualización**
- Descarga individual: redirección 302 a una URL prefirmada (10 min) con el nombre original.
- Vista previa de imágenes, PDF, video, audio y texto.
- Descargar carpeta completa o selección como **ZIP en streaming** (muestra antes el tamaño total; límites configurables).
- Detalle de archivo: metadatos, historial de versiones y quién lo descargó (propietario/editor).

**Compartir**
- Invitar por correo (varios a la vez) con permiso; si la persona ya tiene cuenta se agrega directamente y se le avisa.
- **Enlaces públicos** de solo lectura con contraseña, vencimiento y límite de descargas opcionales; revocables. Cada descarga queda registrada.
- Notificaciones por correo: “Te compartieron una carpeta”, “Se subieron N archivos”.

**Administración**: usuarios (rol, estado, entidad), invitaciones, entidades, límites ajustables, registro de actividad con filtros y uso de almacenamiento por carpeta raíz.

**Interfaz**: en español, responsive (móvil y escritorio), modo oscuro automático, accesible (teclado, `aria-live` para el estado de subidas), búsqueda global.

---

## Instalación local con XAMPP (Windows)

Requisitos: XAMPP con PHP 8.2+ (extensiones `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zlib`), MySQL 8, Composer y Node.js 18+ (solo para compilar el CSS).

```bash
# 1. Dependencias
composer install
npm install && npm run build        # compila Tailwind y copia JS/fuentes a public/assets

# 2. Configuración
cp .env.example .env                # completar DB_*, APP_URL, APP_KEY y los datos de S3
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # valor para APP_KEY

# 3. Base de datos
mysql -u root -p -e "CREATE DATABASE fileshare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
vendor/bin/phinx migrate -c phinx.php

# 4. Bucket y administrador
php bin/console s3:setup            # crea/configura el bucket y verifica permisos
php bin/console user:create-admin   # pide correo, nombre y contraseña
```

Si el proyecto está en `C:\xampp\htdocs\GestorS3` y Apache escucha en el puerto 8080, usa `APP_URL=http://localhost:8080/GestorS3`. El `.htaccess` de la raíz envía todo a `public/`, así que `.env`, `vendor/`, `storage/`, etc. no son accesibles desde la web. `mod_rewrite` debe estar activo (lo está por defecto en XAMPP).

Con `MAIL_DSN=null://null` no se envían correos: el contenido (incluidos los enlaces) se escribe en `storage/logs/mail.log`, y al invitar a alguien la app muestra el enlace de invitación para copiarlo.

## Instalación local con Docker (S3 local, sin AWS)

Incluye PHP-FPM, Nginx, MySQL 8, un **S3 local** y **Mailpit** (bandeja para ver los correos).

> **Nota sobre MinIO:** MinIO dejó de publicar imágenes públicas (Docker Hub y Quay devuelven *acceso denegado*), por lo que el servicio S3 local usa **SeaweedFS**, también compatible con S3 (URLs prefirmadas, multipart y CORS). Si tienes acceso a una imagen de MinIO, basta con reemplazar el servicio `s3` en `docker-compose.yml` y ajustar `S3_ENDPOINT`/`S3_PUBLIC_ENDPOINT` y las credenciales en `.env.docker`.

```bash
cp .env.docker .env      # o, para no tocar tu .env: export APP_ENV_FILE=.env.docker
make up                  # construye, instala dependencias, migra y crea el bucket
make seed                # crea el administrador
npm install && npm run build   # si aún no compilaste los assets
```

| Servicio | URL |
|---|---|
| Aplicación | http://localhost:8081 |
| S3 local (SeaweedFS) | http://localhost:8333 (credenciales en `docker/s3/s3.json`) |
| Mailpit (correos) | http://localhost:8025 |
| MySQL | localhost:3307 (fileshare / fileshare) |

En Docker el servidor habla con el S3 local por `http://s3:8333` (`S3_ENDPOINT`) y el navegador por `http://localhost:8333` (`S3_PUBLIC_ENDPOINT`, usado solo para firmar las URLs). El CORS del S3 local se define con `-s3.allowedOrigins` en `docker-compose.yml`.

## Configuración (.env)

Toda la configuración está en `.env` (ver [.env.example](.env.example), documentado línea a línea). **Nunca** se suben credenciales al repositorio: `.env` está en `.gitignore`. Para cambiar de bucket o de credenciales basta con editar `.env`; el código no cambia.

| Variable | Descripción |
|---|---|
| `APP_URL` | URL pública completa (incluye el subdirectorio si aplica). Define también el origen para CORS y si la cookie es `Secure`. |
| `APP_KEY` | 32 bytes en base64. Firma tokens (invitaciones, enlaces mágicos, enlaces públicos). Si cambia, los enlaces públicos existentes dejan de funcionar. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `S3_BUCKET` | Acceso a S3. |
| `S3_PREFIX` | Subdirectorio opcional dentro del bucket. |
| `S3_ENDPOINT`, `S3_PUBLIC_ENDPOINT`, `S3_USE_PATH_STYLE` | Para almacenamientos compatibles (MinIO, SeaweedFS…). Vacíos en AWS. |
| `S3_PRESIGN_UPLOAD_TTL` / `S3_PRESIGN_DOWNLOAD_TTL` | Validez de las URLs prefirmadas (segundos). |
| `UPLOAD_*`, `FOLDER_MAX_BYTES`, `ZIP_*`, `BLOCKED_EXTENSIONS` | Límites por defecto (el administrador puede sobrescribirlos desde el panel, excepto el umbral simple/multipart y el tamaño de parte). |
| `MAIL_DSN`, `MAIL_FROM` | Symfony Mailer. `null://null` desactiva el envío. |
| `SESSION_LIFETIME_MIN`, `MAGIC_SESSION_LIFETIME_MIN` | Inactividad máxima de las sesiones normal y por enlace mágico. |

## Configuración del bucket S3

El comando `php bin/console s3:setup` hace todo lo siguiente automáticamente (si las credenciales tienen permiso) y al final verifica lectura/escritura. `php bin/console s3:setup --check` solo verifica. Para agregar orígenes CORS extra: `--origin=https://otro.dominio`.

### 1. Bucket privado

- *Block Public Access*: las cuatro opciones activadas.
- Cifrado por defecto SSE-S3 (AES-256).
- Nunca se usan ACL públicas: todo acceso es mediante URLs prefirmadas.

### 2. Política IAM mínima para el usuario de la aplicación

Reemplaza `NOMBRE-DEL-BUCKET` (y agrega `/PREFIJO` a los recursos de objetos si usas `S3_PREFIX`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ObjetosDeLaApp",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListMultipartUploadParts"
      ],
      "Resource": "arn:aws:s3:::NOMBRE-DEL-BUCKET/*"
    },
    {
      "Sid": "BucketDeLaApp",
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:ListBucketMultipartUploads"
      ],
      "Resource": "arn:aws:s3:::NOMBRE-DEL-BUCKET"
    }
  ]
}
```

Para que `s3:setup` pueda crear y configurar el bucket, ese usuario necesita además (solo durante la instalación): `s3:CreateBucket`, `s3:PutBucketPublicAccessBlock`, `s3:PutEncryptionConfiguration`, `s3:PutBucketCORS`, `s3:GetBucketCORS`, `s3:PutLifecycleConfiguration`. Lo recomendable es configurarlo con un usuario administrador y dejar a la app solo con la política mínima.

### 3. CORS (obligatorio para subir desde el navegador)

El navegador sube directo a S3, por lo que el bucket debe permitir el origen de la app **y exponer la cabecera `ETag`** (sin ella las subidas por partes no pueden completarse). Configuración equivalente (consola de S3 → Permisos → CORS):

```json
[
  {
    "AllowedOrigins": ["https://archivos.ejemplo.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag", "Content-Length", "Content-Range"],
    "MaxAgeSeconds": 3600
  }
]
```

Si el origen no está configurado, las subidas fallan con “Se perdió la conexión…”; a los administradores la app les indica explícitamente que revisen el CORS del bucket.

### 4. Ciclo de vida: abortar multiparts incompletos

Una subida por partes abandonada ocupa espacio (y se cobra) hasta que se aborta. Además del comando `uploads:cleanup` (cada hora), configura una regla de ciclo de vida:

- Consola S3 → Administración → Crear regla de ciclo de vida → Ámbito: todo el bucket (o el prefijo) → **Eliminar cargas multiparte incompletas** después de **2 días**.
- Equivalente en JSON: `{"Rules":[{"ID":"abort-incomplete-multipart","Status":"Enabled","Filter":{"Prefix":""},"AbortIncompleteMultipartUpload":{"DaysAfterInitiation":2}}]}`

### 5. Estructura de claves

`{S3_PREFIX/}{uuid-carpeta-raíz}/{uuid-archivo}` — nunca el nombre del usuario (evita colisiones, inyección de rutas y problemas con caracteres). El nombre original se guarda en MySQL y como metadato `x-amz-meta-original-name` (URL-encoded) por trazabilidad.

## Despliegue en producción (Nginx + PHP-FPM)

1. Servidor con PHP 8.2-FPM (`pdo_mysql`, `mbstring`, `intl` recomendada, `curl`, `zlib`, `opcache`), MySQL 8 y Nginx.
2. Código en `/var/www/app` (el *document root* es `/var/www/app/public`):
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build            # o copia public/assets ya compilado (está versionado)
   cp .env.example .env && nano .env   # APP_ENV=production, APP_URL=https://..., credenciales
   vendor/bin/phinx migrate -c phinx.php
   php bin/console s3:setup --check
   php bin/console user:create-admin
   chown -R www-data:www-data storage && chmod -R 775 storage
   ```
3. Nginx: copia [nginx.conf.example](nginx.conf.example) (HTTPS, HSTS, gzip, caché de assets, bloqueo de archivos sensibles y **buffering desactivado + timeout de 3600 s para la ruta de descarga ZIP**). `client_max_body_size 10m` es suficiente porque los archivos no pasan por el servidor.
4. PHP-FPM: `output_buffering=Off` y `zlib.output_compression=Off` (ver [docker/php/php.ini](docker/php/php.ini)); `request_terminate_timeout` ≥ 3600 s si quieres permitir ZIP muy grandes.
5. Con `APP_ENV=production` Twig usa caché en `storage/cache/twig` y los errores no muestran detalles.
6. Sesiones: archivos en `storage/sessions` (cookie `HttpOnly; Secure; SameSite=Lax` cuando `APP_URL` es https).
7. Programa el cron (ver abajo).

## Comandos de mantenimiento

| Comando | Qué hace | Frecuencia |
|---|---|---|
| `php bin/console uploads:cleanup` | Aborta multiparts de más de 24 h, elimina registros `uploading`/`failed` huérfanos (y su objeto), purga tokens y contadores expirados. | Cada hora |
| `php bin/console storage:purge --days=30` | Borra definitivamente de S3 los archivos eliminados hace más de 30 días (con sus versiones). `--dry-run` para simular. | Diario |
| `php bin/console s3:setup [--check]` | Configura o verifica el bucket. | Al instalar / diagnosticar |
| `php bin/console user:create-admin` | Crea un administrador (o promueve uno existente). | Cuando haga falta |

Crontab de ejemplo:

```cron
0 * * * *  cd /var/www/app && php bin/console uploads:cleanup >> storage/logs/cron.log 2>&1
30 3 * * * cd /var/www/app && php bin/console storage:purge --days=30 >> storage/logs/cron.log 2>&1
```

En Windows (XAMPP) usa el Programador de tareas con `C:\xampp\php\php.exe C:\xampp\htdocs\GestorS3\bin\console uploads:cleanup`.

Registros: `storage/logs/app-AAAA-MM-DD.log` (errores, incluidos los de S3) y `storage/logs/mail.log` (correos cuando no hay SMTP o fuera de producción).

## Pruebas y calidad

```bash
vendor/bin/phpunit            # unitarias + integración E2E (crea y migra la BD fileshare_test)
vendor/bin/phpstan analyse    # nivel 6
vendor/bin/php-cs-fixer fix --dry-run --diff   # PSR-12
```

Las pruebas de integración ejecutan la aplicación completa (rutas, middleware, servicios y MySQL) con S3 simulado: login, enlaces mágicos, recuperación de contraseña, CSRF, permisos (un lector recibe 403 al subir o eliminar), subidas simples y multipart con reanudación, conflictos de nombre y versiones, límites, ZIP, invitaciones y enlaces públicos. La base de pruebas se configura en `phpunit.xml` (`DB_NAME=fileshare_test`).

## Arquitectura

```
bin/console              Comandos (symfony/console)
config/                  settings.php (lee .env), dependencies.php (DI), routes.php, middleware.php
db/migrations, db/seeds  Phinx
public/                  index.php + assets compilados (css, js, fuentes, iconos)
resources/css, js, views Fuentes de Tailwind, ES modules y plantillas Twig
src/Controllers          Solo HTTP (entrada/salida)
src/Services             Lógica: Auth, Folder, Member, Invitation, Upload, File, Zip, S3, ShareLink, Mail…
src/Repositories         SQL con PDO (siempre sentencias preparadas)
src/Middleware           Session, Auth, Admin, Csrf, SecurityHeaders
src/Support              Config, Session, FileName, PartSize, Token, Validator, Present…
tests/Unit, Integration  PHPUnit
```

**Protocolo de subida** (`/api/...`):

1. `POST /api/folders/{uuid}/files/init` → valida permiso y límites, crea subcarpetas de `relativePath`, registra el archivo (`uploading`) y devuelve un PUT prefirmado (`single`) o una sesión multipart (`partSize`, `totalParts`).
2. `POST /api/uploads/{session}/parts/sign` → URLs por parte (lotes de hasta 20). El navegador sube cada parte y guarda el `ETag`.
3. `POST /api/uploads/{session}/complete` o `POST /api/files/{uuid}/confirm` → `CompleteMultipartUpload`/`HeadObject`, verificación de tamaño, `ready`.
4. `GET /api/uploads/{session}` → partes ya subidas (`ListParts`) para reanudar. `DELETE /api/uploads/{session}` → cancelar.

Todas las respuestas JSON tienen la forma `{ "ok": true, "data": … }` o `{ "ok": false, "error": { "code", "message" } }`.

**Base de datos**: ver migraciones. Nota: MySQL trata `NULL` como distinto en índices únicos, por lo que la unicidad de nombres “vivos” se implementa con una columna generada `alive` (`1` si no está borrado, `NULL` si lo está) en lugar de `UNIQUE(…, deleted_at)`.

## Seguridad

- Bucket privado; URLs prefirmadas de 30 min (subida) y 10 min (descarga/vista).
- Permisos verificados en cada operación del servidor; sin acceso a una carpeta se responde 404 (no revela su existencia) y sin permiso suficiente 403.
- CSRF en todos los formularios y llamadas de API (`X-CSRF-Token`).
- Rate limiting (MySQL) en login, recuperación, enlaces mágicos, invitaciones, desbloqueo de enlaces públicos y firmas de URLs.
- Contraseñas con argon2id; tokens de un solo uso guardados como HMAC; los enlaces mágicos requieren un clic de confirmación (los escáneres de correo no los consumen).
- Cabeceras: CSP (sin scripts inline; `unsafe-eval` es necesario para Alpine.js), `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, HSTS en Nginx.
- Nombres de archivo saneados (caracteres de control, separadores de ruta, 255 bytes) y nunca usados como clave en S3.
- **La lista negra de extensiones es un control blando**: se basa en el nombre y puede evitarse renombrando o comprimiendo el archivo. No sustituye un antivirus.
- Auditoría (`audit_log`) de inicios de sesión, subidas, descargas (incluidas las de enlaces públicos), ZIP, borrados, invitaciones, cambios de permisos y de configuración.
