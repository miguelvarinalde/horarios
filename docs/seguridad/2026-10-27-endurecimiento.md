# Auditoría de sitio y endurecimiento (2026-10-27)

Alcance: `https://horarios.inalde.edu.co` en producción (solo lectura: cabeceras, cookies, rutas, TLS, métodos HTTP) más el código de la aplicación. Esta revisión complementa la del 2026-10-09 (`2026-10-09-auditoria-seguridad.md`, ya corregida).

> **Sobre "pasar todos los tests".** Ningún escáner (Mozilla Observatory, securityheaders.com, SSL Labs) da una nota perfecta garantizada, y parte de lo que miden vive en Cloudflare y en el DNS, no en el código. Este documento separa lo que ya quedó corregido en el código de lo que hay que cambiar en Cloudflare, BlueHost o el DNS.

## 1. Hallazgos

| # | Prioridad | Hallazgo | Estado |
|---|---|---|---|
| 1 | **Alta** | **Geolocalización bloqueada desde la red de la Universidad.** El DNS interno (`Telescopii.unisabana.edu.co`) resuelve `horarios.inalde.edu.co` directo al origen (`50.6.3.199`), saltándose Cloudflare. El hosting agrega su propio `Permissions-Policy` con `geolocation=()`, que llega **duplicado** junto al de la app (`geolocation=(self)`); el navegador aplica el último y el marcado con ubicación queda bloqueado. Comprobado con un navegador real desde esta red: `featurePolicy.allowsFeature('geolocation')` = `false`. | Corregido en código (`public/.htaccess`); ver sección 3 para el DNS |
| 2 | Media | El origen responde `200` por **HTTP plano** (sin redirigir a HTTPS) cuando se llega directo. | Corregido (`public/.htaccess`) |
| 3 | Media | `security.txt` (lo sirve Cloudflare) está **vencido** (`Expires: 2026-05-31`) y mal formado (`Contact: mailto://...`). | Pendiente en Cloudflare (sección 3); el archivo correcto ya está en el repo |
| 4 | Media | Las otras sesiones de un usuario seguían activas después de cambiar o resetear su contraseña. | Corregido |
| 5 | Media | El login revelaba si un correo existía: texto distinto en cuenta bloqueada y respuesta más rápida con correo inexistente. | Corregido |
| 6 | Media | Contraseña mínima de 8 caracteres, sin ninguna otra regla. | Corregido (política NIST, sección 2) |
| 7 | Baja | Las sesiones PHP se guardaban en el directorio temporal compartido del hosting y se borraban a los 24 min (`gc_maxlifetime`), antes que la cookie de 8 h. | Corregido |
| 8 | Baja | La CSP permitía `'unsafe-inline'` en `style-src` (hojas de estilo en línea). | Corregido parcialmente (sección 2) |
| 9 | Baja | Excel y PDF con datos personales se enviaban con `Cache-Control: max-age=0` (cacheables por el navegador). | Corregido |
| 10 | Baja | Faltaban `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` y `X-Permitted-Cross-Domain-Policies`; `X-Powered-By` podía exponerse. | Corregido |
| 11 | Info | Cloudflare agrega `Expect-CT` (obsoleto) y `X-XSS-Protection: 1; mode=block` (obsoleto) y reescribe `X-Frame-Options` a `SAMEORIGIN`. | Sin acción (la CSP `frame-ancestors 'none'` ya cubre el framing) |

Lo que **ya estaba bien**: TLS 1.0 y 1.1 deshabilitados (solo 1.2 y 1.3), certificados válidos, HTTP→HTTPS por Cloudflare (301), sin CORS, `TRACE/OPTIONS/PUT/DELETE` rechazados (405), `/.env` y `/database/*.sql` bloqueados (406), `/vendor`, `/app`, `/config`, `/storage`, `/.git`, `/docs` y `composer.json` no accesibles (404), `/instalar` cerrado, listados de directorios (403), cookie de sesión `Secure; HttpOnly; SameSite=Lax`, HSTS con `includeSubDomains; preload`, CSP sin scripts en línea, `Cache-Control: no-store` en páginas dinámicas, `composer audit` sin avisos.

## 2. Cambios en el código

- **`public/.htaccess`**: quita las copias duplicadas de `Permissions-Policy`, `Referrer-Policy` y `X-Content-Type-Options` que agrega el hosting y deja una sola; redirige HTTP→HTTPS solo para `*.inalde.edu.co` y solo si ni el servidor, ni `X-Forwarded-Proto`, ni `CF-Visitor` indican que el visitante ya usa HTTPS (sin bucles con Cloudflare "Flexible"); bloquea `.env`, `.sql`, `.md`, `.json`, `.lock`, etc. y archivos ocultos (menos `.well-known`); cachea un año los recursos estáticos versionados.
- **`SecurityHeaders`**: CSP más estricta (`style-src 'self'`, `style-src-elem 'self'`, `script-src-attr 'none'`, `frame-src 'none'`, `worker-src 'none'`, `media-src 'none'`, `manifest-src 'self'`, `upgrade-insecure-requests` con HTTPS), COOP `same-origin`, CORP `same-origin`, `X-Permitted-Cross-Domain-Policies: none`, `Permissions-Policy` ampliado (sensores, USB, serial, HID, Bluetooth, captura de pantalla), HSTS con `preload`, y elimina `X-Powered-By`. Única excepción: `style-src-attr 'unsafe-inline'`, porque las vistas usan unos 100 atributos `style=""`; así no se puede inyectar una hoja de estilos ni un `<style>`. Migrar esos atributos a clases y quitar la excepción es una mejora futura.
- **Sesión**: cookie `__Host-horarios_session` con HTTPS (Secure, `Path=/`, sin `Domain`: ningún subdominio puede sobrescribirla), almacenamiento propio en `storage/sessions` fuera de `public/`, vida del lado servidor igual a la de la cookie, cierre por inactividad (`SESSION_IDLE_MINUTES`, 240 por defecto) y cierre de todas las demás sesiones de una cuenta cuando cambia su contraseña (cambio propio o reseteo por RRHH/Administrador).
- **Login**: mismo mensaje y mismo tiempo de respuesta exista o no el correo y esté o no bloqueada la cuenta (hash ficticio).
- **`PoliticaPasswordService`** (cambio de contraseña, contraseña manual desde Usuarios/Empleados e instalador): mínimo 10 caracteres, máximo 72 (bcrypt trunca más allá), sin palabras comunes (con o sin números y "leet": `Password2026!`, `C0ntrasena123`, `Inalde2026!!`), sin secuencias de teclado/números, sin repeticiones, sin contener el correo y distinta de la actual.
- **Excel y PDF**: `Cache-Control: no-store, private`.
- **Otros**: la página de error ya no lleva `<style>` en línea (`error.css`), `robots.txt` con `Disallow: /`, `public/.well-known/security.txt` correcto (vence 2027-10-27).

Verificación: 98 pruebas PHPUnit (435 aserciones; 12 nuevas en `EndurecimientoTest`), 20 comprobaciones HTTP nuevas, las baterías de seguridad (25) y regresión (20) del 2026-10-09, y pruebas del `.htaccess` en un Apache real simulando las cabeceras duplicadas del hosting.

## 3. Lo que hay que hacer fuera del código

1. **DNS interno de la Universidad** (el más importante). Pedir que `horarios.inalde.edu.co` resuelva a los registros públicos de Cloudflare, no a `50.6.3.199`. Mientras tanto, el `.htaccess` nuevo evita el bloqueo de la geolocalización y el HTTP plano, pero el tráfico desde la red interna sigue sin pasar por el WAF de Cloudflare.
2. **security.txt en Cloudflare**: en el panel (Security → Settings → *security.txt*, o la regla que lo sirve) cambiar a `Contact: mailto:helpdesk@inalde.edu.co` y `Expires` a una fecha futura. Mientras Cloudflare lo sirva, el archivo del repo no se usa por ese camino.
3. **Limitar intentos de login por IP** (hoy solo hay bloqueo por cuenta, 5 intentos / 15 min): crear una regla de *Rate limiting* en Cloudflare para `POST /login`, por ejemplo 10 solicitudes por minuto por IP con acción *Managed Challenge*. Opcional: activar *Bot Fight Mode*.
4. **Cerrar el acceso directo al origen**: cuando el DNS interno ya pase por Cloudflare, restringir el origen a las IP de Cloudflare o activar *Authenticated Origin Pulls*. No hacerlo antes, o se corta el acceso desde la red interna.
5. Confirmar en Cloudflare: *Always Use HTTPS* activo, TLS mínimo 1.2, modo SSL *Full (strict)*, DNSSEC activo, y que ninguna *Transform Rule* reescriba `Permissions-Policy`.

## 4. Despliegue

1. Hacer una copia de `public/.htaccess` del servidor (es el archivo de mayor riesgo; si algo falla, renombrarlo a `.htaccess.off` y el sitio vuelve a como estaba).
2. Subir el zip y comprobar `https://horarios.inalde.edu.co/login`.
3. **Todos los usuarios tendrán que iniciar sesión una vez más**: la cookie cambia de nombre (`__Host-`) y las sesiones se mueven a `storage/sessions`.
4. Desde un equipo de la red interna, abrir el sitio y en la consola del navegador ejecutar `document.featurePolicy.allowsFeature('geolocation')`: debe dar `true`.
5. Comprobar `curl -sI https://horarios.inalde.edu.co/login`: una sola cabecera `Permissions-Policy`.
6. Probar "Marcar entrada/salida" con ubicación, el cambio de contraseña, un Excel y un PDF.
