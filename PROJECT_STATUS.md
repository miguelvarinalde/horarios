# PROJECT_STATUS.md — Estado del proyecto Horarios INALDE

> Generado el 2026-08-23, actualizado el 2026-08-24, el 2026-08-25, y **actualizado de nuevo el 2026-09-16** tras varias sesiones adicionales: multi-área para supervisores (refactor grande en `AlcanceAreasService`), mejoras de geolocalización, ajustes de almuerzo/advertencias de horario, y — lo más reciente — el cierre automático y el motor legal de nómina respetando permisos aprobados, con reportes que ahora muestran las novedades de cada día. Ver también [CLAUDE_horarios.md](CLAUDE_horarios.md) para el contexto operativo/arquitectónico completo y el detalle incidente por incidente.
>
> **Convención**: **[VERIFICADO]** = confirmado por inspección directa de código, BD o prueba HTTP/navegador real. **[NO VERIFICADO]** = cambio hecho sin prueba de extremo a extremo confirmada. **[SOLO CONTEXTO DE SESIÓN]** = lo relató el usuario en el chat pero no se pudo confirmar de forma independiente.

## 1. Estado actual del proyecto

Sistema funcional y **desplegado en producción** en `https://horarios.inalde.edu.co` (BlueHost + Cloudflare). **[VERIFICADO]**: `phpunit` → 27/27 tests, 139 assertions, 0 failures (última corrida el 2026-09-16). **[VERIFICADO]**: 30 archivos de migración locales (la más reciente, `030_create_supervisor_areas.sql`, ya aplicada en producción).

**Prácticamente todo lo funcional está cerrado y confirmado en producción por el usuario**: MS365 SSO, diseño responsive, novedades por rango, los tres reportes (horas extra, horas según registro, nómina según registro), personalización del sitio, descuento de almuerzo (ahora evaluado por bloque, no por día), corrección de marcaciones, áreas/equipos con soporte de multi-área por supervisor, geolocalización mejorada, y el cierre automático de salidas/motor legal respetando permisos aprobados. El único pendiente sustantivo real sigue siendo la validación de los porcentajes legales por un asesor laboral externo (sección 3).

## 2. Trabajo completado

### Base (verificado 2026-08-23), MS365 (cerrado 2026-08-24) y primera ronda de reportes/áreas (2026-08-24 a 2026-08-25)
Ver historial en secciones anteriores de este documento (conservado en el repo si se necesita el detalle exacto de esas fechas) y el detalle incidente por incidente en [CLAUDE_horarios.md](CLAUDE_horarios.md) (incidentes 1-14) — resumen: arquitectura MVC completa, motor de cálculo legal con datos historizados, RBAC, instalador web, actualizaciones sin Terminal, SSO Microsoft 365 confirmado en producción, diseño responsive, novedades por rango, personalización del sitio, descuento automático de almuerzo (primera versión), corrección manual de marcaciones, reporte "Resumen de nómina (registro)", y el reemplazo de `supervisor_id` por `areas`/`equipos.ver_todas` como mecanismo de alcance en todo el sistema (con dos huecos de seguridad reales encontrados y cerrados: `HorarioController` sin autorización por fila, `NovedadController::aprobar/rechazar` sin validar pertenencia al área).

### 2026-09-02: cierre automático de HOY, columnas de entrada/salida, redondeo, umbral de almuerzo
- **Cierre automático ya no se dispara sobre el día de HOY todavía en curso** — nuevo estado `en_curso`: una entrada suelta de hoy ya no inventa una salida estimada, solo indica que la jornada sigue abierta (y sí cuenta las horas de pares ya completados antes, ej. jornada fraccionada). El cierre automático real (con salida estimada) sigue aplicando sin cambios a días ya terminados. **[VERIFICADO]** con casos reales (entrada de ayer sin salida → cierra normal; entrada de hoy sin salida → "En curso").
- **"Horas trabajadas (registro)"**: nuevas columnas independientes "Entrada"/"Salida" (con su versión redondeada al bloque de 30 min más cercano) y "Total redondeado" (suma por segmento ya definitivo, no un simple resta de los redondeados — respeta turnos partidos y almuerzo). Extendido también a que cada columna de tipo de recargo muestre su versión redondeada. **[VERIFICADO]** a mano contra 2 casos reales de producción.
- **Umbral mínimo de 30 min tras el fin de almuerzo configurado** para que aplique el descuento de almuerzo (antes bastaba con llegar exactamente a la hora de fin) — a pedido del usuario tras revisar contra registros reales, con el límite de 30 min **inclusive** (13:30 con la config por defecto NO descuenta; 13:31 sí). **[VERIFICADO]** con 3 casos reales de producción, en ambos motores (legal y de registro).

### 2026-09-05: almuerzo por bloque, advertencia de bloques largos, multi-área, formulario de empleado
- **Descuento de almuerzo evaluado por BLOQUE, no por día** — corrección de fondo: antes, tener 2+ bloques en el día bastaba para no descontar nada (asumiendo que el hueco entre ellos era el almuerzo), lo cual fallaba cuando el hueco real caía mucho más tarde y un bloque individual sí cubría la ventana completa por sí solo. Ahora se evalúa cada bloque por separado, en ambos motores. **[VERIFICADO]** con un horario real (10:00-16:00 + 17:30-19:30): pasó de 8h sin descuento a 7h con el descuento correcto en el primer bloque.
- **Advertencia (no bloqueante) de bloques de 6h+ sin descanso** (Art. 167 CST) al guardar/editar un horario — nuevo flash tipo `warning`. **[VERIFICADO]**: umbral inclusive (exactamente 6.0h sí avisa), sin falsos positivos con 5h.
- **Un supervisor puede revisar varias áreas/departamentos**: nueva tabla `supervisor_areas` (migración 030) + refactor grande centralizando en `AlcanceAreasService` la lógica de alcance que estaba duplicada (con variaciones) en 6 controladores (~10 sitios). Nueva UI en `/empleados/{id}/editar` (checklist de áreas adicionales). **[VERIFICADO]** extensamente: acceso otorgado a una segunda área aparece en las 6 pantallas que dependen de alcance; acceso NO otorgado sigue dando 403; revocar el acceso lo revierte correctamente. **Requirió aplicar la migración 030 en producción**, ya hecho.
- **Formulario de empleado**: se agregó edición del correo de la cuenta de acceso (antes no había forma de corregirlo sin tocar la BD), con manejo de error claro si el correo nuevo ya está en uso. Se corrigió la alineación de los checkboxes de "áreas adicionales" (causa raíz: una regla CSS genérica de `.form-group input` también inflaba checkboxes/radios). **[VERIFICADO]** visualmente y con prueba de correo duplicado.

### 2026-09-08: geolocalización
- **Mejoras a la captura de geolocalización** tras encontrar en producción marcaciones "capturadas" con ±2000m de precisión (inútiles para verificar el lugar real): ventana de espera ampliada de 8 a 18 segundos, nuevo umbral `PRECISION_ACEPTABLE_M = 150` con etiqueta "Baja precisión" visible en los reportes, y retroalimentación en vivo mientras se espera un mejor GPS fix. **Limitación reconocida y comunicada al usuario**: nada de esto puede mejorar hardware GPS deficiente o forzar una ubicación precisa si el dispositivo no la tiene. **[VERIFICADO]** con datos reales (2000m → etiqueta visible; 15m → sin etiqueta).

### 2026-09-16: permisos aprobados respetados por cierre automático y por el motor legal
- **Cierre automático de salidas sin marcar ahora prioriza un permiso aprobado** sobre cualquier horario asumido: si existe una novedad aprobada (permiso/vacaciones/incapacidad/ausencia/descanso_compensatorio) que empieza en o después de la última entrada sin cerrar, se usa esa hora como cierre en vez de asumir 8h/7h de jornada continua o el fin del horario programado. Caso real que lo motivó: un empleado sin horario asignado, con permiso aprobado de 11:00 a 16:30, al que el sistema le contaba casi 4h de más. **[VERIFICADO]** con el caso real y 4 pruebas nuevas de PHPUnit.
- **"Horas trabajadas (registro)"** (vista y Excel) ahora muestra, por cada día, las novedades registradas (cualquier estado: aprobada/pendiente/rechazada) con tipo, horario si es parcial, y el motivo/comentario — sin tener que cruzar manualmente con la pantalla de Novedades.
- **Motor legal de nómina (`CalculoRecargosService`)**: un permiso aprobado **parcial** (con hora de inicio/fin propias) ahora también se resta del horario programado — antes solo un permiso de **día completo** suspendía algo, y uno parcial no restaba nada de los bloques del horario. **[VERIFICADO]** con datos reales de producción (un permiso de prueba de 2h partió correctamente un bloque de horario real, bajando el total exactamente esas 2h) y 3 pruebas nuevas de PHPUnit.
- Para verificar estos cambios se cargó en la base de pruebas un volcado de datos reales de producción (`inaldeed_horarios.sql`: 17 empleados, 380 marcaciones, 15 novedades) — sigue siendo la base de pruebas local vigente para cualquier verificación futura contra datos realistas.

## 3. Trabajo pendiente

1. **Validación de los porcentajes legales por un asesor laboral real** — documento `.docx` (`validacion_porcentajes_legales_horarios.docx`) ya generado y entregado al usuario, respuesta desconocida. Sigue siendo el pendiente sustantivo más importante.
2. **Sincronización de horarios hacia Microsoft 365 (Outlook Calendar)** — solo se hizo el análisis de pros/contras/seguridad (el usuario prefiere permiso de aplicación sobre delegado); **no hay código escrito todavía**. Ver [CLAUDE_horarios.md](CLAUDE_horarios.md) sección 8 para los puntos clave a retomar (Application Access Policy para acotar el permiso, App Registration separado, marcar eventos propios, loguear escrituras).
3. Confirmar backups automáticos activados en cPanel/BlueHost — mencionado hace tiempo, nunca confirmado.
4. Posible mejora futura mencionada pero no solicitada: "aprobar todo un rango de novedades de una vez" (hoy cada día de un rango es una fila de aprobación independiente).

## 4. Decisiones importantes (las más recientes)

- **Áreas reemplaza a `supervisor_id` para todo lo que sea "alcance de equipo"**, y un usuario puede supervisar varias áreas a la vez (`supervisor_areas`) sin necesitar el permiso "ve todas" — centralizado en `AlcanceAreasService` para que cualquier pantalla nueva reutilice la misma lógica de alcance en vez de duplicarla.
- **"Ver todas las áreas" es un permiso (`equipos.ver_todas`), no una columna booleana con UI a la medida** — consistente con cómo ya funciona todo el RBAC del proyecto, y permite crear roles personalizados a futuro sin tocar código.
- **"Horas extra y recargos" (programado) y "Resumen de nómina (registro)" (trabajado) coexisten a propósito** — el usuario decidió explícitamente no migrar el motor legal a horas reales, y en cambio construir el reporte de registro como la vía para ver pago según trabajo real. Sin embargo, **ambos motores ahora respetan permisos aprobados** (de día completo y parciales), manteniendo la separación programado/trabajado pero sin ese hueco de negocio en ninguno de los dos.
- **El descuento de almuerzo se evalúa por bloque/segmento individual, no por el día completo** — un turno partido cuyo hueco real no cae en la ventana de almuerzo puede, aun así, tener un bloque específico que sí la cubre y debe descontarla.
- **Un permiso aprobado es más confiable que cualquier horario asumido** para explicar por qué alguien dejó de marcar — por eso el cierre automático lo consulta ANTES que el horario programado o la jornada continua asumida (8h/7h).
- **Los umbrales que no son constantes legales fijas** (30 min tras almuerzo, 6h para la advertencia de bloques largos, 150m para "baja precisión" GPS) se manejan como constantes de clase documentadas, no valores hardcodeados sin explicación — y, en el caso de precisión GPS, deliberadamente duplicadas en JS y PHP porque una se evalúa en el navegador y la otra en los reportes del servidor.

## 5. Archivos relevantes para retomar el trabajo

- [CLAUDE_horarios.md](CLAUDE_horarios.md) — contexto operativo/arquitectónico completo, con el detalle incidente por incidente (léelo primero)
- `app/Services/AlcanceAreasService.php` — punto único de verdad para "qué áreas/empleados puede ver el usuario en sesión"; cualquier pantalla nueva que filtre por equipo debe usar esto, no reimplementarlo
- `app/Models/SupervisorAreaModel.php`, `database/migrations/030_create_supervisor_areas.sql` — áreas adicionales que un usuario puede supervisar
- `app/Controllers/HorarioController::empleadoAutorizadoOAbortar()`, `NovedadController::puedeGestionarNovedad()` — el patrón de autorización por fila que hay que replicar en cualquier acción nueva que reciba un id de un recurso de un empleado
- `app/Services/CalculoRecargosService.php` (motor legal) y `app/Services/ReporteHorasRegistroService.php` (motor de registro/auditoría) — mismas reglas de negocio (almuerzo, permisos) implementadas independientemente a propósito en cada uno, nunca compartidas por herencia; `restarVentanaDeSegmentos()`/`descontarAlmuerzo()` son el patrón para recortar segmentos de tiempo
- `app/Models/NovedadModel.php` — `aprobadasEnFecha()` (solo aprobadas, usado por el cálculo) vs. `deEmpleadoEnRango()` (cualquier estado, usado por los reportes para mostrar motivo)
- `public/assets/js/marcar-tiempo.js` — constantes de geolocalización (`PRECISION_ACEPTABLE_M`, `TIEMPO_MAXIMO_MS`) duplicadas intencionalmente en las vistas PHP que muestran precisión
- `tests/Unit/CalculoRecargosServiceTest.php` (27 pruebas totales entre ambos archivos) y `tests/Unit/ReporteHorasRegistroServiceTest.php` — correr `vendor/bin/phpunit` antes y después de cualquier cambio al motor de cálculo o al informe de registro

## 6. Instrucciones para continuar en una nueva sesión

1. Lee primero [CLAUDE_horarios.md](CLAUDE_horarios.md) completo.
2. Confirma que MySQL (XAMPP) y Apache estén corriendo antes de tocar el proyecto local.
3. Corre `vendor/bin/phpunit` (debe dar 27/27) antes de cualquier cambio.
4. Si agregas una acción nueva que reciba un `{id}` de un recurso perteneciente a un empleado (aprobar, editar, eliminar, ver detalle), replica el patrón de `HorarioController::empleadoAutorizadoOAbortar()` — filtrar la lista no basta, hay que autorizar la acción también.
5. Si agregas una pantalla nueva que necesite "qué áreas/empleados puede ver este usuario", usa `AlcanceAreasService` — no reimplementes la lógica de alcance.
6. Si agregas una tabla nueva cuya configuración se lea desde `layouts/app.php` o el login, protégela con try/catch y valores por defecto (ver `ConfiguracionSitioModel::obtener()`).
7. Antes de desplegar CSS/JS estático a producción, purga la caché de Cloudflare para esa URL después de subir el archivo.
8. Si el cambio toca reglas de negocio (almuerzo, permisos, cierre automático), verifícalo contra datos reales de producción cargados en la base de pruebas local antes de entregarlo — es el patrón que ha encontrado casi todos los bugs reales de este proyecto.
9. El pendiente sustantivo más importante sigue siendo la validación profesional de los porcentajes legales (sección 3, punto 1). El siguiente tema más probable a retomar es la sincronización de calendario con Microsoft 365 (sección 3, punto 2), que ya tiene el análisis de seguridad hecho pero cero código.

## 7. Qué información podría perderse al compactar el contexto

- El hecho de que `supervisor_id` **ya no controla nada de acceso** — sigue existiendo en el formulario de empleado como dato de organigrama, pero confundirlo con el mecanismo de alcance real (área, vía `AlcanceAreasService`) sería un error fácil de cometer sin este documento.
- El hecho de que "Horas extra y recargos" y "Resumen de nómina (registro)" son **deliberadamente** dos fuentes de verdad distintas (programado vs. trabajado) — no es un descuido ni duplicación accidental, y ambas implementan las mismas reglas de negocio (almuerzo, permisos) por separado a propósito.
- El análisis ya hecho (pero no implementado) sobre sincronización con Microsoft 365 Calendar — si no queda registrado, una sesión nueva tendría que rehacer todo el análisis de seguridad de permisos de aplicación vs. delegados.
- El patrón "filtrar una lista no es autorizar una acción" y los huecos reales que causó (`HorarioController`, `NovedadController::aprobar/rechazar`) — vale la pena revisarlo activamente cada vez que se agregue una acción nueva sobre un recurso de un empleado.
- El patrón confirmado de que Cloudflare sirve assets estáticos cacheados y desactualizados tras un despliegue — hay que purgar la caché de esa URL específica, no basta con subir el archivo nuevo.
- La prioridad exacta del cierre automático (permiso aprobado > horario programado > jornada continua asumida > jornada fraccionada) — invertir este orden reintroduciría el bug real que motivó el cambio del 2026-09-16.
- Que `NovedadModel` tiene dos métodos de lectura con propósitos distintos y no intercambiables: `aprobadasEnFecha()` (solo para cálculo, filtra por aprobado) y `deEmpleadoEnRango()` (para mostrar en reportes, cualquier estado).
