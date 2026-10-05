# Temporary Access API Security Addendum V1.0

**Document type:** SECURITY ADDENDUM
**Status:** SECURITY ADDENDUM
**Version:** 1.0
**Date:** 2026-10-02
**Base contract:** `TEMPORARY_ACCESS_API_CONTRACT V1.0`
**Architecture authority:** `CHECADOR_V2_ARCHITECTURE_FREEZE V1.0`
**Security finding:** SEC-L03 — Functional Supervisor Permission
**Implementation status:** IMPLEMENTED_PENDING_REAUDIT

## 1. Governance and compatibility

This addendum supplements the frozen Temporary Access API Contract V1.0 for the SEC-L03 human authorization boundary. It does not replace or modify that contract, its paths, request/response payloads, state transitions, or the Lector Device Auth contract.

The Architecture Freeze remains authoritative and unchanged. This addendum records the approved human function-permission and station-scope enforcement needed by Portal3 supervisor operations. Closure remains pending the separate G8-B0.4 read-only re-audit.

## 2. Human identity and JWT

Portal3 human identity is `tb_usuarios.id`, carried as JWT `sub`. The canonical issuer is exactly `portal3_auth`; the API requires the issuer claim and rejects a missing or different value. JWT algorithm remains HS256, and human supervisor operations require an unexpired `type=access` token.

An API user is active only when `tb_usuarios.estatus = 0`. A missing/inactive user is rejected. User lookup failures do not create an authenticated fallback identity.

JWTs carry no authorization claims for this feature: no roles, permissions, or station IDs. The API resolves grants and station scope from Portal3 data on each request.

## 3. Functional permission

The function ACL is sourced from `modulos_dpto_operativo`, `modulos_usuarios_do`, and `modulos_puestos_do`. The module key is `acceso-temporal`; that module must exist and be active. Its station configuration in `tb_modulos_config` must also exist and be active.

- Queue listing requires `leer = 1`.
- Authorize, reject, and cancel require `editar = 1`.

The lookup is fresh for every request. Existing Portal3 precedence is preserved exactly: if the user has any direct grant in any module, only direct user grants are considered; otherwise the user's `id_puesto` grant is considered. User and puesto grants are never merged. Missing grants and zero flags deny access. There is no implicit super-admin bypass.

## 4. Server-side station scope

Station scope is derived only from the authenticated `tb_usuarios.id` and Portal3's server-side model: `tb_usuarios.id_gas`, `tb_usuarios.id_puesto`, `tb_multiestacion_usuario`, `tb_multiestacion_puesto`, and active module configuration.

The module configuration must provide a valid, active `acceso-temporal` station allowlist whose `tipo_departamento` is `localidades`. The user's home station or effective multiestación allowlist is intersected with `estaciones_soportadas`. Only active `tb_estaciones` rows (`estatus = 0`) are considered.

For each permitted station, the API maps `tb_estaciones.numlista` to `op_rh_localidades.numlista` and returns the resulting `op_rh_localidades.id` scope used by Temporary Access V2. Station IDs supplied by a browser are not an authorization source. Empty, invalid, unsupported, inactive, or unmappable scope fails closed. There is no numeric identity fallback through `op_rh_personal.id`, no use of `op_rh_localidades_perfil.usuario` as Portal3 identity, and no fallback based on matching numeric IDs.

## 5. Device endpoints

`POST /api/v2/temporary-access/requests` and `POST /api/v2/temporary-access/validate` remain protected by Device Auth. They do not require the human `acceso-temporal` ACL and do not use this addendum's supervisor permission check.

## 6. Denial and dependency errors

Invalid/missing JWT or inactive user returns 401. Missing/inactive module, absent grant, zero required flag, empty scope, or cross-station operation returns 403. ACL, active-user, or station-scope database dependency failures return 503. Unexpected internal failures remain 500. No authorize/reject/cancel mutation is invoked after a human authorization failure.

## 7. Data and rollout boundary

This implementation does not create the `acceso-temporal` module row, user grants, puesto grants, or station configuration in any database. No real-user grant is implied. A later, separately authorized configuration slice must establish the module and explicit grants after re-audit. Production deployment is not authorized by this addendum.
