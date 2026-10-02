# CHECADOR API V2 — SECURITY AND AUDIT ANALYSIS

**API Version:** V2.1  
**Audit Reference:** Slice V2.2-G8-A7 / G8-A7.1 / G8-A7.2 / G8-A7.3  
**Status:** Security Hardened & Audit Passed

---

## 1. RESUMEN DE CONTROLES DE SEGURIDAD IMPLEMENTADOS

| Control / Categoría | Mecanismo Implementado |
| :--- | :--- |
| **SQL Injection** | Interacción 100% mediante Prepared Statements (`mysqli_stmt`) y vinculación de parámetros. |
| **Password & Secret Storage** | Hashes `password_hash($secret, PASSWORD_BCRYPT)` para credenciales y PINs temporales. Zero plaintext secrets in DB or logs. |
| **Cache Leak Prevention** | Encabezados `Cache-Control: no-store, no-cache, must-revalidate` y `Pragma: no-cache` en respuestas HTTP de PINs temporales (`SEC-M01`). |
| **Fail-Closed Env Security** | Error `500` si `JWT_SECRET` está ausente o desconfigurado (`SEC-M02`). |
| **UUID Validation** | Sanitización y validación estricta de sintaxis UUIDv4 en endpoints (`SEC-L01`). |
| **Transaction Concurrency** | Bloqueos optimistas y pesimistas (`FOR UPDATE`) en transacciones MySQL InnoDB para evitar condiciones de carrera (Race Conditions). |
| **Lazy Expiration** | Transición perezosa y atómica de estados vencidos (`PENDING` / `AUTHORIZED` -> `EXPIRED`) en cada interacción HTTP. |
| **Brute-Force Protection** | Bloqueo automático (`status = 'LOCKED'`) al acumular 3 intentos fallidos de validación de PIN. |

---

## 2. CUSTODIA Y MANEJO DEL PIN TEMPORAL

1. **Generación:** PIN numérico de 6 dígitos mediante generador criptográficamente seguro (`random_int(100000, 999999)`).
2. **Hashing Inmediato:** El PIN en texto plano existe en memoria RAM del servidor únicamente durante el ciclo de vida del handler `authorize`. Se persiste en base de datos como hash `PASSWORD_BCRYPT`.
3. **Visibilidad Única (Single-View):** El PIN plano se retorna **una sola vez** en la respuesta JSON del endpoint `POST /authorize`. No es posible consultarlo o recuperarlo posteriormente mediante ningún endpoint.
4. **Búsqueda Segura:** Al validar un PIN (`POST /validate`), el API NO ejecuta búsquedas de hashes en tabla. Selecciona la solicitud activa candidata filtrada por `(id_dispositivo, id_estacion, id_personal_resuelto)` y ejecuta `password_verify($pin, $row['pin_hash'])` sobre la fila única devuelta.

---

## 3. RIESGOS RESIDUALES Y MITIGACIONES RECOMENDADAS

### 3.1 Nginx / Plesk Perimeter Rate Limiting
- **Riesgo:** Inundación de peticiones (DDoS / Denial of Service) sobre endpoints de validación o ingesta.
- **Recomendación:** Configurar reglas `limit_req` a nivel de servidor Web (Nginx/Plesk):
  ```nginx
  limit_req_zone $binary_remote_addr zone=api_v2_limit:10m rate=10r/s;
  
  location /api/v2/ {
      limit_req zone=api_v2_limit burst=20 nodelay;
  }
  ```

### 3.2 SEC-L03 — Supervisor Permission Model & Portal3 Integration (Pending G8-B)
- **Estado Actual:** El API V2 aplica aislamiento por estación (`Station Scope Enforcement`), garantizando que un supervisor solo interactúe con solicitudes pertenecientes a sus estaciones autorizadas.
- **Pendiente para Portal3 (Slice G8-B):** La definición del modelo fino de ACL/Permisos funcionales (distinguir roles de "Supervisor de Asistencia" vs "Operador") depende de las definiciones de Portal3 que se abordarán en el Slice G8-B.

---

## 4. AUDITORÍA DE INMUTABILIDAD DE HISTORIAL Y LOGS

- **No Plaintext Logging:** Se ha verificado formalmente en el Test 42 que ninguna función del sistema escribe PINs temporales, secrets de dispositivos o tokens de autorización en logs de archivo (`error_log`) ni en respuestas de error HTTP.
- **Trazabilidad de Auditoría:** Toda acción de cambio de estado sobre una solicitud de contingencia registra el timestamp UTC y el ID del usuario/sistema responsable (`authorized_by_user_id`, `rejected_by_user_id`, `cancelled_by_user_id`).
