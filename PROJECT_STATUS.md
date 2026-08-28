# PROJECT_STATUS.md — Estado del proyecto Horarios INALDE

> Generado el 2026-08-23, actualizado el 2026-08-24, **actualizado de nuevo el 2026-08-25** tras una sesión larga: descuento automático de almuerzo, corrección manual de marcaciones, nuevo reporte de nómina según registro, y el cambio arquitectónico más grande hasta ahora — **áreas/equipos reemplazando `supervisor_id`** como mecanismo de alcance en todo el sistema, con dos huecos de seguridad reales encontrados y cerrados en el proceso. Ver también [CLAUDE_horarios.md](CLAUDE_horarios.md) para el contexto operativo/arquitectónico completo.
>
> **Convención**: **[VERIFICADO]** = confirmado por inspección directa de código, BD o prueba HTTP/navegador real. **[NO VERIFICADO]** = cambio hecho sin prueba de extremo a extremo confirmada. **[SOLO CONTEXTO DE SESIÓN]** = lo relató el usuario en el chat pero no se pudo confirmar de forma independiente.

## 1. Estado actual del proyecto

Sistema funcional y **desplegado en producción** en `https://horarios.inalde.edu.co` (BlueHost + Cloudflare). **[VERIFICADO]**: `phpunit` → 20/20 tests, 122 assertions, 0 failures (última corrida el 2026-08-25). **[VERIFICADO]**: 28 archivos de migración locales.

**Prácticamente todo lo funcional está cerrado y confirmado en producción por el usuario**: MS365 SSO, diseño responsive, novedades por rango, los tres reportes (horas extra, horas según registro, nómina según registro), personalización del sitio, descuento de almuerzo, corrección de marcaciones, y áreas/equipos con sus dos fixes de seguridad. El único pendiente sustantivo real es la validación de los porcentajes legales por un asesor laboral externo (sección 3).

## 2. Trabajo completado

### Base (verificado 2026-08-23) y MS365 (cerrado 2026-08-24)
Ver historial en secciones anteriores de este documento (conservado en el repo si se necesita el detalle exacto de esas fechas) — resumen: arquitectura MVC completa, motor de cálculo legal con datos historizados, RBAC, instalador web, actualizaciones sin Terminal, SSO Microsoft 365 confirmado funcionando de extremo a extremo en producción con App Registration propio.

### 2026-08-24: responsive, novedades por rango, reportes, personalización del sitio
- Diseño responsive completo (sidebar colapsable, tablas con scroll propio) — **[VERIFICADO]** en producción tras purgar caché de Cloudflare.
- Novedades por rango de fechas (una fila por día, sin cambiar el esquema) — **[VERIFICADO]**.
- Reporte "Horas trabajadas (registro)" con export Excel y columnas por tipo de recargo — **[VERIFICADO]**.
- Personalización del sitio (nombre/logo/footer) — **[VERIFICADO]**, con un incidente real (500 en todo el sitio por `configuracion_sitio` inexistente) diagnosticado, reproducido en local y corregido con hotfix antes de que el usuario lo confirmara resuelto.
- Fix de logo ilegible en el sidebar oscuro — **[VERIFICADO]**.

### 2026-08-25: almuerzo, corrección de marcaciones, nómina, áreas
- **Descuento automático de hora de almuerzo** (`configuracion_global.almuerzo_activo`/`hora_inicio_almuerzo`/`hora_fin_almuerzo`, por defecto inactivo). Aplicado igual en el motor legal y en el reporte de registro, solo cuando el día no viene ya partido en 2+ bloques/marcaciones. **[VERIFICADO]**: 3 pruebas nuevas de PHPUnit (bloque continuo se descuenta, turno partido no se descuenta doble, bloque que no cubre la ventana no se toca) + verificación en vivo en el reporte de registro.
- **Corrección manual de marcaciones** (`/registros-tiempo/crear|editar|eliminar`, permiso `registros_tiempo.corregir`, RRHH/Admin) con motivo obligatorio, y **campo de observaciones opcional** al marcar salida (columna `comentario` que existía sin usar desde el diseño original). **[VERIFICADO]** en navegador: crear, editar, eliminar, y el campo de observaciones apareciendo solo al marcar salida.
- **Diagnóstico de un caso real de marcación mal etiquetada**: se confirmó por qué una entrada sin salida en un día cascadeaba a una "salida" mal etiquetada al día siguiente — el sistema decide el próximo tipo de marcación mirando solo el último registro de la persona, sin reiniciar a medianoche (comportamiento de diseño, no un bug nuevo; la pantalla de corrección de este mismo punto es la solución).
- **Reporte "Resumen de nómina (registro)"** (`/reportes/nomina-registro`): por empleado, columnas por tipo de recargo, total, y columna de días incompletos. Decisión explícita del usuario: basado en horas **trabajadas** (registro), separado de "Horas extra y recargos" que sigue basado en horario **programado** — dos fuentes de verdad a propósito. **[VERIFICADO]** con escenario real de Supervisor + equipo, incluida verificación de que el Excel exportado no contiene datos de fuera del equipo.
- **"Horas extra y recargos" extendido**: periodo por defecto = mes calendario actual (auto-creado si el usuario tiene `calculo.ejecutar`), y alcance por área para Supervisor (antes veía a todos). **[VERIFICADO]**.
- **Áreas/equipos**: nueva tabla `areas` + `empleados.area_id`, reemplaza `empleados.supervisor_id` como mecanismo de alcance **en absolutamente todas las pantallas que antes filtraban por equipo** (empleados, horarios, novedades, calendario, registros de entrada/salida, días compensatorios, los tres reportes). "Ver todas las áreas" es un permiso normal (`equipos.ver_todas`), no una columna booleana aparte — ajustable por rol sin tocar código. **[VERIFICADO]** con un escenario real de dos áreas y tres empleados: alcance correcto en cada pantalla, y los dos fixes de seguridad de abajo confirmados con pruebas directas (no solo visuales). El usuario ya creó las áreas reales y asignó los empleados en producción, y confirmó que todo funciona — **[SOLO CONTEXTO DE SESIÓN, alta confianza]**, consistente con la verificación externa que sí pude hacer (curl a `/login`, `/`, `/admin/areas` sin 500 tras el despliegue).
- **Dos huecos de seguridad reales encontrados y cerrados** durante el trabajo de áreas (no hipotéticos — confirmados con pruebas de acceso cruzado real): `HorarioController` no autorizaba nada (cualquiera con permiso de horarios podía ver/editar el horario de cualquier empleado cambiando el id en la URL); `NovedadController::aprobar/rechazar` no validaba que la novedad perteneciera al área de quien aprueba. Ambos corregidos con 403 explícito.

## 3. Trabajo pendiente

1. **Validación de los porcentajes legales por un asesor laboral real** — documento `.docx` ya generado y entregado al usuario en una sesión anterior, respuesta desconocida. Sigue siendo el pendiente sustantivo más importante.
2. **Sincronización de horarios hacia Microsoft 365 (Outlook Calendar)** — solo se hizo el análisis de pros/contras/seguridad (el usuario prefiere permiso de aplicación sobre delegado); **no hay código escrito todavía**. Ver [CLAUDE_horarios.md](CLAUDE_horarios.md) sección 8 para los puntos clave a retomar (Application Access Policy para acotar el permiso, App Registration separado, marcar eventos propios, loguear escrituras).
3. Confirmar backups automáticos activados en cPanel/BlueHost — mencionado hace tiempo, nunca confirmado.
4. Posible mejora futura mencionada pero no solicitada: "aprobar todo un rango de novedades de una vez" (hoy cada día de un rango es una fila de aprobación independiente).

## 4. Decisiones importantes (las más recientes)

- **Áreas reemplaza a `supervisor_id` para todo lo que sea "alcance de equipo"** — `supervisor_id` no tenía datos reales en producción y no reflejaba cómo INALDE organiza realmente su personal (por departamento, no por cadena de mando individual). `supervisor_id` se conserva solo como dato informativo, y el formulario de empleado lo aclara explícitamente para no confundir a RRHH.
- **"Ver todas las áreas" es un permiso (`equipos.ver_todas`), no una columna booleana con UI a la medida** — consistente con cómo ya funciona todo el RBAC del proyecto (permisos por rol, checkboxes en `/admin/roles`), y permite crear roles personalizados a futuro sin tocar código si se necesita una excepción por persona.
- **"Horas extra y recargos" (programado) y "Resumen de nómina (registro)" (trabajado) coexisten a propósito** — el usuario decidió explícitamente no migrar el motor legal a horas reales, y en cambio construir el reporte de registro como la vía para ver pago según trabajo real.
- **El descuento de almuerzo solo aplica a bloques/días sin partir** — para no restar dos veces cuando ya hay un turno partido o marcación real de almuerzo. Decisión de diseño propia (no pedida explícitamente en esos términos, pero necesaria para evitar un bug de doble descuento).

## 5. Archivos relevantes para retomar el trabajo

- [CLAUDE_horarios.md](CLAUDE_horarios.md) — contexto operativo/arquitectónico completo (léelo primero)
- `app/Models/AreaModel.php`, `app/Core/Auth::veTodasLasAreas()`, `EmpleadoModel::delArea()` — el nuevo mecanismo de alcance
- `app/Controllers/HorarioController::empleadoAutorizadoOAbortar()`, `NovedadController::puedeGestionarNovedad()` — el patrón de autorización por fila que hay que replicar en cualquier acción nueva que reciba un id de un recurso de un empleado
- `app/Services/CalculoRecargosService.php` y `app/Services/ReporteHorasRegistroService.php` — ambos con `descontarAlmuerzo()` duplicado a propósito
- `app/Controllers/RegistroTiempoController.php` — incluye ahora la corrección manual (`crear`/`editar`/`eliminar`) además de la auto-marcación
- `tests/Unit/CalculoRecargosServiceTest.php` — 20 pruebas, correr con `vendor/bin/phpunit` antes y después de cualquier cambio al motor de cálculo

## 6. Instrucciones para continuar en una nueva sesión

1. Lee primero [CLAUDE_horarios.md](CLAUDE_horarios.md) completo.
2. Confirma que MySQL (XAMPP) y Apache estén corriendo antes de tocar el proyecto local.
3. Corre `vendor/bin/phpunit` (debe dar 20/20) antes de cualquier cambio.
4. Si agregas una acción nueva que reciba un `{id}` de un recurso perteneciente a un empleado (aprobar, editar, eliminar, ver detalle), replica el patrón de `HorarioController::empleadoAutorizadoOAbortar()` — filtrar la lista no basta, hay que autorizar la acción también.
5. Si agregas una tabla nueva cuya configuración se lea desde `layouts/app.php` o el login, protégela con try/catch y valores por defecto (ver `ConfiguracionSitioModel::obtener()`).
6. Antes de desplegar CSS/JS estático a producción, purga la caché de Cloudflare para esa URL después de subir el archivo.
7. El pendiente sustantivo más importante sigue siendo la validación profesional de los porcentajes legales (sección 3, punto 1). El siguiente tema más probable a retomar es la sincronización de calendario con Microsoft 365 (sección 3, punto 2), que ya tiene el análisis de seguridad hecho pero cero código.

## 7. Qué información podría perderse al compactar el contexto

- El hecho de que `supervisor_id` **ya no controla nada de acceso** — sigue existiendo en el formulario de empleado como dato de organigrama, pero confundirlo con el mecanismo de alcance real (área) sería un error fácil de cometer sin este documento.
- El hecho de que "Horas extra y recargos" y "Resumen de nómina (registro)" son **deliberadamente** dos fuentes de verdad distintas (programado vs. trabajado) — no es un descuido ni duplicación accidental.
- El análisis ya hecho (pero no implementado) sobre sincronización con Microsoft 365 Calendar — si no queda registrado, una sesión nueva tendría que rehacer todo el análisis de seguridad de permisos de aplicación vs. delegados.
- El patrón "filtrar una lista no es autorizar una acción" y los dos huecos reales que causó (`HorarioController`, `NovedadController::aprobar/rechazar`) — vale la pena revisarlo activamente cada vez que se agregue una acción nueva sobre un recurso de un empleado.
- El patrón confirmado de que Cloudflare sirve assets estáticos cacheados y desactualizados tras un despliegue — hay que purgar la caché de esa URL específica, no basta con subir el archivo nuevo.
