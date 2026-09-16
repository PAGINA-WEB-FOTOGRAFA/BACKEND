# Backend Fotografía

API REST en PHP nativo (sin frameworks) para una web de fotografía. Permite gestionar eventos con sus fotos, registrar ventas y cobrar con **Mercado Pago** (Checkout Pro vía **Orders API**), notificando por correo a la administradora cuando un pago se confirma.

---

## Requisitos

- PHP 8.0+ (PHP 8.3 recomendado)
- MySQL 8
- [Laragon](https://laragon.org/download/) (recomendado en Windows)
- PHP `curl`, `mbstring`, `pdo_mysql` habilitados (vienen por defecto en Laragon)

---

## Instalación (local con Laragon)

1. Clonar/descomprimir el repositorio dentro de `C:\laragon\www\BACKEND FOTOGRAFIA` (o donde prefieras).
2. Crear la base de datos y las tablas:

   ```bash
   mysql -u root < schema.sql
   ```

   Esto crea `fotografo_db` con las tablas `eventos`, `fotos`, `ventas` y `venta_fotos`.

3. Crear la configuración local. **`config.php` NO se sube al repositorio**:
   copiá la plantilla y completá tus valores:

   ```bash
   cp config.example.php config.php   # en Windows: copy config.example.php config.php
   ```

   Como mínimo editá:
   - `MP_ACCESS_TOKEN` → token de tu cuenta Mercado Pago (empieza con `APP_USR-`).
   - `ADMIN_USER` / `ADMIN_PASS_HASH` → usuario y hash de la contraseña del panel admin.
   - `TOKEN_SECRET` → clave aleatoria: `php -r "echo bin2hex(random_bytes(32));"`
   - `ADMIN_EMAIL` / `MAIL_FROM` → email que recibe las ventas.

4. Levantar el servidor:

   ```bash
   php -S localhost:8000
   ```

   La API queda en `http://localhost:8000`.

---

## Configuración (`config.php`)

| Constante             | Qué es                                                                                         |
| --------------------- | ---------------------------------------------------------------------------------------------- |
| `MP_ACCESS_TOKEN`     | Access Token de la cuenta vendedora de Mercado Pago (`APP_USR-...`).                           |
| `MP_BASE_URL`         | Base de la API de MP (`https://api.mercadopago.com`).                                          |
| `BACKEND_URL`         | URL pública del backend (para las fotos de las orders y para el webhook de MP). Local: ngrok.  |
| `WEB_SUCCESS_URL`     | URL de la web a donde vuelve el cliente tras pagar (opcional; se envía junto con pending/failure). |
| `WEB_PENDING_URL`     | Ídem cuando queda pendiente.                                                                   |
| `WEB_FAILURE_URL`     | Ídem cuando el pago falla.                                                                     |
| `ADMIN_EMAIL`         | Email administrador que recibe el aviso de venta confirmada.                                   |
| `MAIL_FROM`           | Remitente de los correos (Gmail exige que coincida con la cuenta SMTP autenticada).            |
| `ADMIN_USER`          | Usuario del panel de administración (es un email).                                             |
| `ADMIN_PASS_HASH`     | Hash bcrypt de la contraseña del panel. Generar con `password_hash()`.                         |
| `TOKEN_SECRET`        | Clave secreta para firmar los tokens de sesión (HMAC-SHA256).                                  |
| `TOKEN_TTL_HORAS`     | Validez del token de sesión (12 h por defecto).                                                |
| `WEBHOOK_TEST_MODE`   | **Solo desarrollo**: si no está vacío, el webhook confía en la notificación sin consultar a MP. Dejar en `''` en producción. |

Nota: la conexión a la BD se define en `db.php` (por defecto `localhost`, base `fotografo_db`, usuario `root`, sin contraseña, como en Laragon).

---

## Autenticación

Endpoints protegidos requieren el header:

```
Authorization: Bearer <token>
```

### `POST /login.php` — público

```json
{ "email": "admin@example.com", "password": "tuclave" }
```

También acepta `usuario` en lugar de `email`. Respuesta:

```json
{
  "status": "success",
  "token": "eyJ1c3VhcmlvIj...firma",
  "expira": "2026-09-16 16:31:40"
}
```

---

## Endpoints

| Método | Endpoint            | Auth | Qué hace                                                  |
| ------ | ------------------- | ---- | --------------------------------------------------------- |
| GET    | `get_eventos.php`   | No*  | Eventos activos (`?id=X` detalle con fotos; `?admin=true` todos). |
| POST   | `crear_evento.php`  | Sí   | Crea evento con fotos (multipart).                        |
| POST   | `editar_evento.php` | Sí   | Edita campos y/o agrega/elimina fotos.                    |
| POST   | `eliminar_evento.php`| Sí  | Elimina evento + fotos.                                   |
| POST   | `crear_venta.php`   | No   | Registra venta y crea la order de pago en Mercado Pago.   |
| GET    | `get_ventas.php`    | Sí   | Lista ventas (`?estado=` y/o `?id=`).                     |
| POST   | `webhook_mp.php`    | No   | Recibe las notificaciones de pago de Mercado Pago.        |
| POST   | `login.php`         | No   | Inicia sesión y devuelve el token.                        |

\* `get_eventos.php?admin=true` sí requiere token.

### `POST /crear_evento.php`

`multipart/form-data` con:
- `nombre`, `lugar`, `fecha_evento` (YYYY-MM-DD), `precio_foto` (número ≥ 0)
- archivos en el campo `fotos[]` (jpg, png, webp, gif; se validan por MIME real)

### `POST /editar_evento.php`

Acepta JSON o multipart. Campos opcionales: `nombre`, `lugar`, `fecha_evento`, `precio_foto`, `activo` (0/1).
- Fotos a borrar: `fotos_eliminar` (array de ids o CSV).
- Fotos nuevas: archivos en `fotos[]` (solo multipart).

### `POST /eliminar_evento.php`

```json
{ "id": 3 }
```

### `POST /crear_venta.php`

```json
{
  "nombre": "Ana",
  "apellido": "Pérez",
  "whatsapp": "11 5555 1234",
  "email": "ana@mail.com",
  "fotos_ids": [1, 2]
}
```

El total se calcula en el servidor (suma del `precio_foto` de cada evento). Respuesta:

```json
{
  "status": "success",
  "venta_id": 7,
  "mp_order_id": "ORDTST...",
  "checkout_url": "https://www.mercadopago.com.ar/checkout/v1/redirect?order_id=...",
  "init_point": "https://www.mercadopago.com.ar/checkout/v1/redirect?order_id=..."
}
```

Redirigí al cliente a `checkout_url` (o `init_point`, son lo mismo).

### `GET /get_ventas.php`

- `?estado=pendiente|pagado`
- `?id=X` detalle de una venta (incluye sus fotos con evento y precio)

---

## Flujo de venta con Mercado Pago

1. El cliente compra fotos → `crear_venta.php` inserta la venta (`estado = 'pendiente'`) y crea una **order** en la API de Orders de MP (`POST /v1/orders`, con `X-Idempotency-Key`).
2. Se devuelve `checkout_url` → el frontend redirige al cliente a Mercado Pago.
3. Cuando el pago se aprueba, **Mercado Pago llama al webhook** `POST /webhook_mp.php`.
4. El webhook verifica el pago en MP y, si está `approved`, marca la venta como `pagado`, guarda el `mp_payment_id` y envía el **mail a la administradora** con los datos del cliente y las fotos vendidas.

### Configurar el webhook en Mercado Pago

En la **API de Orders** el webhook **no viaja en el payload**: se configura en el panel:

Panel de desarrolladores → **Tus integraciones** → tu aplicación → **Webhooks** → URL:

```
https://TU_DOMINIO/webhook_mp.php
```

(elegí el evento de **Pagos**.)

### Probar pagos (sandbox)

- La API de Orders no acepta credenciales de prueba (`TEST-`): las órdenes se crean con el token de producción (`APP_USR-`).
- Para simular compras se usan **usuarios de prueba** (cuentas creadas en **Cuentas de prueba**) usando la tarjeta de prueba `5031 4332 1540 6351` (o Visa `4509 9535 6623 3704`, o pagar con el saldo de la cuenta de prueba).
- Ojo: con credenciales de producción el `checkout_url` es el checkout **real**; las tarjetas de prueba solo funcionan en los flujos/entornos de prueba habilitados.

> `WEBHOOK_TEST_MODE`: si se setea en desarrollo, el webhook procesa la notificación simulada sin consultar a MP (util para probar el mail y la BD). Dejar vacío en producción.

---

## Correos (email de venta confirmada)

Se usa la función `mail()` de PHP. En local, el correo no se envía directo: se puede configurar un relay como **Mailpit** hacia Gmail (SMTP/relay) y el mail llega de verdad a la casilla de la administradora. En producción, el hosting debe tener PHP mail o SMTP configurado.

---

## Exponer el backend local con ngrok

Para que Mercado Pago pueda llamar al webhook desde tu PC:

```bash
ngrok config add-authtoken TU_AUTHTOKEN
ngrok http 8000
```

- La URL pública (ej. `https://xxxx.ngrok-free.app`) va en `BACKEND_URL` (config.php) y en el webhook del panel de MP: `https://xxxx.ngrok-free.app/webhook_mp.php`.
- **La URL gratis de ngrok cambia en cada reinicio**: hay que actualizar ambos lugares.

---

## Estructura del proyecto

```
BACKEND FOTOGRAFIA/
├── config.example.php   # plantilla de config (se copia a config.php)
├── config.php           # configuración + credenciales (NO se sube al repo)
├── db.php               # conexión PDO a MySQL
├── auth.php             # tokens HMAC + requireAuth()
├── login.php            # login del administrador
├── crear_evento.php     # alta evento + fotos
├── editar_evento.php    # edición evento + fotos
├── eliminar_evento.php  # baja evento + fotos
├── get_eventos.php      # listado eventos (público/admin)
├── crear_venta.php      # venta + order en Mercado Pago
├── get_ventas.php       # listado ventas (admin)
├── webhook_mp.php       # notificación de pago (MP) → pago confirmado + mail
├── schema.sql           # estructura de la base de datos
├── uploads/             # fotos subidas (no versionada; solo .gitignore)
└── README.md
```

---

## Seguridad

- `config.php` está en `.gitignore`: **no commitear credenciales** (token MP, hash admin, clave de firma).
- En producción usar HTTPS obligatorio.
- Cambiar `ADMIN_PASS_HASH` y `TOKEN_SECRET` antes de publicar.
- Las subidas (`uploads/`) validan MIME real de las imágenes y usan nombres aleatorios.