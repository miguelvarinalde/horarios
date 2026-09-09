/**
 * Captura la ubicacion con la mayor precision posible antes de enviar el
 * formulario de marcacion de entrada/salida.
 *
 * En vez de una sola lectura (getCurrentPosition), usa watchPosition durante
 * una ventana de tiempo y se queda con la lectura de menor coords.accuracy
 * (radio de incertidumbre en metros) recibida, hasta que:
 *   - se alcanza una precision objetivo (PRECISION_OBJETIVO_M), o
 *   - se agota el tiempo maximo (TIEMPO_MAXIMO_MS), en cuyo caso se usa la
 *     mejor lectura obtenida hasta ese momento (si hubo alguna) — puede
 *     seguir siendo una precision mala (cientos/miles de metros) si el
 *     dispositivo nunca logro un mejor "fix" de GPS; ver PRECISION_ACEPTABLE_M
 *     mas abajo para el umbral que los reportes usan para marcarlo como tal.
 *
 * Nunca bloquea la marcacion: si no se pudo obtener ubicacion por cualquier
 * motivo, llama al callback con el estado correspondiente y sin coordenadas,
 * para que el formulario se envie igual.
 *
 * (2026-09-08, a pedido del usuario tras encontrar marcaciones "capturadas"
 * pero con +-2000m de precision, inutiles para verificar el lugar real):
 * se amplio la ventana de espera de 8 a 18 segundos — un GPS "frio" (recien
 * encendido, senal debil en interior) casi siempre necesita mas de 8s para
 * lograr un fix preciso; con mas tiempo de espera sube la probabilidad de
 * obtener una lectura realmente util en vez de conformarse con la primera
 * triangulacion por wifi/antenas (rapida pero imprecisa). Tambien se agrego
 * retroalimentacion en vivo (onProgreso) para que la persona vea que precision
 * lleva mientras espera, en vez de un mensaje fijo sin informacion.
 */
function obtenerUbicacion(callback, onProgreso) {
    if (!('geolocation' in navigator)) {
        callback({ estado: 'no_soportado' });
        return;
    }

    var TIEMPO_MAXIMO_MS = 18000;
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
                    if (typeof onProgreso === 'function') {
                        onProgreso(mejor.coords.accuracy);
                    }
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

    var PRECISION_ACEPTABLE_M = 150;
    var boton = document.getElementById('btn-marcar');
    var estadoTexto = document.getElementById('estado-ubicacion-texto');
    var yaProcesado = false;

    form.addEventListener('submit', function (e) {
        if (yaProcesado) {
            return; // segundo submit (disparado por nosotros mismos): dejarlo pasar
        }
        e.preventDefault();
        boton.disabled = true;
        estadoTexto.textContent = 'Buscando tu ubicacion (puede tardar unos segundos, mientras mas quieto y a cielo abierto, mejor)...';

        obtenerUbicacion(
            function (resultado) {
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

                if (resultado.estado === 'capturada') {
                    var precisionRedondeada = Math.round(resultado.precision);
                    estadoTexto.textContent = precisionRedondeada <= PRECISION_ACEPTABLE_M
                        ? 'Ubicacion capturada (precision aprox. ' + precisionRedondeada + 'm). Registrando...'
                        : 'Ubicacion capturada, pero con baja precision (aprox. ' + precisionRedondeada + 'm). Registrando de todas formas...';
                } else {
                    estadoTexto.textContent = 'No fue posible obtener la ubicacion (' + resultado.estado + '). Registrando de todas formas...';
                }

                yaProcesado = true;
                form.submit();
            },
            function (precisionActual) {
                // Retroalimentacion en vivo mientras se sigue buscando una
                // mejor lectura (a pedido del usuario, 2026-09-08): antes el
                // mensaje quedaba fijo sin informacion durante toda la espera.
                if (yaProcesado) {
                    return;
                }
                var precisionRedondeada = Math.round(precisionActual);
                estadoTexto.textContent = precisionRedondeada <= PRECISION_ACEPTABLE_M
                    ? 'Precision actual: ' + precisionRedondeada + 'm (buena). Confirmando...'
                    : 'Precision actual: ' + precisionRedondeada + 'm, buscando algo mejor...';
            }
        );
    });
});
