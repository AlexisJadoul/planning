<?php
declare(strict_types=1);

/** Reads the saved values in our .xlsx planning format; never evaluates formulas. */
function parseExcel(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Classeur illisible.');
    try {
        if ($zip->numFiles > 2000) throw new RuntimeException('Trop de fichiers.');
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) $size += $zip->statIndex($i)['size'];
        if ($size > 64 * 1024 * 1024) throw new RuntimeException('Classeur trop volumineux.');
        $xml = static function (string $entry) use ($zip): SimpleXMLElement {
            $content = $zip->getFromName($entry);
            if ($content === false || stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
                throw new RuntimeException('XML absent ou incompatible.');
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $node = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
                if ($node === false) throw new RuntimeException('XML invalide.');
                $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                return $node;
            } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        };
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $shared = $xml('xl/sharedStrings.xml');
            foreach ($shared->xpath('//m:si') as $item) {
                $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $strings[] = implode('', array_map('strval', $item->xpath('.//m:t')));
            }
        }
        $relationships = $xml('xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($relationships->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            if ((string)$rel->attributes()['TargetMode'] === 'External') continue;
            $target = (string)$rel->attributes()['Target'];
            if (str_contains($target, '..')) throw new RuntimeException('Chemin incompatible.');
            $targets[(string)$rel->attributes()['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        $book = $xml('xl/workbook.xml');
        $weeks = [];
        $dayNames = ['LUNDI','MARDI','MERCREDI','JEUDI','VENDREDI','SAMEDI','DIMANCHE'];
        foreach ($book->xpath('//m:sheet') as $sheet) {
            $name = trim((string)$sheet['name']);
            if (!preg_match('/^S[IP]-(\d{2})$/', $name, $match)) continue;
            $number = (int)$match[1];
            $id = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (!isset($targets[$id])) throw new RuntimeException('Feuille manquante.');
            $document = $xml($targets[$id]);
            $rows = [];
            foreach ($document->xpath('//m:sheetData/m:row') as $row) {
                $values = array_fill(0, 9, '');
                foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main') as $cell) {
                    if (!preg_match('/^([A-I])(\d+)$/', (string)$cell->attributes()['r'], $pos)) continue;
                    $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $raw = (string)($cell->xpath('./m:v')[0] ?? '');
                    $type = (string)$cell->attributes()['t'];
                    $value = $type === 's' ? ($strings[(int)$raw] ?? '') : ($type === 'inlineStr' ? implode('', array_map('strval', $cell->xpath('.//m:t'))) : $raw);
                    if ($type === 'e') throw new RuntimeException('Erreur Excel dans les tournées.');
                    $values[ord($pos[1])-65] = trim($value);
                }
                $rows[(int)$row->attributes()['r']] = $values;
            }
            ksort($rows);
            $title = $rows[1][0] ?? '';
            preg_match_all('/20\d{2}/', $title, $years);
            if (!$years[0]) throw new RuntimeException('Année introuvable.');
            $year = (int)$years[0][0];
            if ($number >= 52 && str_contains(strtoupper($title), 'JANVIER')) $year = (int)end($years[0]) - 1;
            if ($number === 1 && count($years[0]) > 1) $year = (int)end($years[0]);
            $monday = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->setISODate($year, $number, 1)->setTime(0, 0);
            if ((int)$monday->format('o') !== $year || (int)$monday->format('W') !== $number) throw new RuntimeException('Semaine ISO invalide.');
            $days = [];
            $current = null;
            foreach ($rows as $values) {
                foreach ([7, 8] as $col) {
                    $offset = array_search(strtoupper($values[$col]), $dayNames, true);
                    if ($offset !== false) {
                        $days[] = ['name'=>ucfirst(strtolower($dayNames[$offset])), 'date'=>$monday->modify("+$offset days")->format('Y-m-d'), 'routes'=>[]];
                        $current = count($days)-1;
                        break;
                    }
                }
                $fields = array_slice($values, 0, 7);
                if ($current !== null && count(array_filter($fields, fn($v) => $v !== '')) && !str_starts_with(strtoupper($values[0]), 'SEMAINE')) {
                    $days[$current]['routes'][] = array_combine(['number','route','type','vehicle','driver','crew1','crew2'], $fields);
                }
            }
            if (!$days || !array_sum(array_map(fn($d) => count($d['routes']), $days))) throw new RuntimeException('Aucune tournée.');
            $weekId = sprintf('%d-W%02d', $year, $number);
            if (isset($weeks[$weekId])) throw new RuntimeException('Semaines en double.');
            $weeks[$weekId] = ['id'=>$weekId, 'number'=>$number, 'year'=>$year, 'title'=>$title, 'start'=>$monday->format('Y-m-d'), 'days'=>$days];
        }
        if (!$weeks) throw new RuntimeException('Aucune feuille SI-xx ou SP-xx.');
        ksort($weeks);
        return array_values($weeks);
    } finally { $zip->close(); }
}
