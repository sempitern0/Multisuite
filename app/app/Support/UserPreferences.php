<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Per-person interface preferences. Unknown or invalid stored values fall back to
 * the defaults, so a bad row can never break a page. Never used for authorisation.
 */
final class UserPreferences
{
    /** Allowed values per key; the first one is the default. */
    public const OPTIONS = [
        // system follows prefers-reduced-motion; on/off override it for Multisuite only.
        'motion' => ['system', 'on', 'off'],
        'density' => ['comfortable', 'compact'],
        // Set with the collapse button of the side menu, not from the Preferences form.
        'sidebar' => ['full', 'compact'],
        'start' => ['services', 'files'],
        'per_page' => [25, 10, 50],
        // How Mis archivos shows a folder; changed from that page, not from the Preferences form.
        'files_view' => ['details', 'list', 'gallery'],
    ];

    /** Keys chosen elsewhere than the Preferences form. */
    private const OUTSIDE_FORM = ['sidebar', 'files_view'];

    /** @param array{motion: string, density: string, sidebar: string, start: string, per_page: int, files_view: string} $values */
    private function __construct(private readonly array $values) {}

    /** @param array<string, mixed>|null $stored */
    public static function from(?array $stored): self
    {
        $values = [];
        foreach (self::OPTIONS as $key => $allowed) {
            $value = $stored[$key] ?? null;
            $values[$key] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        return new self($values);
    }

    public static function defaults(): self
    {
        return self::from(null);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $rules = [];
        foreach (array_diff_key(self::OPTIONS, array_flip(self::OUTSIDE_FORM)) as $key => $allowed) {
            $rules[$key] = ['required', Rule::in($allowed)];
        }

        return $rules;
    }

    public function motion(): string
    {
        return $this->values['motion'];
    }

    public function density(): string
    {
        return $this->values['density'];
    }

    public function sidebar(): string
    {
        return $this->values['sidebar'];
    }

    public function startRoute(): string
    {
        return $this->values['start'] === 'files' ? 'files.index' : 'dashboard';
    }

    public function perPage(): int
    {
        return $this->values['per_page'];
    }

    public function filesView(): string
    {
        return $this->values['files_view'];
    }

    /** Same preferences with one value changed (invalid values fall back to the default). */
    public function with(string $key, mixed $value): self
    {
        return self::from([$key => $value] + $this->values);
    }

    /** @return array{motion: string, density: string, sidebar: string, start: string, per_page: int, files_view: string} */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Attributes the theme applies to <html>; the theme decides what they look like.
     *
     * @return array{data-motion: string, data-density: string, data-sidebar: string}
     */
    public function htmlAttributes(): array
    {
        return ['data-motion' => $this->motion(), 'data-density' => $this->density(), 'data-sidebar' => $this->sidebar()];
    }
}
