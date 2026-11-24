# CHECADOR V2 — Station Biometric Roster API Contract Draft V1.1

**Status:** OWNER APPROVED FOR PREPRODUCTION IMPLEMENTATION (2026-11-24); not frozen, route registered locally, not deployed, no runtime HTTP evidence.
**Supersedes for reconciliation only:** the unapproved Windows handoff draft dated 2026-11-24. It does not modify Architecture Freeze V1.0 or any frozen contract.

## Purpose and boundary

Read-only `GET /api/v2/devices/station/biometric-roster` supplies the station's active native workers, device-specific external references, and fingerprint templates for in-memory Windows matching. The response is sensitive personal data. The owner approved this contract as the implementation basis for preproduction; route deployment remains gated by successful isolated MariaDB, security and compatibility tests.

## Authentication and scope

Every request requires `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <device secret>`, and `X-Supervisor-Token: <Portal3 JWT>`. API V2 validates the device with `DispositivoV2::autenticarCredencial`, validates the human token with `Portal3JwtAuthHelper::validateToken`, requires `biometricos.leer` through `authorizeBiometricos`, and maps the device's physical `tb_estaciones.id` to one canonical `op_rh_localidades.id` with `WorkplacePhysicalStationResolver`. The canonical locality must be in the human user's freshly resolved authorized station scope. Client-supplied station IDs are not accepted.

Device `id_estacion` and worker `id_estacion_adscripcion` are physical `tb_estaciones.id` values, matching `/api/v2/devices/context`. ACL station scope is canonical `op_rh_localidades.id`; IDs are compared only after the approved resolver maps between domains. Active Portal3 users and physical stations use `estatus = 0` per the existing helpers.

## Worker and identity eligibility

This draft includes only active native workers (`op_rh_personal.estado = 1`, `id_estacion` equal to the authenticated device's physical station) with exactly one current `op_rh_v2_dispositivo_identidad_personal` mapping for that device. The mapping must be active and within its validity interval; its `referencia_externa_personal` is returned verbatim as a JSON string and is the value the Lector sends to punch ingestion and temporary-access requests. Missing or ambiguous mappings fail closed and are not replaced by `op_rh_personal.id`. Proposed technical bounds are 1,000 workers per response and 2,048 decoded template bytes per worker; these limits remain subject to owner approval and runtime data evidence.

Legacy `op_rh_comodines_dia` rows are not roster eligibility evidence by themselves. No comodines are included until the owner approves a biometric eligibility rule that reconciles the canonical Rol de Comodines source with V2 workplace coverage and station/time validity. A worker's normal station membership and temporary coverage remain distinct.

## Data minimization

The API may select `op_rh_personal_acceso.huella` only. It must never select or return `pin`, password, hashes, JWT, or device secrets. Workers with a valid device identity and no fingerprint are included with `has_biometric_template: false` and `biometric_template: null`. Ambiguous duplicate access rows must not yield a template. Responses and errors must not be logged with template or token contents. Send `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` and `Pragma: no-cache`.

## Success response (proposed)

```json
{
  "code": 200,
  "status": "OK",
  "id_estacion": 7,
  "station_name": "Synthetic Station",
  "total_workers": 1,
  "workers_with_biometrics": 0,
  "timestamp": "2026-11-24T12:00:00-06:00",
  "workers": [
    {
      "external_worker_reference": "reader-ref-17",
      "id_personal": 17,
      "no_colaborador": 1017,
      "nombre": "Synthetic Worker",
      "puesto": "Synthetic Position",
      "id_estacion_adscripcion": 7,
      "es_comodin": false,
      "has_biometric_template": false,
      "biometric_template": null
    }
  ]
}
```

`id_estacion` is physical `tb_estaciones.id`, not canonical locality ID. Required JSON types: `code`/IDs/counts are integers, `status`/name/reference/worker text/timestamp are strings, flags are booleans, template is string or null, and workers is an array. `pin` and `password` are forbidden keys.

## Error boundary (proposed)

`400 MISSING_HEADERS`; `401 DEVICE_UNAUTHORIZED` or `401 SUPERVISOR_UNAUTHORIZED`; `403 FUNCTION_PERMISSION_DENIED` or `403 STATION_JURISDICTION_MISMATCH`; `404 STATION_NOT_FOUND`; `503 ROSTER_UNAVAILABLE`. All bodies are JSON, contain no sensitive data, and fail closed. There is no fallback to legacy routes.

## Approval and verification gates

Before preproduction deployment: confirmation of the physical schema on the intended runtime; isolated MariaDB authorization and payload tests; Windows fail-closed and DTO fixes; and a real authorized HTTP 200 from the Lector followed by supervised physical tests. Owner approval is recorded for the native-worker contract and controlled template exposure. Existing Windows 106/106 and handoff mock 10/10 results do not verify this endpoint.

## Windows DTO compatibility

The supplied `DescargarCatalogoBiometricoEstacionAsync` reads `workers`, `external_worker_reference`, `nombre`, `puesto`, and `biometric_template`; the proposed names and scalar types are compatible with those reads. It currently does not validate the envelope or worker data, assigns the forbidden `pin` field, falls back to alternate identities/template names, and allows a null catalog; therefore client compatibility is only structural, not acceptance-tested. `Empleado.idpersonal` is a string and receives the external reference. API `code` is integer while the method locally sets `Resultado.code` to string; it does not deserialize that field.

The client must validate `code == 200`, `status == "OK"`, physical station context, unique nonempty references, field types, bounded worker/template sizes, and template Base64 before initializing biometrics. It must not read `pin`, write templates to disk, or log templates/JWT/DPAPI values. `ObtenerContextoDispositivoAsync()` success must be checked; `station_name` must not be sourced from `device_name`; invalid context, failed login, denied/error/empty catalog must stop initialization and never call legacy `GetPersonal`/`GetEmpresa`.

## Non-goals and open decisions

This GET does not implement fingerprint enrollment (`/editar-huella`), inherited PIN handling (`/editar-pin`), or any other legacy operation. Those remain separate scope decisions. The route must not be deployed before the stated technical gates pass.
