<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seeder;
use App\Core\Session;
use App\Core\View;

/**
 * Permite aplicar migraciones pendientes Y los catalogos base (seeds:
 * roles, permisos, tipos de novedad/recargo) desde el navegador, sin
 * necesitar Terminal/SSH — util para actualizar produccion en BlueHost
 * despues de subir codigo nuevo por FTP.
 *
 * Los seeds se reaplican SIEMPRE junto con las migraciones (no solo cuando
 * hay migraciones pendientes): son idempotentes (INSERT IGNORE), y una
 * actualizacion de codigo puede traer un permiso o catalogo nuevo sin
 * traer ninguna migracion de estructura — si solo se corrieran migraciones,
 * ese permiso nunca quedaria sembrado (asi paso con 'usuarios.gestionar').
 */
class ActualizacionController
{
    public function mostrar(Request $request): string
    {
        $pdo = Database::connection();
        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations';

        return View::render('admin/actualizaciones', [
            'pendientes' => Migrator::pendientes($pdo, $migrationsDir),
            'resultado' => Session::getFlash('resultado'),
        ]);
    }

    public function aplicar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $pdo = Database::connection();
        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
        $seedsDir = dirname(__DIR__, 2) . '/database/seeds';

        try {
            $migracionesAplicadas = Migrator::migrar($pdo, $migrationsDir);
            $seedsAplicados = Seeder::aplicarSeeds($pdo, $seedsDir);
        } catch (\Throwable $e) {
            Session::flash('error', 'Error aplicando la actualizacion: ' . $e->getMessage());
            Response::redirect('/admin/actualizaciones');
            return;
        }

        Session::flash('success', 'Actualizacion aplicada correctamente.');
        Session::flash('resultado', [
            'migraciones' => $migracionesAplicadas,
            'seeds' => $seedsAplicados,
        ]);
        Response::redirect('/admin/actualizaciones');
    }
}
