<?php use App\Core\Session; use App\Core\View; ?>

<?php if (!$empleado): ?>
    <div class="card">
        <h2>Marcar entrada / salida</h2>
        <p class="text-muted">Tu usuario no tiene un empleado asociado, por lo que no puedes registrar entrada/salida. Pide a RRHH que vincule tu usuario a tu registro de empleado.</p>
    </div>

<?php elseif ($necesitaConsentimiento): ?>
    <div class="card">
        <h2>Uso de tu ubicacion</h2>
        <p>
            Para registrar tu entrada y salida, este sistema solicita acceso a la ubicacion de tu dispositivo
            en el momento de cada marcacion, con el fin de dejar constancia del lugar desde donde se realizo
            el registro. Esta informacion se usa unicamente para fines de control horario y auditoria interna,
            conforme a la Ley 1581 de 2012 (proteccion de datos personales). Si no otorgas el permiso de
            ubicacion al navegador, tu marcacion se registrara igualmente, dejando constancia de que la
            ubicacion no estuvo disponible.
        </p>
        <form method="post" action="/registros-tiempo/consentimiento">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <button type="submit" class="btn btn-primary">Entiendo y acepto</button>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h2>Marcar <?= $siguienteTipo === 'entrada' ? 'entrada' : 'salida' ?></h2>
        <p class="text-muted">Al presionar el boton se intentara capturar tu ubicacion actual (puede tardar unos segundos) y luego se registrara la marcacion, incluso si la ubicacion no esta disponible.</p>

        <form id="form-marcar" method="post" action="/registros-tiempo">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <input type="hidden" id="input-lat" name="lat" value="">
            <input type="hidden" id="input-lon" name="lon" value="">
            <input type="hidden" id="input-precision" name="precision" value="">
            <input type="hidden" id="input-estado" name="ubicacion_estado" value="">
            <input type="hidden" id="input-fecha-cliente" name="fecha_hora_cliente" value="">

            <?php if ($siguienteTipo === 'salida'): ?>
                <div class="form-group">
                    <label>Observaciones (opcional)</label>
                    <textarea name="comentario" rows="2" maxlength="255" placeholder="Ej. Sali temprano por cita medica, permiso, etc."></textarea>
                </div>
            <?php endif; ?>

            <button type="submit" id="btn-marcar" class="btn btn-primary btn-marcar-tiempo">
                Marcar <?= $siguienteTipo === 'entrada' ? 'entrada' : 'salida' ?>
            </button>
            <p id="estado-ubicacion-texto" class="text-muted" style="margin-top:.5rem"></p>
        </form>
    </div>

    <div class="card">
        <h2>Tu historial reciente</h2>
        <div class="table-responsive">
        <table>
            <thead><tr><th>Tipo</th><th>Fecha y hora</th><th>Ubicacion</th><th>Observaciones</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $r): ?>
                <tr>
                    <td><?= $r['tipo'] === 'entrada' ? '<span class="badge badge-aprobado">Entrada</span>' : '<span class="badge badge-pendiente">Salida</span>' ?></td>
                    <td><?= View::e($r['fecha_hora']) ?></td>
                    <td>
                        <?php if ($r['ubicacion_estado'] === 'capturada' && $r['latitud'] !== null): ?>
                            <a href="https://www.google.com/maps?q=<?= View::e((string) $r['latitud']) ?>,<?= View::e((string) $r['longitud']) ?>" target="_blank" rel="noopener">
                                Ver mapa (&plusmn;<?= round((float) $r['precision_metros']) ?>m)
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Sin ubicacion (<?= View::e($r['ubicacion_estado']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['comentario'] ? View::e($r['comentario']) : '<span class="text-muted">&mdash;</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($historial)): ?>
                <tr><td colspan="4" class="text-muted">Aun no tienes marcaciones registradas.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <script src="/assets/js/marcar-tiempo.js"></script>
<?php endif; ?>
