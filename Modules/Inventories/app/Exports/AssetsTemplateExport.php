<?php

namespace Modules\Inventories\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Formato de la base contable. Toda la columna del código va como TEXTO (también las filas
 * que el usuario llene después) para que Excel no quite los ceros a la izquierda (000123)
 * ni convierta códigos largos a notación científica.
 */
class AssetsTemplateExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings
{
    public const HEADINGS = ['CODIGO PRODUCTO', 'NOMBRE PRODUCTO', 'UNIDAD MEDIDA', 'CANTIDAD', 'VALOR'];

    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                $sheet->getStyle('A1:E1')->getFont()->setBold(true);
                $sheet->freezePane('A2');
            },
        ];
    }
}
