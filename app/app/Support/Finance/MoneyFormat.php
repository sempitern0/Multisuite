<?php

namespace App\Support\Finance;

use NumberFormatter;

/**
 * Currency and number format for one person (intl, docs/DEPENDENCIES.md). Amounts are integer
 * minor units; parsing never goes through floats.
 */
final class MoneyFormat
{
    /** Offered currencies (two or zero decimals, so the parser has no 3-decimal ambiguity). */
    public const CURRENCIES = [
        'EUR' => 'Euro',
        'USD' => 'Dólar estadounidense',
        'GBP' => 'Libra esterlina',
        'CHF' => 'Franco suizo',
        'MXN' => 'Peso mexicano',
        'ARS' => 'Peso argentino',
        'COP' => 'Peso colombiano',
        'CLP' => 'Peso chileno',
        'BRL' => 'Real brasileño',
        'CAD' => 'Dólar canadiense',
        'JPY' => 'Yen japonés',
    ];

    /** Number formats by ICU locale; the label is built with the person's currency. */
    public const FORMATS = ['es_ES', 'en_US', 'fr_FR', 'de_CH'];

    private const MAX_DIGITS = 12;

    public function __construct(public readonly string $currency, public readonly string $locale) {}

    public static function supports(string $currency, string $locale): bool
    {
        return isset(self::CURRENCIES[$currency]) && in_array($locale, self::FORMATS, true);
    }

    public function decimals(): int
    {
        return self::fractionDigits($this->currency);
    }

    public static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /** "1.234,56 €", "$1,234.56"… for an amount in minor units of $currency (defaults to this one). */
    public function format(int $minor, ?string $currency = null): string
    {
        $currency ??= $this->currency;
        $formatter = new NumberFormatter($this->locale, NumberFormatter::CURRENCY);
        $digits = self::fractionDigits($currency);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);

        // Display only: minor units below 2^53 are exact as floats.
        return (string) $formatter->formatCurrency($minor / (10 ** $digits), $currency);
    }

    /** Plain number for an input field, with this format's decimal separator ("1234,56"). */
    public function input(int $minor): string
    {
        $digits = $this->decimals();
        $whole = intdiv($minor, 10 ** $digits);
        if ($digits === 0) {
            return (string) $whole;
        }

        return $whole.$this->decimalSeparator().str_pad((string) ($minor % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Example shown next to each format option. */
    public function example(): string
    {
        return $this->format($this->decimals() === 0 ? 1234 : 123456);
    }

    public function decimalSeparator(): string
    {
        return (new NumberFormatter($this->locale, NumberFormatter::DECIMAL))->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }

    /**
     * Minor units from what a person typed, or null if it is not an amount.
     * Accepts either separator: a final group of 1..decimals digits is the fraction, anything
     * else is grouping ("12.5" → 12,50; "1.234" → 1234; "1,234.56" → 1234,56). Spaces,
     * apostrophes and currency symbols are ignored.
     */
    public function parse(string $value): ?int
    {
        if (str_contains($value, '-')) {
            return null; // amounts are positive; the kind says whether it is income or expense
        }
        $clean = (string) preg_replace('/[\s\x{00A0}\x{202F}\'’]|[^\d.,]/u', '', $value);
        if ($clean === '' || preg_match('/^[.,]|[.,]$/', $clean)) {
            return null;
        }
        $digits = $this->decimals();
        $whole = $clean;
        $fraction = '';
        $last = max((int) strrpos($clean, ','), (int) strrpos($clean, '.'));
        if (preg_match('/[.,]/', $clean)) {
            $tail = substr($clean, $last + 1);
            if ($digits > 0 && strlen($tail) >= 1 && strlen($tail) <= $digits) {
                $whole = substr($clean, 0, $last);
                $fraction = $tail;
            }
        }
        $whole = str_replace(['.', ','], '', $whole);
        if (! ctype_digit($whole) || ($fraction !== '' && ! ctype_digit($fraction)) || strlen($whole) > self::MAX_DIGITS) {
            return null;
        }

        return ((int) $whole * (10 ** $digits)) + (int) str_pad($fraction, $digits, '0');
    }
}
