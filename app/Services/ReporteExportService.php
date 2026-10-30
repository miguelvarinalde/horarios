<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReporteExportService
{
    /**
     * Escribe texto proveniente de personas (nombres, comentarios de
     * marcacion, motivos de novedad) como celda de TEXTO. Con
     * setCellValue, un texto que empieza por "=", "+", "-" o "@" se
     * interpreta como formula y Excel la ejecutaria al abrir el archivo
     * (inyeccion de formulas).
     */
    private function escribirTexto(Worksheet $sheet, int $columna, int $fila, string $valor): void
    {
        $sheet->setCellValueExplicitByColumnAndRow($columna, $fila, $valor, DataType::TYPE_STRING);
    }

    /**
     * Resume en una sola celda de texto las novedades (cualquier estado) de
     * un dia, para la columna "Permiso / Novedad" del Excel de horas segun
     * registro: tipo, estado, horario si es parcial, y el comentario/motivo.
     *
     * @param array<int, array{tipo_nombre:string, estado:string, hora_inicio:?string, hora_fin:?string, comentario:?string}> $novedadesDelDia
     */
    private function formatearNovedadesDelDia(array $novedadesDelDia): string
    {
        $partes = [];
        foreach ($novedadesDelDia as $n) {
            $texto = $n['tipo_nombre'] . ' (' . ucfirst($n['estado']) . ')';
            if (!empty($n['hora_inicio']) && !empty($n['hora_fin'])) {
                $texto .= ' ' . substr($n['hora_inicio'], 0, 5) . '-' . substr($n['hora_fin'], 0, 5);
            }
            if (!empty($n['comentario'])) {
                $texto .= ': ' . $n['comentario'];
            }
            $partes[] = $texto;
        }
        return implode(' | ', $partes);
    }

    /**
     * Resume en una sola celda de texto los comentarios que el propio
     * empleado escribe al marcar entrada/salida (registros_tiempo.comentario)
     * — dato distinto de las novedades de RRHH (formatearNovedadesDelDia()):
     * es un campo libre, no pasa por el modulo de Novedades.
     *
     * @param array<int, array{tipo:string, fecha_hora:string, comentario:?string}> $marcacionesDelDia
     */
    private function formatearComentariosDeMarcaciones(array $marcacionesDelDia): string
    {
        $partes = [];
        foreach ($marcacionesDelDia as $m) {
            if (empty($m['comentario'])) {
                continue;
            }
            $tipo = $m['tipo'] === 'entrada' ? 'Entrada' : 'Salida';
            $hora = substr($m['fecha_hora'], 11, 5);
            $partes[] = "{$tipo} {$hora}: {$m['comentario']}";
        }
        return implode(' | ', $partes);
    }

    /**
     * @param array<int, array{empleado_nombre:string, recargos: array<string,float>, total_horas: float}> $filas
     * @param string[] $columnas codigos de tipo_recargo, en orden
     */
    public function generarExcel(array $filas, array $columnas, string $tituloPeriodo): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Horas extra y recargos');

        $col = 1;
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Empleado');
        foreach ($columnas as $codigo) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $codigo);
        }
        $sheet->setCellValueByColumnAndRow($col, 1, 'Total horas');

        $fila = 2;
        foreach ($filas as $registro) {
            $col = 1;
            $this->escribirTexto($sheet, $col++, $fila, (string) $registro['empleado_nombre']);
            foreach ($columnas as $codigo) {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['recargos'][$codigo] ?? 0);
            }
            $sheet->setCellValueByColumnAndRow($col, $fila, $registro['total_horas']);
            $fila++;
        }

        foreach (range(1, $col) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="horas_extra_' . preg_replace('/[^a-z0-9_\-]/i', '_', $tituloPeriodo) . '.xlsx"');
        header('Cache-Control: no-store, private');

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        exit;
    }

    /**
     * Informe dia por dia de un solo empleado (Horas trabajadas segun registro), con una
     * columna independiente por cada tipo de recargo que aparezca en el rango.
     *
     * @param array<int, array{fecha:string, es_domingo_o_festivo:bool, estado:string, nota:?string, primera_entrada:?string, ultima_salida:?string, primera_entrada_redondeada:?string, ultima_salida_redondeada:?string, horas_totales:float, horas_totales_redondeadas:?float, almuerzo_descontado_horas:float, almuerzo_no_tomado:bool, alertas:string[], novedades:array, marcaciones:array, recargos: array<string,float>, recargos_redondeados: array<string,float>}> $filas
     * @param string[] $columnas codigos de tipo_recargo, en el orden en que deben mostrarse
     * @param array<string,string> $nombresPorCodigo codigo => nombre completo del tipo de recargo
     * @param array{nombre?:?string, documento?:?string, cargo?:?string, area_nombre?:?string} $empleado datos del empleado que se imprimen sobre la tabla
     */
    public function generarExcelHorasRegistro(array $filas, array $columnas, array $nombresPorCodigo, string $empleadoNombre, string $desde, string $hasta, array $empleado = []): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Horas segun registro');

        // Bloque de datos del empleado (filas 1-5, una fila en blanco y la tabla desde la 7).
        // Todo se escribe como texto explicito: el documento no debe perder ceros a la izquierda
        // ni convertirse en notacion cientifica, y ningun dato debe interpretarse como formula.
        $datosEmpleado = [
            'Empleado' => $empleado['nombre'] ?? $empleadoNombre,
            'Identificacion' => $empleado['documento'] ?? '',
            'Cargo' => $empleado['cargo'] ?? '',
            'Area' => $empleado['area_nombre'] ?? '',
            'Periodo' => "{$desde} a {$hasta}",
        ];
        $filaDato = 1;
        foreach ($datosEmpleado as $etiqueta => $valor) {
            $this->escribirTexto($sheet, 1, $filaDato, $etiqueta);
            $sheet->getStyleByColumnAndRow(1, $filaDato)->getFont()->setBold(true);
            $this->escribirTexto($sheet, 2, $filaDato, (string) $valor);
            $sheet->getStyleByColumnAndRow(2, $filaDato)->getAlignment()->setHorizontal('left');
            $filaDato++;
        }
        $filaEncabezado = $filaDato + 1;

        $col = 1;
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Fecha');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Dom/Festivo');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Estado');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Entrada');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Entrada redondeada');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Salida');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Salida redondeada');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Permiso / Novedad');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Comentario de marcacion');
        foreach ($columnas as $codigo) {
            $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, $nombresPorCodigo[$codigo] ?? $codigo);
            $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, ($nombresPorCodigo[$codigo] ?? $codigo) . ' (redondeado)');
        }
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Total horas');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Total redondeado');
        $sheet->setCellValueByColumnAndRow($col++, $filaEncabezado, 'Almuerzo descontado (h)');
        $sheet->setCellValueByColumnAndRow($col, $filaEncabezado, 'Alertas');
        $ultimaColumna = $col;
        $sheet->getStyle([1, $filaEncabezado, $ultimaColumna, $filaEncabezado])->getFont()->setBold(true);
        $sheet->freezePane('A' . ($filaEncabezado + 1));

        $fila = $filaEncabezado + 1;
        foreach ($filas as $dia) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['fecha']);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['es_domingo_o_festivo'] ? 'Si' : '');
            $estadoTexto = match ($dia['estado']) {
                'completo' => 'Completo',
                'cerrado_automatico' => 'Cerrado automatico (salida estimada ' . substr((string) ($dia['salida_estimada'] ?? ''), 0, 5) . '): ' . $dia['nota'],
                'en_curso' => 'En curso: ' . $dia['nota'],
                'incompleto' => 'Incompleto: ' . $dia['nota'],
                default => 'Sin marcaciones',
            };
            $this->escribirTexto($sheet, $col++, $fila, $estadoTexto);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['primera_entrada'] ? substr($dia['primera_entrada'], 0, 5) : '');
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['primera_entrada_redondeada'] ? substr($dia['primera_entrada_redondeada'], 0, 5) : '');
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['ultima_salida'] ? substr($dia['ultima_salida'], 0, 5) : '');
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['ultima_salida_redondeada'] ? substr($dia['ultima_salida_redondeada'], 0, 5) : '');
            $this->escribirTexto($sheet, $col++, $fila, $this->formatearNovedadesDelDia($dia['novedades'] ?? []));
            $this->escribirTexto($sheet, $col++, $fila, $this->formatearComentariosDeMarcaciones($dia['marcaciones'] ?? []));
            foreach ($columnas as $codigo) {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['recargos'][$codigo] ?? 0);
                $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['recargos_redondeados'][$codigo] ?? 0);
            }
            $sheet->setCellValueByColumnAndRow($col++, $fila, in_array($dia['estado'], ['completo', 'cerrado_automatico', 'en_curso'], true) ? $dia['horas_totales'] : 0);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['horas_totales_redondeadas'] ?? 0);
            // Almuerzo: horas descontadas ese dia, o la marca de que no se tomo (novedad aprobada).
            if (!empty($dia['almuerzo_no_tomado'])) {
                $this->escribirTexto($sheet, $col++, $fila, 'No tomado (novedad aprobada)');
            } else {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['almuerzo_descontado_horas'] ?? 0);
            }
            $this->escribirTexto($sheet, $col, $fila, implode(' | ', $dia['alertas'] ?? []));
            $fila++;
        }

        foreach (range(1, $ultimaColumna) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $nombreArchivo = 'horas_registro_' . preg_replace('/[^a-z0-9_\-]/i', '_', $empleadoNombre) . "_{$desde}_a_{$hasta}.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
        header('Cache-Control: no-store, private');

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        exit;
    }

    /**
     * Resumen de nomina por empleado segun registro (marcaciones reales), un
     * empleado por fila con una columna por tipo de recargo, total, y
     * cuantos dias quedaron incompletos en el rango (para que RRHH revise
     * antes de pagar si el total podria estar subestimado).
     *
     * @param array<int, array{empleado_nombre:string, recargos: array<string,float>, total_horas: float, dias_cerrados_automaticamente: int, dias_en_curso: int, dias_incompletos: int}> $filas
     * @param string[] $columnas codigos de tipo_recargo, en orden
     */
    public function generarExcelNominaRegistro(array $filas, array $columnas, string $desde, string $hasta): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nomina segun registro');

        $col = 1;
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Empleado');
        foreach ($columnas as $codigo) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $codigo);
        }
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Total horas');
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Dias cerrados automaticamente');
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Dias en curso');
        $sheet->setCellValueByColumnAndRow($col, 1, 'Dias incompletos');
        $ultimaColumna = $col;

        $fila = 2;
        foreach ($filas as $registro) {
            $col = 1;
            $this->escribirTexto($sheet, $col++, $fila, (string) $registro['empleado_nombre']);
            foreach ($columnas as $codigo) {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['recargos'][$codigo] ?? 0);
            }
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['total_horas']);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['dias_cerrados_automaticamente']);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['dias_en_curso']);
            $sheet->setCellValueByColumnAndRow($col, $fila, $registro['dias_incompletos']);
            $fila++;
        }

        foreach (range(1, $ultimaColumna) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $nombreArchivo = "nomina_registro_{$desde}_a_{$hasta}.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
        header('Cache-Control: no-store, private');

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        exit;
    }

    /**
     * @param array<int, array{empleado_nombre:string, recargos: array<string,float>, total_horas: float}> $filas
     * @param string[] $columnas
     */
    public function generarPdf(array $filas, array $columnas, string $tituloPeriodo): void
    {
        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: sans-serif; font-size: 11px; }
            h1 { font-size: 16px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #999; padding: 4px 6px; text-align: right; }
            th, td:first-child { text-align: left; }
        </style></head><body>';
        $html .= '<h1>Horas extra y recargos - ' . htmlspecialchars($tituloPeriodo, ENT_QUOTES, 'UTF-8') . '</h1>';
        $html .= '<table><thead><tr><th>Empleado</th>';
        foreach ($columnas as $codigo) {
            $html .= '<th>' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '<th>Total</th></tr></thead><tbody>';

        foreach ($filas as $registro) {
            $html .= '<tr><td>' . htmlspecialchars($registro['empleado_nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
            foreach ($columnas as $codigo) {
                $html .= '<td>' . number_format($registro['recargos'][$codigo] ?? 0, 2) . '</td>';
            }
            $html .= '<td>' . number_format($registro['total_horas'], 2) . '</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // Se envia a mano (en vez de $dompdf->stream) para controlar la cache: contiene datos personales.
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="horas_extra_' . preg_replace('/[^a-z0-9_\-]/i', '_', $tituloPeriodo) . '.pdf"');
        header('Cache-Control: no-store, private');
        echo $dompdf->output();
        exit;
    }
}
