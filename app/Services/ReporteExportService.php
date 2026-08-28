<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReporteExportService
{
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
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['empleado_nombre']);
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
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Informe dia por dia de un solo empleado (Horas trabajadas segun registro), con una
     * columna independiente por cada tipo de recargo que aparezca en el rango.
     *
     * @param array<int, array{fecha:string, es_domingo_o_festivo:bool, estado:string, nota:?string, horas_totales:float, recargos: array<string,float>}> $filas
     * @param string[] $columnas codigos de tipo_recargo, en el orden en que deben mostrarse
     * @param array<string,string> $nombresPorCodigo codigo => nombre completo del tipo de recargo
     */
    public function generarExcelHorasRegistro(array $filas, array $columnas, array $nombresPorCodigo, string $empleadoNombre, string $desde, string $hasta): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Horas segun registro');

        $col = 1;
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Fecha');
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Dom/Festivo');
        $sheet->setCellValueByColumnAndRow($col++, 1, 'Estado');
        foreach ($columnas as $codigo) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $nombresPorCodigo[$codigo] ?? $codigo);
        }
        $sheet->setCellValueByColumnAndRow($col, 1, 'Total horas');
        $ultimaColumna = $col;

        $fila = 2;
        foreach ($filas as $dia) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['fecha']);
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['es_domingo_o_festivo'] ? 'Si' : '');
            $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['estado'] === 'completo' ? 'Completo' : ($dia['estado'] === 'incompleto' ? 'Incompleto: ' . $dia['nota'] : 'Sin marcaciones'));
            foreach ($columnas as $codigo) {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $dia['recargos'][$codigo] ?? 0);
            }
            $sheet->setCellValueByColumnAndRow($col, $fila, $dia['estado'] === 'completo' ? $dia['horas_totales'] : 0);
            $fila++;
        }

        foreach (range(1, $ultimaColumna) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $nombreArchivo = 'horas_registro_' . preg_replace('/[^a-z0-9_\-]/i', '_', $empleadoNombre) . "_{$desde}_a_{$hasta}.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Resumen de nomina por empleado segun registro (marcaciones reales), un
     * empleado por fila con una columna por tipo de recargo, total, y
     * cuantos dias quedaron incompletos en el rango (para que RRHH revise
     * antes de pagar si el total podria estar subestimado).
     *
     * @param array<int, array{empleado_nombre:string, recargos: array<string,float>, total_horas: float, dias_incompletos: int}> $filas
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
        $sheet->setCellValueByColumnAndRow($col, 1, 'Dias incompletos');
        $ultimaColumna = $col;

        $fila = 2;
        foreach ($filas as $registro) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['empleado_nombre']);
            foreach ($columnas as $codigo) {
                $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['recargos'][$codigo] ?? 0);
            }
            $sheet->setCellValueByColumnAndRow($col++, $fila, $registro['total_horas']);
            $sheet->setCellValueByColumnAndRow($col, $fila, $registro['dias_incompletos']);
            $fila++;
        }

        foreach (range(1, $ultimaColumna) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $nombreArchivo = "nomina_registro_{$desde}_a_{$hasta}.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
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

        $dompdf->stream('horas_extra_' . preg_replace('/[^a-z0-9_\-]/i', '_', $tituloPeriodo) . '.pdf', ['Attachment' => true]);
        exit;
    }
}
