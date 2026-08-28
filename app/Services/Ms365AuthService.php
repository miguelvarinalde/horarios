<?php

namespace App\Services;

use App\Models\ConfiguracionMs365Model;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;

/**
 * Autenticacion "Iniciar sesion con Microsoft" (Fase 2) via OAuth2 /
 * OpenID Connect contra Entra ID (Azure AD), usando el endpoint v2.0 del
 * tenant especifico configurado (no el endpoint multi-tenant "common"),
 * de modo que solo cuentas de ese tenant puedan autenticarse.
 *
 * La configuracion (tenant/client id/secret/redirect uri) vive en la tabla
 * configuracion_ms365, editable desde /admin/ms365, no en .env — asi se
 * puede rotar el client secret u otros valores sin tocar archivos ni
 * redesplegar.
 *
 * No usa una libreria especifica de Microsoft: el proveedor generico de
 * league/oauth2-client apuntado a los endpoints de Microsoft es suficiente,
 * y el perfil (oid/correo/nombre) se obtiene llamando a Microsoft Graph
 * (/me) con el access token, en vez de decodificar el id_token a mano
 * (evita tener que verificar la firma JWT nosotros mismos).
 */
class Ms365AuthService
{
    private GenericProvider $provider;

    public function __construct()
    {
        $config = ConfiguracionMs365Model::obtener();

        $this->provider = new GenericProvider([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
            'urlAuthorize' => "https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/token",
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'responseResourceOwnerId' => 'id',
        ]);
    }

    public static function configurado(): bool
    {
        return ConfiguracionMs365Model::configurado();
    }

    /** @return array{0:string,1:string} [url de autorizacion, state para validar el callback] */
    public function urlAutorizacion(): array
    {
        $url = $this->provider->getAuthorizationUrl([
            'scope' => 'openid profile email User.Read',
        ]);
        return [$url, $this->provider->getState()];
    }

    public function intercambiarCodigo(string $code): AccessTokenInterface
    {
        return $this->provider->getAccessToken('authorization_code', ['code' => $code]);
    }

    /** @return array{oid:string, email:string, upn:string, nombre:string} */
    public function obtenerPerfil(AccessTokenInterface $token): array
    {
        $datos = $this->provider->getResourceOwner($token)->toArray();

        $email = $datos['mail'] ?? $datos['userPrincipalName'] ?? null;
        if (!$email) {
            throw new RuntimeException('La cuenta de Microsoft no tiene un correo (mail/userPrincipalName) disponible.');
        }
        $oid = $datos['id'] ?? null;
        if (!$oid) {
            throw new RuntimeException('Microsoft Graph no devolvio un identificador de usuario (oid).');
        }

        return [
            'oid' => $oid,
            'email' => strtolower($email),
            'upn' => $datos['userPrincipalName'] ?? $email,
            'nombre' => $datos['displayName'] ?? $email,
        ];
    }
}
