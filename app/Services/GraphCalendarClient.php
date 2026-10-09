<?php
// app/Services/GraphCalendarClient.php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Transporte HTTP puro hacia Microsoft Graph (calendarios/eventos), sin
 * conocer nada de horarios_base/novedades — esa traduccion vive en
 * CalendarioMs365Service. Usa permiso de APLICACION (client credentials),
 * a diferencia de Ms365AuthService (login delegado): un App Registration
 * completamente distinto, ver spec seccion 2.
 */
class GraphCalendarClient
{
    private const NOMBRE_CALENDARIO = 'Horario de trabajo';

    private GenericProvider $provider;
    private Client $http;
    private ?string $token = null;

    public function __construct(string $tenantId, string $clientId, string $clientSecret)
    {
        $this->provider = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'urlAuthorize' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            'urlResourceOwnerDetails' => '',
        ]);
        $this->http = new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']);
    }

    private function token(): string
    {
        if ($this->token === null) {
            $accessToken = $this->provider->getAccessToken('client_credentials', [
                'scope' => 'https://graph.microsoft.com/.default',
            ]);
            $this->token = $accessToken->getToken();
        }
        return $this->token;
    }

    /**
     * El correo del empleado se concatena en la ruta de Graph: solo se
     * aceptan caracteres propios de un correo corporativo, para que un
     * valor con "/", "?" o "#" no pueda redirigir la llamada a otro recurso.
     */
    private function rutaUsuario(string $email): string
    {
        if (!preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\z/', $email)) {
            throw new \InvalidArgumentException('Correo no valido para la sincronizacion de calendario.');
        }

        return "users/{$email}";
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/json',
        ];
    }

    public function obtenerOCrearCalendario(string $email): string
    {
        $respuesta = $this->http->get($this->rutaUsuario($email) . "/calendars", [
            'headers' => $this->headers(),
            'query' => ['$filter' => "name eq '" . self::NOMBRE_CALENDARIO . "'"],
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        if (!empty($datos['value'][0]['id'])) {
            return $datos['value'][0]['id'];
        }

        $respuesta = $this->http->post($this->rutaUsuario($email) . "/calendars", [
            'headers' => $this->headers(),
            'json' => ['name' => self::NOMBRE_CALENDARIO],
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        return $datos['id'];
    }

    public function crearEvento(string $email, string $calendarId, array $payload): string
    {
        $respuesta = $this->http->post($this->rutaUsuario($email) . "/calendars/{$calendarId}/events", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
        $datos = json_decode((string) $respuesta->getBody(), true);
        return $datos['id'];
    }

    public function actualizarEvento(string $email, string $calendarId, string $eventId, array $payload): void
    {
        $this->http->patch($this->rutaUsuario($email) . "/calendars/{$calendarId}/events/{$eventId}", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
    }

    /** Un 404 (el evento ya no existe en Graph) se trata como exito: el resultado deseado (que no exista) ya se cumple. */
    public function eliminarEvento(string $email, string $calendarId, string $eventId): void
    {
        try {
            $this->http->delete($this->rutaUsuario($email) . "/calendars/{$calendarId}/events/{$eventId}", [
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }
}
