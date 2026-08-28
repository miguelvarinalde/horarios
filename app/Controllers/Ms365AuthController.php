<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\UsuarioModel;
use App\Models\UsuarioMs365Model;
use App\Services\Ms365AuthService;

class Ms365AuthController
{
    public function iniciar(Request $request)
    {
        if (!Ms365AuthService::configurado()) {
            Response::abort(404, 'El inicio de sesion con Microsoft 365 no esta configurado en este servidor.');
            return;
        }

        $service = new Ms365AuthService();
        [$url, $state] = $service->urlAutorizacion();
        Session::put('ms365_oauth_state', $state);

        Response::redirect($url);
    }

    public function callback(Request $request)
    {
        if (!Ms365AuthService::configurado()) {
            Response::abort(404, 'El inicio de sesion con Microsoft 365 no esta configurado en este servidor.');
            return;
        }

        $estadoEsperado = Session::get('ms365_oauth_state');
        Session::forget('ms365_oauth_state');

        if (!$estadoEsperado || $request->query('state') !== $estadoEsperado) {
            Session::flash('error', 'La sesion de inicio de sesion con Microsoft expiro o no es valida. Intenta de nuevo.');
            Response::redirect('/login');
            return;
        }

        if ($request->query('error')) {
            Session::flash('error', 'Microsoft no autorizo el inicio de sesion: ' . $request->query('error_description', (string) $request->query('error')));
            Response::redirect('/login');
            return;
        }

        $code = $request->query('code');
        if (!$code) {
            Session::flash('error', 'No se recibio el codigo de autorizacion de Microsoft.');
            Response::redirect('/login');
            return;
        }

        try {
            $service = new Ms365AuthService();
            $token = $service->intercambiarCodigo($code);
            $perfil = $service->obtenerPerfil($token);
        } catch (\Throwable $e) {
            Session::flash('error', 'No fue posible validar la cuenta de Microsoft: ' . $e->getMessage());
            Response::redirect('/login');
            return;
        }

        $vinculo = UsuarioMs365Model::porAzureOid($perfil['oid']);

        if ($vinculo) {
            $usuario = UsuarioModel::withRol((int) $vinculo['usuario_id']);
            UsuarioMs365Model::marcarLogin((int) $vinculo['id']);
        } else {
            // Primera vez que esta cuenta de Microsoft inicia sesion aqui:
            // se vincula por correo a un usuario YA EXISTENTE (no se crea
            // uno nuevo). El usuario debe haber sido creado antes por
            // RRHH/Administrador, con el mismo correo que en Microsoft 365.
            $usuario = UsuarioModel::findByEmail($perfil['email']);
            if (!$usuario) {
                Session::flash('error', "Tu cuenta de Microsoft ({$perfil['email']}) no tiene un usuario creado en este sistema. Pide a RRHH o al Administrador que te cree uno primero, usando el mismo correo corporativo.");
                Response::redirect('/login');
                return;
            }

            UsuarioMs365Model::vincular((int) $usuario['id'], $perfil['oid'], $perfil['upn']);
            $usuario = UsuarioModel::withRol((int) $usuario['id']);
        }

        if (!$usuario['activo']) {
            Session::flash('error', 'Tu usuario esta inactivo. Contacta al Administrador.');
            Response::redirect('/login');
            return;
        }

        if (!empty($usuario['debe_cambiar_password'])) {
            UsuarioModel::limpiarBanderaCambioPassword((int) $usuario['id']);
        }

        Auth::login($usuario);
        UsuarioModel::marcarLogin((int) $usuario['id']);

        Response::redirect('/');
    }
}
