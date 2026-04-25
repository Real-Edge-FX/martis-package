<?php

namespace Martis\Fields;

use Martis\Enums\CurrencyCode;
use Martis\Enums\CurrencyDisplayMode;

/**
 * Currency field — monetary value input with currency formatting.
 *
 * Extends Number behavior for monetary values.
 * Stores numeric value in the database; display includes currency symbol.
 *
 * API:
 *   - currency($code)    — ISO 4217 currency code (default: USD)
 *   - locale($locale)    — override app locale for formatting
 *   - asMinorUnits()     — treat stored value as minor units (cents)
 *   - asMajorUnits()     — treat stored value as major units (dollars)
 *
 * Martis extension — visual presentation:
 *   - displayMode('text'|'badge'|'badge_text') — controls display
 *   - showBadge()   — display badge with symbol only
 *   - showText()    — display text with currency name only
 *   - badgeColor()  — badge color (e.g. green, blue, red)
 *
 * Contexts: create, update, detail, index.
 */
class Currency extends Number
{
    protected CurrencyCode $currencyCode = CurrencyCode::USD;

    protected ?string $locale = null;

    protected bool $minorUnits = false;

    protected CurrencyDisplayMode $displayMode = CurrencyDisplayMode::Text;

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

    /**
     * Type.
     */
    public function type(): string
    {
        return 'currency';
    }

    /**
     * Set the ISO 4217 currency code.
     */
    public function currency(CurrencyCode $code): static
    {
        $this->currencyCode = $code;

        // Set step based on currency decimals
        $info = self::$currencies[$this->currencyCode->value] ?? null;
        if ($info !== null) {
            $this->step = $info['decimals'] > 0 ? (float) ('0.'.str_repeat('0', $info['decimals'] - 1).'1') : 1;
        }

        return $this;
    }

    /**
     * Override locale for currency formatting.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Treat stored value as minor units (cents).
     */
    public function asMinorUnits(): static
    {
        $this->minorUnits = true;

        return $this;
    }

    /**
     * Treat stored value as major units (dollars).
     */
    public function asMajorUnits(): static
    {
        $this->minorUnits = false;

        return $this;
    }

    /**
     * Get the configured currency code.
     */
    public function getCurrencyCode(): CurrencyCode
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
     * Martis extension.
     */
    public function displayMode(CurrencyDisplayMode $mode): static
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
        $this->displayMode = CurrencyDisplayMode::Badge;

        return $this;
    }

    /**
     * Show currency as text only (default).
     * Martis extension.
     */
    public function showText(): static
    {
        $this->displayMode = CurrencyDisplayMode::Text;

        return $this;
    }

    /**
     * Show currency as badge + text.
     * Martis extension.
     */
    public function showBadgeText(): static
    {
        $this->displayMode = CurrencyDisplayMode::BadgeText;

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
    public function getDisplayMode(): CurrencyDisplayMode
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
        return self::$currencies[$this->currencyCode->value] ?? [
            'symbol' => $this->currencyCode->value,
            'name' => $this->currencyCode->value,
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
            'currencyCode' => $this->currencyCode->value,
            'currencySymbol' => $info['symbol'],
            'currencyName' => $info['name'],
            'currencyDecimals' => $info['decimals'],
            'locale' => $this->locale,
            'minorUnits' => $this->minorUnits ?: null,
            'displayMode' => $this->displayMode->value,
            'badgeColor' => $this->badgeColor,
        ], fn (mixed $v): bool => $v !== null));
    }
}
