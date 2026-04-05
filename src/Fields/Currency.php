<?php

namespace Martis\Fields;

/**
 * Currency field — monetary value input with currency formatting.
 *
 * Paridade com Laravel Nova v5: Currency field.
 * Extends Number behavior for monetary values.
 * Stores numeric value in the database; display includes currency symbol.
 *
 * Nova-compatible API:
 *   - currency($code)    — ISO 4217 currency code (default: USD)
 *   - locale($locale)    — override app locale for formatting
 *   - asMinorUnits()     — treat stored value as minor units (cents)
 *   - asMajorUnits()     — treat stored value as major units (dollars)
 *
 * Extensão Martis — apresentação visual:
 *   - displayMode('text'|'badge'|'badge_text') — controla exibição
 *   - showBadge()   — exibir apenas badge com símbolo
 *   - showText()    — exibir apenas texto com nome da moeda
 *   - badgeColor()  — cor do badge (ex: green, blue, red)
 *
 * Contextos: create, update, detail, index.
 */
class Currency extends Number
{
    protected string $currencyCode = 'USD';

    protected ?string $locale = null;

    protected bool $minorUnits = false;

    /** @var 'text'|'badge'|'badge_text' */
    protected string $displayMode = 'text';

    protected ?string $badgeColor = null;

    /**
     * Known currencies: code => [symbol, name, decimals].
     *
     * @var array<string, array{symbol: string, name: string, decimals: int}>
     */
    protected static array $currencies = [
        'USD' => ['symbol' => '$', 'name' => 'US Dollar', 'decimals' => 2],
        'EUR' => ['symbol' => '€', 'name' => 'Euro', 'decimals' => 2],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound', 'decimals' => 2],
        'BRL' => ['symbol' => 'R$', 'name' => 'Real brasileiro', 'decimals' => 2],
        'JPY' => ['symbol' => '¥', 'name' => 'Japanese Yen', 'decimals' => 0],
        'CNY' => ['symbol' => '¥', 'name' => 'Chinese Yuan', 'decimals' => 2],
        'CAD' => ['symbol' => 'CA$', 'name' => 'Canadian Dollar', 'decimals' => 2],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar', 'decimals' => 2],
        'CHF' => ['symbol' => 'CHF', 'name' => 'Swiss Franc', 'decimals' => 2],
        'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee', 'decimals' => 2],
        'MXN' => ['symbol' => 'MX$', 'name' => 'Mexican Peso', 'decimals' => 2],
        'KRW' => ['symbol' => '₩', 'name' => 'South Korean Won', 'decimals' => 0],
        'SEK' => ['symbol' => 'kr', 'name' => 'Swedish Krona', 'decimals' => 2],
        'NOK' => ['symbol' => 'kr', 'name' => 'Norwegian Krone', 'decimals' => 2],
        'DKK' => ['symbol' => 'kr', 'name' => 'Danish Krone', 'decimals' => 2],
        'PLN' => ['symbol' => 'zł', 'name' => 'Polish Zloty', 'decimals' => 2],
        'THB' => ['symbol' => '฿', 'name' => 'Thai Baht', 'decimals' => 2],
        'ZAR' => ['symbol' => 'R', 'name' => 'South African Rand', 'decimals' => 2],
        'TRY' => ['symbol' => '₺', 'name' => 'Turkish Lira', 'decimals' => 2],
        'RUB' => ['symbol' => '₽', 'name' => 'Russian Ruble', 'decimals' => 2],
        'NZD' => ['symbol' => 'NZ$', 'name' => 'New Zealand Dollar', 'decimals' => 2],
        'SGD' => ['symbol' => 'S$', 'name' => 'Singapore Dollar', 'decimals' => 2],
        'HKD' => ['symbol' => 'HK$', 'name' => 'Hong Kong Dollar', 'decimals' => 2],
        'CLP' => ['symbol' => '$', 'name' => 'Chilean Peso', 'decimals' => 0],
        'ARS' => ['symbol' => '$', 'name' => 'Argentine Peso', 'decimals' => 2],
        'COP' => ['symbol' => '$', 'name' => 'Colombian Peso', 'decimals' => 2],
        'PEN' => ['symbol' => 'S/', 'name' => 'Peruvian Sol', 'decimals' => 2],
    ];

    public function type(): string
    {
        return 'currency';
    }

    /**
     * Set the ISO 4217 currency code.
     * Nova-compatible API.
     */
    public function currency(string $code): static
    {
        $this->currencyCode = strtoupper($code);

        // Set step based on currency decimals
        $info = self::$currencies[$this->currencyCode] ?? null;
        if ($info !== null) {
            $this->step = $info['decimals'] > 0 ? (float) ('0.'.str_repeat('0', $info['decimals'] - 1).'1') : 1;
        }

        return $this;
    }

    /**
     * Override locale for currency formatting.
     * Nova-compatible API.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Treat stored value as minor units (cents).
     * Nova-compatible API.
     */
    public function asMinorUnits(): static
    {
        $this->minorUnits = true;

        return $this;
    }

    /**
     * Treat stored value as major units (dollars).
     * Nova-compatible API.
     */
    public function asMajorUnits(): static
    {
        $this->minorUnits = false;

        return $this;
    }

    /**
     * Get the configured currency code.
     */
    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * Get locale (falls back to app locale).
     */
    public function getLocale(): string
    {
        return $this->locale ?? config('app.locale', 'en');
    }

    /**
     * Check if using minor units.
     */
    public function isMinorUnits(): bool
    {
        return $this->minorUnits;
    }

    // -------------------------------------------------------------------------
    // Martis visual extensions
    // -------------------------------------------------------------------------

    /**
     * Set the display mode for currency visualization.
     * Martis extension — not part of Nova API.
     *
     * @param  'text'|'badge'|'badge_text'  $mode
     */
    public function displayMode(string $mode): static
    {
        $this->displayMode = $mode;

        return $this;
    }

    /**
     * Show currency as badge only.
     * Martis extension.
     */
    public function showBadge(): static
    {
        $this->displayMode = 'badge';

        return $this;
    }

    /**
     * Show currency as text only (default).
     * Martis extension.
     */
    public function showText(): static
    {
        $this->displayMode = 'text';

        return $this;
    }

    /**
     * Show currency as badge + text.
     * Martis extension.
     */
    public function showBadgeText(): static
    {
        $this->displayMode = 'badge_text';

        return $this;
    }

    /**
     * Set badge color for currency display.
     * Martis extension.
     */
    public function badgeColor(string $color): static
    {
        $this->badgeColor = $color;

        return $this;
    }

    /**
     * Get display mode.
     */
    public function getDisplayMode(): string
    {
        return $this->displayMode;
    }

    /**
     * Get badge color.
     */
    public function getBadgeColor(): ?string
    {
        return $this->badgeColor;
    }

    /**
     * Get currency info (symbol, name, decimals).
     *
     * @return array{symbol: string, name: string, decimals: int}
     */
    public function getCurrencyInfo(): array
    {
        return self::$currencies[$this->currencyCode] ?? [
            'symbol' => $this->currencyCode,
            'name' => $this->currencyCode,
            'decimals' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $info = $this->getCurrencyInfo();

        return array_merge(parent::extraAttributes(), array_filter([
            'currencyCode' => $this->currencyCode,
            'currencySymbol' => $info['symbol'],
            'currencyName' => $info['name'],
            'currencyDecimals' => $info['decimals'],
            'locale' => $this->locale,
            'minorUnits' => $this->minorUnits ?: null,
            'displayMode' => $this->displayMode,
            'badgeColor' => $this->badgeColor,
        ], fn (mixed $v): bool => $v !== null));
    }
}
