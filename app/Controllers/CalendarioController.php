<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\EmpleadoModel;
use App\Models\HorarioBaseModel;
use App\Models\NovedadModel;
use DateTimeImmutable;

class CalendarioController
{
    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 0 => 'Domingo'];

    public function index(Request $request): string
    {
        $ancla = $request->query('semana') ?: date('Y-m-d');
        $semana = $this->fechasDeLaSemana($ancla);

        $usuario = Auth::usuario();
        $rol = $usuario['rol_nombre'] ?? '';
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        if ($rol === 'Empleado') {
            $empleados = $empleadoPropio ? [$empleadoPropio] : [];
        } elseif (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $empleados = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
        } else {
            $empleados = array_filter(EmpleadoModel::todosConSupervisor(), fn ($e) => $e['activo']);
        }

        $datos = [];
        foreach ($empleados as $empleado) {
            $porDia = [];
            foreach ($semana as $diaSemana => $fecha) {
                $porDia[$diaSemana] = [
                    'fecha' => $fecha,
                    'bloques' => HorarioBaseModel::vigenteEnFecha((int) $empleado['id'], $fecha),
                    'novedades' => NovedadModel::aprobadasEnFecha((int) $empleado['id'], $fecha),
                ];
            }
            $datos[] = ['empleado' => $empleado, 'dias' => $porDia];
        }

        $lunes = new DateTimeImmutable($semana[1]);

        return View::render('calendario/index', [
            'semana' => $semana,
            'dias' => self::DIAS,
            'datos' => $datos,
            'semanaAnteriorAncla' => $lunes->modify('-7 days')->format('Y-m-d'),
            'semanaSiguienteAncla' => $lunes->modify('+7 days')->format('Y-m-d'),
        ]);
    }

    /** @return array<int, string> dia_semana (0=domingo..6=sabado) => Y-m-d */
    private function fechasDeLaSemana(string $ancla): array
    {
        $cursor = new DateTimeImmutable($ancla);
        $iso = (int) $cursor->format('N');
        $lunes = $cursor->modify('-' . ($iso - 1) . ' days');

        $dias = [];
        for ($i = 0; $i < 7; $i++) {
            $fecha = $lunes->modify("+{$i} days");
            $dias[(int) $fecha->format('w')] = $fecha->format('Y-m-d');
        }
        // reordena para iterar lunes..domingo en las vistas
        return [1 => $dias[1], 2 => $dias[2], 3 => $dias[3], 4 => $dias[4], 5 => $dias[5], 6 => $dias[6], 0 => $dias[0]];
    }
}
