<?php
/**
 * Rebuilds the plugin's postcode data from Japan Post's file (maintainers only, command line).
 *
 * Source: https://www.post.japanpost.jp/zipcode/download.html — "住所の郵便番号（1レコード1行、UTF-8形式）",
 * utf_ken_all.zip (one row per postcode and town, 15 columns, UTF-8). Japan Post claims no
 * copyright on the postcode data and allows free redistribution.
 *
 * Usage, from the website root:
 *   php app/GP247/Plugins/AddressJP/tools/build-postcodes.php <utf_ken_all.zip|utf_ken_all.csv> <YYYY-MM-DD> [<output dir>]
 *
 * <YYYY-MM-DD> is the date of the Japan Post file; <output dir> defaults to the plugin's Data/.
 * Every row is checked first; when one cannot be trusted (column count, postcode not 7 digits,
 * municipality code not 5 digits, unknown prefecture, a code with two names) nothing is written.
 * Otherwise meta.json, prefectures.json and postcodes/*.json are written, and shards the new
 * file no longer has are removed from <output dir>/postcodes — all of it is regenerated from
 * the source file, nothing else is touched.
 *
 * @aidlc-unit plugin-address-jp
 * @aidlc-story US-address-jp-postcode-data
 * @aidlc-adr address-jp_bundled-postcode-shards
 */

use App\GP247\Plugins\AddressJP\Services\JapanPostcodeData;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[, $input, $asOf, $out] = $argv + [null, null, null, null];
if (!$input || !is_file($input) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asOf)) {
    fwrite(STDERR, "usage: php build-postcodes.php <utf_ken_all.zip|csv> <YYYY-MM-DD> [<output dir>]\n");
    exit(2);
}
// WHY no framework: the build only needs the town cleaning rules, kept in one class with the runtime.
require_once dirname(__DIR__) . '/Services/JapanPostcodeData.php';
$out = rtrim($out ?: dirname(__DIR__) . '/Data', '/\\');

$csv = readSource($input);
if ($csv === null) {
    fwrite(STDERR, "Cannot read $input (a .zip needs the PHP zip extension).\n");
    exit(1);
}

$prefectures = [];
$cities = [];
$shards = [];
$errors = [];
$rows = 0;
$line = 0;
$handle = fopen('php://memory', 'r+');
fwrite($handle, $csv);
rewind($handle);
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $line++;
    if ($row === [null]) {
        continue;
    }
    if (count($row) !== 15) {
        $errors[] = "line $line: " . count($row) . ' columns';
        continue;
    }
    [$jis, , $postcode, , , , $prefName, $city, $town] = array_map('trim', $row);
    $prefCode = substr($jis, 0, 2);
    if (!preg_match('/^\d{5}$/', $jis) || !preg_match('/^\d{7}$/', $postcode) || (int) $prefCode < 1 || (int) $prefCode > 47
        || $prefName === '' || $city === '' || substr_count($town, '（') !== substr_count($town, '）')) {
        $errors[] = "line $line: " . implode(',', $row);
        continue;
    }
    if (($prefectures[$prefCode] ??= $prefName) !== $prefName) {
        $errors[] = "line $line: prefecture $prefCode has two names";
    }
    if (($cities[$jis] ??= $city) !== $city) {
        $errors[] = "line $line: municipality $jis has two names";
    }
    $entry = [$jis, $city, JapanPostcodeData::cleanTown($town, $city)];
    $list = &$shards[substr($postcode, 0, 3)][$postcode];
    // WHY: rows that differ only by a note in parentheses become the same town.
    if (!in_array($entry, $list ?? [], true)) {
        $list[] = $entry;
    }
    unset($list);
    $rows++;
}
fclose($handle);

if ($errors !== [] || $rows === 0) {
    fwrite(STDERR, ($errors === [] ? 'No row found.' : implode("\n", array_slice($errors, 0, 50))) . "\n");
    exit(1);
}

ksort($prefectures, SORT_STRING);
ksort($shards, SORT_STRING);
$json = static fn (array $data, int $flags = 0): string => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | $flags) . "\n";

if (!is_dir($out . '/postcodes') && !mkdir($out . '/postcodes', 0755, true)) {
    fwrite(STDERR, "Cannot create $out/postcodes\n");
    exit(1);
}
$postcodes = 0;
foreach ($shards as $prefix => $codes) {
    ksort($codes, SORT_STRING);
    $postcodes += count($codes);
    file_put_contents($out . '/postcodes/' . $prefix . '.json', $json($codes));
}
foreach (glob($out . '/postcodes/*.json') ?: [] as $file) {
    if (!isset($shards[basename($file, '.json')])) {
        unlink($file);
    }
}
file_put_contents($out . '/prefectures.json', $json($prefectures, JSON_PRETTY_PRINT));
file_put_contents($out . '/meta.json', $json([
    'version' => $asOf,
    'source' => 'Japan Post — https://www.post.japanpost.jp/zipcode/download.html, utf_ken_all.zip of ' . $asOf . ' (no copyright claimed, free redistribution)',
    'postcodes' => $postcodes,
    'rows' => $rows,
], JSON_PRETTY_PRINT));

echo count($prefectures), ' prefectures, ', $postcodes, ' postcodes, ', count($shards), ' shards → ', $out, PHP_EOL;

/**
 * @param string $input utf_ken_all.zip or the CSV inside it.
 * @return string|null CSV text (UTF-8), or null when it cannot be read.
 */
function readSource(string $input): ?string
{
    if (strtolower(pathinfo($input, PATHINFO_EXTENSION)) !== 'zip') {
        $text = file_get_contents($input);

        return $text === false ? null : $text;
    }
    if (!class_exists(ZipArchive::class)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($input) !== true) {
        return null;
    }
    $text = $zip->numFiles === 1 ? $zip->getFromIndex(0) : false;
    $zip->close();

    return $text === false ? null : $text;
}
