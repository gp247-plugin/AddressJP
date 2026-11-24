<?php

namespace App\GP247\Plugins\AddressJP\Services;

/**
 * Japan Post postcode data shipped with the plugin.
 *
 * Layout of the data directory (written by tools/build-postcodes.php):
 * - meta.json: version (date of the Japan Post file), source, number of postcodes and rows;
 * - prefectures.json: { "<2-digit JIS code>": "<name>" } in code order;
 * - postcodes/<first 3 digits>.json: { "<7 digits>": [ ["<5-digit JIS code>", "<municipality>", "<town>"], … ] }.
 *
 * A lookup reads one shard of a few kilobytes, never the whole data set. Missing or
 * damaged data throws, so the shop falls back to free text instead of showing an error.
 *
 * @aidlc-unit plugin-address-jp
 * @aidlc-story US-address-jp-postcode-data
 * @aidlc-adr address-jp_bundled-postcode-shards
 */
class JapanPostcodeData
{
    /** Characters a shopper may type around or inside a postcode: 〒, dashes, spaces. */
    private const IGNORED = ['〒', '-', '－', 'ー', '‐', '−', '―', ' ', '　'];

    /** Full-width digits → ASCII. */
    private const DIGITS = ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9'];

    private string $dir;

    /** @var array<string, mixed>|null */
    private ?array $meta = null;

    /** @var array<string, string>|null */
    private ?array $prefectures = null;

    /** @var array<string, array<string, array<int, array<int, string>>>> Loaded shards. */
    private array $shards = [];

    /**
     * @param string|null $dir Data directory; null = config `Plugins/AddressJP.data_path`, then the bundled one.
     */
    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (config('Plugins/AddressJP.data_path') ?: dirname(__DIR__) . '/Data'), '/\\');
    }

    /**
     * @return string Date of the Japan Post file the data was built from.
     * @throws \RuntimeException When the data is missing or damaged.
     */
    public function version(): string
    {
        return (string) ($this->meta()['version'] ?? '');
    }

    /**
     * @return array<string, string> Prefectures by 2-digit JIS code, in code order.
     * @throws \RuntimeException When the data is missing or damaged.
     */
    public function prefectures(): array
    {
        if ($this->prefectures === null) {
            $list = $this->read($this->dir . '/prefectures.json');
            ksort($list, SORT_STRING);
            $this->prefectures = array_map('strval', $list);
        }

        return $this->prefectures;
    }

    /**
     * Rows of a postcode, in the order of the Japan Post file.
     *
     * @param string $digits Postcode as 7 ASCII digits (see normalizePostcode()).
     * @return array<int, array{0: string, 1: string, 2: string}> [JIS code of the municipality, municipality, town] — town may be ''.
     * @throws \RuntimeException When the data or the shard is damaged.
     */
    public function rows(string $digits): array
    {
        if (preg_match('/^\d{7}$/', $digits) !== 1) {
            return [];
        }
        $this->meta();
        $prefix = substr($digits, 0, 3);
        if (!array_key_exists($prefix, $this->shards)) {
            $file = $this->dir . '/postcodes/' . $prefix . '.json';
            // WHY: no shard = no postcode starting with these digits, not damaged data.
            $this->shards[$prefix] = is_file($file) ? $this->read($file) : [];
        }

        return $this->shards[$prefix][$digits] ?? [];
    }

    /**
     * @param string $typed Postcode as typed: full-width digits, dashes, spaces and 〒 are accepted.
     * @return string|null 7 ASCII digits, or null when it cannot be a Japanese postcode.
     */
    public static function normalizePostcode(string $typed): ?string
    {
        $digits = str_replace(self::IGNORED, '', strtr(trim($typed), self::DIGITS));

        return preg_match('/^\d{7}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * @param string $digits 7 ASCII digits.
     * @return string The postcode as written on a Japanese address: NNN-NNNN.
     */
    public static function formatPostcode(string $digits): string
    {
        return substr($digits, 0, 3) . '-' . substr($digits, 3);
    }

    /**
     * Turns the town column of the Japan Post file into what a shopper should see.
     *
     * - placeholders ("以下に掲載がない場合", "…の次に…番地がくる場合", "<municipality>一円") → '';
     * - notes in full-width parentheses are dropped ("大通西（１〜１９丁目）" → "大通西");
     * - a list of land-division numbers keeps its common name ("穴明２２地割、穴明２３地割" → "穴明");
     * - any other list of several towns → '' (the shopper types the right one).
     *
     * @param string $town         Town column (kanji).
     * @param string $municipality Municipality column (kanji).
     * @return string Town name, or '' when the row names no single town.
     *
     * @aidlc-story US-address-jp-postcode-lookup
     */
    public static function cleanTown(string $town, string $municipality): string
    {
        $town = trim($town);
        if ($town === '以下に掲載がない場合' || preg_match('/の次に.*がくる場合/u', $town) === 1) {
            return '';
        }
        // WHY not every "一円": 一円 is also a real town name (犬上郡多賀町一円).
        if ($town !== '一円' && str_ends_with($town, '一円')) {
            return '';
        }
        $town = trim((string) preg_replace('/（[^（）]*）/u', '', $town));
        if (preg_match('/[、〜～]/u', $town) === 1) {
            return preg_match('/^(\D+?)[０-９]+地割/u', $town, $m) === 1 ? $m[1] : '';
        }

        return $town;
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException When the data is missing or damaged.
     */
    private function meta(): array
    {
        if ($this->meta === null) {
            $meta = $this->read($this->dir . '/meta.json');
            if (!is_string($meta['version'] ?? null) || $meta['version'] === '') {
                throw new \RuntimeException('AddressJP: postcode data has no version.');
            }
            $this->meta = $meta;
        }

        return $this->meta;
    }

    /**
     * @param string $file JSON file of the data directory.
     * @return array<string, mixed>
     * @throws \RuntimeException When the file is missing or not a JSON object.
     */
    private function read(string $file): array
    {
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AddressJP: postcode data missing or damaged (' . basename($file) . ').');
        }

        return $data;
    }
}
