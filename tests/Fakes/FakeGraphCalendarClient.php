<?php
// tests/Fakes/FakeGraphCalendarClient.php
namespace Tests\Fakes;

use App\Services\GraphCalendarClient;

/**
 * Doble de prueba de GraphCalendarClient: no hereda su logica de red (la
 * sobreescribe por completo), solo registra las llamadas para que las
 * pruebas de CalendarioMs365Service puedan hacer aserciones sin tocar la
 * red. El constructor padre no hace I/O (Guzzle/GenericProvider no llaman a
 * nada hasta que se invoca un metodo HTTP), asi que pasar valores dummy es
 * seguro.
 */
class FakeGraphCalendarClient extends GraphCalendarClient
{
    /** @var array<int, array{email:string, payload:array}> */
    public array $eventosCreados = [];
    /** @var array<int, array{email:string, eventId:string, payload:array}> */
    public array $eventosActualizados = [];
    /** @var array<int, array{email:string, eventId:string}> */
    public array $eventosEliminados = [];
    /** @var array<string, string> email => calendarId ya "creado" */
    public array $calendariosPorEmail = [];

    private int $siguienteId = 1;

    /** @var array<string, true> event ids marcados para fallar en la proxima llamada */
    public array $fallarPara = [];

    /**
     * IDs reales de Microsoft Graph no tienen longitud maxima documentada y
     * son comunmente de 150-500+ caracteres. Permite a una prueba forzar que
     * el proximo crearEvento() devuelva un id largo especifico, en vez del
     * id secuencial corto de siempre, sin romper las demas pruebas que
     * dependen de la secuencia 'ev-fake-1', 'ev-fake-2', etc.
     */
    public ?string $siguienteIdForzado = null;

    public function __construct()
    {
        // No llama a parent::__construct(): evita construir un GenericProvider/Client reales innecesarios.
    }

    public function obtenerOCrearCalendario(string $email): string
    {
        return $this->calendariosPorEmail[$email] ??= 'cal-fake-' . $email;
    }

    public function crearEvento(string $email, string $calendarId, array $payload): string
    {
        if ($this->siguienteIdForzado !== null) {
            $id = $this->siguienteIdForzado;
            $this->siguienteIdForzado = null;
        } else {
            $id = 'ev-fake-' . $this->siguienteId++;
        }
        if (isset($this->fallarPara[$id])) {
            throw new \RuntimeException('Fallo simulado creando evento');
        }
        $this->eventosCreados[] = ['email' => $email, 'calendarId' => $calendarId, 'payload' => $payload];
        return $id;
    }

    public function actualizarEvento(string $email, string $calendarId, string $eventId, array $payload): void
    {
        if (isset($this->fallarPara[$eventId])) {
            throw new \RuntimeException('Fallo simulado actualizando evento');
        }
        $this->eventosActualizados[] = ['email' => $email, 'calendarId' => $calendarId, 'eventId' => $eventId, 'payload' => $payload];
    }

    public function eliminarEvento(string $email, string $calendarId, string $eventId): void
    {
        if (isset($this->fallarPara[$eventId])) {
            throw new \RuntimeException('Fallo simulado eliminando evento');
        }
        $this->eventosEliminados[] = ['email' => $email, 'calendarId' => $calendarId, 'eventId' => $eventId];
    }
}
