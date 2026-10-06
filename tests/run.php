<?php
declare(strict_types=1);
$temp = sys_get_temp_dir().'/planning-tests-'.bin2hex(random_bytes(6));
mkdir($temp, 0700);
putenv('DATA_DIR='.$temp);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Excel.php';
$checks = 0;
function check(bool $result, string $name): void {
    global $checks;
    if (!$result) throw new RuntimeException('ÉCHEC : '.$name);
    $checks++;
    echo 'OK : '.$name.PHP_EOL;
}
try {
    $zip = new ZipArchive();
    $path = $temp.'/test.xlsx';
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="SP-53" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>03</t></si><si><r><t>PIER</t></r><r><t>RIC</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>SEMAINE IMPAIRE 53 - DU 28 AU 2 JANVIER 2027</t></is></c></row><row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2" t="s"><v>1</v></c><c r="E2" t="inlineStr"><is><t>ÉQUIPE</t></is></c><c r="I2" t="inlineStr"><is><t>SAMEDI</t></is></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>LIVRAISON BACS</t></is></c></row></sheetData></worksheet>');
    $zip->close();
    $weeks = parseExcel($path);
    check($weeks[0]['id'] === '2026-W53' && $weeks[0]['start'] === '2026-12-28', 'Année ISO au changement d’année');
    check($weeks[0]['days'][0]['date'] === '2027-01-02', 'Samedi décalé et colonne I');
    check(count($weeks[0]['days'][0]['routes']) === 2 && $weeks[0]['days'][0]['routes'][0]['route'] === 'PIERRIC', 'Tournées, livraison et chaînes partagées enrichies');
    $state = updateState(fn($s)=>array_replace($s, ['weeks'=>$weeks, 'revision'=>'test']));
    check(readState()['revision'] === 'test', 'Stockage persistant');
    check(activePlanning($state, new DateTimeImmutable('2027-01-02'))['week']['id'] === '2026-W53', 'Sélection automatique ISO');
    check(activePlanning($state, new DateTimeImmutable('2026-01-01'))['week'] === null, 'Semaine absente');
    $state['mode'] = 'manual'; $state['selected'] = '2026-W53';
    check(activePlanning($state, new DateTimeImmutable('2026-01-01'))['week']['id'] === '2026-W53', 'Sélection manuelle');
    file_put_contents($temp.'/invalid.xlsx', 'invalid');
    $rejected = false;
    try { parseExcel($temp.'/invalid.xlsx'); } catch (Throwable $e) { $rejected = true; }
    check($rejected, 'Import invalide refusé');
    if (isset($argv[1])) {
        $actual = parseExcel($argv[1]);
        check(count($actual) === 52, 'Classeur fourni : 52 semaines');
        $week41 = array_values(array_filter($actual, fn($w)=>$w['id'] === '2026-W41'))[0];
        check(count($week41['days']) === 5 && count($week41['days'][0]['routes']) === 9, 'Classeur fourni : semaine 41');
        check(!in_array('2026-W50', array_column($actual, 'id')), 'Semaine 50 absente du classeur');
    }
    echo "$checks vérifications réussies.".PHP_EOL;
} finally {
    foreach (glob($temp.'/*') as $file) unlink($file);
    rmdir($temp);
}
