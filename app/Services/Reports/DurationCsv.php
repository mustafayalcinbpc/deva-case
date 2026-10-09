<?php

namespace App\Services\Reports;

/**
 * Süre ve efor raporunun makine özetini CSV'ye yazar. Türkçe Excel'in beklediği biçim: UTF-8
 * BOM, ";" ayırıcı, ondalık için ",". Süreler saniye cinsinden tam sayıdır.
 */
final class DurationCsv
{
    private const HEADER = [
        'Tesis', 'Hat', 'Makine kodu', 'Makine adı', 'Tamamlanan kayıt',
        'Ort. net süre (sn)', 'En kısa net süre (sn)', 'En uzun net süre (sn)',
        'Ort. brüt süre (sn)', 'Ort. insan eforu (sn)', 'Ort. çalışma dilimi',
    ];

    public function __construct(private readonly DurationReport $report) {}

    public function render(ReportFilters $filters): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\u{FEFF}");
        $this->write($handle, self::HEADER);

        foreach ($this->report->machines($filters) as $row) {
            $this->write($handle, [
                $this->text($row['facility_code']),
                $this->text($row['line_code']),
                $this->text($row['machine_code']),
                $this->text($row['machine_name']),
                $row['completed_count'],
                $row['avg_net_seconds'],
                $row['min_net_seconds'],
                $row['max_net_seconds'],
                $row['avg_gross_seconds'],
                $row['avg_effort_seconds'],
                number_format($row['avg_slice_count'], 1, ',', ''),
            ]);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    public function fileName(ReportFilters $filters): string
    {
        return 'sure-ve-efor_'.$filters->from->format('Y-m-d').'_'.$filters->to->format('Y-m-d').'.csv';
    }

    /**
     * @param  resource  $handle
     * @param  list<int|string>  $fields
     */
    private function write($handle, array $fields): void
    {
        fputcsv($handle, $fields, ';', '"', '', "\r\n");
    }

    /**
     * Tabloda formül olarak yorumlanabilecek metinler (=, +, -, @ ile başlayan) düz metne çevrilir.
     */
    private function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
