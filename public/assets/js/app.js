/**
 * Comportamiento no intrusivo compartido por las vistas del panel
 * (delegacion de eventos), para poder aplicar una Content-Security-Policy
 * sin 'unsafe-inline' en script-src: nada de atributos onclick/onchange/
 * onsubmit en el HTML.
 *
 * Se activa via atributos data-*:
 *   <form data-confirm="Mensaje de confirmacion">        -> confirm() antes de enviar
 *   <select data-autosubmit>                              -> envia su formulario al cambiar
 *   <input type="checkbox" data-toggle="idDelElemento">   -> muestra ese elemento si esta marcado, lo oculta si no
 *   <input type="checkbox" data-toggle-ocultar="idDelElemento"> -> oculta ese elemento si esta marcado, lo muestra si no
 *   <button data-menu-toggle>                             -> abre/cierra el menu lateral (movil)
 *   <button data-menu-close> / <div data-menu-close>       -> cierra el menu lateral (movil)
 */
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.matches && form.matches('[data-confirm]')) {
        var mensaje = form.getAttribute('data-confirm');
        if (!window.confirm(mensaje)) {
            e.preventDefault();
        }
    }
});

document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) {
        el.form.submit();
    }
});

document.addEventListener('click', function (e) {
    var el = e.target;
    if (el.matches && el.matches('[data-toggle]')) {
        var objetivo = document.getElementById(el.getAttribute('data-toggle'));
        if (objetivo) {
            objetivo.style.display = el.checked ? 'block' : 'none';
        }
    }

    if (el.matches && el.matches('[data-toggle-ocultar]')) {
        var aOcultar = document.getElementById(el.getAttribute('data-toggle-ocultar'));
        if (aOcultar) {
            aOcultar.style.display = el.checked ? 'none' : 'block';
        }
    }

    var shell = document.getElementById('app-shell');
    if (!shell) {
        return;
    }
    if (el.closest && el.closest('[data-menu-toggle]')) {
        shell.classList.toggle('menu-abierto');
    } else if (el.closest && el.closest('[data-menu-close]')) {
        shell.classList.remove('menu-abierto');
    }
});
