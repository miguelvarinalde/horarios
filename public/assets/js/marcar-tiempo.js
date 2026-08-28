/**
 * Captura la ubicacion con la mayor precision posible antes de enviar el
 * formulario de marcacion de entrada/salida.
 *
 * En vez de una sola lectura (getCurrentPosition), usa watchPosition durante
 * una ventana corta de tiempo y se queda con la lectura de menor
 * coords.accuracy (radio de incertidumbre en metros) recibida, hasta que:
 *   - se alcanza una precision objetivo (PRECISION_OBJETIVO_M), o
 *   - se agota el tiempo maximo (TIEMPO_MAXIMO_MS), en cuyo caso se usa la
 *     mejor lectura obtenida hasta ese momento (si hubo alguna).
 *
 * Nunca bloquea la marcacion: si no se pudo obtener ubicacion por cualquier
 * motivo, llama al callback con el estado correspondiente y sin coordenadas,
 * para que el formulario se envie igual.
 */
function obtenerUbicacion(callback) {
    if (!('geolocation' in navigator)) {
        callback({ estado: 'no_soportado' });
        return;
    }

    var TIEMPO_MAXIMO_MS = 8000;
    var PRECISION_OBJETIVO_M = 20;

    var mejor = null;
    var watchId = null;
    var terminado = false;

    function finalizar(estadoSiNoHayLectura) {
        if (terminado) {
            return;
        }
        terminado = true;
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
        }
        if (mejor) {
            callback({
                estado: 'capturada',
                lat: mejor.coords.latitude,
                lon: mejor.coords.longitude,
                precision: mejor.coords.accuracy,
            });
        } else {
            callback({ estado: estadoSiNoHayLectura });
        }
    }

    var timeoutId = setTimeout(function () {
        finalizar('tiempo_agotado');
    }, TIEMPO_MAXIMO_MS);

    try {
        watchId = navigator.geolocation.watchPosition(
            function (posicion) {
                if (!mejor || posicion.coords.accuracy < mejor.coords.accuracy) {
                    mejor = posicion;
                }
                if (posicion.coords.accuracy <= PRECISION_OBJETIVO_M) {
                    clearTimeout(timeoutId);
                    finalizar('capturada');
                }
            },
            function (error) {
                clearTimeout(timeoutId);
                var estado = (error.code === error.PERMISSION_DENIED) ? 'denegada' : 'no_disponible';
                finalizar(estado);
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: TIEMPO_MAXIMO_MS }
        );
    } catch (e) {
        clearTimeout(timeoutId);
        finalizar('no_disponible');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('form-marcar');
    if (!form) {
        return;
    }

    var boton = document.getElementById('btn-marcar');
    var estadoTexto = document.getElementById('estado-ubicacion-texto');
    var yaProcesado = false;

    form.addEventListener('submit', function (e) {
        if (yaProcesado) {
            return; // segundo submit (disparado por nosotros mismos): dejarlo pasar
        }
        e.preventDefault();
        boton.disabled = true;
        estadoTexto.textContent = 'Obteniendo tu ubicacion (puede tardar unos segundos)...';

        obtenerUbicacion(function (resultado) {
            document.getElementById('input-lat').value = resultado.lat !== undefined ? resultado.lat : '';
            document.getElementById('input-lon').value = resultado.lon !== undefined ? resultado.lon : '';
            document.getElementById('input-precision').value = resultado.precision !== undefined ? resultado.precision : '';
            document.getElementById('input-estado').value = resultado.estado;

            var ahora = new Date();
            var fechaHoraCliente = ahora.getFullYear() + '-' +
                String(ahora.getMonth() + 1).padStart(2, '0') + '-' +
                String(ahora.getDate()).padStart(2, '0') + ' ' +
                String(ahora.getHours()).padStart(2, '0') + ':' +
                String(ahora.getMinutes()).padStart(2, '0') + ':' +
                String(ahora.getSeconds()).padStart(2, '0');
            document.getElementById('input-fecha-cliente').value = fechaHoraCliente;

            estadoTexto.textContent = resultado.estado === 'capturada'
                ? 'Ubicacion capturada (precision aprox. ' + Math.round(resultado.precision) + 'm). Registrando...'
                : 'No fue posible obtener la ubicacion (' + resultado.estado + '). Registrando de todas formas...';

            yaProcesado = true;
            form.submit();
        });
    });
});
