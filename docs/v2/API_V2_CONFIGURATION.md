# CHECADOR API V2 — CONFIGURATION MANUAL

**API Version:** V2.1  
**Environment Requirements:** PHP 8.x, MySQL 5.7+ / MariaDB 10.3+, extensiones `mysqli`, `json`, `random`.

---

## 1. VARIABLES DE ENTORNO REQUERIDAS

| Variable | Descripción | Ejemplo / Valor por Defecto | Obligatorio |
| :--- | :--- | :--- | :--- |
| `JWT_SECRET` | Clave secreta compartida para la firma de tokens JWT de Portal3 (`HS256`). | `SuperSecretKeyPortal3_2026_Secure!` | **SÍ** (Fail-Closed 500) |
| `DB_HOST` | Host o IP del servidor de base de datos MySQL. | `localhost` | SÍ |
| `DB_USER` | Usuario con permisos de lectura/escritura en la base de datos. | `portal_user` | SÍ |
| `DB_PASS` | Contraseña de base de datos. | `SecretPassword123` | SÍ |
| `DB_NAME` | Nombre de la base de datos de Portal/Checador. | `portal3_db` | SÍ |

---

## 2. CONSTANTES CONFIGURABLES DEL SISTEMA

Las constantes operativas del módulo de Contingencia y Acceso Temporal están definidas en `modelo/v2/TemporaryAccessV2Service.php`:

```php
const REQUEST_TTL_MINUTES = 30; // Tiempo de vida de solicitud PENDING (30 minutos)
const PIN_TTL_MINUTES     = 15; // Tiempo de vida del PIN AUTHORIZED (15 minutos)
const PIN_LENGTH          = 6;  // Longitud exacta del PIN (6 dígitos numéricos)
const PIN_MAX_ATTEMPTS    = 3;  // Intentos fallidos máximos antes de LOCKED (3 intentos)
```

---

## 3. CONFIGURACIÓN DEL SERVIDOR WEB (NGINX / APACHE / PLESK)

### Ejemplo de Configuración Nginx (`/api/v2/` rewrite rule):
```nginx
location /portal-api-v.1.0.1/api/v2/ {
    try_files $uri $uri/ /portal-api-v.1.0.1/index.php?$args;
}

# Bloqueo preventivo de encabezados de cache para la API
location /portal-api-v.1.0.1/api/v2/temporary-access/ {
    add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0";
    add_header Pragma "no-cache";
}
```

---

## 4. ESTRUCTURA Y MIGRACIONES DE BASE DE DATOS

Las tablas necesarias para la operación de API V2 son:
- `op_rh_v2_dispositivos`
- `op_rh_v2_dispositivo_credenciales`
- `op_rh_v2_identidad_dispositivo_personal`
- `op_rh_v2_marcaciones_crudas`
- `op_rh_v2_solicitudes_acceso_temporal`

Las migraciones DDL oficiales se gestionan mediante el script de pruebas / inicialización.
