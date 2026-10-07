<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class TableExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(private array $head, private array $rows)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->head;
    }

    /** Download a table as xlsx (default) or pdf. */
    public static function respond(string $title, array $head, array $rows, string $fmt = 'xlsx', array $totals = [])
    {
        $file = Str::slug($title).'-'.now()->format('Ymd-His');

        if ($fmt === 'pdf') {
            return Pdf::loadView('exports.table', compact('title', 'head', 'rows', 'totals'))
                ->setPaper('a4', count($head) > 6 ? 'landscape' : 'portrait')
                ->download("$file.pdf");
        }

        if ($totals) {
            $rows[] = array_pad([], count($head), '');
            foreach ($totals as $k => $v) {
                $rows[] = array_merge([$k, $v], array_pad([], max(0, count($head) - 2), ''));
            }
        }

        return Excel::download(new self($head, $rows), "$file.xlsx");
    }
}
