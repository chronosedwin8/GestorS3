# CLAUDE.md — Plataforma de intercambio de archivos (S3 + PHP + MySQL + Nginx)

> Este archivo es la especificación de referencia del proyecto. Léelo completo antes de escribir código.
> Cuando tengas dudas de diseño que cambien la arquitectura, pregunta antes de implementar.
> Las credenciales de S3 y el nombre del bucket se entregarán después: **nunca las inventes ni las hardcodees**; usa `.env`.

---

## 1. Objetivo

Construir una aplicación web que permita a un usuario crear **carpetas**, subir en ellas **archivos de cualquier extensión** (incluyendo múltiples archivos y carpetas completas) y **compartirlas** con otras personas, que al ingresar pueden **ver, previsualizar (cuando aplique) y descargar** los archivos.

Los archivos se almacenan en **Amazon S3**. Los metadatos, usuarios y permisos viven en **MySQL**.

Los usuarios pertenecen a **distintas entidades/organizaciones** y tienen correos de dominios diferentes; no existe un dominio corporativo único.

Prioridades, en este orden:

1. **Facilidad de uso**: cualquier persona sin formación técnica debe poder subir y descargar sin instrucciones.
2. **Feedback claro**: todo proceso de carga y descarga debe ser informativo (progreso por archivo y global, velocidad, errores concretos, reintentos).
3. **Interfaz moderna y limpia**: sin ruido visual, responsive, en español.
4. **Seguridad razonable**: bucket privado, URLs firmadas de corta duración, control de acceso por carpeta.

---

## 2. Stack obligatorio

| Capa | Tecnología | Notas |
|---|---|---|
| Lenguaje | **PHP 8.2+** | Tipado estricto (`declare(strict_types=1)`), PSR-12, PSR-4 vía Composer |
| Framework | **Slim 4** + PHP-DI | Ligero. Alternativa aceptable: MVC propio con FastRoute. No usar Laravel/Symfony completos. |
| Plantillas | **Twig 3** | Layout base + vistas |
| Base de datos | **MySQL 8.0** | InnoDB, `utf8mb4_unicode_ci`, acceso con PDO (prepared statements siempre) |
| Migraciones | **Phinx** | Todas las tablas se crean por migración; nunca SQL manual en producción |
| Almacenamiento | **Amazon S3** vía `aws/aws-sdk-php` | Bucket privado. Subidas y descargas con **URLs prefirmadas** |
| Servidor web | **Nginx** + **PHP-FPM** | Entregar `nginx.conf` de ejemplo |
| Frontend | **Tailwind CSS** (CLI compilado, no CDN en producción) + **Alpine.js** + JS vanilla (ES modules) | Sin React/Vue. Sin bundler pesado; a lo sumo Tailwind CLI para el CSS |
| Sesiones | Sesiones PHP nativas en cookie `HttpOnly; Secure; SameSite=Lax` | Almacenar en MySQL o Redis si está disponible; por defecto archivos |
| Correo | **Symfony Mailer** vía SMTP configurable | Invitaciones, verificación, notificaciones |
| Tests | **PHPUnit** | Al menos para servicios de dominio y validaciones |

Dependencias sugeridas (`composer.json`):

```
slim/slim, slim/psr7, php-di/php-di, twig/twig, slim/twig-view,
aws/aws-sdk-php, robmorgan/phinx, vlucas/phpdotenv, symfony/mailer,
ramsey/uuid, maennchen/zipstream-php, monolog/monolog,
respect/validation (o similar), phpunit/phpunit (dev)
```

---

## 3. Principios de arquitectura

1. **Los archivos nunca pasan por el servidor PHP** en el flujo normal. El navegador sube directo a S3 con URLs prefirmadas (PUT simple para archivos pequeños, **Multipart Upload** para archivos grandes). Así no dependemos de `client_max_body_size`, `upload_max_filesize` ni timeouts de PHP-FPM, y el progreso es real.
2. Las descargas individuales también van directo desde S3 mediante URL prefirmada de corta duración (5–15 min) con `Content-Disposition: attachment; filename="..."`.
3. La única excepción es **descargar una carpeta como ZIP**: el servidor la genera en streaming con ZipStream-PHP leyendo de S3, sin escribir en disco.
4. **Nombres de objeto en S3 nunca son el nombre del archivo del usuario.** Clave = `{folder_uuid}/{file_uuid}` (ver §5). El nombre visible vive en MySQL. Esto evita colisiones, inyección de rutas y problemas con caracteres.
5. Separar en capas: `Controllers` (HTTP) → `Services` (lógica) → `Repositories` (PDO). Nada de SQL en controladores ni en Twig.
6. Toda respuesta de API es JSON con forma consistente: `{ "ok": true, "data": {...} }` o `{ "ok": false, "error": { "code": "...", "message": "..." } }`.
7. Configuración exclusivamente por `.env` (ver §9). Publicar `.env.example` completo.

---

## 4. Modelo de usuarios, entidades y permisos

### 4.1 Cuentas

- Registro **solo por invitación** (no hay registro abierto). Un administrador o el dueño de una carpeta invita por correo; el invitado recibe un enlace, define su nombre y contraseña, y queda asociado a su entidad.
- Login con **correo + contraseña** (bcrypt/argon2id). Recuperación de contraseña por correo.
- Opción de **acceso por enlace mágico** (magic link) para invitados que solo necesitan descargar: recibe un correo, hace clic y queda autenticado con sesión limitada. Esto reduce fricción para usuarios externos poco técnicos. Implementarlo en Fase 3 (ver §11).
- Un usuario tiene: `name`, `email` (único), `entity_id` (opcional, nullable), `role` global.

### 4.2 Entidades

Tabla `entities` (organizaciones): nombre, dominio opcional (solo informativo, no restringe), logo opcional. Sirve para agrupar y filtrar usuarios y para mostrar "de qué entidad es" cada persona en la lista de compartidos.

### 4.3 Roles

**Rol global** (`users.role`):

- `admin`: gestiona usuarios, entidades, ve todas las carpetas, configuración.
- `user`: puede crear carpetas propias y ser invitado a otras.

**Permiso por carpeta** (`folder_members.permission`):

- `owner`: creador. Todo, incluyendo eliminar la carpeta y gestionar miembros.
- `editor`: subir, renombrar, eliminar archivos, crear subcarpetas, invitar `viewer`s.
- `viewer`: ver y descargar únicamente.

Los permisos se asignan en la **carpeta raíz** y se heredan a subcarpetas. No hay permisos por archivo en v1.

### 4.4 Enlaces públicos (opcional, Fase 3)

Una carpeta puede tener un **enlace de solo lectura** con token, expiración opcional y contraseña opcional, para compartir con alguien sin cuenta. Debe poder revocarse. Registrar cada descarga en `audit_log`.

---

## 5. Esquema de base de datos (MySQL 8)

Convenciones: `id` `BIGINT UNSIGNED AUTO_INCREMENT`; además `uuid CHAR(36)` único en tablas expuestas por URL. Timestamps `created_at`, `updated_at` en todas; `deleted_at` para borrado lógico en `folders` y `files`.

```sql
entities        (id, uuid, name, domain NULL, logo_key NULL, created_at, updated_at)

users           (id, uuid, entity_id NULL FK, name, email UNIQUE, password_hash NULL,
                 role ENUM('admin','user'), email_verified_at NULL, last_login_at NULL,
                 status ENUM('active','disabled'), created_at, updated_at)

invitations     (id, uuid, email, entity_id NULL, invited_by FK users, folder_id NULL FK,
                 permission ENUM('editor','viewer') NULL, token_hash, expires_at,
                 accepted_at NULL, created_at)

magic_links     (id, user_id FK, token_hash, expires_at, used_at NULL, created_at)

password_resets (id, user_id FK, token_hash, expires_at, used_at NULL, created_at)

folders         (id, uuid, parent_id NULL FK folders, root_id NULL FK folders,
                 owner_id FK users, name, description NULL, path_cache VARCHAR(1024),
                 created_at, updated_at, deleted_at NULL)
                 -- root_id = id de la carpeta raíz (para heredar permisos con un solo JOIN)
                 -- UNIQUE (parent_id, name, deleted_at) para evitar duplicados vivos

folder_members  (id, folder_id FK folders (solo raíces), user_id FK users,
                 permission ENUM('owner','editor','viewer'), added_by FK users,
                 created_at, UNIQUE(folder_id, user_id))

files           (id, uuid, folder_id FK folders, uploaded_by FK users,
                 name, extension VARCHAR(32), mime_type, size_bytes BIGINT UNSIGNED,
                 s3_key VARCHAR(512) UNIQUE, etag NULL, sha256 NULL,
                 status ENUM('uploading','ready','failed'), version INT DEFAULT 1,
                 created_at, updated_at, deleted_at NULL)
                 -- UNIQUE (folder_id, name, deleted_at)

upload_sessions (id, uuid, file_id FK files, user_id FK users, s3_upload_id VARCHAR(255) NULL,
                 part_size INT, total_parts INT, completed_parts JSON,
                 status ENUM('active','completed','aborted'), expires_at, created_at, updated_at)

share_links     (id, uuid, folder_id FK folders, created_by FK users, token_hash,
                 password_hash NULL, expires_at NULL, max_downloads NULL, downloads INT DEFAULT 0,
                 revoked_at NULL, created_at)

audit_log       (id, user_id NULL FK, share_link_id NULL FK, action VARCHAR(64),
                 target_type VARCHAR(32), target_id BIGINT UNSIGNED, meta JSON NULL,
                 ip VARCHAR(45), user_agent VARCHAR(255), created_at)
                 -- acciones: login, upload, download, download_zip, delete, share, invite, revoke...

settings        (key VARCHAR(64) PK, value TEXT)  -- límites ajustables por admin
```

Índices mínimos: `folders(parent_id)`, `folders(root_id)`, `folders(owner_id)`, `files(folder_id, status)`, `folder_members(user_id)`, `audit_log(created_at)`, `audit_log(target_type, target_id)`.

**Clave S3**: `{root_folder_uuid}/{file_uuid}` — sin extensión ni nombre original. Se guarda el `Content-Type` real como metadato del objeto y el nombre original como metadato `x-amz-meta-original-name` (URL-encoded) por trazabilidad.

---

## 6. Flujo de subida (el corazón del sistema)

### 6.1 Desde el navegador

- Zona de **arrastrar y soltar** ocupando el área principal de la carpeta + botón "Subir archivos" + botón "Subir carpeta" (`<input type="file" webkitdirectory multiple>`).
- Al soltar una carpeta, usar `DataTransferItem.webkitGetAsEntry()` para recorrer recursivamente y reconstruir la estructura (`webkitRelativePath`).
- **Panel de cola de subida** fijo (abajo a la derecha, estilo Google Drive): lista de archivos con nombre, tamaño, barra de progreso individual, estado (en cola / subiendo / completado / error), velocidad y tiempo restante estimado, barra de progreso global, botones **pausar / reanudar / cancelar / reintentar**. Minimizable. Persiste mientras se navega dentro de la app (usar un componente Alpine global en el layout).
- Concurrencia: máximo **3 archivos** simultáneos y, dentro de un multipart, máximo **4 partes** en paralelo. Configurable en JS.
- Advertir con `beforeunload` si hay subidas activas.
- Al terminar: el listado de la carpeta se actualiza sin recargar la página.

### 6.2 Protocolo cliente ↔ servidor ↔ S3

1. `POST /api/folders/{uuid}/files/init` con `{ name, size, mime, relativePath }`.
   - El servidor valida permiso (`editor`/`owner`), límites (§8), crea las subcarpetas que indique `relativePath` (idempotente), inserta `files` con `status='uploading'`.
   - Si `size <= 15 MB`: devuelve **URL prefirmada PUT** (expira 30 min) y `mode: 'single'`.
   - Si `size > 15 MB`: crea `CreateMultipartUpload`, calcula `part_size` (mínimo 8 MB, ajustado para no superar 10 000 partes), guarda `upload_sessions`, devuelve `mode: 'multipart'`, `uploadSessionUuid`, `partSize`, `totalParts`.
2. Multipart: `POST /api/uploads/{sessionUuid}/parts/sign` con `{ partNumbers: [...] }` → devuelve URLs prefirmadas por parte (por lotes de hasta 20). El cliente hace `PUT` a cada URL y captura el header `ETag`.
3. `POST /api/uploads/{sessionUuid}/complete` con `{ parts: [{partNumber, etag}] }` → el servidor llama `CompleteMultipartUpload`, hace `HeadObject` para confirmar tamaño, marca `files.status='ready'`, guarda `etag`.
4. Single: `POST /api/files/{uuid}/confirm` → `HeadObject`, verificar tamaño, `status='ready'`.
5. Cancelación: `DELETE /api/uploads/{sessionUuid}` → `AbortMultipartUpload` + `files.status='failed'`.
6. **Reanudación**: si el navegador se recarga, el cliente puede consultar `GET /api/uploads/{sessionUuid}` para saber qué partes ya están completas (`ListParts` en S3) y continuar.
7. **Limpieza**: comando CLI `php bin/console uploads:cleanup` (cron cada hora) que aborta multiparts con más de 24 h y elimina registros `uploading`/`failed` huérfanos. Configurar además una **regla de ciclo de vida** en el bucket para abortar multiparts incompletos a los 2 días (documentarlo en README).

### 6.3 CORS del bucket

Documentar en README la configuración CORS necesaria (`PUT`, `GET`, `HEAD`, `ExposeHeaders: ETag`, origen = dominio de la app). Si el origen no está configurado, la subida directa falla; el mensaje de error en la UI debe indicarlo claramente a un admin.

---

## 7. Flujo de descarga

- **Archivo individual**: `GET /api/files/{uuid}/download` → verifica permiso, registra en `audit_log`, redirige (302) a URL prefirmada S3 con `ResponseContentDisposition=attachment; filename*=UTF-8''...` y expiración de 10 min. El navegador muestra su propio progreso; adicionalmente, en la UI marcar el archivo como "Descargando…" durante 3 s para dar feedback inmediato.
- **Previsualización**: para imágenes, PDF, video/audio y texto plano, botón "Ver" que abre una URL prefirmada `inline` en un modal/visor. Otros tipos: solo descarga.
- **Selección múltiple / carpeta completa**: `POST /api/folders/{uuid}/download-zip` con lista opcional de `fileUuids` → respuesta `application/zip` en streaming (ZipStream-PHP + `GetObject` por streams). Nombre `{carpeta}.zip`. Límite configurable (por defecto 5 GB o 2 000 archivos; si se supera, mostrar mensaje sugiriendo descargar por subcarpetas). Deshabilitar buffering en Nginx para esa ruta (`proxy_buffering off` / `fastcgi_buffering off`, `X-Accel-Buffering: no`).
- Mostrar en la UI el tamaño total antes de descargar un ZIP.

---

## 8. Límites y validaciones (configurables en `.env` / `settings`)

- Tamaño máximo por archivo: por defecto **5 GB**.
- Tamaño máximo por carpeta raíz: por defecto **50 GB** (validar en `init`).
- Extensiones: **todas permitidas** salvo lista negra configurable (`.exe .bat .cmd .sh .msi .scr .js .vbs .ps1` por defecto; el admin puede vaciarla). Documentar claramente que es un control blando.
- Nombres: sanear (quitar caracteres de control, limitar a 255 bytes, conservar acentos y espacios). Si existe un archivo con el mismo nombre en la carpeta, preguntar al usuario: **reemplazar (nueva versión)**, **conservar ambos** (`nombre (2).ext`) o **omitir**. La decisión puede aplicarse "a todos".
- Rate limiting básico en login, invitaciones y firmas de URL (por IP y por usuario).
- CSRF en todos los formularios y en la API (token en header `X-CSRF-Token`).
- Cabeceras de seguridad: CSP razonable, `X-Content-Type-Options`, `Referrer-Policy`, HSTS en Nginx.

---

## 9. Configuración (`.env.example`)

```
APP_NAME="Compartir Archivos"
APP_ENV=production
APP_URL=https://archivos.ejemplo.com
APP_KEY=                      # 32 bytes base64 para firmar tokens
APP_TIMEZONE=America/Bogota
APP_LOCALE=es

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fileshare
DB_USER=
DB_PASS=

AWS_ACCESS_KEY_ID=            # se entregará después
AWS_SECRET_ACCESS_KEY=        # se entregará después
AWS_REGION=us-east-1
S3_BUCKET=                    # se entregará después
S3_PREFIX=                    # opcional, subdirectorio dentro del bucket
S3_ENDPOINT=                  # vacío para AWS; permite MinIO en desarrollo
S3_USE_PATH_STYLE=false
S3_PRESIGN_UPLOAD_TTL=1800
S3_PRESIGN_DOWNLOAD_TTL=600

UPLOAD_SINGLE_MAX_BYTES=15728640
UPLOAD_PART_SIZE_BYTES=8388608
UPLOAD_MAX_FILE_BYTES=5368709120
FOLDER_MAX_BYTES=53687091200
ZIP_MAX_BYTES=5368709120
ZIP_MAX_FILES=2000
BLOCKED_EXTENSIONS=exe,bat,cmd,sh,msi,scr,js,vbs,ps1

MAIL_DSN=smtp://user:pass@smtp.ejemplo.com:587
MAIL_FROM="Compartir Archivos <no-reply@ejemplo.com>"

SESSION_LIFETIME_MIN=480
LOG_LEVEL=info
```

Para desarrollo local proveer `docker-compose.yml` con `php-fpm`, `nginx`, `mysql:8`, `minio` (compatible S3) y `mailpit`, de modo que todo funcione sin credenciales reales. Las credenciales de AWS reales solo se colocan en el `.env` del servidor.

---

## 10. Interfaz de usuario

### 10.1 Principios

- Español neutro, tono claro ("Arrastra tus archivos aquí o haz clic para seleccionarlos").
- Diseño limpio: mucho espacio en blanco, una tipografía sans (Inter vía fuente local), una paleta con un color de acento, esquinas suaves, sombras sutiles. Modo claro por defecto; modo oscuro respetando `prefers-color-scheme` si es barato de mantener.
- Responsive: usable en móvil (descargar y ver) y completo en escritorio.
- Accesible: navegación por teclado, `aria-live` para el estado de subidas, contraste AA.
- Cada acción tiene respuesta visible inmediata (toast, spinner, cambio de estado). Nunca dejar al usuario sin saber si algo ocurrió.
- Errores en lenguaje humano con acción sugerida ("No se pudo subir *informe.pdf* porque supera el límite de 5 GB. Puedes comprimirlo o dividirlo.").

### 10.2 Pantallas

1. **Login** (correo + contraseña, enlace "Olvidé mi contraseña", opción "Enviarme un enlace de acceso").
2. **Aceptar invitación / crear cuenta** (nombre, contraseña; muestra quién invita y a qué carpeta).
3. **Inicio (Mis carpetas)**: dos secciones — "Mis carpetas" y "Compartidas conmigo". Tarjetas o lista con nombre, entidad del dueño, nº de archivos, tamaño, última actividad. Botón destacado "Nueva carpeta". Buscador por nombre.
4. **Vista de carpeta**: breadcrumb; barra de acciones (Subir archivos, Subir carpeta, Nueva subcarpeta, Descargar todo, Compartir); listado en tabla (icono por tipo, nombre, tamaño legible, subido por, fecha) con orden por columna y vista de cuadrícula alternativa; selección múltiple con checkboxes → descargar ZIP / eliminar (según permiso); menú contextual por archivo (Ver, Descargar, Renombrar, Eliminar, Detalles). Estado vacío con la zona de arrastre grande e instrucción clara.
5. **Compartir carpeta** (modal): lista de miembros con entidad y permiso, cambiar permiso, quitar; invitar por correo (varios correos separados por coma, con permiso); enlace público (Fase 3).
6. **Panel de subidas** (global, §6.1).
7. **Detalle de archivo** (panel lateral): metadatos, historial de versiones, quién lo descargó (solo owner/editor).
8. **Administración** (solo `admin`): usuarios, entidades, límites, registro de actividad con filtros, uso de almacenamiento por carpeta raíz.
9. **Perfil**: nombre, cambiar contraseña, cerrar sesión.

---

## 11. Plan de implementación por fases

Ejecuta las fases en orden. Al terminar cada fase, corre migraciones y tests, y deja la app en estado funcional.

**Fase 0 — Andamiaje**
Estructura de carpetas (§12), Composer, Slim + DI + Twig, `.env`, conexión PDO, Phinx con migraciones de todas las tablas, `docker-compose.yml`, `nginx.conf`, layout base con Tailwind compilado, Makefile (`make up`, `make migrate`, `make seed`, `make test`, `make css`). Seed con un admin inicial (`bin/console user:create-admin`).

**Fase 1 — Autenticación y carpetas**
Login/logout, recuperación de contraseña, invitaciones por correo, entidades, CRUD de carpetas y subcarpetas, miembros y permisos, middleware de autorización, pantallas 1–3 y 5 (sin enlace público).

**Fase 2 — Subida y descarga**
Servicio S3, endpoints de `init/sign/complete/confirm/abort`, cola de subida en el navegador con progreso, subida de carpetas completas, manejo de duplicados, descarga individual con URL prefirmada, previsualización, ZIP en streaming, comando `uploads:cleanup`, pantallas 4, 6 y 7, `audit_log`.

**Fase 3 — Compartir externo y pulido**
Magic links, enlaces públicos con contraseña/expiración, notificaciones por correo ("Te compartieron una carpeta", "Se subieron N archivos"), panel de administración (pantalla 8), búsqueda global, modo oscuro, pruebas end-to-end básicas, guía de despliegue en README.

---

## 12. Estructura de carpetas sugerida

```
/
├── bin/console                 # comandos CLI (symfony/console)
├── config/                     # settings.php, dependencies.php, routes.php, middleware.php
├── db/migrations/              # Phinx
├── db/seeds/
├── docker/                     # Dockerfiles, nginx/default.conf, php/php.ini
├── public/                     # document root de Nginx
│   ├── index.php
│   └── assets/                 # css compilado, js (ES modules), fuentes, iconos
├── resources/
│   ├── css/app.css             # fuente Tailwind
│   ├── js/                     # upload-queue.js, s3-uploader.js, folder-view.js, api.js
│   └── views/                  # Twig: layouts/, auth/, folders/, admin/, partials/, emails/
├── src/
│   ├── Controllers/
│   ├── Services/               # AuthService, FolderService, UploadService, DownloadService, ZipService, S3Service, MailService
│   ├── Repositories/
│   ├── Middleware/             # Auth, Csrf, RateLimit, FolderAccess
│   ├── Support/                # helpers: Bytes, Slug, Uuid, Response
│   └── Exceptions/
├── tests/
├── storage/logs/
├── .env.example
├── composer.json
├── Makefile
├── nginx.conf.example
└── README.md
```

---

## 13. Nginx (entregar `nginx.conf.example`)

Debe incluir: `root /var/www/app/public`, `try_files $uri /index.php?$query_string`, `fastcgi_pass` a PHP-FPM, negar acceso a `.env`, `/vendor`, `/storage`, `/config`; gzip; caché de assets estáticos; HSTS y cabeceras de seguridad; y para `location /api/folders/*/download-zip` desactivar buffering y ampliar `fastcgi_read_timeout` (p. ej. 3600 s). Como las subidas van directo a S3, `client_max_body_size` puede quedar en 10 MB.

---

## 14. Criterios de aceptación

- [ ] Un usuario invitado con correo de cualquier dominio puede crear su cuenta y entrar en menos de 1 minuto.
- [ ] Arrastrar una carpeta con 200 archivos y subcarpetas la reproduce fielmente en la app, con progreso por archivo y global.
- [ ] Un archivo de 3 GB se sube por multipart, se puede pausar/reanudar y sobrevive una recarga de página.
- [ ] Un `viewer` no ve botones de subir/eliminar y las llamadas directas a esos endpoints devuelven 403.
- [ ] La descarga de un archivo no pasa por PHP (verificable en logs de Nginx: solo un 302).
- [ ] "Descargar todo" de una carpeta de 1 GB comienza a descargar en menos de 3 s y no consume disco del servidor.
- [ ] Ninguna clave de S3 aparece en el repositorio; `.env.example` está completo y documentado.
- [ ] Todo el flujo funciona en local con `docker compose up` usando MinIO, sin credenciales de AWS.
- [ ] `make test` pasa; el código sigue PSR-12 (`php-cs-fixer`) y no tiene errores en `phpstan` nivel 6.

---

## 15. Instrucciones de trabajo para Claude Code

- Antes de empezar, resume tu plan de la fase actual en 5–10 líneas y espera confirmación solo si detectas una contradicción con este documento.
- Haz commits pequeños con mensajes descriptivos en español por cada bloque funcional.
- No introduzcas dependencias fuera de las listadas sin justificarlo en el mensaje.
- Escribe el README a medida que avanzas: instalación local, configuración del bucket (política IAM mínima, CORS, ciclo de vida), despliegue en Nginx, comandos de mantenimiento.
- Cuando se entreguen las credenciales de S3 y el bucket, solo se deben colocar en `.env`; el código no debe cambiar.
- La política IAM mínima que debe documentarse para el usuario de la app: `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts`, `s3:ListBucketMultipartUploads`, `s3:ListBucket` restringidos al bucket (y al `S3_PREFIX` si aplica).
