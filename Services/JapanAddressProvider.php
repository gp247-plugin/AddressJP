<?php

namespace App\GP247\Plugins\AddressJP\Services;

use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Contracts\ListsAddressOptions;
use GP247\Shop\Address\Contracts\ResolvesPostcode;
use GP247\Shop\Address\Data\AddressComponent;
use GP247\Shop\Address\Data\AddressOption;
use GP247\Shop\Address\Data\AddressSchema;
use GP247\Shop\Address\Data\AddressSuggestion;
use GP247\Shop\Address\Data\NormalizedAddress;

/**
 * Japan behind the shop's address contract: the shopper types a postcode and the
 * prefecture (都道府県), municipality (市区町村) and town (町域) fill in; without a postcode
 * the prefecture is picked from the list of 47.
 *
 * Default mapping onto the shop's address fields: Postcode ← postcode, City ← prefecture,
 * District ← municipality, Address 1 ← town (the shopper adds the block and building
 * after it). The site owner can change it on the shop's Address lists screen. Place
 * names stay in Japanese, as a delivery address in Japan is written. All data comes from
 * the Japan Post file shipped with the plugin; nothing is sent to another service.
 *
 * @aidlc-unit plugin-address-jp
 * @aidlc-story US-address-jp-postcode-lookup
 * @aidlc-story US-address-jp-prefecture-list
 * @aidlc-story US-address-jp-normalize
 * @aidlc-adr shop-address_provider-capability-contract
 */
class JapanAddressProvider implements AddressProvider, ListsAddressOptions, ResolvesPostcode
{
    public const COUNTRY = 'JP';

    private const LANG = 'Plugins/AddressJP::lang.';

    /** Suffixes a prefecture name may be typed without (東京 = 東京都). */
    private const PREFECTURE_SUFFIXES = ['都', '道', '府', '県'];

    /**
     * @param JapanPostcodeData $data Japan Post data shipped with the plugin.
     */
    public function __construct(private JapanPostcodeData $data)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function schema(string $country): AddressSchema
    {
        return new AddressSchema(
            self::COUNTRY,
            $this->data->version(),
            [
                new AddressComponent('postcode', self::LANG . 'postcode', AddressComponent::KIND_LOOKUP),
                new AddressComponent('prefecture', self::LANG . 'prefecture', AddressComponent::KIND_LIST),
                new AddressComponent('municipality', self::LANG . 'municipality', AddressComponent::KIND_DERIVED, 'prefecture'),
                new AddressComponent('town', self::LANG . 'town', AddressComponent::KIND_DERIVED, 'municipality'),
            ],
            ['postcode' => 'postcode', 'city' => 'prefecture', 'district' => 'municipality', 'address1' => 'town'],
            ['address1'],
        );
    }

    /**
     * Only the prefectures form a list: Japan Post publishes municipalities and towns
     * through the postcode data, so they come from a lookup or are typed.
     *
     * {@inheritDoc}
     */
    public function options(string $country, string $component, ?string $parentCode, string $locale): array
    {
        if ($component !== 'prefecture') {
            return [];
        }
        $out = [];
        foreach ($this->data->prefectures() as $code => $name) {
            $out[] = new AddressOption((string) $code, $name);
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function lookup(string $country, string $postcode, string $locale): array
    {
        $digits = JapanPostcodeData::normalizePostcode($postcode);
        if ($digits === null) {
            return [];
        }
        $prefectures = $this->data->prefectures();
        $out = [];
        foreach ($this->data->rows($digits) as [$cityCode, $city, $town]) {
            $prefecture = substr((string) $cityCode, 0, 2);
            $out[] = new AddressSuggestion([
                'prefecture' => ['code' => $prefecture, 'name' => (string) ($prefectures[$prefecture] ?? '')],
                'municipality' => ['code' => (string) $cityCode, 'name' => (string) $city],
                'town' => $town === '' ? ['name' => ''] : ['code' => $digits, 'name' => (string) $town],
            ]);
        }

        return $out;
    }

    /**
     * Checks a submitted Japanese address. The postcode is stored as NNN-NNNN; the
     * prefecture is matched by code or name; the municipality and town are matched only
     * against the rows of a valid postcode of the same prefecture, so a code never ends
     * up next to a place it does not belong to.
     *
     * {@inheritDoc}
     */
    public function normalize(string $country, array $components, string $locale): NormalizedAddress
    {
        $matched = [];
        $errors = [];

        $prefecture = isset($components['prefecture'])
            ? $this->findPrefecture($components['prefecture']['code'] ?? null, $components['prefecture']['name'] ?? null)
            : null;
        if ($prefecture !== null) {
            $matched['prefecture'] = $prefecture;
        } elseif (isset($components['prefecture'])) {
            $errors['prefecture'] = self::LANG . 'unknown_prefecture';
        }

        if (!isset($components['postcode'])) {
            return new NormalizedAddress($matched, $errors);
        }
        $digits = JapanPostcodeData::normalizePostcode((string) ($components['postcode']['name'] ?? $components['postcode']['code'] ?? ''));
        if ($digits === null) {
            $errors['postcode'] = self::LANG . 'invalid_postcode';

            return new NormalizedAddress($matched, $errors);
        }
        $rows = $this->data->rows($digits);
        if ($rows === []) {
            // WHY no error: Japan Post adds postcodes every month, the shipped data may be older.
            return new NormalizedAddress($matched, $errors);
        }
        if ($prefecture !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row[0], $prefecture['code'])));
            if ($rows === []) {
                $errors['postcode'] = self::LANG . 'postcode_prefecture_mismatch';

                return new NormalizedAddress($matched, $errors);
            }
        }
        $matched['postcode'] = ['code' => $digits, 'name' => JapanPostcodeData::formatPostcode($digits)];

        return new NormalizedAddress($this->matchPlace($matched, $components, $rows, $digits), $errors);
    }

    /**
     * Matches the municipality and the town against the rows of the postcode.
     *
     * @param array<string, array{code: string, name: string}>   $matched    Components matched so far.
     * @param array<string, array{code: ?string, name: ?string}> $components Submitted components.
     * @param array<int, array<int, string>>                     $rows       Rows of the postcode (same prefecture).
     * @param string                                             $digits     Postcode, 7 digits.
     * @return array<string, array{code: string, name: string}> Matched components.
     */
    private function matchPlace(array $matched, array $components, array $rows, string $digits): array
    {
        if (isset($components['municipality'])) {
            $code = (string) ($components['municipality']['code'] ?? '');
            $name = $this->plain($components['municipality']['name'] ?? null);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => ($code !== '' && $row[0] === $code) || ($name !== '' && $row[1] === $name)));
            if ($rows === []) {
                // WHY stop: a town matched under another municipality would store a wrong code.
                return $matched;
            }
            $matched['municipality'] = ['code' => (string) $rows[0][0], 'name' => (string) $rows[0][1]];
        }

        $typed = $this->plain($components['town']['name'] ?? null);
        $best = '';
        foreach ($rows as $row) {
            // WHY prefix: the shopper adds the block number and building after the town.
            if ($row[2] !== '' && str_starts_with($typed, $row[2]) && strlen($row[2]) > strlen($best)) {
                $best = (string) $row[2];
            }
        }
        if (isset($components['town']) && $best !== '') {
            $matched['town'] = ['code' => $digits, 'name' => $best];
        }

        return $matched;
    }

    /**
     * @param string|null $code 2-digit JIS code.
     * @param string|null $name Name, with or without 都 / 道 / 府 / 県.
     * @return array{code: string, name: string}|null
     */
    private function findPrefecture(?string $code, ?string $name): ?array
    {
        $prefectures = $this->data->prefectures();
        $code = trim((string) $code);
        if ($code !== '' && isset($prefectures[$code])) {
            return ['code' => $code, 'name' => (string) $prefectures[$code]];
        }
        $name = $this->plain($name);
        if ($name === '') {
            return null;
        }
        foreach ($prefectures as $key => $official) {
            if ($official === $name || $this->withoutSuffix($official) === $name) {
                return ['code' => (string) $key, 'name' => (string) $official];
            }
        }

        return null;
    }

    /**
     * @param string $name Prefecture name.
     * @return string The name without its trailing 都 / 道 / 府 / 県.
     */
    private function withoutSuffix(string $name): string
    {
        foreach (self::PREFECTURE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }

    /**
     * @param mixed $value Submitted value.
     * @return string Trimmed, including the full-width space a Japanese keyboard types.
     */
    private function plain(mixed $value): string
    {
        return is_scalar($value) ? trim(str_replace('　', ' ', (string) $value)) : '';
    }
}
