# Ventana de entrada — contrato V1.2

Estado: acción autorizada por el propietario; validación de integración pendiente. Complementa V1.1 sin modificarla ni añadir rutas.

Cuando una nueva captura física supera los controles de dispositivo, identidad, estación y entrada anticipada, pero su dirección debe determinarla J1, la API puede responder HTTP 200 con:

```json
{"status":"success","data":{"gatekeeper_action":"ALLOW_PENDING_CLASSIFICATION","is_entry_allowed":null,"should_record_punch":true,"inferred_direction":null,"external_worker_reference":"worker-ref","workplace_id":1,"fecha_local":"2026-11-27","timezone":"America/Mexico_City","policy_version":6,"desktop_ui_message":{"header":"MARCACIÓN REGISTRADA","detail":"Marcación pendiente de clasificación.","card_color":"GREEN","sound_alert":"CONFIRMATION_SUCCESS"}}}
```

El Lector conserva la captura física con su UUID, secuencia y hora originales, sin presentarla como entrada ni salida confirmada. Un reintento técnico conserva el UUID original; una nueva captura física utiliza su identidad propia. La ventana de repetición procede de M11, no convierte el hecho en duplicado técnico. Los conflictos reales, la falta de autorización y el rechazo confirmado por entrada anticipada no se eluden con esta acción. La política histórica conserva su comportamiento anterior y la captura offline sigue el contrato V1.1.
