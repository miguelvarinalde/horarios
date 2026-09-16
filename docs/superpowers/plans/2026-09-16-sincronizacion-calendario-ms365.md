# Sincronización de horarios/novedades con calendario de Outlook (Microsoft 365) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que los turnos programados (`horarios_base`) y las novedades aprobadas (`novedades`) de cada empleado aparezcan automáticamente, sin intervención manual, en un calendario dedicado ("Horario de trabajo") dentro de su buzón de Outlook.

**Architecture:** Un nuevo servicio `CalendarioMs365Service` (autenticado contra Microsoft Graph vía *client credentials*, permiso de aplicación — independiente del login SSO delegado ya existente) se engancha, como efecto secundario best-effort, a los puntos donde ya se guardan horarios y se aprueban/rechazan novedades. Una tabla de mapeo local (`eventos_calendario_ms365`) rastrea qué evento de Graph corresponde a qué horario/novedad, para poder actualizarlo o borrarlo en vez de duplicarlo. Ningún fallo de Microsoft Graph bloquea ni revierte el guardado local; los fallos quedan visibles en una pantalla de administración con reintento manual.

**Tech Stack:** PHP 8.1+ (MVC propio, sin framework), MySQL/MariaDB, `league/oauth2-client` (grant `client_credentials`, ya en `composer.json`), `guzzlehttp/guzzle` (dependencia transitiva ya instalada, usada aquí directamente para las llamadas REST a Graph), PHPUnit 10.

**Spec de referencia:** `docs/superpowers/specs/2026-09-16-sincronizacion-calendario-ms365-design.md` — léelo completo antes de empezar. Este plan no repite las secciones 1-2 (infraestructura de Microsoft 365 fuera de código) ni la sección 7 (fuera de alcance); esas partes no requieren código.

## Global Constraints

- PHP >= 8.1, PSR-4 autoload `App\` → `app/`, `Tests\` → `tests/` (ya configurado en `composer.json`).
- No se agregan dependencias nuevas de Composer — `league/oauth2-client` y `guzzlehttp/guzzle` ya están instalados.
- `vendor/bin/phpunit` debe seguir en verde (línea base: 27/27 pasando, 139 assertions) antes y después de cada tarea.
- Migraciones numeradas secuencialmente en `database/migrations/NNN_descripcion.sql` (última existente: `030_create_supervisor_areas.sql` → la siguiente es `031`). Seeds en `database/seeds/NNN_descripcion.sql` (última existente: `015_permiso_calculo_ejecutar_supervisor.sql` → la siguiente es `016`).
- Ningún fallo de Microsoft Graph puede bloquear ni revertir una operación local (guardar horario, aprobar/rechazar novedad, guardar empleado) — todas las llamadas al nuevo servicio se envuelven en `try/catch` en el propio controlador, además del manejo de errores interno del servicio.
- Todo cambio de UI se verifica manualmente en navegador contra el servidor local (`php -S localhost:8000 -t public`) antes de darlo por terminado — no basta con que compile.
- Todo cambio a `HorarioController`, `NovedadController` o `EmpleadoController` debe preservar exactamente el comportamiento y las validaciones de autorización ya existentes en esos métodos (ver `empleadoAutorizadoOAbortar()`, `puedeGestionarNovedad()`) — este plan solo AGREGA una llamada al nuevo servicio después de que la operación local ya tuvo éxito, nunca reemplaza lógica existente.
- Estilo de código: sin comentarios que expliquen QUÉ hace el código (los nombres ya lo dicen); comentarios solo para decisiones no obvias, igual que el resto del proyecto.

---

## Task 1: Esquema de base de datos

**Files:**
- Create: `database/migrations/031_create_configuracion_calendario_ms365.sql`
- Create: `database/migrations/032_add_sincronizar_calendario_a_empleados.sql`
- Create: `database/migrations/033_create_eventos_calendario_ms365.sql`
- Create: `database/seeds/016_permiso_admin_calendario_ms365.sql`

**Interfaces:**
- Produces: tabla `configuracion_calendario_ms365` (columnas `id`, `tenant_id`, `client_id`, `client_secret`, `activo`, `updated_at`); columna `empleados.sincronizar_calendario`; tabla `eventos_calendario_ms365` (columnas `id`, `empleado_id`, `novedad_id`, `horario_vigente_desde`, `horario_dia_semana`, `horario_orden`, `ms_calendar_id`, `ms_event_id`, `estado`, `error_mensaje`, `sincronizado_at`, `created_at`, `updated_at`); permiso `admin.calendario_ms365` asignado al rol Administrador. Todas las tareas siguientes dependen de este esquema.

- [ ] **Step 1: Crear la migración de `configuracion_calendario_ms365`**

```sql
-- database/migrations/031_create_configuracion_calendario_ms365.sql
-- Configuracion del App Registration de calendario (permiso de aplicacion,
-- independiente del App Registration de login SSO en configuracion_ms365).
-- Fila unica (id=1), igual patron que configuracion_ms365.
CREATE TABLE IF NOT EXISTS configuracion_calendario_ms365 (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(100) NULL,
    client_id VARCHAR(100) NULL,
    client_secret VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion_calendario_ms365 (id) VALUES (1);
```

- [ ] **Step 2: Crear la migración de `empleados.sincronizar_calendario`**

```sql
-- database/migrations/032_add_sincronizar_calendario_a_empleados.sql
ALTER TABLE empleados ADD COLUMN sincronizar_calendario TINYINT(1) NOT NULL DEFAULT 0 AFTER area_id;
```

- [ ] **Step 3: Crear la migración de `eventos_calendario_ms365`**

```sql
-- database/migrations/033_create_eventos_calendario_ms365.sql
-- Mapeo entre un horario/novedad local y su evento en Microsoft Graph.
-- La sincronizacion de horarios trabaja a nivel de VIGENCIA COMPLETA
-- (empleado_id + horario_vigente_desde), no de bloque individual: el id de
-- un bloque en horarios_base_bloques NUNCA sobrevive una edicion
-- (HorarioBaseModel::actualizarVigencia() borra y reinserta todo), asi que
-- la llave estable es (empleado_id, horario_vigente_desde, dia_semana,
-- orden). Las novedades si son estables (novedades.id nunca cambia), por
-- eso su llave es simplemente novedad_id.
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

- [ ] **Step 4: Crear el seed del permiso de administración**

```sql
-- database/seeds/016_permiso_admin_calendario_ms365.sql
INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.calendario_ms365', 'Configurar la sincronizacion de calendario Microsoft 365', 'admin');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), (SELECT id FROM permisos WHERE codigo = 'admin.calendario_ms365');
```

- [ ] **Step 5: Aplicar las migraciones y el seed en la base de pruebas local**

Run: `php scripts/migrate.php` seguido de `php scripts/seed.php` (o, si el proyecto usa una sola pantalla `/admin/actualizaciones` para ambos, verifícalo ahí en el navegador tras iniciar sesión como Administrador).
Expected: sin errores; `SHOW TABLES LIKE 'eventos_calendario_ms365'` devuelve la tabla, `DESCRIBE empleados` incluye `sincronizar_calendario`, y `SELECT * FROM permisos WHERE codigo='admin.calendario_ms365'` devuelve una fila.

- [ ] **Step 6: Correr la suite completa para confirmar que nada se rompió**

Run: `vendor/bin/phpunit`
Expected: `OK (27 tests, 139 assertions)` — sin cambios de comportamiento todavía, solo esquema nuevo.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/031_create_configuracion_calendario_ms365.sql database/migrations/032_add_sincronizar_calendario_a_empleados.sql database/migrations/033_create_eventos_calendario_ms365.sql database/seeds/016_permiso_admin_calendario_ms365.sql
git commit -m "Agrega esquema para sincronizacion de calendario Microsoft 365"
```

---

## Task 2: Modelos de datos (`ConfiguracionCalendarioMs365Model`, `EventoCalendarioMs365Model`)

**Files:**
- Create: `app/Models/ConfiguracionCalendarioMs365Model.php`
- Create: `app/Models/EventoCalendarioMs365Model.php`
- Test: `tests/Unit/EventoCalendarioMs365ModelTest.php`

**Interfaces:**
- Consumes: tabla `configuracion_calendario_ms365` y `eventos_calendario_ms365` (Task 1).
- Produces: `ConfiguracionCalendarioMs365Model::obtener(): array`, `ConfiguracionCalendarioMs365Model::guardar(string $tenantId, string $clientId, ?string $clientSecret, bool $activo): void`; `EventoCalendarioMs365Model::porVigencia(int $empleadoId, string $vigenteDesde): array`, `::porNovedad(int $novedadId): ?array`, `::porEmpleado(int $empleadoId): array`, `::calendarioIdDeEmpleado(int $empleadoId): ?string`, `::guardarHorario(array $datos): void`, `::guardarNovedad(array $datos): void`, `::conError(): array`, más `find(int $id): ?array` y `delete(int $id): bool` heredados de `App\Core\Model`. Usados por `CalendarioMs365Service` (Tasks 4-6) y por la pantalla de administración (Task 10).

- [ ] **Step 1: Escribir la prueba que falla para `EventoCalendarioMs365Model`**

```php
<?php
// tests/Unit/EventoCalendarioMs365ModelTest.php
namespace Tests\Unit;

use App\Models\EventoCalendarioMs365Model;
use Tests\TestCase;

class EventoCalendarioMs365ModelTest extends TestCase
{
    public function test_guardarHorario_crea_y_luego_actualiza_por_llave_natural(): void
    {
        $empleadoId = $this->crearEmpleado();

        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-01 08:00:00',
        ]);

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas);
        $this->assertSame('ev-1', $filas[0]['ms_event_id']);

        // Segunda llamada con la MISMA llave natural (empleado/vigencia/dia/orden) actualiza, no duplica.
        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-1-actualizado',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-02 08:00:00',
        ]);

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas, 'No debe duplicar la fila al reinsertar la misma llave natural');
        $this->assertSame('ev-1-actualizado', $filas[0]['ms_event_id']);
    }

    public function test_guardarNovedad_y_porNovedad(): void
    {
        $empleadoId = $this->crearEmpleado();
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', '2026-01-05');

        $this->assertNull(EventoCalendarioMs365Model::porNovedad($novedadId));

        EventoCalendarioMs365Model::guardarNovedad([
            'empleado_id' => $empleadoId,
            'novedad_id' => $novedadId,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-novedad-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-05 08:00:00',
        ]);

        $fila = EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertNotNull($fila);
        $this->assertSame('ev-novedad-1', $fila['ms_event_id']);
    }

    public function test_calendarioIdDeEmpleado_devuelve_null_sin_filas_y_el_id_una_vez_hay_una(): void
    {
        $empleadoId = $this->crearEmpleado();
        $this->assertNull(EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId));

        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-reutilizable',
            'ms_event_id' => 'ev-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-01 08:00:00',
        ]);

        $this->assertSame('cal-reutilizable', EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId));
    }
}
```

- [ ] **Step 2: Correr la prueba y verificar que falla**

Run: `vendor/bin/phpunit --filter EventoCalendarioMs365ModelTest`
Expected: FAIL — `Class "App\Models\EventoCalendarioMs365Model" not found`.

- [ ] **Step 3: Crear `ConfiguracionCalendarioMs365Model`**

```php
<?php
// app/Models/ConfiguracionCalendarioMs365Model.php
namespace App\Models;

use App\Core\Database;

class ConfiguracionCalendarioMs365Model
{
    public static function obtener(): array
    {
        $row = Database::connection()->query('SELECT * FROM configuracion_calendario_ms365 WHERE id = 1')->fetch();
        return $row ?: ['tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'activo' => 0];
    }

    /**
     * Actualiza tenant_id/client_id/activo siempre; client_secret solo si
     * se envia un valor no vacio (mismo patron que ConfiguracionMs365Model).
     */
    public static function guardar(string $tenantId, string $clientId, ?string $clientSecret, bool $activo): void
    {
        $db = Database::connection();

        if ($clientSecret !== null && $clientSecret !== '') {
            $stmt = $db->prepare(
                'UPDATE configuracion_calendario_ms365 SET tenant_id = ?, client_id = ?, client_secret = ?, activo = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $clientSecret, $activo ? 1 : 0]);
        } else {
            $stmt = $db->prepare(
                'UPDATE configuracion_calendario_ms365 SET tenant_id = ?, client_id = ?, activo = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $activo ? 1 : 0]);
        }
    }
}
```

- [ ] **Step 4: Crear `EventoCalendarioMs365Model`**

```php
<?php
// app/Models/EventoCalendarioMs365Model.php
namespace App\Models;

use App\Core\Model;

class EventoCalendarioMs365Model extends Model
{
    protected static string $table = 'eventos_calendario_ms365';

    /** @return array<int, array> todas las filas de horario de una vigencia (cualquier dia/orden). */
    public static function porVigencia(int $empleadoId, string $vigenteDesde): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM eventos_calendario_ms365 WHERE empleado_id = ? AND horario_vigente_desde = ?'
        );
        $stmt->execute([$empleadoId, $vigenteDesde]);
        return $stmt->fetchAll();
    }

    public static function porNovedad(int $novedadId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM eventos_calendario_ms365 WHERE novedad_id = ?');
        $stmt->execute([$novedadId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array> todas las filas (horario y novedad) de un empleado. */
    public static function porEmpleado(int $empleadoId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM eventos_calendario_ms365 WHERE empleado_id = ?');
        $stmt->execute([$empleadoId]);
        return $stmt->fetchAll();
    }

    /** Reutiliza el calendario "Horario de trabajo" ya creado para este empleado, si existe alguna fila suya. */
    public static function calendarioIdDeEmpleado(int $empleadoId): ?string
    {
        $stmt = self::db()->prepare('SELECT ms_calendar_id FROM eventos_calendario_ms365 WHERE empleado_id = ? LIMIT 1');
        $stmt->execute([$empleadoId]);
        $valor = $stmt->fetchColumn();
        return $valor !== false ? $valor : null;
    }

    /** @return array<int, array> filas en estado 'error', con datos del empleado/novedad para la pantalla de administracion. */
    public static function conError(): array
    {
        return self::db()->query(
            "SELECT ec.*, e.nombre AS empleado_nombre, tn.nombre AS novedad_tipo_nombre, n.fecha AS novedad_fecha
             FROM eventos_calendario_ms365 ec
             JOIN empleados e ON e.id = ec.empleado_id
             LEFT JOIN novedades n ON n.id = ec.novedad_id
             LEFT JOIN tipos_novedad tn ON tn.id = n.tipo_novedad_id
             WHERE ec.estado = 'error'
             ORDER BY ec.updated_at DESC"
        )->fetchAll();
    }

    /**
     * Crea o actualiza (por la llave natural empleado+vigencia+dia+orden) la
     * fila de un bloque de horario. $datos debe traer: empleado_id,
     * horario_vigente_desde, horario_dia_semana, horario_orden,
     * ms_calendar_id, ms_event_id (nullable), estado, error_mensaje
     * (nullable), sincronizado_at (nullable).
     */
    public static function guardarHorario(array $datos): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO eventos_calendario_ms365
                (empleado_id, horario_vigente_desde, horario_dia_semana, horario_orden, ms_calendar_id, ms_event_id, estado, error_mensaje, sincronizado_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ms_calendar_id = VALUES(ms_calendar_id),
                ms_event_id = VALUES(ms_event_id),
                estado = VALUES(estado),
                error_mensaje = VALUES(error_mensaje),
                sincronizado_at = VALUES(sincronizado_at)'
        );
        $stmt->execute([
            $datos['empleado_id'],
            $datos['horario_vigente_desde'],
            $datos['horario_dia_semana'],
            $datos['horario_orden'],
            $datos['ms_calendar_id'],
            $datos['ms_event_id'],
            $datos['estado'],
            $datos['error_mensaje'],
            $datos['sincronizado_at'],
        ]);
    }

    /**
     * Crea o actualiza (por la llave natural novedad_id) la fila de una
     * novedad. Mismas claves que guardarHorario() pero con novedad_id en
     * vez de las 3 columnas de horario.
     */
    public static function guardarNovedad(array $datos): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO eventos_calendario_ms365
                (empleado_id, novedad_id, ms_calendar_id, ms_event_id, estado, error_mensaje, sincronizado_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ms_calendar_id = VALUES(ms_calendar_id),
                ms_event_id = VALUES(ms_event_id),
                estado = VALUES(estado),
                error_mensaje = VALUES(error_mensaje),
                sincronizado_at = VALUES(sincronizado_at)'
        );
        $stmt->execute([
            $datos['empleado_id'],
            $datos['novedad_id'],
            $datos['ms_calendar_id'],
            $datos['ms_event_id'],
            $datos['estado'],
            $datos['error_mensaje'],
            $datos['sincronizado_at'],
        ]);
    }
}
```

- [ ] **Step 5: Correr la prueba y verificar que pasa**

Run: `vendor/bin/phpunit --filter EventoCalendarioMs365ModelTest`
Expected: `OK (3 tests, ...)`

- [ ] **Step 6: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (30 tests, ...)` (27 anteriores + 3 nuevas).

- [ ] **Step 7: Commit**

```bash
git add app/Models/ConfiguracionCalendarioMs365Model.php app/Models/EventoCalendarioMs365Model.php tests/Unit/EventoCalendarioMs365ModelTest.php
git commit -m "Agrega modelos de configuracion y mapeo de eventos de calendario MS365"
```

---

## Task 3: `GraphCalendarClient` (transporte HTTP hacia Microsoft Graph) + doble de prueba

**Files:**
- Create: `app/Services/GraphCalendarClient.php`
- Create: `tests/Fakes/FakeGraphCalendarClient.php`

**Interfaces:**
- Consumes: `league/oauth2-client` (`GenericProvider`, grant `client_credentials`), `guzzlehttp/guzzle` (`GuzzleHttp\Client`, `GuzzleHttp\Exception\ClientException`) — ambos ya en `vendor/`.
- Produces: `GraphCalendarClient::__construct(string $tenantId, string $clientId, string $clientSecret)`, `->obtenerOCrearCalendario(string $email): string`, `->crearEvento(string $email, string $calendarId, array $payload): string`, `->actualizarEvento(string $email, string $calendarId, string $eventId, array $payload): void`, `->eliminarEvento(string $email, string $calendarId, string $eventId): void`. `Tests\Fakes\FakeGraphCalendarClient` (misma firma pública, sin red) — usado por `CalendarioMs365ServiceTest` en las Tasks 4-6.

**Nota sobre pruebas de esta tarea**: esta clase hace llamadas HTTP reales a Microsoft Graph; no se le escribe una prueba de PHPUnit propia (mismo criterio que ya aplica el proyecto a `Ms365AuthService`, que tampoco tiene pruebas automatizadas por la misma razón — ver `tests/Unit/`). Su verificación es manual, contra un tenant de prueba, en la Task 7 en adelante cuando ya esté enganchada a un flujo real. Lo que sí se prueba exhaustivamente es `CalendarioMs365Service` (Tasks 4-6), inyectándole el doble de prueba que se crea en el Step 3 de esta tarea.

- [ ] **Step 1: Crear `GraphCalendarClient`**

```php
<?php
// app/Services/GraphCalendarClient.php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Transporte HTTP puro hacia Microsoft Graph (calendarios/eventos), sin
 * conocer nada de horarios_base/novedades — esa traduccion vive en
 * CalendarioMs365Service. Usa permiso de APLICACION (client credentials),
 * a diferencia de Ms365AuthService (login delegado): un App Registration
 * completamente distinto, ver spec seccion 2.
 */
class GraphCalendarClient
{
    private const NOMBRE_CALENDARIO = 'Horario de trabajo';

    private GenericProvider $provider;
    private Client $http;
    private ?string $token = null;

    public function __construct(string $tenantId, string $clientId, string $clientSecret)
    {
        $this->provider = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'urlAuthorize' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            'urlResourceOwnerDetails' => '',
        ]);
        $this->http = new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']);
    }

    private function token(): string
    {
        if ($this->token === null) {
            $accessToken = $this->provider->getAccessToken('client_credentials', [
                'scope' => 'https://graph.microsoft.com/.default',
            ]);
            $this->token = $accessToken->getToken();
        }
        return $this->token;
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/json',
        ];
    }

    public function obtenerOCrearCalendario(string $email): string
    {
        $respuesta = $this->http->get("users/{$email}/calendars", [
            'headers' => $this->headers(),
            'query' => ['$filter' => "name eq '" . self::NOMBRE_CALENDARIO . "'"],
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        if (!empty($datos['value'][0]['id'])) {
            return $datos['value'][0]['id'];
        }

        $respuesta = $this->http->post("users/{$email}/calendars", [
            'headers' => $this->headers(),
            'json' => ['name' => self::NOMBRE_CALENDARIO],
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        return $datos['id'];
    }

    public function crearEvento(string $email, string $calendarId, array $payload): string
    {
        $respuesta = $this->http->post("users/{$email}/calendars/{$calendarId}/events", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        return $datos['id'];
    }

    public function actualizarEvento(string $email, string $calendarId, string $eventId, array $payload): void
    {
        $this->http->patch("users/{$email}/calendars/{$calendarId}/events/{$eventId}", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
    }

    /** Un 404 (el evento ya no existe en Graph) se trata como exito: el resultado deseado (que no exista) ya se cumple. */
    public function eliminarEvento(string $email, string $calendarId, string $eventId): void
    {
        try {
            $this->http->delete("users/{$email}/calendars/{$calendarId}/events/{$eventId}", [
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }
}
```

- [ ] **Step 2: Verificar que el proyecto compila (no hay prueba automatizada para esta clase, ver nota arriba)**

Run: `php -l app/Services/GraphCalendarClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Crear el doble de prueba `FakeGraphCalendarClient`**

```php
<?php
// tests/Fakes/FakeGraphCalendarClient.php
namespace Tests\Fakes;

use App\Services\GraphCalendarClient;

/**
 * Doble de prueba de GraphCalendarClient: no hereda su logica de red (la
 * sobreescribe por completo), solo registra las llamadas para que las
 * pruebas de CalendarioMs365Service puedan hacer aserciones sin tocar la
 * red. El constructor padre no hace I/O (Guzzle/GenericProvider no llaman a
 * nada hasta que se invoca un metodo HTTP), asi que pasar valores dummy es
 * seguro.
 */
class FakeGraphCalendarClient extends GraphCalendarClient
{
    /** @var array<int, array{email:string, payload:array}> */
    public array $eventosCreados = [];
    /** @var array<int, array{email:string, eventId:string, payload:array}> */
    public array $eventosActualizados = [];
    /** @var array<int, array{email:string, eventId:string}> */
    public array $eventosEliminados = [];
    /** @var array<string, string> email => calendarId ya "creado" */
    public array $calendariosPorEmail = [];

    private int $siguienteId = 1;

    /** @var array<string, true> event ids marcados para fallar en la proxima llamada */
    public array $fallarPara = [];

    public function __construct()
    {
        // No llama a parent::__construct(): evita construir un GenericProvider/Client reales innecesarios.
    }

    public function obtenerOCrearCalendario(string $email): string
    {
        return $this->calendariosPorEmail[$email] ??= 'cal-fake-' . $email;
    }

    public function crearEvento(string $email, string $calendarId, array $payload): string
    {
        $id = 'ev-fake-' . $this->siguienteId++;
        if (isset($this->fallarPara[$id])) {
            throw new \RuntimeException('Fallo simulado creando evento');
        }
        $this->eventosCreados[] = ['email' => $email, 'calendarId' => $calendarId, 'payload' => $payload];
        return $id;
    }

    public function actualizarEvento(string $email, string $calendarId, string $eventId, array $payload): void
    {
        if (isset($this->fallarPara[$eventId])) {
            throw new \RuntimeException('Fallo simulado actualizando evento');
        }
        $this->eventosActualizados[] = ['email' => $email, 'calendarId' => $calendarId, 'eventId' => $eventId, 'payload' => $payload];
    }

    public function eliminarEvento(string $email, string $calendarId, string $eventId): void
    {
        if (isset($this->fallarPara[$eventId])) {
            throw new \RuntimeException('Fallo simulado eliminando evento');
        }
        $this->eventosEliminados[] = ['email' => $email, 'calendarId' => $calendarId, 'eventId' => $eventId];
    }
}
```

- [ ] **Step 4: Verificar que el doble de prueba compila**

Run: `php -l tests/Fakes/FakeGraphCalendarClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add app/Services/GraphCalendarClient.php tests/Fakes/FakeGraphCalendarClient.php
git commit -m "Agrega cliente de Microsoft Graph para calendarios y su doble de prueba"
```

---

## Task 4: `CalendarioMs365Service` — `activo()`, `calendarioDe()`, `sincronizarVigencia()`, `eliminarVigencia()`

**Files:**
- Create: `app/Services/CalendarioMs365Service.php`
- Test: `tests/Unit/CalendarioMs365ServiceTest.php`
- Modify: `tests/TestCase.php` (helper para vincular un empleado a una cuenta de usuario con correo)

**Interfaces:**
- Consumes: `EventoCalendarioMs365Model` (Task 2), `Tests\Fakes\FakeGraphCalendarClient` (Task 3), `App\Models\EmpleadoModel::find()`/`::update()`, `App\Models\UsuarioModel::find()`, `App\Models\HorarioBaseModel::vigencia()` (ya existente).
- Produces: `CalendarioMs365Service::__construct(?GraphCalendarClient $cliente = null)`, `->activo(): bool`, `->sincronizarVigencia(int $empleadoId, string $vigenteDesde): void`, `->eliminarVigencia(int $empleadoId, string $vigenteDesde): void`. Usados por Tasks 5, 6, 7, 9.

- [ ] **Step 1: Agregar el helper `crearEmpleadoConCorreo()` a `TestCase`**

En `tests/TestCase.php`, agrega este método junto a `crearEmpleado()` (después de su definición):

```php
    /**
     * Igual que crearEmpleado(), pero ademas crea y vincula una cuenta de
     * usuario con el correo indicado — necesario para cualquier prueba de
     * CalendarioMs365Service, que resuelve el correo del empleado a traves
     * de usuario_id.
     */
    protected function crearEmpleadoConCorreo(string $email, bool $sincronizarCalendario = true): int
    {
        $empleadoId = $this->crearEmpleado();
        $usuarioId = $this->crearUsuarioDummy();
        $this->db->prepare('UPDATE usuarios SET email = ? WHERE id = ?')->execute([$email, $usuarioId]);
        $this->db->prepare('UPDATE empleados SET usuario_id = ?, sincronizar_calendario = ? WHERE id = ?')
            ->execute([$usuarioId, $sincronizarCalendario ? 1 : 0, $empleadoId]);
        return $empleadoId;
    }
```

- [ ] **Step 2: Escribir la prueba que falla para `sincronizarVigencia()`/`eliminarVigencia()`**

```php
<?php
// tests/Unit/CalendarioMs365ServiceTest.php
namespace Tests\Unit;

use App\Models\EventoCalendarioMs365Model;
use App\Models\HorarioBaseModel;
use App\Services\CalendarioMs365Service;
use Tests\Fakes\FakeGraphCalendarClient;
use Tests\TestCase;

class CalendarioMs365ServiceTest extends TestCase
{
    private FakeGraphCalendarClient $cliente;
    private CalendarioMs365Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = new FakeGraphCalendarClient();
        $this->service = new CalendarioMs365Service($this->cliente);

        // La integracion se activa globalmente vía configuracion_calendario_ms365
        // (fila unica). Se restaura al estado inactivo en tearDown para no
        // afectar otras pruebas ni el resto de la app.
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET tenant_id='t', client_id='c', client_secret='s', activo=1 WHERE id=1");
    }

    protected function tearDown(): void
    {
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET tenant_id=NULL, client_id=NULL, client_secret=NULL, activo=0 WHERE id=1");
        parent::tearDown();
    }

    public function test_sincronizarVigencia_crea_un_evento_recurrente_por_bloque(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado1@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]],
        ], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosCreados);
        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas);
        $this->assertSame('sincronizado', $filas[0]['estado']);
        $this->assertSame(1, (int) $filas[0]['horario_dia_semana']);
        $this->assertSame(1, (int) $filas[0]['horario_orden']);
    }

    public function test_sincronizarVigencia_reutiliza_el_calendario_ya_creado_del_empleado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado2@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->calendariosPorEmail, 'Debe llamar a Graph una sola vez para obtener/crear el calendario, no una por evento');
    }

    public function test_sincronizarVigencia_agregar_un_bloque_crea_uno_nuevo_y_deja_el_existente(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado3@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        // Simula que el usuario edito la vigencia agregando un segundo bloque el mismo dia (turno partido).
        HorarioBaseModel::actualizarVigencia($empleadoId, '2026-01-01', null, null, [
            1 => [['hora_inicio' => '08:00', 'hora_fin' => '12:00'], ['hora_inicio' => '14:00', 'hora_fin' => '18:00']],
        ]);
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosActualizados, 'El bloque 1 (08-12) ya existia: se actualiza');
        $this->assertCount(1, $this->cliente->eventosCreados, 'El bloque 2 (14-18) es nuevo: se crea');
        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(2, $filas);
    }

    public function test_sincronizarVigencia_quitar_un_bloque_borra_su_evento_en_graph_y_su_fila(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado4@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00'], ['14:00', '18:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $this->assertCount(2, EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));

        HorarioBaseModel::actualizarVigencia($empleadoId, '2026-01-01', null, null, [
            1 => [['hora_inicio' => '08:00', 'hora_fin' => '12:00']],
        ]);
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosEliminados);
        $this->assertCount(1, EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }

    public function test_sincronizarVigencia_no_hace_nada_si_la_integracion_esta_inactiva(): void
    {
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET activo=0 WHERE id=1");
        $empleadoId = $this->crearEmpleadoConCorreo('empleado5@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertEmpty($this->cliente->eventosCreados);
        $this->assertEmpty(EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }

    public function test_sincronizarVigencia_no_hace_nada_si_el_empleado_tiene_el_interruptor_apagado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado6@example.test', sincronizarCalendario: false);
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertEmpty($this->cliente->eventosCreados);
    }

    public function test_sincronizarVigencia_un_fallo_de_graph_deja_la_fila_en_error_sin_afectar_las_demas(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado7@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');

        // Fuerza que el primer evento creado falle: el fake asigna ids secuenciales, el primero sera 'ev-fake-1'.
        $this->cliente->fallarPara['ev-fake-1'] = true;

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $estados = array_column($filas, 'estado');
        sort($estados);
        $this->assertSame(['error', 'sincronizado'], $estados, 'Un evento en error no debe impedir que el otro se sincronice');
    }

    public function test_eliminarVigencia_borra_todos_los_eventos_de_esa_vigencia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado8@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->service->eliminarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(2, $this->cliente->eventosEliminados);
        $this->assertEmpty(EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }
}
```

- [ ] **Step 3: Correr la prueba y verificar que falla**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: FAIL — `Class "App\Services\CalendarioMs365Service" not found`.

- [ ] **Step 4: Crear `CalendarioMs365Service` con estos 4 métodos (los de novedad/reintento se agregan en Tasks 5 y 6)**

```php
<?php
// app/Services/CalendarioMs365Service.php
namespace App\Services;

use App\Models\ConfiguracionCalendarioMs365Model;
use App\Models\EmpleadoModel;
use App\Models\EventoCalendarioMs365Model;
use App\Models\HorarioBaseModel;
use App\Models\UsuarioModel;
use DateTimeImmutable;
use Throwable;

/**
 * Sincroniza horarios/novedades hacia un calendario dedicado ("Horario de
 * trabajo") en el buzon de Outlook de cada empleado, via permiso de
 * aplicacion de Microsoft Graph (GraphCalendarClient). Ver spec
 * docs/superpowers/specs/2026-09-16-sincronizacion-calendario-ms365-design.md.
 *
 * La sincronizacion de horarios trabaja a nivel de VIGENCIA COMPLETA
 * (empleado_id + vigente_desde), no de bloque individual, porque
 * HorarioBaseModel::actualizarVigencia() borra y reinserta todos los
 * bloques de una vigencia en cada edicion — el id de un bloque nunca es
 * estable entre ediciones. La llave estable usada aqui es
 * (empleado_id, vigente_desde, dia_semana, orden dentro del dia).
 *
 * Ningun fallo de Graph en un evento puntual detiene el procesamiento de
 * los demas eventos de la misma llamada, ni revierte nada localmente: la
 * fila de eventos_calendario_ms365 correspondiente queda en estado
 * 'error' con el motivo, visible en /admin/calendario-ms365 para
 * reintentar manualmente.
 */
class CalendarioMs365Service
{
    private ?GraphCalendarClient $clienteInyectado;

    public function __construct(?GraphCalendarClient $cliente = null)
    {
        $this->clienteInyectado = $cliente;
    }

    private function cliente(): GraphCalendarClient
    {
        if ($this->clienteInyectado === null) {
            $config = ConfiguracionCalendarioMs365Model::obtener();
            $this->clienteInyectado = new GraphCalendarClient(
                (string) $config['tenant_id'],
                (string) $config['client_id'],
                (string) $config['client_secret']
            );
        }
        return $this->clienteInyectado;
    }

    public function activo(): bool
    {
        $config = ConfiguracionCalendarioMs365Model::obtener();
        return !empty($config['activo'])
            && !empty($config['tenant_id'])
            && !empty($config['client_id'])
            && !empty($config['client_secret']);
    }

    public function sincronizarVigencia(int $empleadoId, string $vigenteDesde): void
    {
        if (!$this->activo()) {
            return;
        }
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado || empty($empleado['sincronizar_calendario']) || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        $vigencia = HorarioBaseModel::vigencia($empleadoId, $vigenteDesde);
        $diasNuevos = $vigencia['dias'] ?? [];
        $vigenteHasta = $vigencia['vigente_hasta'] ?? null;

        $existentes = EventoCalendarioMs365Model::porVigencia($empleadoId, $vigenteDesde);
        $existentesPorClave = [];
        foreach ($existentes as $ev) {
            $existentesPorClave[$ev['horario_dia_semana'] . ':' . $ev['horario_orden']] = $ev;
        }

        $calendarId = $this->calendarioDe($empleadoId, $email);

        $clavesNuevas = [];
        foreach ($diasNuevos as $diaSemana => $dia) {
            $orden = 1;
            foreach ($dia['bloques'] as $bloque) {
                $clave = $diaSemana . ':' . $orden;
                $clavesNuevas[$clave] = true;
                $existente = $existentesPorClave[$clave] ?? null;
                $payload = $this->payloadBloque((int) $diaSemana, $bloque, $vigenteDesde, $vigenteHasta);

                try {
                    if ($existente && $existente['ms_event_id']) {
                        $this->cliente()->actualizarEvento($email, $calendarId, $existente['ms_event_id'], $payload);
                        $eventId = $existente['ms_event_id'];
                    } else {
                        $eventId = $this->cliente()->crearEvento($email, $calendarId, $payload);
                    }
                    EventoCalendarioMs365Model::guardarHorario([
                        'empleado_id' => $empleadoId,
                        'horario_vigente_desde' => $vigenteDesde,
                        'horario_dia_semana' => $diaSemana,
                        'horario_orden' => $orden,
                        'ms_calendar_id' => $calendarId,
                        'ms_event_id' => $eventId,
                        'estado' => 'sincronizado',
                        'error_mensaje' => null,
                        'sincronizado_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (Throwable $e) {
                    EventoCalendarioMs365Model::guardarHorario([
                        'empleado_id' => $empleadoId,
                        'horario_vigente_desde' => $vigenteDesde,
                        'horario_dia_semana' => $diaSemana,
                        'horario_orden' => $orden,
                        'ms_calendar_id' => $calendarId,
                        'ms_event_id' => $existente['ms_event_id'] ?? null,
                        'estado' => 'error',
                        'error_mensaje' => substr($e->getMessage(), 0, 500),
                        'sincronizado_at' => null,
                    ]);
                }
                $orden++;
            }
        }

        foreach ($existentesPorClave as $clave => $ev) {
            if (isset($clavesNuevas[$clave])) {
                continue;
            }
            if ($ev['ms_event_id']) {
                try {
                    $this->cliente()->eliminarEvento($email, $ev['ms_calendar_id'], $ev['ms_event_id']);
                } catch (Throwable $e) {
                    // Best effort: se elimina igual la fila local para no reintentar por siempre un evento fantasma.
                }
            }
            EventoCalendarioMs365Model::delete((int) $ev['id']);
        }
    }

    public function eliminarVigencia(int $empleadoId, string $vigenteDesde): void
    {
        if (!$this->activo()) {
            return;
        }
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        foreach (EventoCalendarioMs365Model::porVigencia($empleadoId, $vigenteDesde) as $ev) {
            if ($ev['ms_event_id']) {
                try {
                    $this->cliente()->eliminarEvento($email, $ev['ms_calendar_id'], $ev['ms_event_id']);
                } catch (Throwable $e) {
                }
            }
            EventoCalendarioMs365Model::delete((int) $ev['id']);
        }
    }

    /** Reutiliza el calendario ya creado para este empleado (cualquier fila existente); si no hay ninguna, se lo pide a Graph. */
    private function calendarioDe(int $empleadoId, string $email): string
    {
        $existente = EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId);
        return $existente ?? $this->cliente()->obtenerOCrearCalendario($email);
    }

    /** @param array{hora_inicio:string, hora_fin:string} $bloque */
    private function payloadBloque(int $diaSemana, array $bloque, string $vigenteDesde, ?string $vigenteHasta): array
    {
        $diasIngles = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
        $primeraFecha = $this->primeraFechaDesde($vigenteDesde, $diaSemana);

        $rango = $vigenteHasta !== null
            ? ['type' => 'endDate', 'startDate' => $primeraFecha, 'endDate' => $vigenteHasta]
            : ['type' => 'noEnd', 'startDate' => $primeraFecha];

        return [
            'subject' => 'Turno de trabajo',
            'body' => ['contentType' => 'text', 'content' => 'Generado automaticamente por el Sistema de Gestion de Horarios.'],
            'start' => ['dateTime' => $primeraFecha . 'T' . $this->normalizarHora($bloque['hora_inicio']), 'timeZone' => 'America/Bogota'],
            'end' => ['dateTime' => $primeraFecha . 'T' . $this->normalizarHora($bloque['hora_fin']), 'timeZone' => 'America/Bogota'],
            'recurrence' => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [$diasIngles[$diaSemana]]],
                'range' => $rango,
            ],
            'isReminderOn' => false,
        ];
    }

    /** Primera fecha en o despues de $desde cuyo dia de la semana (0=domingo..6=sabado) coincide con $diaSemana. */
    private function primeraFechaDesde(string $desde, int $diaSemana): string
    {
        $fecha = new DateTimeImmutable($desde);
        while ((int) $fecha->format('w') !== $diaSemana) {
            $fecha = $fecha->modify('+1 day');
        }
        return $fecha->format('Y-m-d');
    }

    private function normalizarHora(string $hora): string
    {
        return strlen($hora) === 5 ? $hora . ':00' : $hora;
    }
}
```

- [ ] **Step 5: Correr la prueba y verificar que pasa**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: `OK (8 tests, ...)`

- [ ] **Step 6: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (38 tests, ...)` (30 anteriores + 8 nuevas).

- [ ] **Step 7: Commit**

```bash
git add app/Services/CalendarioMs365Service.php tests/Unit/CalendarioMs365ServiceTest.php tests/TestCase.php
git commit -m "Agrega CalendarioMs365Service: sincronizacion de vigencias de horario"
```

---

## Task 5: `CalendarioMs365Service` — `sincronizarNovedad()`, `eliminarNovedad()`

**Files:**
- Modify: `app/Services/CalendarioMs365Service.php`
- Modify: `tests/Unit/CalendarioMs365ServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\NovedadModel::conDetalle(int $id): ?array` (ya existente; incluye `empleado_id`, `fecha`, `hora_inicio`, `hora_fin`, `comentario`, `tipo_nombre`).
- Produces: `CalendarioMs365Service::sincronizarNovedad(int $novedadId): void`, `->eliminarNovedad(int $novedadId): void`. Usados por Task 8.

- [ ] **Step 1: Agregar las pruebas que fallan**

Agrega estos métodos dentro de la clase `CalendarioMs365ServiceTest` (después del último método existente, antes del `}` de cierre):

```php
    public function test_sincronizarNovedad_dia_completo_crea_un_evento_de_dia_completo(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad1@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-10');

        $this->service->sincronizarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosCreados);
        $this->assertTrue($this->cliente->eventosCreados[0]['payload']['isAllDay']);
        $fila = \App\Models\EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertSame('sincronizado', $fila['estado']);
    }

    public function test_sincronizarNovedad_parcial_crea_un_evento_con_hora(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad2@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', '2026-02-11', '11:00:00', '16:30:00');

        $this->service->sincronizarNovedad($novedadId);

        $payload = $this->cliente->eventosCreados[0]['payload'];
        $this->assertArrayNotHasKey('isAllDay', $payload);
        $this->assertStringContainsString('11:00:00', $payload['start']['dateTime']);
        $this->assertStringContainsString('16:30:00', $payload['end']['dateTime']);
    }

    public function test_sincronizarNovedad_llamada_dos_veces_actualiza_en_vez_de_duplicar(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad3@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-12');

        $this->service->sincronizarNovedad($novedadId);
        $this->service->sincronizarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosCreados);
        $this->assertCount(1, $this->cliente->eventosActualizados);
    }

    public function test_eliminarNovedad_borra_el_evento_y_la_fila_si_existia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad4@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-13');
        $this->service->sincronizarNovedad($novedadId);

        $this->service->eliminarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosEliminados);
        $this->assertNull(\App\Models\EventoCalendarioMs365Model::porNovedad($novedadId));
    }

    public function test_eliminarNovedad_no_hace_nada_si_nunca_se_habia_sincronizado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad5@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-14');

        $this->service->eliminarNovedad($novedadId);

        $this->assertEmpty($this->cliente->eventosEliminados);
    }
```

- [ ] **Step 2: Correr las pruebas y verificar que fallan**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: FAIL — `Call to undefined method App\Services\CalendarioMs365Service::sincronizarNovedad()`.

- [ ] **Step 3: Agregar `sincronizarNovedad()` y `eliminarNovedad()` a `CalendarioMs365Service`**

Agrega estos 3 métodos dentro de la clase, después de `eliminarVigencia()` (antes de los métodos privados `calendarioDe`/`payloadBloque`/etc.):

```php
    public function sincronizarNovedad(int $novedadId): void
    {
        if (!$this->activo()) {
            return;
        }
        $novedad = \App\Models\NovedadModel::conDetalle($novedadId);
        if (!$novedad) {
            return;
        }
        $empleado = EmpleadoModel::find((int) $novedad['empleado_id']);
        if (!$empleado || empty($empleado['sincronizar_calendario']) || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        $calendarId = $this->calendarioDe((int) $empleado['id'], $email);
        $existente = EventoCalendarioMs365Model::porNovedad($novedadId);
        $payload = $this->payloadNovedad($novedad);

        try {
            if ($existente && $existente['ms_event_id']) {
                $this->cliente()->actualizarEvento($email, $calendarId, $existente['ms_event_id'], $payload);
                $eventId = $existente['ms_event_id'];
            } else {
                $eventId = $this->cliente()->crearEvento($email, $calendarId, $payload);
            }
            EventoCalendarioMs365Model::guardarNovedad([
                'empleado_id' => (int) $empleado['id'],
                'novedad_id' => $novedadId,
                'ms_calendar_id' => $calendarId,
                'ms_event_id' => $eventId,
                'estado' => 'sincronizado',
                'error_mensaje' => null,
                'sincronizado_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            EventoCalendarioMs365Model::guardarNovedad([
                'empleado_id' => (int) $empleado['id'],
                'novedad_id' => $novedadId,
                'ms_calendar_id' => $calendarId,
                'ms_event_id' => $existente['ms_event_id'] ?? null,
                'estado' => 'error',
                'error_mensaje' => substr($e->getMessage(), 0, 500),
                'sincronizado_at' => null,
            ]);
        }
    }

    public function eliminarNovedad(int $novedadId): void
    {
        if (!$this->activo()) {
            return;
        }
        $existente = EventoCalendarioMs365Model::porNovedad($novedadId);
        if (!$existente) {
            return;
        }
        $empleado = EmpleadoModel::find((int) $existente['empleado_id']);
        if (!$empleado || empty($empleado['usuario_id'])) {
            EventoCalendarioMs365Model::delete((int) $existente['id']);
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        $email = $usuario['email'] ?? null;

        if ($email && $existente['ms_event_id']) {
            try {
                $this->cliente()->eliminarEvento($email, $existente['ms_calendar_id'], $existente['ms_event_id']);
            } catch (Throwable $e) {
            }
        }
        EventoCalendarioMs365Model::delete((int) $existente['id']);
    }

    /** @param array{fecha:string, hora_inicio:?string, hora_fin:?string, comentario:?string, tipo_nombre:string} $novedad */
    private function payloadNovedad(array $novedad): array
    {
        $descripcion = $novedad['comentario'] ?: 'Sin comentario adicional.';

        if (empty($novedad['hora_inicio']) || empty($novedad['hora_fin'])) {
            $fin = (new DateTimeImmutable($novedad['fecha']))->modify('+1 day')->format('Y-m-d');
            return [
                'subject' => $novedad['tipo_nombre'],
                'body' => ['contentType' => 'text', 'content' => $descripcion],
                'start' => ['dateTime' => $novedad['fecha'] . 'T00:00:00', 'timeZone' => 'America/Bogota'],
                'end' => ['dateTime' => $fin . 'T00:00:00', 'timeZone' => 'America/Bogota'],
                'isAllDay' => true,
                'isReminderOn' => false,
            ];
        }

        return [
            'subject' => $novedad['tipo_nombre'],
            'body' => ['contentType' => 'text', 'content' => $descripcion],
            'start' => ['dateTime' => $novedad['fecha'] . 'T' . $this->normalizarHora($novedad['hora_inicio']), 'timeZone' => 'America/Bogota'],
            'end' => ['dateTime' => $novedad['fecha'] . 'T' . $this->normalizarHora($novedad['hora_fin']), 'timeZone' => 'America/Bogota'],
            'isReminderOn' => false,
        ];
    }
```

Y agrega el import al inicio del archivo, junto a los demás `use`:

```php
use App\Models\NovedadModel;
```

(y reemplaza las 2 apariciones de `\App\Models\NovedadModel::conDetalle` por `NovedadModel::conDetalle` ahora que está importado — opcional, cualquiera de las dos formas funciona).

- [ ] **Step 4: Correr las pruebas y verificar que pasan**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: `OK (13 tests, ...)`

- [ ] **Step 5: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (43 tests, ...)`

- [ ] **Step 6: Commit**

```bash
git add app/Services/CalendarioMs365Service.php tests/Unit/CalendarioMs365ServiceTest.php
git commit -m "CalendarioMs365Service: sincronizacion de novedades aprobadas"
```

---

## Task 6: `CalendarioMs365Service` — `reintentar()`, `eliminarTodosLosEventosDelEmpleado()`

**Files:**
- Modify: `app/Services/CalendarioMs365Service.php`
- Modify: `tests/Unit/CalendarioMs365ServiceTest.php`

**Interfaces:**
- Produces: `CalendarioMs365Service::reintentar(int $eventoCalendarioId): void` (usado por Task 10, botón "Reintentar"), `->eliminarTodosLosEventosDelEmpleado(int $empleadoId): void` (usado por Task 9, al apagar el interruptor).

- [ ] **Step 1: Agregar las pruebas que fallan**

```php
    public function test_reintentar_una_fila_de_horario_en_error_vuelve_a_sincronizar_toda_la_vigencia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-reintento1@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');
        $this->cliente->fallarPara['ev-fake-1'] = true;
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $filaConError = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01')[0];
        $this->assertSame('error', $filaConError['estado']);

        unset($this->cliente->fallarPara['ev-fake-1']);
        $this->service->reintentar((int) $filaConError['id']);

        $filaActualizada = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01')[0];
        $this->assertSame('sincronizado', $filaActualizada['estado']);
    }

    public function test_reintentar_una_fila_de_novedad_en_error_la_vuelve_a_sincronizar(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-reintento2@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-20');
        $this->cliente->fallarPara['ev-fake-1'] = true;
        $this->service->sincronizarNovedad($novedadId);
        $filaConError = EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertSame('error', $filaConError['estado']);

        unset($this->cliente->fallarPara['ev-fake-1']);
        $this->service->reintentar((int) $filaConError['id']);

        $this->assertSame('sincronizado', EventoCalendarioMs365Model::porNovedad($novedadId)['estado']);
    }

    public function test_eliminarTodosLosEventosDelEmpleado_borra_horarios_y_novedades(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-borrartodo@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-21');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $this->service->sincronizarNovedad($novedadId);

        $this->service->eliminarTodosLosEventosDelEmpleado($empleadoId);

        $this->assertCount(2, $this->cliente->eventosEliminados);
        $this->assertEmpty(EventoCalendarioMs365Model::porEmpleado($empleadoId));
    }
```

- [ ] **Step 2: Correr las pruebas y verificar que fallan**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: FAIL — `Call to undefined method App\Services\CalendarioMs365Service::reintentar()`.

- [ ] **Step 3: Agregar `reintentar()` y `eliminarTodosLosEventosDelEmpleado()`**

Agrega dentro de la clase, después de `eliminarNovedad()`:

```php
    /** Vuelve a intentar la sincronizacion completa de origen de una fila en error (toda la vigencia si es horario, la novedad si es novedad). */
    public function reintentar(int $eventoCalendarioId): void
    {
        $evento = EventoCalendarioMs365Model::find($eventoCalendarioId);
        if (!$evento) {
            return;
        }

        if ($evento['novedad_id']) {
            $this->sincronizarNovedad((int) $evento['novedad_id']);
        } else {
            $this->sincronizarVigencia((int) $evento['empleado_id'], $evento['horario_vigente_desde']);
        }
    }

    /** Borra en Graph y localmente TODOS los eventos (horario y novedades) de un empleado — usado al apagar su interruptor. */
    public function eliminarTodosLosEventosDelEmpleado(int $empleadoId): void
    {
        if (!$this->activo()) {
            return;
        }
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        foreach (EventoCalendarioMs365Model::porEmpleado($empleadoId) as $ev) {
            if ($ev['ms_event_id']) {
                try {
                    $this->cliente()->eliminarEvento($email, $ev['ms_calendar_id'], $ev['ms_event_id']);
                } catch (Throwable $e) {
                }
            }
            EventoCalendarioMs365Model::delete((int) $ev['id']);
        }
    }
```

- [ ] **Step 4: Correr las pruebas y verificar que pasan**

Run: `vendor/bin/phpunit --filter CalendarioMs365ServiceTest`
Expected: `OK (16 tests, ...)`

- [ ] **Step 5: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (46 tests, ...)`

- [ ] **Step 6: Commit**

```bash
git add app/Services/CalendarioMs365Service.php tests/Unit/CalendarioMs365ServiceTest.php
git commit -m "CalendarioMs365Service: reintento manual y limpieza total por empleado"
```

---

## Task 7: Integración en `HorarioController`

**Files:**
- Modify: `app/Controllers/HorarioController.php`

**Interfaces:**
- Consumes: `CalendarioMs365Service::sincronizarVigencia()`, `::eliminarVigencia()` (Task 4).

- [ ] **Step 1: Agregar el import y enganchar `guardar()`, `actualizar()`, `eliminar()`**

En `app/Controllers/HorarioController.php`, agrega el import junto a los demás `use` del inicio del archivo:

```php
use App\Services\CalendarioMs365Service;
```

Modifica `guardar()` (agrega el bloque `try/catch` justo después de la llamada a `HorarioBaseModel::crearVigencia(...)`, antes del `Session::flash('success', ...)`):

```php
    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = $request->input('vigente_desde');
        $vigenteHasta = $request->input('vigente_hasta') ?: null;
        $comentario = $request->input('comentario') ?: null;
        $dias = $this->leerDiasDelFormulario($request);

        HorarioBaseModel::crearVigencia($empleadoId, $vigenteDesde, $vigenteHasta, $comentario, $dias);

        try {
            (new CalendarioMs365Service())->sincronizarVigencia($empleadoId, $vigenteDesde);
        } catch (\Throwable $e) {
            // La sincronizacion con Outlook nunca debe bloquear el guardado local; el error queda
            // visible en /admin/calendario-ms365 para reintentar.
        }

        Session::flash('success', 'Horario base creado correctamente.');
        $this->flashAdvertenciaBloquesLargos($dias);
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }
```

Modifica `actualizar()` de la misma forma (después de `HorarioBaseModel::actualizarVigencia(...)`):

```php
    public function actualizar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = (string) $request->param('vigenteDesde');
        if (!HorarioBaseModel::vigencia($empleadoId, $vigenteDesde)) {
            Response::abort(404, 'Vigencia de horario no encontrada.');
        }

        $vigenteHasta = $request->input('vigente_hasta') ?: null;
        $comentario = $request->input('comentario') ?: null;
        $dias = $this->leerDiasDelFormulario($request);

        HorarioBaseModel::actualizarVigencia($empleadoId, $vigenteDesde, $vigenteHasta, $comentario, $dias);

        try {
            (new CalendarioMs365Service())->sincronizarVigencia($empleadoId, $vigenteDesde);
        } catch (\Throwable $e) {
        }

        Session::flash('success', 'Vigencia de horario actualizada.');
        $this->flashAdvertenciaBloquesLargos($dias);
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }
```

Modifica `eliminar()` (después de `HorarioBaseModel::eliminarVigencia(...)`):

```php
    public function eliminar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = $request->input('vigente_desde');

        HorarioBaseModel::eliminarVigencia($empleadoId, $vigenteDesde);

        try {
            (new CalendarioMs365Service())->eliminarVigencia($empleadoId, $vigenteDesde);
        } catch (\Throwable $e) {
        }

        Session::flash('success', 'Vigencia de horario eliminada.');
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }
```

- [ ] **Step 2: Correr la suite completa (no hay pruebas nuevas — HorarioController no tiene suite de pruebas propia en este proyecto; se verifica en el paso siguiente)**

Run: `vendor/bin/phpunit`
Expected: `OK (46 tests, ...)` — sin cambios, confirma que no se rompió nada existente.

- [ ] **Step 3: Verificar manualmente en navegador**

Con el servidor local corriendo (`php -S localhost:8000 -t public`), la integración de calendario en `activo()=false` (configuración por defecto tras la Task 1), y sesión iniciada como Administrador:
1. Ve a `/empleados/{id}/horarios/crear` para un empleado cualquiera, guarda un horario.
Expected: el flash "Horario base creado correctamente." aparece igual que antes (el `try/catch` no debe alterar el flujo ni mostrar ningún error, porque `activo()` es `false` y el servicio no hace nada).
2. Repite editando y eliminando una vigencia.
Expected: mismos flashes de siempre, sin errores en el log de PHP (`php_server.log` si usaste el mismo patrón de este proyecto para levantar el servidor).

- [ ] **Step 4: Commit**

```bash
git add app/Controllers/HorarioController.php
git commit -m "Engancha CalendarioMs365Service a guardar/actualizar/eliminar horarios"
```

---

## Task 8: Integración en `NovedadController`

**Files:**
- Modify: `app/Controllers/NovedadController.php`

**Interfaces:**
- Consumes: `CalendarioMs365Service::sincronizarNovedad()`, `::eliminarNovedad()` (Task 5).

- [ ] **Step 1: Agregar el import y enganchar `aprobar()`/`rechazar()`**

Agrega el import junto a los demás `use` de `app/Controllers/NovedadController.php`:

```php
use App\Services\CalendarioMs365Service;
```

Modifica `aprobar()`:

```php
    public function aprobar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }
        $id = (int) $request->param('id');
        if (!$this->puedeGestionarNovedad($id)) {
            Response::abort(403, 'No tienes permiso para aprobar esta novedad (no pertenece a tu area).');
            return;
        }
        NovedadModel::aprobar($id, (int) Auth::id());

        try {
            (new CalendarioMs365Service())->sincronizarNovedad($id);
        } catch (\Throwable $e) {
        }

        Session::flash('success', 'Novedad aprobada.');
        Response::redirect('/novedades');
    }
```

Modifica `rechazar()`:

```php
    public function rechazar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }
        $id = (int) $request->param('id');
        if (!$this->puedeGestionarNovedad($id)) {
            Response::abort(403, 'No tienes permiso para rechazar esta novedad (no pertenece a tu area).');
            return;
        }
        NovedadModel::rechazar($id, (int) Auth::id());

        try {
            (new CalendarioMs365Service())->eliminarNovedad($id);
        } catch (\Throwable $e) {
        }

        Session::flash('success', 'Novedad rechazada.');
        Response::redirect('/novedades');
    }
```

- [ ] **Step 2: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (46 tests, ...)`

- [ ] **Step 3: Verificar manualmente en navegador**

Con `activo()=false` todavía: aprueba y rechaza una novedad de prueba desde `/novedades`.
Expected: los flashes "Novedad aprobada."/"Novedad rechazada." aparecen igual que antes, sin errores.

- [ ] **Step 4: Commit**

```bash
git add app/Controllers/NovedadController.php
git commit -m "Engancha CalendarioMs365Service a aprobar/rechazar novedades"
```

---

## Task 9: Interruptor por empleado (`/empleados/{id}/editar`)

**Files:**
- Modify: `app/Models/HorarioBaseModel.php`
- Modify: `app/Controllers/EmpleadoController.php`
- Modify: `app/Views/empleados/form.php`

**Interfaces:**
- Consumes: `CalendarioMs365Service::sincronizarVigencia()` (Task 4), `::eliminarTodosLosEventosDelEmpleado()` (Task 6).
- Produces: `HorarioBaseModel::vigenciasVigentesHoy(int $empleadoId): array` — usado solo aquí.

- [ ] **Step 1: Agregar `HorarioBaseModel::vigenciasVigentesHoy()`**

Agrega este método a `app/Models/HorarioBaseModel.php`, después de `vigenteEnFecha()`:

```php
    /** @return string[] las fechas vigente_desde (unicas) de las vigencias activas hoy para este empleado, sin importar el dia de la semana. */
    public static function vigenciasVigentesHoy(int $empleadoId): array
    {
        $hoy = date('Y-m-d');
        $stmt = self::db()->prepare(
            'SELECT DISTINCT vigente_desde FROM horarios_base
             WHERE empleado_id = ? AND vigente_desde <= ? AND (vigente_hasta IS NULL OR vigente_hasta >= ?)'
        );
        $stmt->execute([$empleadoId, $hoy, $hoy]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
```

- [ ] **Step 2: Agregar el checkbox a la vista**

En `app/Views/empleados/form.php`, agrega este bloque justo después del `</div>` que cierra el campo "Correo de la cuenta de acceso" (dentro del mismo `if ($empleado && !empty($empleado['usuario_id']))`, antes del campo "Areas adicionales"):

```php
            <div class="form-group">
                <label><input type="checkbox" name="sincronizar_calendario" value="1" <?= !empty($empleado['sincronizar_calendario']) ? 'checked' : '' ?>> Sincronizar con calendario de Outlook</label>
                <small class="text-muted">Crea/actualiza automaticamente los turnos y novedades aprobadas de este empleado en un calendario "Horario de trabajo" en su Outlook. Requiere que la integracion este configurada y activa en <a href="/admin/calendario-ms365">Calendario Microsoft 365</a>.</small>
            </div>
```

- [ ] **Step 3: Enganchar el toggle en `EmpleadoController::actualizar()`**

Agrega el import junto a los demás `use` de `app/Controllers/EmpleadoController.php`:

```php
use App\Models\HorarioBaseModel;
use App\Services\CalendarioMs365Service;
```

Reemplaza el cuerpo de `actualizar()` por:

```php
    public function actualizar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $empleado = EmpleadoModel::find($id);

        $sincronizarCalendarioAnterior = (bool) ($empleado['sincronizar_calendario'] ?? false);
        $sincronizarCalendarioNuevo = (bool) $request->input('sincronizar_calendario');

        EmpleadoModel::update($id, [
            'nombre' => trim((string) $request->input('nombre')),
            'documento' => trim((string) $request->input('documento')),
            'cargo' => $request->input('cargo') ?: null,
            'fecha_ingreso' => $request->input('fecha_ingreso'),
            'supervisor_id' => $request->input('supervisor_id') ?: null,
            'area_id' => $request->input('area_id') ?: null,
            'activo' => $request->input('activo') ? 1 : 0,
            'sincronizar_calendario' => $sincronizarCalendarioNuevo ? 1 : 0,
        ]);

        if ($empleado && $empleado['usuario_id']) {
            $usuarioId = (int) $empleado['usuario_id'];

            $areaIds = array_map('intval', (array) $request->input('areas_adicionales', []));
            SupervisorAreaModel::sincronizar($usuarioId, $areaIds);

            $nuevoEmail = trim((string) $request->input('email', ''));
            if ($nuevoEmail !== '') {
                try {
                    UsuarioModel::update($usuarioId, ['email' => $nuevoEmail]);
                } catch (\PDOException $e) {
                    Session::flash('error', 'El empleado se actualizo, pero el correo no se pudo cambiar: "' . $nuevoEmail . '" ya esta en uso por otra cuenta.');
                    Response::redirect("/empleados/{$id}/editar");
                    return;
                }
            }

            if ($sincronizarCalendarioNuevo && !$sincronizarCalendarioAnterior) {
                try {
                    $servicio = new CalendarioMs365Service();
                    foreach (HorarioBaseModel::vigenciasVigentesHoy($id) as $vigenteDesde) {
                        $servicio->sincronizarVigencia($id, $vigenteDesde);
                    }
                } catch (\Throwable $e) {
                }
            } elseif (!$sincronizarCalendarioNuevo && $sincronizarCalendarioAnterior) {
                try {
                    (new CalendarioMs365Service())->eliminarTodosLosEventosDelEmpleado($id);
                } catch (\Throwable $e) {
                }
            }
        }

        Session::flash('success', 'Empleado actualizado correctamente.');
        Response::redirect('/empleados');
    }
```

- [ ] **Step 4: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (46 tests, ...)`

- [ ] **Step 5: Verificar manualmente en navegador**

1. Ve a `/empleados/{id}/editar` para un empleado con cuenta vinculada.
Expected: aparece el nuevo checkbox "Sincronizar con calendario de Outlook", sin marcar por defecto.
2. Márcalo y guarda.
Expected: flash "Empleado actualizado correctamente.", sin error (con `activo()=false` el `try/catch` no hace nada visible, pero tampoco debe fallar).
3. Vuelve a editar: el checkbox debe aparecer marcado (se guardó correctamente en `empleados.sincronizar_calendario`).
4. Desmárcalo y guarda de nuevo.
Expected: mismo flash de éxito, checkbox queda desmarcado al volver a entrar.

- [ ] **Step 6: Commit**

```bash
git add app/Models/HorarioBaseModel.php app/Controllers/EmpleadoController.php app/Views/empleados/form.php
git commit -m "Agrega interruptor por empleado para sincronizar con calendario Outlook"
```

---

## Task 10: Pantalla de administración `/admin/calendario-ms365`

**Files:**
- Create: `app/Controllers/CalendarioMs365ConfigController.php`
- Create: `app/Views/admin/calendario_ms365.php`
- Modify: `public/index.php`

**Interfaces:**
- Consumes: `ConfiguracionCalendarioMs365Model` (Task 2), `EventoCalendarioMs365Model::conError()` (Task 2), `CalendarioMs365Service::reintentar()` (Task 6), permiso `admin.calendario_ms365` (Task 1).

- [ ] **Step 1: Crear el controlador**

```php
<?php
// app/Controllers/CalendarioMs365ConfigController.php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ConfiguracionCalendarioMs365Model;
use App\Models\EventoCalendarioMs365Model;
use App\Services\CalendarioMs365Service;

class CalendarioMs365ConfigController
{
    public function mostrar(Request $request): string
    {
        return View::render('admin/calendario_ms365', [
            'config' => ConfiguracionCalendarioMs365Model::obtener(),
            'errores' => EventoCalendarioMs365Model::conError(),
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        ConfiguracionCalendarioMs365Model::guardar(
            trim((string) $request->input('tenant_id')),
            trim((string) $request->input('client_id')),
            $request->input('client_secret') !== '' ? trim((string) $request->input('client_secret')) : null,
            (bool) $request->input('activo')
        );

        Session::flash('success', 'Configuracion de calendario Microsoft 365 guardada.');
        Response::redirect('/admin/calendario-ms365');
    }

    public function reintentar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        (new CalendarioMs365Service())->reintentar($id);

        Session::flash('success', 'Se reintento la sincronizacion.');
        Response::redirect('/admin/calendario-ms365');
    }
}
```

- [ ] **Step 2: Crear la vista**

```php
<?php // app/Views/admin/calendario_ms365.php
use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Calendario Microsoft 365</h2>
    <p class="text-muted">
        Sincroniza turnos y novedades aprobadas hacia un calendario dedicado ("Horario de trabajo") en el Outlook
        de cada empleado. Usa un App Registration <strong>separado</strong> del de
        <a href="/admin/ms365">inicio de sesion (SSO)</a>: valores en
        <a href="https://portal.azure.com" target="_blank" rel="noopener">Azure Portal &rarr; Microsoft Entra ID &rarr; Registros de aplicaciones</a>
        con permiso de APLICACION <code>Calendars.ReadWrite</code>.
    </p>

    <?php $estado = !empty($config['tenant_id']) && !empty($config['client_id']) && !empty($config['client_secret']); ?>
    <p>
        Estado actual:
        <?= $estado ? '<span class="badge badge-aprobado">Configurado</span>' : '<span class="badge badge-pendiente">Incompleto</span>' ?>
        <?= !empty($config['activo']) ? '<span class="badge badge-aprobado">Activo</span>' : '<span class="badge badge-rechazado">Inactivo</span>' ?>
    </p>

    <form method="post" action="/admin/calendario-ms365">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <div class="form-group">
            <label>Tenant ID (Id. de directorio)</label>
            <input type="text" name="tenant_id" value="<?= View::e($config['tenant_id'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Client ID (Id. de aplicacion)</label>
            <input type="text" name="client_id" value="<?= View::e($config['client_id'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Client secret</label>
            <input type="password" name="client_secret" placeholder="<?= !empty($config['client_secret']) ? '•••••••••••••••••••••• (dejar vacio para conservar el actual)' : 'Pega aqui el valor del secreto' ?>" autocomplete="off">
        </div>

        <div class="form-group">
            <label><input type="checkbox" name="activo" value="1" <?= !empty($config['activo']) ? 'checked' : '' ?>> Sincronizacion activa</label>
            <small class="text-muted">Interruptor maestro: si esta apagado, no se sincroniza NINGUN empleado sin importar su interruptor individual.</small>
        </div>

        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>

<div class="card">
    <h2>Sincronizaciones con error</h2>
    <?php if (empty($errores)): ?>
        <p class="text-muted">No hay sincronizaciones pendientes de reintentar.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table>
            <thead><tr><th>Empleado</th><th>Tipo</th><th>Motivo</th><th>Ultimo intento</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($errores as $e): ?>
                <tr>
                    <td><?= View::e($e['empleado_nombre']) ?></td>
                    <td><?= $e['novedad_id'] ? 'Novedad (' . View::e($e['novedad_tipo_nombre'] ?? '') . ', ' . View::e($e['novedad_fecha'] ?? '') . ')' : 'Horario (' . View::e($e['horario_vigente_desde'] ?? '') . ')' ?></td>
                    <td><?= View::e($e['error_mensaje'] ?? '') ?></td>
                    <td><?= View::e($e['updated_at']) ?></td>
                    <td>
                        <form method="post" action="/admin/calendario-ms365/reintentar/<?= (int) $e['id'] ?>" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <button type="submit" class="btn">Reintentar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Registrar las rutas**

En `public/index.php`, agrega el import junto a los demás `use App\Controllers\...`:

```php
use App\Controllers\CalendarioMs365ConfigController;
```

Y agrega las rutas justo después del bloque `// --- Administracion: Microsoft 365 (SSO) ---` existente:

```php
// --- Administracion: Calendario Microsoft 365 ---
$router->get('/admin/calendario-ms365', [CalendarioMs365ConfigController::class, 'mostrar'], [[RbacMiddleware::class, 'admin.calendario_ms365']]);
$router->post('/admin/calendario-ms365', [CalendarioMs365ConfigController::class, 'guardar'], [[RbacMiddleware::class, 'admin.calendario_ms365']]);
$router->post('/admin/calendario-ms365/reintentar/{id:\d+}', [CalendarioMs365ConfigController::class, 'reintentar'], [[RbacMiddleware::class, 'admin.calendario_ms365']]);
```

- [ ] **Step 4: Correr la suite completa**

Run: `vendor/bin/phpunit`
Expected: `OK (46 tests, ...)`

- [ ] **Step 5: Verificar manualmente en navegador**

1. Inicia sesión como Administrador, ve a `/admin/calendario-ms365`.
Expected: la pantalla carga, muestra "Incompleto"/"Inactivo", el formulario vacío, y "No hay sincronizaciones pendientes de reintentar."
2. Guarda unos valores de prueba (tenant/client id cualquiera, sin activar "Sincronizacion activa").
Expected: flash de éxito, los valores quedan precargados al recargar, "Incompleto" pasa a "Configurado" (si los 3 campos quedaron con valor) pero sigue "Inactivo".
3. Inicia sesión con un usuario SIN el permiso `admin.calendario_ms365` (ej. rol Supervisor) e intenta entrar a `/admin/calendario-ms365` directamente por URL.
Expected: 403.
4. (Opcional, si ya se corrió alguna prueba manual de horarios/novedades con la integración activa y algún fallo real) confirma que una fila en error aparece en la tabla y que "Reintentar" la actualiza.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/CalendarioMs365ConfigController.php app/Views/admin/calendario_ms365.php public/index.php
git commit -m "Agrega pantalla de administracion /admin/calendario-ms365"
```

---

## Task 11: Documentación

**Files:**
- Modify: `CLAUDE_horarios.md`
- Modify: `PROJECT_STATUS.md`
- Modify (sync a mirror local, si aplica en el entorno de quien ejecute): copia del proyecto en `C:\xampp\htdocs\horarios`

**Interfaces:**
- Ninguna — tarea de documentación pura, cierre del feature.

- [ ] **Step 1: Agregar el incidente/feature a `CLAUDE_horarios.md`**

Agrega una entrada numerada (siguiente número disponible en la sección "Incidentes reales encontrados y resueltos durante esta sesión") describiendo: qué se construyó (sincronización de horarios/novedades hacia un calendario dedicado de Outlook vía permiso de aplicación de Microsoft Graph), el hallazgo clave sobre `HorarioBaseModel::actualizarVigencia()` que determinó trabajar a nivel de vigencia completa en vez de bloque individual, los 3 elementos nuevos de esquema, y el resultado de `vendor/bin/phpunit` (46/46 tras esta tarea). Sigue el mismo estilo narrativo que las entradas 19-22 ya existentes (motivo real, causa raíz, cómo se verificó, qué se dejó fuera de alcance a propósito).

- [ ] **Step 2: Actualizar `PROJECT_STATUS.md`**

En la sección "3. Trabajo pendiente", elimina o marca como resuelto el punto sobre sincronización de calendario con Microsoft 365 (ya no es un pendiente sin código). Agrega una entrada nueva en "2. Trabajo completado" describiendo brevemente el feature, y actualiza el conteo de PHPUnit en la sección "1. Estado actual del proyecto" a 46/46. Menciona explícitamente los 3 pasos manuales de infraestructura de Microsoft 365 (App Registration nuevo, Application Access Policy, pegar credenciales en `/admin/calendario-ms365`) que el usuario todavía debe ejecutar fuera de la app antes de que la sincronización funcione de verdad en producción — el código está listo, pero sin esa configuración manual `activo()` seguirá siendo `false`.

- [ ] **Step 3: Sincronizar los archivos modificados al mirror local, si existe**

Si el entorno de ejecución tiene un mirror en `C:\xampp\htdocs\horarios` (patrón usado en sesiones anteriores de este proyecto), copia ahí todos los archivos creados/modificados en las Tasks 1-10 más `CLAUDE_horarios.md`/`PROJECT_STATUS.md`. Si no existe ese mirror en el entorno actual, omite este paso.

- [ ] **Step 4: Commit final**

```bash
git add CLAUDE_horarios.md PROJECT_STATUS.md
git commit -m "Documenta la sincronizacion de calendario Microsoft 365 en CLAUDE_horarios.md y PROJECT_STATUS.md"
```

- [ ] **Step 5: Push**

```bash
git push origin main
```

---

## Nota final para quien ejecute este plan

El código queda completo y probado (46 pruebas de PHPUnit) al terminar la Task 10, pero **la sincronización real con Outlook no funcionará en producción hasta que el usuario complete los 3 pasos manuales de la sección 2 del spec** (nuevo App Registration con permiso de aplicación `Calendars.ReadWrite`, Application Access Policy en Exchange Online, y pegar tenant/client id/secret + activar el interruptor maestro en `/admin/calendario-ms365`). Avísale esto explícitamente al terminar, y ofrece redactar el paso a paso exacto de esa configuración en Azure/Exchange si lo necesita — no está en este plan porque no es código.
