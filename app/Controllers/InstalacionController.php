<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seeder;
use App\Core\Session;
use App\Core\View;
use PDO;
use PDOException;

/**
 * Asistente de instalacion web: configura la conexion a la base de datos y
 * crea el usuario Administrador inicial sin necesidad de editar .env a mano
 * ni tener acceso SSH — util para BlueHost con solo cPanel/FTP.
 *
 * Seguridad: una vez completada la instalacion se escribe storage/instalado.lock
 * y esta pantalla deja de funcionar (redirige a /login). Tambien se considera
 * "ya instalado" automaticamente si el .env actual ya conecta a una base de
 * datos con usuarios creados (compatibilidad con instalaciones hechas antes
 * de que existiera este asistente).
 */
class InstalacionController
{
    private function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/instalado.lock';
    }

    private function yaInstalado(): bool
    {
        if (is_file($this->lockPath())) {
            return true;
        }

        try {
            $pdo = Database::connection();
            $tieneUsuarios = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
            if ($tieneUsuarios) {
                $this->crearLock();
                return true;
            }
        } catch (\Throwable $e) {
            // Sin conexion o sin tablas todavia: no esta instalado.
        }

        return false;
    }

    private function crearLock(): void
    {
        $dir = dirname($this->lockPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->lockPath(), 'Instalado el ' . date('Y-m-d H:i:s') . "\n");
    }

    public function mostrar(Request $request): string
    {
        if ($this->yaInstalado()) {
            Response::redirect('/login');
            return '';
        }

        return View::render('instalar/index', [
            'error' => Session::getFlash('error'),
            'valores' => Session::getFlash('valores') ?? [],
        ], layout: null);
    }

    public function instalar(Request $request)
    {
        if ($this->yaInstalado()) {
            Response::redirect('/login');
            return;
        }

        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Session::flash('error', 'La sesion del formulario expiro, recarga la pagina e intenta de nuevo.');
            Response::redirect('/instalar');
            return;
        }

        $dbHost = trim((string) $request->input('db_host', '127.0.0.1'));
        $dbPort = trim((string) $request->input('db_port', '3306'));
        $dbDatabase = trim((string) $request->input('db_database', ''));
        $dbUsername = trim((string) $request->input('db_username', ''));
        $dbPassword = (string) $request->input('db_password', '');
        $appUrl = rtrim(trim((string) $request->input('app_url', '')), '/');
        $adminNombre = trim((string) $request->input('admin_nombre', ''));
        $adminEmail = trim((string) $request->input('admin_email', ''));
        $adminPassword = (string) $request->input('admin_password', '');

        $valoresParaReintentar = compact('dbHost', 'dbPort', 'dbDatabase', 'dbUsername', 'appUrl', 'adminNombre', 'adminEmail');

        if (!$dbDatabase || !$dbUsername || !$appUrl || !$adminNombre || !$adminEmail || strlen($adminPassword) < 8) {
            Session::flash('error', 'Completa todos los campos. La contrasena del Administrador debe tener al menos 8 caracteres.');
            Session::flash('valores', $valoresParaReintentar);
            Response::redirect('/instalar');
            return;
        }

        // 1) Probar la conexion con los datos enviados ANTES de escribir nada.
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbDatabase);
            $pdo = new PDO($dsn, $dbUsername, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            Session::flash('error', 'No fue posible conectar a la base de datos con esos datos: ' . $e->getMessage());
            Session::flash('valores', $valoresParaReintentar);
            Response::redirect('/instalar');
            return;
        }

        // 2) Migrar y sembrar catalogos + administrador, usando esa conexion directamente
        //    (el .env todavia no existe con estos valores en este mismo request).
        try {
            Migrator::migrar($pdo, dirname(__DIR__, 2) . '/database/migrations');
            Seeder::aplicarSeeds($pdo, dirname(__DIR__, 2) . '/database/seeds');
            Seeder::crearAdministrador($pdo, $adminNombre, $adminEmail, $adminPassword);
        } catch (\Throwable $e) {
            Session::flash('error', 'La conexion funciono, pero fallo al preparar la base de datos: ' . $e->getMessage());
            Session::flash('valores', $valoresParaReintentar);
            Response::redirect('/instalar');
            return;
        }

        // Los festivos si dependen del bootstrap normal de la app (Database::connection()
        // via .env), asi que se generan despues de escribir el .env, no aqui.

        // 3) Escribir el .env con los valores ya confirmados.
        $envContenido = $this->generarEnv($appUrl, $dbHost, $dbPort, $dbDatabase, $dbUsername, $dbPassword);
        $envPath = dirname(__DIR__, 2) . '/.env';
        $escrito = @file_put_contents($envPath, $envContenido);

        if ($escrito === false) {
            // No se pudo escribir el archivo (permisos): se muestra para copiar a mano.
            return View::render('instalar/manual', ['envContenido' => $envContenido], layout: null);
        }

        // 4) Ahora si, con .env ya escrito, generar festivos vía el bootstrap normal
        //    (config/database.php lee de $_ENV, que en este mismo request todavia
        //    tiene los valores por defecto: se actualiza a mano antes de reconectar).
        Database::reset();
        $_ENV['DB_HOST'] = $dbHost;
        $_ENV['DB_PORT'] = $dbPort;
        $_ENV['DB_DATABASE'] = $dbDatabase;
        $_ENV['DB_USERNAME'] = $dbUsername;
        $_ENV['DB_PASSWORD'] = $dbPassword;
        Seeder::generarFestivos();

        $this->crearLock();

        Session::flash('success', 'Instalacion completada. Ingresa con el usuario Administrador que acabas de crear.');
        Response::redirect('/login');
    }

    private function generarEnv(string $appUrl, string $dbHost, string $dbPort, string $dbDatabase, string $dbUsername, string $dbPassword): string
    {
        $appKey = bin2hex(random_bytes(32));
        $dbPasswordEscapada = str_replace('"', '\\"', $dbPassword);

        return <<<ENV
        APP_NAME="Sistema de Horarios"
        APP_ENV=production
        APP_DEBUG=false
        APP_URL={$appUrl}
        APP_KEY={$appKey}

        DB_HOST={$dbHost}
        DB_PORT={$dbPort}
        DB_DATABASE={$dbDatabase}
        DB_USERNAME={$dbUsername}
        DB_PASSWORD="{$dbPasswordEscapada}"

        SESSION_NAME=horarios_session
        SESSION_LIFETIME=480

        # Microsoft 365 / Entra ID (Fase 2, SSO) se administra desde /admin/ms365
        # (tabla configuracion_ms365), ya no por variables de entorno.

        ENV;
    }
}
