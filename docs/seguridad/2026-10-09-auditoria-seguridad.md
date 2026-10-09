# Auditoría de seguridad: plan de corrección (2026-10-09)

Origen: auditoría `security-audit` en perfil *quick* sobre el commit `04a4220`, revisando también los cambios sin commitear en `ReporteExportService.php` y `horas_registro.php`.
Método: revisión del código fuente con verificación independiente de cada candidato. **No se ejecutó código**, porque la máquina no tenía sandbox. Por eso cada hallazgo trae una prueba de regresión que sirve de validación al corregirlo.
Reporte completo de la auditoría (fuera del repo): `C:\Users\miguelvar\security-audit-skill\horarios\run-1\`.

Las rutas son relativas a la raíz del repo. Los números de línea corresponden al commit auditado.

## Estado de corrección (2026-10-09)

Todos los hallazgos se reprodujeron en local (servidor `php -S` y copia de la base de producción) y se corrigieron; el detalle está en el incidente 25 de `CLAUDE_horarios.md`.

| # | Estado | Nota |
|---|---|---|
| 1 | Corregido | `PrivilegiosService` + 403 en `resetearPassword`; botón oculto en `/usuarios` |
| 2 | Corregido | El rol se valida antes de crear el empleado; el select solo lista roles asignables |
| 3 | Corregido | Falla en cerrado ante BD caída; `.env` con valores escapados; sin mensajes crudos de PDO |
| 4 | Corregido | `usuarios.activo` sigue a `empleados.activo`; `Auth::check()` revalida cada petición |
| 5 | Corregido (opción a) | Correo y estado solo si el actor puede gestionar la cuenta. La opción b no se aplicó (no hay forma de vincular SSO desde una sesión local) |
| 6 | Corregido | Id de sesión y token CSRF se rotan al iniciar sesión; modo estricto; `Secure`/HSTS por `APP_URL` |
| 7 | Corregido | `RangoFechasService` (máximo 366 días) en los tres puntos |
| 8 | Corregido (política asumida) | El rol Empleado solo ve su propia ficha. Confirmar con el usuario; es fácil de revertir |
| 9-12 | Corregidos | `AlcanceAreasService::autorizarEmpleadoOAbortar()`. **Nota:** #10 y #12 sí eran explotables hoy por el rol Supervisor real |
| Endurecimiento | Aplicado | CSRF solo por cuerpo, logout con token, Excel como texto, logos, tenant GUID/dominio, correo en Graph |
| Endurecimiento | Pendiente | dompdf 2.0.8 → 3.1.6 (salto mayor, probar PDF); invalidar sesiones al cambiar la contraseña; autoaprobación de novedades (política) |


---

## Resumen y orden sugerido

| # | Prioridad | Problema | Archivos principales |
|---|---|---|---|
| 1 | Alta | RRHH resetea la contraseña del Administrador y recibe la clave | `app/Controllers/UsuarioController.php` |
| 2 | Alta | RRHH crea cuentas con rol Administrador (`rol_id` sin validar) | `app/Controllers/EmpleadoController.php` |
| 3 | Alta | Instalador anónimo que falla abierto si falta el lock y cae la BD | `app/Controllers/InstalacionController.php` |
| 4 | Alta | Dar de baja a un empleado no revoca su acceso | `EmpleadoController.php`, `app/Core/Auth.php` |
| 5 | Media | El SSO MS365 vincula por correo; RRHH puede redirigir el correo de un Administrador | `Ms365AuthController.php`, `EmpleadoController.php` |
| 6 | Media | El id de sesión no se rota al iniciar sesión (fijación) | `app/Core/Auth.php`, `app/Core/Session.php` |
| 7 | Media | Rango de fechas sin límite en los reportes por registro | `app/Controllers/ReporteController.php` |
| 8 | Baja | El rol Empleado ve los horarios de compañeros de su área | `app/Controllers/HorarioController.php` |
| 9–12 | Baja hoy* | Faltan controles de alcance por área en acciones de escritura | Empleado, RegistroTiempo, DiaCompensatorio, Novedad |

\* Los puntos 9 a 12 solo se pueden explotar si existe un rol personalizado con el permiso de escritura y sin `equipos.ver_todas`. Conviene corregirlos igual, porque el diseño permite crear esos roles desde `/admin/roles`.

Al terminar cada punto:

- Correr `phpunit`.
- Probar en `https://horarios.test`.
- Desplegar como actualización liviana de archivos. Ninguno de los puntos requiere migraciones.

---

## 1. RRHH puede tomar la cuenta del Administrador con el reseteo de contraseña

**Ubicación:**
- `public/index.php:61`: la ruta `POST /usuarios/{id}/resetear-password` solo exige `usuarios.gestionar`, que tienen Administrador y RRHH (seed `010`).
- `app/Controllers/UsuarioController.php:21-58` acepta cualquier `id`, incluido el de un Administrador.
- La clave en claro se entrega al actor (`password_generada`, líneas 52-56).
- Con esa clave RRHH entra como Administrador y la cambia en `/cambiar-password`, porque solo le piden la clave temporal, que ya conoce.

**Invariante:** nadie puede resetear la contraseña de un usuario que tenga permisos que el actor no tiene.

**Cambio propuesto:**

1. Crear un helper reutilizable, por ejemplo en `RbacService` o `Auth`:

```php
/** true si $usuarioObjetivoId no tiene ningún permiso que el usuario en sesión no tenga. */
public static function puedeGestionarUsuario(int $usuarioObjetivoId): bool
{
    $propios = Auth::permisos();
    $ajenos  = UsuarioModel::permisos($usuarioObjetivoId);
    return array_diff($ajenos, $propios) === [];
}
```

2. Usarlo en `resetearPassword`, justo después del `find`:

```php
if (!Auth::puedeGestionarUsuario($id)) {
    Response::abort(403, 'No puedes reiniciar la contrasena de un usuario con mas permisos que tu.');
    return;
}
```

3. En `app/Views/usuarios/index.php:30`, ocultar el botón para esas filas. Es solo cosmético: el control real es el del servidor.

**Prueba de regresión:**
- Como RRHH, `POST /usuarios/{idAdmin}/resetear-password` debe responder **403** y la contraseña del Administrador no debe cambiar.
- Como RRHH sobre un Empleado debe seguir funcionando.
- Como Administrador sobre cualquier usuario debe seguir funcionando.

---

## 2. RRHH crea cuentas Administrador al dar de alta un empleado

**Ubicación:**
- `public/index.php:74`: la ruta `POST /empleados` solo exige `empleados.crear` (RRHH lo tiene).
- `app/Controllers/EmpleadoController.php:71` hace `'rol_id' => (int) $request->input('rol_id')` sin validar.
- `app/Views/empleados/form.php:98` lista todos los roles.
- El actor elige o recibe la contraseña inicial.
- `actualizar` **no** cambia `rol_id`, así que no hay variante en la edición.

**Invariante:** un actor solo puede asignar roles cuyo conjunto de permisos esté contenido en el suyo.

**Cambio propuesto:**

1. Crear un helper parecido al del punto 1, pero que compare con los permisos del **rol**: consultar `rol_permisos`/`permisos` para el `rol_id` y hacer el mismo `array_diff`.
2. En `guardar`, antes de `UsuarioModel::insert`:
   - Validar que el rol existe y que pasa el helper.
   - Si no pasa, mostrar un flash de error y redirigir, sin crear ni el empleado ni el usuario.
3. Filtrar el `select` de `form.php` con el mismo helper, pasando desde el controlador solo los roles asignables.

**Prueba de regresión:**
- Como RRHH, crear un empleado con `crear_usuario=1` y el `rol_id` de Administrador debe ser rechazado, sin crear filas.
- Con `rol_id` de Empleado o Supervisor debe funcionar.

---

## 3. El instalador falla en abierto

**Ubicación:**
- `app/Controllers/InstalacionController.php:33-51`: `yaInstalado()` devuelve `false` ante **cualquier** excepción de la BD.
- El lock `storage/instalado.lock` está en `.gitignore`, no va en el paquete de despliegue (`CLAUDE_horarios.md:28`) y se escribe con `@` (líneas 57-59). La rama manual (línea 144) no lo crea.
- Si en producción falta el lock y la BD falla un momento, `/instalar` queda abierto a cualquiera. Desde ahí se puede apuntar la app a otra BD, crear un Administrador y sobrescribir `.env`.
- Además, `generarEnv` (líneas ~164-180) no filtra saltos de línea, así que se pueden inyectar variables en `.env`.

**Invariante:** si ya hay un `.env` configurado, el instalador no se muestra nunca. Ante una duda, el instalador debe fallar en cerrado.

**Cambio propuesto:**

```php
private function yaInstalado(): bool
{
    if (is_file($this->lockPath())) {
        return true;
    }

    // Un .env con base de datos configurada = ya instalado, aunque la BD no responda ahora.
    if (!empty($_ENV['DB_DATABASE'])) {
        $this->crearLock();
        return true;
    }

    try {
        $pdo = Database::connection();
        if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
            $this->crearLock();
            return true;
        }
    } catch (\Throwable $e) {
        // Sin .env configurado y sin BD: instalación nueva legítima.
    }
    return false;
}
```

Además:
- Llamar a `crearLock()` también en la rama manual (línea 144).
- En `generarEnv`, rechazar cualquier valor con `\r`, `\n` u otros caracteres de control. Poner los valores entre comillas y escapar `\` y `"`.
- No reflejar el mensaje crudo de `PDOException` (línea 115). Usar un mensaje genérico.

**Acción inmediata en producción, sin esperar el código:** en cPanel > Administrador de archivos, verificar que existe `storage/instalado.lock` y crearlo si falta.

**Prueba de regresión:**
- Con `.env` presente, sin lock y con MySQL detenido, `GET /instalar` debe redirigir a `/login`.
- Con un valor `app_url` que contenga `\n`, la instalación debe ser rechazada.

---

## 4. La baja de un empleado no revoca su acceso

**Ubicación:**
- La única baja posible es la casilla "Empleado activo" (`app/Views/empleados/form.php:85`), que escribe `empleados.activo` (`EmpleadoController.php:129`).
- El login local (`AuthController.php:51`) y el SSO (`Ms365AuthController.php:89`) solo leen `usuarios.activo`, y nada en la app lo pone en 0.
- `Auth::check()` (`app/Core/Auth.php:21-24`) no relee ningún estado, así que las sesiones abiertas siguen activas.

**Invariante:** un empleado o usuario inactivo no inicia sesión, y sus sesiones abiertas dejan de funcionar en la siguiente petición.

**Cambio propuesto:**

1. En `EmpleadoController::actualizar`, si el empleado tiene usuario vinculado, sincronizar `usuarios.activo` con `empleados.activo`.
2. Revalidar en cada petición, en `Auth::check()`:

```php
public static function check(): bool
{
    if (!Session::has('usuario_id')) {
        return false;
    }
    $u = self::usuario();
    if (!$u || empty($u['activo'])) {
        self::logout();
        return false;
    }
    return true;
}
```

`UsuarioModel::withRol` ya hace esa consulta en cada petición, así que no añade costo.

3. Opcional: añadir un control "Usuario activo" en `/usuarios` para dar de baja cuentas que no tienen empleado.

**Revisión en producción (solo lectura):**

```sql
SELECT e.id, e.nombre, u.email, r.nombre AS rol, u.ultimo_login_at
FROM empleados e JOIN usuarios u ON u.id = e.usuario_id JOIN roles r ON r.id = u.rol_id
WHERE e.activo = 0 AND u.activo = 1;
```

**Prueba de regresión:**
- Desactivar un empleado que tenga una sesión abierta: la siguiente petición debe ir a `/login`.
- `POST /login` con sus credenciales debe ser rechazado.

---

## 5. Vinculación del SSO por correo

**Ubicación:**
- `app/Controllers/Ms365AuthController.php:78-99`. En el primer login, si el `oid` no está vinculado, busca el usuario local por `mail ?? userPrincipalName` sin filtrar por rol, lo vincula para siempre y abre sesión.
- `EmpleadoController::actualizar` (línea 162) permite a RRHH cambiar el correo de la cuenta de cualquier empleado sin comparar privilegios.
- Escenario: un Administrador con ficha de empleado que nunca usó SSO. RRHH le pone a esa cuenta el correo de una identidad de Microsoft que controla y entra como Administrador sin que nadie lo note.

**Cambio propuesto (cualquiera de los dos cierra la vía dentro de la app):**

- **a) Proteger el correo:** en `EmpleadoController::actualizar`, solo permitir cambiar el correo de la cuenta vinculada si `Auth::puedeGestionarUsuario($usuarioId)` (helper del punto 1).
- **b) Restringir la vinculación automática:** en el callback, no vincular automáticamente cuentas con permisos administrativos (por ejemplo `admin.roles`). Esas cuentas deben vincularse desde una sesión local ya autenticada.

Recomendado: aplicar **a)** siempre, y **b)** como defensa adicional.

**Revisión en producción:** listar los Administradores sin fila en `usuarios_ms365`. Si los hay, pedirles que inicien sesión una vez por SSO.

---

## 6. Fijación de sesión

**Ubicación:**
- `app/Core/Auth.php:67-72`: `login()` no llama a `session_regenerate_id`, y en el repo no existe ninguna llamada a esa función. Lo usan tanto el login local como el SSO.
- El token `_csrf` tampoco se rota.

**Cambio propuesto:**

1. Añadir una función a `Session`:

```php
public static function regenerar(): void
{
    session_regenerate_id(true);
    unset($_SESSION['_csrf']); // se regenera en el próximo token()
}
```

Ajustar la clave al nombre real que use `Session` para el token.

2. Llamarla al inicio de `Auth::login()`.
3. En `Session::start()`, antes de `session_start()`:

```php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
```

4. Revisar el atributo `secure` (`Session.php:17`) y HSTS (`SecurityHeaders.php:41`). Hoy dependen de `$_SERVER['HTTPS']`, que detrás de Cloudflare puede llegar vacío. En producción conviene forzarlos cuando `APP_URL` empieza por `https://`.

**Prueba de regresión:** después de `POST /login`, la respuesta debe traer un `Set-Cookie` con un id distinto, y el id anterior no debe quedar autenticado.

---

## 7. Rango de fechas sin límite en los reportes por registro

**Ubicación:**
- `app/Controllers/ReporteController.php:197-198`, `251-252` y `266-267` toman `desde`/`hasta` de la URL sin validarlos.
- `ReporteHorasRegistroService::generarInforme` recorre día por día con una consulta SQL por día y por empleado.
- Con un rango de siglos, una sola petición de un Supervisor o Auditor puede saturar la cuenta compartida de BlueHost.

**Cambio propuesto:** crear un helper privado en `ReporteController` y usarlo en los tres puntos.

```php
private function rangoValido(?string $desde, ?string $hasta): ?array
{
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $desde);
    $h = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $hasta);
    if (!$d || !$h || $d > $h || $d->diff($h)->days > 366) {
        return null;
    }
    return [$d->format('Y-m-d'), $h->format('Y-m-d')];
}
```

Si devuelve `null`, mostrar un flash de error y usar el rango por defecto. Esto también evita el 500 que hoy dan las fechas inválidas y valida el nombre de archivo de la exportación.

**Mejora opcional:** cachear `ConfiguracionGlobalModel::vigenteEnFecha` dentro de `generarInforme`, ya que cambia muy poco.

---

## 8. El rol Empleado ve los horarios de compañeros de su área

**Ubicación:**
- `app/Controllers/HorarioController.php:229-242`: `empleadoAutorizadoOAbortar` usa el alcance por área.
- Para el rol Empleado ese alcance cubre a toda su área, mientras que Calendario (`:26`), Novedades (`:26`) y Reportes (`:180`) lo limitan a sí mismo.
- El seed `003` lo describe como "su propio horario".

**Antes de corregir:** confirmar la política. Si se quiere que los empleados vean los horarios de su área, este punto se descarta.

**Cambio propuesto:** aplicar en `empleadoAutorizadoOAbortar` el mismo caso de autoservicio que en Calendario. Si el rol es Empleado, el alcance es solo su propio `empleado_id` (`EmpleadoModel::porUsuario`).

**Mejora estructural:** el autoservicio depende del nombre literal del rol `'Empleado'` en cuatro controladores. Conviene centralizarlo en `AlcanceAreasService`, por ejemplo con un permiso `equipos.solo_propio` o un método `esAutoservicio()`, para que un rol personalizado equivalente lo herede.

---

## 9–12. Controles de alcance por área ausentes en acciones de escritura

**Patrón común:** el listado (`index`) filtra por `AlcanceAreasService`, pero las acciones que reciben un `id` o un `empleado_id` no lo hacen. `HorarioController::empleadoAutorizadoOAbortar` ya implementa la versión correcta.

**Cambio propuesto:** extraer ese método a `AlcanceAreasService`, por ejemplo `autorizarEmpleadoOAbortar(int $empleadoId)`, que devuelve 403 si el empleado no está en `empleadoIdsPermitidos()`. Luego llamarlo en:

| # | Acción | Qué verificar |
|---|---|---|
| 9 | `EmpleadoController::editar` / `actualizar` | El `{id}` de la ruta. Además, filtrar `area_id` y `areas_adicionales` del cuerpo contra `areaIdsPermitidos()`, porque hoy un usuario puede ampliar su propio alcance a todas las áreas. |
| 10 | `RegistroTiempoController::crearForm` / `crear` / `editarForm` / `editar` / `eliminar` | En `crear`, el `empleado_id` del cuerpo; en las demás, el `empleado_id` del registro cargado por `{id}`. `crearForm` debe listar `empleadosPermitidos()` en vez de `todosConSupervisor()`. `eliminar` debe comprobar que el registro existe. |
| 11 | `DiaCompensatorioController::actualizarTratamiento` / `marcarDescansoTomado` | El `empleado_id` del registro. `marcarDescansoTomado` también debe comprobar existencia y validar la fecha. |
| 12 | `NovedadController::guardar` | Para roles distintos de Empleado, el `empleado_id` del cuerpo debe estar en `empleadoIdsPermitidos()`. Para un Empleado sin empleado vinculado, rechazar en vez de usar el cuerpo. |

**Revisión en producción:** comprobar en `/admin/roles` si algún rol tiene `empleados.editar`, `registros_tiempo.corregir`, `dias_compensatorios.gestionar` o `novedades.crear` sin tener `equipos.ver_todas`. Si no hay ninguno, hoy no se pueden explotar.

**Prueba de regresión, una por acción:** con un rol de prueba que tenga el permiso y no tenga `ver_todas`, operar sobre un empleado de otra área debe dar **403** sin cambiar filas.

---

## Endurecimiento recomendado (no son vulnerabilidades)

- `Request::input` acepta `_csrf` desde la query string. Leerlo solo del cuerpo.
- `AuthController::logout` no verifica CSRF. Añadirlo es trivial.
- `AdminConfigController::subirLogo`/`eliminarLogo` hacen `unlink` con `logo_path` sin `basename()`. Aplicar `basename()` y validar con `^logo_[0-9a-f]{16}\.(png|jpg|gif|webp)$`.
- `public/uploads/.htaccess`: `php_flag engine off` solo funciona con mod_php. Añadir `RemoveHandler .php .phtml .phar`.
- Exportaciones Excel: escribir los textos con `setCellValueExplicit(..., DataType::TYPE_STRING)` o `StringValueBinder`, y llamar a `setPreCalculateFormulas(false)` en el writer.
- `GraphCalendarClient`: validar el correo con `FILTER_VALIDATE_EMAIL` y aplicar `rawurlencode` al segmento `{email}` de la ruta.
- `Ms365ConfigController`: validar que `tenant_id` sea un GUID, para que no se pueda usar `common` u `organizations`.
- Las cambios de contraseña no invalidan otras sesiones. Se puede guardar una versión de credencial en la sesión y compararla en `Auth::check`.
- Un Supervisor puede aprobar sus propias novedades. Evaluar si conviene separar funciones.
- Ejecutar `composer audit` con red. Versiones actuales: dompdf 2.0.8 y PhpSpreadsheet 1.30.6.

## Pendiente de auditar

Estas áreas no se revisaron en la pasada *quick*:

1. Creación y recálculo de periodos:
   - `crearPeriodo` no valida las fechas.
   - El estado `abierto/cerrado` nunca se aplica, así que se puede recalcular un periodo ya pagado.
   - Tampoco se revisaron los catálogos de administración que alimentan el motor de cálculo.
2. Transiciones de aprobación de novedades (reaprobar algo ya rechazado, autoaprobación) y de días compensatorios.
3. Marcación propia: `lat`, `lon`, `precision`, `ubicacion_estado` y `fecha_hora_cliente` llegan del cliente sin validar, así que la evidencia de ubicación se puede falsificar.
4. `/admin/ms365` y `/admin/calendario-ms365` (el `client_secret` se guarda en claro), `/admin/actualizaciones` y `RolController`.

## Lo que ya está bien (conservar)

- PDO con prepares reales.
- `View::e` en toda la salida.
- CSP sin `unsafe-inline` en scripts.
- CSRF en todos los POST.
- `state` de OAuth de un solo uso.
- `HorarioController` y la aprobación de novedades ya validan el alcance por área.
- PDF con `isRemoteEnabled=false`.
- Logo validado con `getimagesize` y nombre generado por el servidor.
- No hay secretos versionados.
