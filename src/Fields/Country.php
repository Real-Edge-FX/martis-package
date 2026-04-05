<?php

namespace Martis\Fields;

/**
 * Country selection field — ISO 3166-1 alpha-2 country picker.
 *
 * Paridade com Laravel Nova v5: Country field.
 * Armazena o código de país de 2 letras (ex: US, BR, PT).
 * Exibe o nome completo do país em detail/index.
 *
 * Extensão Martis: suporte opcional a bandeiras (emoji flags).
 *   - withFlags()    — habilita bandeiras (padrão: desabilitado)
 *   - withoutFlags() — desabilita bandeiras explicitamente
 *
 * Contextos: create, update, detail, index.
 */
class Country extends Field
{
    protected bool $showFlags = false;

    /** @var list<array{label: string, value: string, flag?: string}> */
    protected array $countries = [];

    protected ?string $filterPlaceholder = null;

    public function type(): string
    {
        return 'country';
    }

    /**
     * Enable flag emoji display alongside country names.
     * Martis extension — not part of Nova API.
     */
    public function withFlags(): static
    {
        $this->showFlags = true;

        return $this;
    }

    /**
     * Disable flag emoji display (default behavior).
     * Martis extension — not part of Nova API.
     */
    public function withoutFlags(): static
    {
        $this->showFlags = false;

        return $this;
    }

    /**
     * Set the filter (search) placeholder text.
     * Allows the user to customize the search placeholder in the dropdown.
     */
    public function filterPlaceholder(string $text): static
    {
        $this->filterPlaceholder = $text;

        return $this;
    }

    /**
     * Check if flags are enabled.
     */
    public function hasFlags(): bool
    {
        return $this->showFlags;
    }

    /**
     * Get the full list of ISO 3166-1 countries with labels and codes.
     *
     * @return list<array{label: string, value: string, flag: string}>
     */
    public static function countryList(): array
    {
        return [
            ['label' => 'Afghanistan', 'value' => 'AF', 'flag' => '🇦🇫'],
            ['label' => 'Albania', 'value' => 'AL', 'flag' => '🇦🇱'],
            ['label' => 'Algeria', 'value' => 'DZ', 'flag' => '🇩🇿'],
            ['label' => 'Andorra', 'value' => 'AD', 'flag' => '🇦🇩'],
            ['label' => 'Angola', 'value' => 'AO', 'flag' => '🇦🇴'],
            ['label' => 'Antigua and Barbuda', 'value' => 'AG', 'flag' => '🇦🇬'],
            ['label' => 'Argentina', 'value' => 'AR', 'flag' => '🇦🇷'],
            ['label' => 'Armenia', 'value' => 'AM', 'flag' => '🇦🇲'],
            ['label' => 'Australia', 'value' => 'AU', 'flag' => '🇦🇺'],
            ['label' => 'Austria', 'value' => 'AT', 'flag' => '🇦🇹'],
            ['label' => 'Azerbaijan', 'value' => 'AZ', 'flag' => '🇦🇿'],
            ['label' => 'Bahamas', 'value' => 'BS', 'flag' => '🇧🇸'],
            ['label' => 'Bahrain', 'value' => 'BH', 'flag' => '🇧🇭'],
            ['label' => 'Bangladesh', 'value' => 'BD', 'flag' => '🇧🇩'],
            ['label' => 'Barbados', 'value' => 'BB', 'flag' => '🇧🇧'],
            ['label' => 'Belarus', 'value' => 'BY', 'flag' => '🇧🇾'],
            ['label' => 'Belgium', 'value' => 'BE', 'flag' => '🇧🇪'],
            ['label' => 'Belize', 'value' => 'BZ', 'flag' => '🇧🇿'],
            ['label' => 'Benin', 'value' => 'BJ', 'flag' => '🇧🇯'],
            ['label' => 'Bhutan', 'value' => 'BT', 'flag' => '🇧🇹'],
            ['label' => 'Bolivia', 'value' => 'BO', 'flag' => '🇧🇴'],
            ['label' => 'Bosnia and Herzegovina', 'value' => 'BA', 'flag' => '🇧🇦'],
            ['label' => 'Botswana', 'value' => 'BW', 'flag' => '🇧🇼'],
            ['label' => 'Brazil', 'value' => 'BR', 'flag' => '🇧🇷'],
            ['label' => 'Brunei', 'value' => 'BN', 'flag' => '🇧🇳'],
            ['label' => 'Bulgaria', 'value' => 'BG', 'flag' => '🇧🇬'],
            ['label' => 'Burkina Faso', 'value' => 'BF', 'flag' => '🇧🇫'],
            ['label' => 'Burundi', 'value' => 'BI', 'flag' => '🇧🇮'],
            ['label' => 'Cabo Verde', 'value' => 'CV', 'flag' => '🇨🇻'],
            ['label' => 'Cambodia', 'value' => 'KH', 'flag' => '🇰🇭'],
            ['label' => 'Cameroon', 'value' => 'CM', 'flag' => '🇨🇲'],
            ['label' => 'Canada', 'value' => 'CA', 'flag' => '🇨🇦'],
            ['label' => 'Central African Republic', 'value' => 'CF', 'flag' => '🇨🇫'],
            ['label' => 'Chad', 'value' => 'TD', 'flag' => '🇹🇩'],
            ['label' => 'Chile', 'value' => 'CL', 'flag' => '🇨🇱'],
            ['label' => 'China', 'value' => 'CN', 'flag' => '🇨🇳'],
            ['label' => 'Colombia', 'value' => 'CO', 'flag' => '🇨🇴'],
            ['label' => 'Comoros', 'value' => 'KM', 'flag' => '🇰🇲'],
            ['label' => 'Congo (DRC)', 'value' => 'CD', 'flag' => '🇨🇩'],
            ['label' => 'Congo (Republic)', 'value' => 'CG', 'flag' => '🇨🇬'],
            ['label' => 'Costa Rica', 'value' => 'CR', 'flag' => '🇨🇷'],
            ['label' => 'Croatia', 'value' => 'HR', 'flag' => '🇭🇷'],
            ['label' => 'Cuba', 'value' => 'CU', 'flag' => '🇨🇺'],
            ['label' => 'Cyprus', 'value' => 'CY', 'flag' => '🇨🇾'],
            ['label' => 'Czech Republic', 'value' => 'CZ', 'flag' => '🇨🇿'],
            ['label' => 'Denmark', 'value' => 'DK', 'flag' => '🇩🇰'],
            ['label' => 'Djibouti', 'value' => 'DJ', 'flag' => '🇩🇯'],
            ['label' => 'Dominica', 'value' => 'DM', 'flag' => '🇩🇲'],
            ['label' => 'Dominican Republic', 'value' => 'DO', 'flag' => '🇩🇴'],
            ['label' => 'Ecuador', 'value' => 'EC', 'flag' => '🇪🇨'],
            ['label' => 'Egypt', 'value' => 'EG', 'flag' => '🇪🇬'],
            ['label' => 'El Salvador', 'value' => 'SV', 'flag' => '🇸🇻'],
            ['label' => 'Equatorial Guinea', 'value' => 'GQ', 'flag' => '🇬🇶'],
            ['label' => 'Eritrea', 'value' => 'ER', 'flag' => '🇪🇷'],
            ['label' => 'Estonia', 'value' => 'EE', 'flag' => '🇪🇪'],
            ['label' => 'Eswatini', 'value' => 'SZ', 'flag' => '🇸🇿'],
            ['label' => 'Ethiopia', 'value' => 'ET', 'flag' => '🇪🇹'],
            ['label' => 'Fiji', 'value' => 'FJ', 'flag' => '🇫🇯'],
            ['label' => 'Finland', 'value' => 'FI', 'flag' => '🇫🇮'],
            ['label' => 'France', 'value' => 'FR', 'flag' => '🇫🇷'],
            ['label' => 'Gabon', 'value' => 'GA', 'flag' => '🇬🇦'],
            ['label' => 'Gambia', 'value' => 'GM', 'flag' => '🇬🇲'],
            ['label' => 'Georgia', 'value' => 'GE', 'flag' => '🇬🇪'],
            ['label' => 'Germany', 'value' => 'DE', 'flag' => '🇩🇪'],
            ['label' => 'Ghana', 'value' => 'GH', 'flag' => '🇬🇭'],
            ['label' => 'Greece', 'value' => 'GR', 'flag' => '🇬🇷'],
            ['label' => 'Grenada', 'value' => 'GD', 'flag' => '🇬🇩'],
            ['label' => 'Guatemala', 'value' => 'GT', 'flag' => '🇬🇹'],
            ['label' => 'Guinea', 'value' => 'GN', 'flag' => '🇬🇳'],
            ['label' => 'Guinea-Bissau', 'value' => 'GW', 'flag' => '🇬🇼'],
            ['label' => 'Guyana', 'value' => 'GY', 'flag' => '🇬🇾'],
            ['label' => 'Haiti', 'value' => 'HT', 'flag' => '🇭🇹'],
            ['label' => 'Honduras', 'value' => 'HN', 'flag' => '🇭🇳'],
            ['label' => 'Hungary', 'value' => 'HU', 'flag' => '🇭🇺'],
            ['label' => 'Iceland', 'value' => 'IS', 'flag' => '🇮🇸'],
            ['label' => 'India', 'value' => 'IN', 'flag' => '🇮🇳'],
            ['label' => 'Indonesia', 'value' => 'ID', 'flag' => '🇮🇩'],
            ['label' => 'Iran', 'value' => 'IR', 'flag' => '🇮🇷'],
            ['label' => 'Iraq', 'value' => 'IQ', 'flag' => '🇮🇶'],
            ['label' => 'Ireland', 'value' => 'IE', 'flag' => '🇮🇪'],
            ['label' => 'Israel', 'value' => 'IL', 'flag' => '🇮🇱'],
            ['label' => 'Italy', 'value' => 'IT', 'flag' => '🇮🇹'],
            ['label' => 'Ivory Coast', 'value' => 'CI', 'flag' => '🇨🇮'],
            ['label' => 'Jamaica', 'value' => 'JM', 'flag' => '🇯🇲'],
            ['label' => 'Japan', 'value' => 'JP', 'flag' => '🇯🇵'],
            ['label' => 'Jordan', 'value' => 'JO', 'flag' => '🇯🇴'],
            ['label' => 'Kazakhstan', 'value' => 'KZ', 'flag' => '🇰🇿'],
            ['label' => 'Kenya', 'value' => 'KE', 'flag' => '🇰🇪'],
            ['label' => 'Kiribati', 'value' => 'KI', 'flag' => '🇰🇮'],
            ['label' => 'Kuwait', 'value' => 'KW', 'flag' => '🇰🇼'],
            ['label' => 'Kyrgyzstan', 'value' => 'KG', 'flag' => '🇰🇬'],
            ['label' => 'Laos', 'value' => 'LA', 'flag' => '🇱🇦'],
            ['label' => 'Latvia', 'value' => 'LV', 'flag' => '🇱🇻'],
            ['label' => 'Lebanon', 'value' => 'LB', 'flag' => '🇱🇧'],
            ['label' => 'Lesotho', 'value' => 'LS', 'flag' => '🇱🇸'],
            ['label' => 'Liberia', 'value' => 'LR', 'flag' => '🇱🇷'],
            ['label' => 'Libya', 'value' => 'LY', 'flag' => '🇱🇾'],
            ['label' => 'Liechtenstein', 'value' => 'LI', 'flag' => '🇱🇮'],
            ['label' => 'Lithuania', 'value' => 'LT', 'flag' => '🇱🇹'],
            ['label' => 'Luxembourg', 'value' => 'LU', 'flag' => '🇱🇺'],
            ['label' => 'Madagascar', 'value' => 'MG', 'flag' => '🇲🇬'],
            ['label' => 'Malawi', 'value' => 'MW', 'flag' => '🇲🇼'],
            ['label' => 'Malaysia', 'value' => 'MY', 'flag' => '🇲🇾'],
            ['label' => 'Maldives', 'value' => 'MV', 'flag' => '🇲🇻'],
            ['label' => 'Mali', 'value' => 'ML', 'flag' => '🇲🇱'],
            ['label' => 'Malta', 'value' => 'MT', 'flag' => '🇲🇹'],
            ['label' => 'Marshall Islands', 'value' => 'MH', 'flag' => '🇲🇭'],
            ['label' => 'Mauritania', 'value' => 'MR', 'flag' => '🇲🇷'],
            ['label' => 'Mauritius', 'value' => 'MU', 'flag' => '🇲🇺'],
            ['label' => 'Mexico', 'value' => 'MX', 'flag' => '🇲🇽'],
            ['label' => 'Micronesia', 'value' => 'FM', 'flag' => '🇫🇲'],
            ['label' => 'Moldova', 'value' => 'MD', 'flag' => '🇲🇩'],
            ['label' => 'Monaco', 'value' => 'MC', 'flag' => '🇲🇨'],
            ['label' => 'Mongolia', 'value' => 'MN', 'flag' => '🇲🇳'],
            ['label' => 'Montenegro', 'value' => 'ME', 'flag' => '🇲🇪'],
            ['label' => 'Morocco', 'value' => 'MA', 'flag' => '🇲🇦'],
            ['label' => 'Mozambique', 'value' => 'MZ', 'flag' => '🇲🇿'],
            ['label' => 'Myanmar', 'value' => 'MM', 'flag' => '🇲🇲'],
            ['label' => 'Namibia', 'value' => 'NA', 'flag' => '🇳🇦'],
            ['label' => 'Nauru', 'value' => 'NR', 'flag' => '🇳🇷'],
            ['label' => 'Nepal', 'value' => 'NP', 'flag' => '🇳🇵'],
            ['label' => 'Netherlands', 'value' => 'NL', 'flag' => '🇳🇱'],
            ['label' => 'New Zealand', 'value' => 'NZ', 'flag' => '🇳🇿'],
            ['label' => 'Nicaragua', 'value' => 'NI', 'flag' => '🇳🇮'],
            ['label' => 'Niger', 'value' => 'NE', 'flag' => '🇳🇪'],
            ['label' => 'Nigeria', 'value' => 'NG', 'flag' => '🇳🇬'],
            ['label' => 'North Korea', 'value' => 'KP', 'flag' => '🇰🇵'],
            ['label' => 'North Macedonia', 'value' => 'MK', 'flag' => '🇲🇰'],
            ['label' => 'Norway', 'value' => 'NO', 'flag' => '🇳🇴'],
            ['label' => 'Oman', 'value' => 'OM', 'flag' => '🇴🇲'],
            ['label' => 'Pakistan', 'value' => 'PK', 'flag' => '🇵🇰'],
            ['label' => 'Palau', 'value' => 'PW', 'flag' => '🇵🇼'],
            ['label' => 'Palestine', 'value' => 'PS', 'flag' => '🇵🇸'],
            ['label' => 'Panama', 'value' => 'PA', 'flag' => '🇵🇦'],
            ['label' => 'Papua New Guinea', 'value' => 'PG', 'flag' => '🇵🇬'],
            ['label' => 'Paraguay', 'value' => 'PY', 'flag' => '🇵🇾'],
            ['label' => 'Peru', 'value' => 'PE', 'flag' => '🇵🇪'],
            ['label' => 'Philippines', 'value' => 'PH', 'flag' => '🇵🇭'],
            ['label' => 'Poland', 'value' => 'PL', 'flag' => '🇵🇱'],
            ['label' => 'Portugal', 'value' => 'PT', 'flag' => '🇵🇹'],
            ['label' => 'Qatar', 'value' => 'QA', 'flag' => '🇶🇦'],
            ['label' => 'Romania', 'value' => 'RO', 'flag' => '🇷🇴'],
            ['label' => 'Russia', 'value' => 'RU', 'flag' => '🇷🇺'],
            ['label' => 'Rwanda', 'value' => 'RW', 'flag' => '🇷🇼'],
            ['label' => 'Saint Kitts and Nevis', 'value' => 'KN', 'flag' => '🇰🇳'],
            ['label' => 'Saint Lucia', 'value' => 'LC', 'flag' => '🇱🇨'],
            ['label' => 'Saint Vincent and the Grenadines', 'value' => 'VC', 'flag' => '🇻🇨'],
            ['label' => 'Samoa', 'value' => 'WS', 'flag' => '🇼🇸'],
            ['label' => 'San Marino', 'value' => 'SM', 'flag' => '🇸🇲'],
            ['label' => 'Sao Tome and Principe', 'value' => 'ST', 'flag' => '🇸🇹'],
            ['label' => 'Saudi Arabia', 'value' => 'SA', 'flag' => '🇸🇦'],
            ['label' => 'Senegal', 'value' => 'SN', 'flag' => '🇸🇳'],
            ['label' => 'Serbia', 'value' => 'RS', 'flag' => '🇷🇸'],
            ['label' => 'Seychelles', 'value' => 'SC', 'flag' => '🇸🇨'],
            ['label' => 'Sierra Leone', 'value' => 'SL', 'flag' => '🇸🇱'],
            ['label' => 'Singapore', 'value' => 'SG', 'flag' => '🇸🇬'],
            ['label' => 'Slovakia', 'value' => 'SK', 'flag' => '🇸🇰'],
            ['label' => 'Slovenia', 'value' => 'SI', 'flag' => '🇸🇮'],
            ['label' => 'Solomon Islands', 'value' => 'SB', 'flag' => '🇸🇧'],
            ['label' => 'Somalia', 'value' => 'SO', 'flag' => '🇸🇴'],
            ['label' => 'South Africa', 'value' => 'ZA', 'flag' => '🇿🇦'],
            ['label' => 'South Korea', 'value' => 'KR', 'flag' => '🇰🇷'],
            ['label' => 'South Sudan', 'value' => 'SS', 'flag' => '🇸🇸'],
            ['label' => 'Spain', 'value' => 'ES', 'flag' => '🇪🇸'],
            ['label' => 'Sri Lanka', 'value' => 'LK', 'flag' => '🇱🇰'],
            ['label' => 'Sudan', 'value' => 'SD', 'flag' => '🇸🇩'],
            ['label' => 'Suriname', 'value' => 'SR', 'flag' => '🇸🇷'],
            ['label' => 'Sweden', 'value' => 'SE', 'flag' => '🇸🇪'],
            ['label' => 'Switzerland', 'value' => 'CH', 'flag' => '🇨🇭'],
            ['label' => 'Syria', 'value' => 'SY', 'flag' => '🇸🇾'],
            ['label' => 'Taiwan', 'value' => 'TW', 'flag' => '🇹🇼'],
            ['label' => 'Tajikistan', 'value' => 'TJ', 'flag' => '🇹🇯'],
            ['label' => 'Tanzania', 'value' => 'TZ', 'flag' => '🇹🇿'],
            ['label' => 'Thailand', 'value' => 'TH', 'flag' => '🇹🇭'],
            ['label' => 'Timor-Leste', 'value' => 'TL', 'flag' => '🇹🇱'],
            ['label' => 'Togo', 'value' => 'TG', 'flag' => '🇹🇬'],
            ['label' => 'Tonga', 'value' => 'TO', 'flag' => '🇹🇴'],
            ['label' => 'Trinidad and Tobago', 'value' => 'TT', 'flag' => '🇹🇹'],
            ['label' => 'Tunisia', 'value' => 'TN', 'flag' => '🇹🇳'],
            ['label' => 'Turkey', 'value' => 'TR', 'flag' => '🇹🇷'],
            ['label' => 'Turkmenistan', 'value' => 'TM', 'flag' => '🇹🇲'],
            ['label' => 'Tuvalu', 'value' => 'TV', 'flag' => '🇹🇻'],
            ['label' => 'Uganda', 'value' => 'UG', 'flag' => '🇺🇬'],
            ['label' => 'Ukraine', 'value' => 'UA', 'flag' => '🇺🇦'],
            ['label' => 'United Arab Emirates', 'value' => 'AE', 'flag' => '🇦🇪'],
            ['label' => 'United Kingdom', 'value' => 'GB', 'flag' => '🇬🇧'],
            ['label' => 'United States', 'value' => 'US', 'flag' => '🇺🇸'],
            ['label' => 'Uruguay', 'value' => 'UY', 'flag' => '🇺🇾'],
            ['label' => 'Uzbekistan', 'value' => 'UZ', 'flag' => '🇺🇿'],
            ['label' => 'Vanuatu', 'value' => 'VU', 'flag' => '🇻🇺'],
            ['label' => 'Vatican City', 'value' => 'VA', 'flag' => '🇻🇦'],
            ['label' => 'Venezuela', 'value' => 'VE', 'flag' => '🇻🇪'],
            ['label' => 'Vietnam', 'value' => 'VN', 'flag' => '🇻🇳'],
            ['label' => 'Yemen', 'value' => 'YE', 'flag' => '🇾🇪'],
            ['label' => 'Zambia', 'value' => 'ZM', 'flag' => '🇿🇲'],
            ['label' => 'Zimbabwe', 'value' => 'ZW', 'flag' => '🇿🇼'],
        ];
    }

    /**
     * Resolve display name from stored country code.
     */
    public static function resolveCountryName(string $code): ?string
    {
        foreach (self::countryList() as $country) {
            if ($country['value'] === strtoupper($code)) {
                return $country['label'];
            }
        }

        return null;
    }

    /**
     * Resolve flag emoji from stored country code.
     */
    public static function resolveCountryFlag(string $code): ?string
    {
        foreach (self::countryList() as $country) {
            if ($country['value'] === strtoupper($code)) {
                return $country['flag'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'countries' => self::countryList(),
            'showFlags' => $this->showFlags,
            'filterPlaceholder' => $this->filterPlaceholder,
        ], fn ($v) => $v !== null);
    }
}
