# CHECADOR API V2 — AUTHENTICATION & AUTHORIZATION SPECIFICATION

**API Version:** V2.1  
**Auth Schemes:** Device Authentication (`Bcrypt`), Portal3 Supervisor Authentication (`JWT HS256`)

---

## 1. MODO DUAL DE AUTENTICACIÓN

El API V2 implementa un esquema dual de autenticación adaptado rigurosamente al tipo de cliente que consume el servicio:

```text
               ┌────────────────────────────────────────────────────────┐
               │                     CLIENT REQUEST                     │
               └───────────────────────────┬────────────────────────────┘
                                           │
                           ¿Qué tipo de cliente realiza la llamada?
                                           │
                  ┌────────────────────────┴────────────────────────┐
                  ▼                                                 ▼
        [ LECTOR BIOMÉTRICO ]                             [ PORTAL3 SUPERVISOR ]
  (Dispositivo / Terminal Físico)                        (Panel Web Administrativo)
                  │                                                 │
  Headers Requeridos:                               Header Requerido:
  - X-Device-Id                                     - Authorization: Bearer <JWT>
  - X-Credential-Key                                                │
  - Authorization: Bearer <Secret>                                  ▼
                  │                                  Validación JWT HS256:
                  ▼                                  - Signature check (JWT_SECRET)
  Validación de Credencial BD:                       - Claims: iss=portal3_auth, exp,
  - Lookup en op_rh_v2_dispositivo_credenciales        type=access, sub=tb_usuarios.id
  - Hash Bcrypt: password_verify(secret, hash)                      │
                  │                                                 ▼
                  ▼                                  Resuelve Estaciones Autorizadas:
  Resuelve id_dispositivo & id_estacion             - Tabla localidades_perfil / personal
```

---

## 2. AUTENTICACIÓN DE DISPOSITIVOS (`Device Auth`)

Utilizada por los terminales físicos (`Lector`) para la ingesta de marcaciones (`POST /api/v2/punch-events`), creación de contingencias (`POST /api/v2/temporary-access/requests`) y validación de PINs (`POST /api/v2/temporary-access/validate`).

### Headers HTTP Obligatorios:
```http
X-Device-Id: DEV-ZK-EST01-001
X-Credential-Key: CRD-KEY-2026-A
Authorization: Bearer SEC_SUPER_SECRET_98765
```

### Proceso de Verificación Server-Side:
1. El API consulta la tabla `op_rh_v2_dispositivo_credenciales` buscando una credencial activa (`status = 'ACTIVE'`) que coincida con `credential_key` y pertenezca al dispositivo identificado por `X-Device-Id`.
2. Se evalúa el hash de la clave secreta mediante `password_verify($secret, $row['secret_hash'])`.
3. Se verifica que la fecha actual esté dentro de la ventana de validez (`valid_from_utc <= NOW() < valid_until_utc`).
4. Si la validación es exitosa, se obtienen transaccionalmente el `id_dispositivo` y la `id_estacion` oficial asignada al hardware.

---

## 3. AUTENTICACIÓN Y AUTORIZACIÓN DE SUPERVISORES (`Portal3 JWT`)

Utilizada por la aplicación web (`Portal3`) para operaciones administrativas de contingencia (`GET /requests`, `POST /authorize`, `POST /reject`, `POST /cancel`).

### Header HTTP Obligatorio:
```http
Authorization: Bearer <JWT_TOKEN_STRING>
```

### Reglas Estrictas de Validación del Token (`Portal3JwtAuthHelper`):
1. **Algoritmo (Header):** Debe ser estrictamente `HS256`.
2. **Secreto de Firma (`JWT_SECRET`):** Debe configurarse obligatoriamente en las variables de entorno del servidor.
   - **Regla Fail-Closed (SEC-M02):** Si `getenv('JWT_SECRET')` está ausente o vacío, el API interrumpe la ejecución inmediatamente y retorna un error `500 Internal Server Error`. No se asumen secretos por defecto.
3. **Issuer Claim (`iss`):** Debe ser exactamente `portal3_auth`.
4. **Expiration Claim (`exp`):** El timestamp UTC del token debe ser mayor que la hora actual del servidor (`exp > time()`).
5. **Type Claim (`type`):** Debe ser estrictamente `access`.
6. **Subject Claim (`sub`):** Debe contener el ID numérico del usuario en Portal3 (`tb_usuarios.id` > 0).

### Verificación de Usuario Activo en Base de Datos:
Tras validar la firma y los claims del token, el API consulta la base de datos de Portal3:
```sql
SELECT id, usuario FROM tb_usuarios WHERE id = :sub AND estatus = 1 LIMIT 1
```
Si el usuario no existe o está inactivo (`estatus != 1`), el API rechaza la solicitud con `401 Unauthorized`.

---

## 4. AISLAMIENTO POR ESTACIÓN (`Station Scope Enforcement`)

El API V2 aplica un modelo estricto de aislamiento por estación (Multitenancy Server-Side) para evitar que supervisores accedan o autoricen solicitudes fuera de su jurisdicción:

1. **Confianza Cero en el Cliente:** El API **NUNCA** confía en `id_estacion` enviado en el cuerpo JSON o parámetros de consulta del cliente web.
2. **Resolución Server-Side:** Las estaciones autorizadas para un supervisor se obtienen directamente de las tablas de permisos de usuario (`localidades_perfil` o asignación de personal).
3. **Validación de Operaciones:** En las acciones `AUTHORIZE`, `REJECT` y `CANCEL`, el API verifica que la estación de la solicitud coincida con una de las estaciones autorizadas del supervisor:
   ```sql
   WHERE id_estacion IN (:authorized_stations) AND id = :request_id
   ```
   Si un supervisor intenta operar sobre una solicitud de otra estación, el API responde `403 Forbidden`.
