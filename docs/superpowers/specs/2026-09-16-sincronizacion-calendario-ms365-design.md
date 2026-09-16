# Diseño: Sincronización de horarios/novedades hacia el calendario de Outlook (Microsoft 365)

**Fecha**: 2026-09-16
**Estado**: Aprobado por el usuario, pendiente de plan de implementación.
**Contexto previo**: este tema estaba pendiente desde una sesión anterior con solo un análisis de seguridad hecho (ver `CLAUDE_horarios.md` sección 8 y `PROJECT_STATUS.md` sección 3.2) — cero código escrito. Este documento retoma y formaliza esa decisión (permiso de aplicación sobre delegado) y define el diseño completo.

## 1. Objetivo y alcance

Que los turnos programados (`horarios_base`) y las novedades aprobadas (`novedades`) de cada empleado aparezcan automáticamente en su calendario de Outlook, sin que nadie tenga que copiarlos a mano.

**Dentro del alcance**:
- Turnos del horario base (bloques recurrentes semanales), por vigencia.
- Novedades aprobadas (permisos, vacaciones, incapacidades, etc.), un evento puntual por novedad.
- Interruptor de activación por empleado, para poder hacer un piloto por área antes de expandir.
- Sincronización automática en el momento de guardar/aprobar (no manual, no por tarea programada).

**Fuera del alcance (explícitamente, para no ampliar el proyecto sin pedirlo)**:
- Marcaciones reales (`registros_tiempo`) — el usuario decidió que NO se sincronizan.
- Lectura del calendario del empleado (esto es solo escritura, una sola vía: del sistema hacia Outlook).
- Cualquier UI para que el empleado edite el evento directamente en Outlook y eso se refleje de vuelta en el sistema.

## 2. Infraestructura de Microsoft 365 requerida (fuera del código)

La sincronización usa **permiso de aplicación** (`Calendars.ReadWrite` en Microsoft Graph, flujo *client credentials*) — completamente independiente del login delegado SSO que ya existe (`Ms365AuthService`, permisos `openid profile email User.Read`). Nunca se debe reutilizar el mismo App Registration para ambas cosas: un secreto comprometido de este nuevo registro solo podría escribir calendarios, nunca iniciar sesión como nadie.

Pasos que el usuario debe ejecutar manualmente en los portales de Microsoft (no hay UI dentro de la app para esto, igual que ya pasó con el App Registration del login):

1. **Nuevo App Registration en Entra ID**, separado del de login SSO, con permiso de aplicación `Calendars.ReadWrite` (Microsoft Graph), consentimiento de administrador otorgado.
2. **Application Access Policy en Exchange Online**, apuntando a un grupo de seguridad que contenga solo los buzones de los empleados que participan en la sincronización — acota el permiso de aplicación (que por defecto aplicaría a *todo* el tenant) a ese grupo. Este grupo se administra desde el portal de Microsoft 365/Exchange Online, no desde la app.
3. Anotar tenant id, client id y client secret del nuevo App Registration para pegarlos en la pantalla de configuración de la app (sección 5).

## 3. Modelo de datos

### 3.1. `configuracion_calendario_ms365` (fila única, id=1, mismo patrón que `configuracion_ms365`)

```sql
CREATE TABLE IF NOT EXISTS configuracion_calendario_ms365 (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(100) NULL,
    client_id VARCHAR(100) NULL,
    client_secret VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO configuracion_calendario_ms365 (id) VALUES (1);
```

`activo` es el interruptor maestro: permite pausar toda la integración sin tocar el interruptor de cada empleado uno por uno.

### 3.2. `empleados.sincronizar_calendario`

```sql
ALTER TABLE empleados ADD COLUMN sincronizar_calendario TINYINT(1) NOT NULL DEFAULT 0 AFTER area_id;
```

Interruptor por empleado, editable desde `/empleados/{id}/editar` (visible solo si el empleado tiene una cuenta de usuario vinculada — sin eso no hay correo al cual escribir).

### 3.3. `eventos_calendario_ms365` (tabla de mapeo local ↔ evento de Graph)

**Hallazgo clave que determina esta tabla**: `HorarioBaseModel::actualizarVigencia()` borra y reinserta TODAS las filas de `horarios_base`/`horarios_base_bloques` de una vigencia en cada edición (mismo patrón que `RolModel::sincronizarPermisos`) — el `id` de un bloque **nunca sobrevive una edición**, así que no puede ser la llave de este mapeo. Lo único estable en una vigencia es `(empleado_id, vigente_desde)`, que el controlador nunca cambia al editar. Por eso la sincronización de horarios trabaja a nivel de **vigencia completa**, no de bloque individual: cada guardado/edición regenera únicamente los eventos de esa vigencia, comparando el estado nuevo contra lo que ya había en esta tabla.

Las novedades sí son estables (`novedades.id` nunca cambia, solo su `estado` vía `aprobar()`/`rechazar()`), así que ahí es un mapeo 1 a 1 sin esta complicación.

```sql
CREATE TABLE IF NOT EXISTS eventos_calendario_ms365 (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    novedad_id INT UNSIGNED NULL,
    horario_vigente_desde DATE NULL,
    horario_dia_semana TINYINT UNSIGNED NULL,
    horario_orden TINYINT UNSIGNED NULL,
    ms_calendar_id VARCHAR(150) NOT NULL,
    ms_event_id VARCHAR(150) NULL,
    estado ENUM('sincronizado', 'error') NOT NULL DEFAULT 'error',
    error_mensaje VARCHAR(500) NULL,
    sincronizado_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evento_novedad (novedad_id),
    UNIQUE KEY uq_evento_horario (empleado_id, horario_vigente_desde, horario_dia_semana, horario_orden),
    CONSTRAINT chk_evento_calendario_tipo CHECK (
        (novedad_id IS NOT NULL AND horario_vigente_desde IS NULL)
        OR
        (novedad_id IS NULL AND horario_vigente_desde IS NOT NULL)
    ),
    CONSTRAINT fk_eventoscalendario_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventoscalendario_novedad FOREIGN KEY (novedad_id) REFERENCES novedades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`ms_calendar_id` se repite en cada fila a propósito (denormalizado): evita una tabla/join adicional solo para un valor que casi nunca cambia por empleado, y el servicio puede reutilizar el de cualquier fila existente de ese empleado antes de preguntarle a Graph si ya existe el calendario "Horario de trabajo".

## 4. `CalendarioMs365Service`

Nuevo servicio, autenticado contra Graph vía *client credentials* (no reutiliza `Ms365AuthService`, que es para el login delegado).

- **`activo(): bool`** — `configuracion_calendario_ms365.activo` más tenant/client id/secret configurados. Toda la clase debe volverse no-op si esto es falso.
- **`sincronizarVigencia(empleadoId, vigenteDesde): void`** — llamada tras `HorarioBaseModel::crearVigencia()`/`actualizarVigencia()`. Compara los bloques ACTUALES de esa vigencia contra las filas ya presentes en `eventos_calendario_ms365` para `(empleadoId, vigenteDesde)`:
  - Bloque que ya tenía evento → `PATCH` (actualiza horario/fecha fin de la serie recurrente).
  - Bloque nuevo → `POST` (crea serie recurrente semanal por ese día, acotada a `vigente_hasta` si existe, sin fin si no).
  - Bloque que ya no está → `DELETE` del evento en Graph + de la fila de mapeo.
- **`eliminarVigencia(empleadoId, vigenteDesde): void`** — tras `HorarioBaseModel::eliminarVigencia()`: borra todos los eventos de esa vigencia.
- **`sincronizarNovedad(novedadId): void`** — tras `NovedadModel::aprobar()`: crea un evento puntual (no recurrente) ese día — de día completo si `hora_inicio`/`hora_fin` son NULL, con hora si son parciales — con el tipo y el comentario/motivo en la descripción.
- **`eliminarNovedad(novedadId): void`** — tras `NovedadModel::rechazar()`: por seguridad, si esa novedad ya tenía un evento sincronizado (se aprobó y luego se corrigió a rechazada), lo borra.
- **`reintentar(int $eventoCalendarioId): void`** — usada por el botón "Reintentar" de la pantalla de administración; vuelve a ejecutar `sincronizarVigencia`/`sincronizarNovedad` para el origen de esa fila.

**Reglas comunes a todos los métodos anteriores**:
- Si `!activo()` o el empleado tiene `sincronizar_calendario = 0`, no hacen absolutamente nada (ni una llamada HTTP).
- Un fallo de Graph en un evento puntual (buzón sin licencia, token inválido, límite de tasa, etc.) deja esa fila en `estado = 'error'` con el motivo, pero **nunca** interrumpe el procesamiento de los demás eventos de la misma operación ni revierte el guardado local del horario/novedad.
- Cada llamada se envuelve además en try/catch en el propio controlador (cinturón de seguridad extra): ninguna excepción inesperada del servicio puede tumbar el flujo de guardar un horario o aprobar una novedad.

### Puntos de integración

| Acción del usuario | Método local ya existente | Llamada nueva |
|---|---|---|
| Crear vigencia de horario | `HorarioController::guardar()` | `sincronizarVigencia()` |
| Editar vigencia de horario | `HorarioController::actualizar()` | `sincronizarVigencia()` |
| Eliminar vigencia de horario | `HorarioController::eliminar()` | `eliminarVigencia()` |
| Aprobar novedad | `NovedadController::aprobar()` | `sincronizarNovedad()` |
| Rechazar novedad | `NovedadController::rechazar()` | `eliminarNovedad()` |
| Activar el interruptor de un empleado | `EmpleadoController::actualizar()` | `sincronizarVigencia()` para cada vigencia vigente hoy |
| Desactivar el interruptor de un empleado | `EmpleadoController::actualizar()` | borra todos los eventos ya sincronizados de ese empleado |

**Nota de rendimiento aceptada explícitamente por el usuario**: al elegir "automático al guardar" en vez de un botón manual o una tarea programada, una vigencia con varios días/bloques (ej. 7 días × 2 bloques = hasta 14 eventos) puede añadir varios segundos a la respuesta de "Guardar horario" mientras se procesan las llamadas a Graph. Aceptable para 17 empleados; se documenta aquí para que no sorprenda si crece la plantilla.

## 5. UI nueva

### 5.1. Interruptor por empleado (`/empleados/{id}/editar`)

Checkbox "Sincronizar con calendario de Outlook", junto al campo de correo de la cuenta de acceso (visible solo si el empleado tiene cuenta vinculada). Comportamiento al guardar el formulario:
- Pasa de apagado a encendido → se sincronizan de inmediato las vigencias de horario vigentes hoy (no hay que esperar a la próxima edición para ver algo en el calendario).
- Pasa de encendido a apagado → se borran todos los eventos que ya se habían creado en su calendario "Horario de trabajo" (para que "apagado" signifique que no queda nada ahí).

### 5.2. Pantalla de administración `/admin/calendario-ms365`

Permiso nuevo `admin.calendario_ms365`, solo rol Administrador (mismo patrón que `admin.ms365`).

- Formulario de configuración: tenant id / client id / client secret (el secreto en blanco conserva el guardado, mismo patrón que `ConfiguracionMs365Model::guardar()`), e interruptor maestro `activo`.
- Lista de sincronizaciones en `estado = 'error'`: empleado, si es horario o novedad, el mensaje de error, fecha del último intento, botón "Reintentar" por fila.

## 6. Pruebas

- PHPUnit para la lógica de comparación de `sincronizarVigencia()` (qué se crea/actualiza/borra al cambiar los bloques de una vigencia) inyectando un cliente Graph *fake*/mock — sin llamadas HTTP reales en la suite, siguiendo el mismo principio que el resto de pruebas del proyecto (aisladas, repetibles).
- Casos mínimos a cubrir: vigencia nueva (todo se crea), vigencia editada agregando un bloque (uno se actualiza, uno se crea), vigencia editada quitando un bloque (uno se borra), novedad aprobada luego rechazada (el evento se borra), Graph devolviendo error en un evento (los demás de la misma operación igual se procesan, el guardado local no se revierte).
- Verificación manual end-to-end contra un tenant de prueba de Microsoft 365 antes de darlo por bueno en producción — mismo criterio que se siguió para el SSO de MS365.

## 7. Explícitamente fuera de este spec (decidido con el usuario)

- No se sincronizan marcaciones reales (`registros_tiempo`).
- No hay lectura desde Outlook hacia el sistema (una sola vía).
- No hay tarea programada/cron ni botón de sincronización manual masiva — todo es automático al guardar.
- No se reutiliza el App Registration del login SSO.
