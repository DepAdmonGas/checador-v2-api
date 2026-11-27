# Procesador de jornadas — propuesta de autorización PREPROD

**Estado:** diseño y preparación del perfil aprobados por el propietario; activación, validación operativa y producción no autorizadas. No habilita PREPROD, producción, cron ni escrituras.

## Cambio mínimo propuesto

Parámetros propuestos por el propietario: servidor `82e9a5d.online-server.cloud`, Debian 12, MariaDB 10.11.19, PHP CLI `/opt/plesk/php/8.4/bin/php` 8.4.28, usuario de sistema `admongas` UID 10000/GID 1003; SQL `localhost:3306`, base `bd_admongas_v2_preprod`, usuario `checador_motor_preprod@localhost`. Principal institucional `SERVICE` 1, `journey-preprod`, permiso `checador.comodines.leer`, módulo `checador_journey_service`, estaciones físicas `[1,2,3,4,5,6,7,8,14]`, vigencia propuesta hasta `2026-12-19 06:00:00 UTC` exclusiva. Datos informados por el propietario, no verificados por esta ejecución.

El propietario aprobó para preparación los perfiles `development`/`journey-local` y `preprod`/`journey-preprod`. Informa que la cuenta SQL existe, tiene SELECT en 26 tablas y quedó bloqueada; también verificó la configuración SERVICE ID 51 y grant ID 25. El autorizador reconoce `journey-preprod` tras la validación del perfil y de la conexión; no se declara validada la autorización efectiva durante una ejecución del Motor.

El reconocimiento en `JourneyEnvironmentProfile::isPreparedPreprod` no concede acceso. Los modos individual y batch requieren después una política protegida de root y verifican la conexión. Producción continúa denegada. El batch usa una clave separada para PREPROD; el checkpoint de Fedora permanece intacto.

El modelo propuesto confía en el sistema operativo administrado, root y el usuario del daemon MariaDB. Comprueba jerarquía del socket, política y marcador protegidos por root, socket explícito y atributos en la misma conexión. No es una prueba criptográfica de identidad del daemon; la protección supone que root y el usuario mysql no están comprometidos. La aprobación operativa y la verificación real de permisos/rutas siguen pendientes. No existe fallback TCP.

La implementación usa el socket Unix explícito, compara `DATABASE()`, `CURRENT_USER()` y atributos del servidor en la misma conexión y compara un marcador de host con la política protegida por root. El marcador no es secreto ni identifica criptográficamente MariaDB. El propietario verificó manualmente la vinculación entre el servicio y el socket; queda pendiente validar el recorrido real del CLI con su configuración efectiva y aprobar el modelo de confianza local para operación.

Extender la identidad SERVICE de desarrollo autorizada en `CHECADOR_V2_COMODINES_SERVICE_PRINCIPAL_ADDENDUM_V1.0.md` para permitir un perfil PREPROD explícito y separado. El Freeze y los contratos J1/J2 permanecen intactos. El perfil local continúa autorizado solo para `bd_admongas` en host local; `bd_admongas_v2_preprod` requiere la política root, modo autorizado y verificaciones de conexión. Producción no se contempla.

Antes de habilitar PREPROD se debe validar el grant institucional efectivo, UID, allowlist, propiedad de archivos y socket, conexión y cuenta en el recorrido real. El propietario informa que ya verificó desde PHP la conexión SQL por socket y los atributos de base, usuario y servidor; esa prueba no ejecutó el Motor. El diseño de preparación no autoriza desbloquear la cuenta, activar gates, ejecutar dry-run del Motor, escrituras ni cron.

Separar checkpoints y bloqueos batch por perfil, identidad de servidor, base y principal de servicio, además del ámbito ya usado. Validar directorio privado, propietario y permisos; no compartir checkpoint entre perfiles. El lock canónico de trabajador sigue siendo el existente en MariaDB, sin cambiar J1/J2.

## Preparación del servidor, pendiente de verificación

- El propietario reporta PHP CLI 8.4.28 con `mysqli`, `json`, `posix` y demás extensiones disponibles; la ejecución del Motor con esa configuración permanece sin verificar y debe realizarse sin root.
- Archivo de configuración privado fuera del árbol público, con dueño autorizado, modo 0600, UID del proceso y estaciones explícitas. Variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` se inyectan privadamente; no registrarlas. El servicio no debe heredar credenciales de un entorno distinto.
- Privilegios SQL mínimos deben determinarse mediante revisión por operación: SELECT de programación, personal, identidad, políticas, evidencia y autorización; INSERT/UPDATE estrictamente necesarios para materialización, Level 2 y revisiones J1/J2. No conceder DDL ni DELETE por este documento. Comprobar privilegios y tablas reales antes de aprobarlos.
- El checkpoint existente se guarda en el directorio del archivo de configuración, cuyo acceso debe ser privado y escribible por el UID. Aislarlo por entorno y conservarlo para recuperación. El dry-run no lo modifica.
- Enviar stdout/stderr a un destino privado con rotación y retención controladas; el resultado incluye identificadores de trabajador, estación y fecha, por lo que el log exige acceso restringido. No registrar contraseñas, tokens, PIN ni datos biométricos.
- Cron o temporizador, si se autoriza, ejecutará el batch una vez por minuto con el mismo UID/configuración. El lock batch actual es por clave de configuración y el lock del trabajador es MariaDB; comprobar que no existan configuraciones alternativas que permitan ejecución concurrente incompatible.
- Suspensión: detener el programador, deshabilitar gates de ejecución y esperar el término o cierre del proceso activo. Recuperación: revisar el último error y checkpoint privado y reanudar solo tras corregir el problema; no borrar checkpoint a ciegas. No instalar ni activar el programador durante esta tarea.

## Validación previa

Pruebas de perfiles, identidad efectiva `DATABASE()`/`CURRENT_USER()` y servidor, host/puerto/TLS, UID, configuración y allowlists, gates, denegación de producción, dry-run de solo lectura, aislamiento de checkpoints y concurrencia interproceso; pruebas integradas autorizadas de materialización y recuperación J1/J2. Las pruebas realizadas con dobles no acreditan la identidad ni los permisos del servidor Plesk.

## Plantilla e instalación futura

La plantilla sin secretos está en `docs/v2/CHECADOR_JOURNEY_PREPROD_PRIVATE_CONFIGURATION_TEMPLATE.json`. Solo después de la autorización operativa, el administrador puede crear el directorio y copiarla fuera del DocumentRoot:

```bash
sudo install -d -o admongas -g 1003 -m 0700 /var/www/vhosts/admongas.com.mx/private/journey-preprod
sudo install -o admongas -g 1003 -m 0600 /var/www/vhosts/admongas.com.mx/api.admongas.com.mx/docs/v2/CHECADOR_JOURNEY_PREPROD_PRIVATE_CONFIGURATION_TEMPLATE.json /var/www/vhosts/admongas.com.mx/private/journey-preprod/config.json
```

Comando candidato sujeto a la política protegida de root, credenciales, cuenta SQL y aprobación operativa; sin ellas el código lo rechaza:

```bash
cd /var/www/vhosts/admongas.com.mx/api.admongas.com.mx
/opt/plesk/php/8.4/bin/php scripts/journey_processor.php --batch-dry-run --config=/var/www/vhosts/admongas.com.mx/private/journey-preprod/config.json
```

No colocar contraseñas en la plantilla, en comandos ni en Git. La cuenta informada permanece bloqueada y no se modifica mediante este documento.

Política administrativa `/etc/checador/journey-preprod.json`, propiedad root, archivo no escribible por grupo/otros y directorios de la ruta propiedad root no escribibles por grupo/otros. Campos JSON `profile_authorized`, `dry_run_authorized`, `single_write_authorized`, `batch_write_authorized` son booleanos independientes y deben permanecer `false` hasta la autorización correspondiente. `host_identity` debe coincidir con el contenido hexadecimal de 64 caracteres del archivo raíz `/etc/checador/journey-host-identity`, también protegido; el valor no se guarda en Git. La política se lee antes de cada conexión. La cuenta del proceso debe poder leer ambos archivos sin poder cambiarlos.

El entorno requiere `DB_HOST=localhost`, `DB_PORT=3306`, `DB_NAME=bd_admongas_v2_preprod`, `DB_USER=checador_motor_preprod` y `DB_PASS` inyectada en privado. La escritura individual mantiene además `execution_enabled=true` en config y `CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1`; V1.1 exige `CHECADOR_J1_V11_PERSISTENCE_ENABLED=1`. Batch requiere además `batch_execution_enabled=true`, fecha `batch_from_local_date` y `CHECADOR_JOURNEY_BATCH_EXECUTION_ENABLED=1`. Las variables no sustituyen autorizaciones root ni grants.
