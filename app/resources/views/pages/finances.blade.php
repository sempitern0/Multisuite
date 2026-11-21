<?php

use App\Actions\Finance\DeleteTransaction;
use App\Actions\Finance\ReceiptsFolder;
use App\Actions\Finance\RecordTransaction;
use App\Actions\Finance\SetMonthlyBudget;
use App\Jobs\ReadReceipt;
use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Models\PersonalTransaction;
use App\Models\UserFile;
use App\Services\Finance\FinanceSummary;
use App\Support\Finance\Categories;
use App\Support\Finance\MoneyFormat;
use App\Support\Finance\ReceiptReader;
use App\Support\UserPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Mis finanzas (docs/PERSONAL_FINANCES.md): validates input and shows totals. Recording,
 * deleting and budgets live in App\Actions\Finance; totals in FinanceSummary.
 */
new #[Title('Mis finanzas')] class extends Component {
    use NotifiesInterface, ThrottlesActions, WithPagination;

    public int $year;
    public int $month;
    public string $kind = 'expense';
    public string $category = 'other';
    public string $description = '';
    public string $amount = '';
    public string $date = '';
    public string $limit = '';
    public string $currency = 'EUR';
    public string $format = 'es_ES';
    /** Month list filters: all | income | expense, and a text search on the concept. */
    public string $show = 'all';
    public string $search = '';
    #[Locked]
    public ?int $receipt = null;
    #[Locked]
    public ?int $editing = null;
    #[Locked]
    public ?string $editingCurrency = null;
    #[Locked]
    public string $entryKey = '';
    #[Locked]
    public bool $scanning = false;
    /** When the current reading started: the page stops waiting after SCAN_WAIT seconds. */
    #[Locked]
    public ?int $scanStartedAt = null;

    private const SCAN_WAIT = 120;

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
        $prefs = Auth::user()->preferences();
        [$this->currency, $this->format] = [$prefs->moneyFormat()->currency, $prefs->moneyFormat()->locale];
        $this->clearDraft();
        $this->loadLimit();
        if ($id = request()->integer('receipt')) {
            $file = UserFile::where('user_id', Auth::id())->find($id);
            abort_unless($file !== null && $file->isCurrent() && in_array($file->upload()->value('mime'), ReceiptReader::TYPES, true), 404);
            $existing = PersonalTransaction::ownedBy(Auth::user())->where('receipt_id', $id)->first();
            if ($existing !== null) {
                $this->edit($existing->id);
            } else {
                $this->receipt = $id;
                $this->description = Str::limit(pathinfo($file->name, PATHINFO_FILENAME), 120, '');
            }
        }
    }

    #[Computed]
    public function money(): MoneyFormat
    {
        return Auth::user()->preferences()->moneyFormat();
    }

    /** Format used to read the amount field: the edited movement keeps its own currency. */
    private function inputMoney(): MoneyFormat
    {
        return new MoneyFormat($this->editingCurrency ?? $this->money->currency, $this->money->locale);
    }

    /** @return array<int, string> */
    #[Computed]
    public function years(): array
    {
        $now = (int) now()->year;
        $years = range($now + 1, $now - 9);

        return array_combine($years, array_map('strval', $years));
    }

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1);
    }

    public function monthName(int $month): string
    {
        return Str::ucfirst(CarbonImmutable::create($this->year, $month, 1)->locale('es')->translatedFormat('F'));
    }

    private function loadLimit(): void
    {
        $limit = app(FinanceSummary::class)->budget(Auth::user(), $this->monthStart(), $this->money->currency);
        $this->limit = $limit === null ? '' : $this->money->input($limit);
    }

    public function updatedYear(): void
    {
        if (! array_key_exists($this->year, $this->years)) {
            $this->year = (int) now()->year;
            $this->addError('year', 'Elige un año de la lista.');
        }
        $this->loadLimit();
        $this->resetPage();
    }

    public function selectMonth(int $month): void
    {
        abort_unless($month >= 1 && $month <= 12, 404);
        $this->month = $month;
        $this->loadLimit();
        $this->resetPage();
    }

    public function previousMonth(): void
    {
        $this->moveMonth(-1);
    }

    public function nextMonth(): void
    {
        $this->moveMonth(1);
    }

    private function moveMonth(int $step): void
    {
        $target = $this->monthStart()->addMonths($step);
        if (! array_key_exists((int) $target->year, $this->years)) {
            return;
        }
        [$this->year, $this->month] = [(int) $target->year, (int) $target->month];
        $this->loadLimit();
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        if (! in_array($this->show, ['all', 'income', 'expense'], true)) {
            $this->show = 'all';
        }
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->search = Str::limit($this->search, 60, '');
        $this->resetPage();
    }

    /** Folder of Mis archivos that receives tickets uploaded here (created the first time). */
    #[Computed]
    public function receiptsFolder(): int
    {
        return app(ReceiptsFolder::class)->for(Auth::user())->id;
    }

    /**
     * A ticket uploaded from this page (files.js, through Mis archivos) becomes the receipt of
     * a new movement and is read straight away.
     *
     * @param  array<mixed>  $ids
     */
    #[On('files-uploaded')]
    public function receiptUploaded(array $ids = []): void
    {
        $id = collect($ids)->filter(fn ($id) => is_int($id))->last();
        $file = $id === null ? null : UserFile::where('user_id', Auth::id())->find($id);
        if ($file === null || ! $file->isCurrent()) {
            return;
        }
        $this->clearDraft();
        if (! in_array($file->upload()->value('mime'), ReceiptReader::TYPES, true)) {
            $this->addError('receipt', 'Se ha guardado en Mis archivos, pero solo se pueden leer fotos (JPG, PNG, WebP) o PDF.');

            return;
        }
        $this->receipt = $file->id;
        $this->description = Str::limit(pathinfo($file->name, PATHINFO_FILENAME), 120, '');
        $this->startScan();
    }

    public function updatedKind(): void
    {
        if (! Categories::valid($this->kind, $this->category)) {
            $this->category = 'other';
        }
    }

    public function saveSettings(): void
    {
        Gate::authorize('use-finances');
        $this->throttle('finances');
        $this->validate([
            'currency' => ['required', Rule::in(UserPreferences::OPTIONS['finance_currency'])],
            'format' => ['required', Rule::in(UserPreferences::OPTIONS['finance_format'])],
        ]);
        $user = Auth::user();
        $user->preferences = $user->preferences()->with('finance_currency', $this->currency)->with('finance_format', $this->format)->toArray();
        $user->save();
        unset($this->money);
        $this->loadLimit();
        $this->dispatch('ui-dialog-close', name: 'finance-settings');
        $this->toast('Moneda y formato guardados');
    }

    public function saveLimit(SetMonthlyBudget $budgets): void
    {
        Gate::authorize('use-finances');
        $this->throttle('finances');
        $minor = trim($this->limit) === '' ? null : $this->money->parse($this->limit);
        if (trim($this->limit) !== '' && $minor === null) {
            $this->addError('limit', 'Escribe una cantidad, por ejemplo '.$this->money->input(50000).'.');

            return;
        }
        $budgets->handle(Auth::user(), $this->monthStart(), $this->money->currency, $minor);
        $this->resetValidation('limit');
        $this->toast($minor === null ? 'Presupuesto quitado' : 'Presupuesto guardado');
    }

    public function clearDraft(): void
    {
        $this->reset('kind', 'category', 'description', 'amount', 'receipt', 'editing', 'editingCurrency', 'scanning', 'scanStartedAt');
        $this->resetValidation();
        $this->date = now()->format('Y-m-d');
        $this->entryKey = (string) Str::uuid();
    }

    public function edit(int $id): void
    {
        $transaction = PersonalTransaction::ownedBy(Auth::user())->find($id);
        if ($transaction === null) {
            $this->toast('Ese movimiento ya no existe', 'warn');

            return;
        }
        $this->clearDraft();
        $this->editing = $transaction->id;
        $this->editingCurrency = $transaction->currency;
        $this->receipt = $transaction->receipt_id;
        $this->kind = $transaction->kind;
        $this->category = $transaction->category;
        $this->date = substr($transaction->occurred_on, 0, 10);
        $this->description = $transaction->description;
        $this->amount = $this->inputMoney()->input($transaction->amount_minor);
    }

    public function startScan(): void
    {
        abort_if($this->receipt === null, 404);
        Gate::authorize('use-finances');
        $this->throttle('receipt-ocr');
        $this->resetValidation('receipt');
        Cache::put(ReadReceipt::key((int) Auth::id(), $this->receipt), ['status' => 'pending'], now()->addMinutes(15));
        ReadReceipt::dispatch((int) Auth::id(), $this->receipt);
        $this->scanning = true;
        $this->scanStartedAt = now()->getTimestamp();
        $this->checkScan(); // with a synchronous queue the result is already there
    }

    public function checkScan(): void
    {
        if (! $this->scanning || $this->receipt === null) {
            return;
        }
        $result = Cache::get(ReadReceipt::key((int) Auth::id(), $this->receipt));
        $waiting = is_array($result) && ($result['status'] ?? null) === 'pending';
        if ($waiting && now()->getTimestamp() - (int) $this->scanStartedAt < self::SCAN_WAIT) {
            return;
        }
        $this->reset('scanning', 'scanStartedAt');
        if (! is_array($result) || $waiting) {
            // Nothing will arrive (no queue worker, cache cleared or result expired): stop polling.
            $this->addError('receipt', 'La lectura no está disponible ahora. Escribe el movimiento a mano o inténtalo más tarde.');

            return;
        }
        if ($result['status'] !== 'done') {
            $this->addError('receipt', (string) ($result['error'] ?? 'No se ha podido leer la factura.'));

            return;
        }
        if (($result['date'] ?? '') !== '') {
            $this->date = $result['date'];
        }
        $minor = ($result['amount'] ?? '') === '' ? null : $this->inputMoney()->parse($result['amount']);
        if ($minor !== null) {
            $this->amount = $this->inputMoney()->input($minor);
        }
        $this->toast($minor === null ? 'Comprueba la fecha y escribe el total' : 'Revisa los datos antes de guardar');
    }

    public function saveTransaction(RecordTransaction $record): void
    {
        Gate::authorize('use-finances');
        $this->throttle('finances');
        $this->validate([
            'kind' => ['required', Rule::in(array_keys(Categories::KINDS))],
            'category' => ['required', Rule::in(array_keys(Categories::options($this->kind)))],
            'description' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'amount' => ['required', 'string', 'max:24'],
        ]);
        $minor = $this->inputMoney()->parse($this->amount);
        if ($minor === null || $minor < 1) {
            $this->addError('amount', $minor === null ? 'Escribe una cantidad, por ejemplo '.$this->inputMoney()->input(1250).'.' : 'Escribe una cantidad mayor que cero.');

            return;
        }
        $result = $record->handle(Auth::user(), [
            'kind' => $this->kind, 'category' => $this->category, 'occurred_on' => $this->date,
            'description' => trim($this->description), 'amount_minor' => $minor, 'currency' => $this->inputMoney()->currency,
        ], $this->entryKey, $this->receipt, $this->editing);

        $date = CarbonImmutable::parse($this->date);
        $wasEditing = $this->editing !== null;
        [$this->year, $this->month] = [(int) $date->year, (int) $date->month];
        $this->clearDraft();
        $this->loadLimit();
        $this->resetPage();
        $this->toast($result['created'] ? 'Movimiento guardado' : ($wasEditing ? 'Movimiento actualizado' : 'Este movimiento ya estaba registrado'));
    }

    public function deleteTransaction(DeleteTransaction $delete, int $id): void
    {
        Gate::authorize('use-finances');
        $this->throttle('finances');
        if (! $delete->handle(Auth::user(), $id)) {
            $this->toast('Ese movimiento ya no existe', 'warn');

            return;
        }
        if ($this->editing === $id) {
            $this->clearDraft();
        }
        $this->resetPage();
        $this->toast('Movimiento eliminado');
    }

    /** @return array{months: array<int, array{income: int, expense: int, balance: int}>, income: int, expense: int, balance: int, categories: array{income: array<string, int>, expense: array<string, int>}, otherCurrencies: list<string>} */
    #[Computed]
    public function yearSummary(): array
    {
        return app(FinanceSummary::class)->year(Auth::user(), $this->year, $this->money->currency);
    }

    /** @return LengthAwarePaginator<int, PersonalTransaction> */
    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        $start = $this->monthStart();

        $search = trim($this->search);

        return PersonalTransaction::ownedBy(Auth::user())
            ->where('occurred_on', '>=', $start->toDateString())->where('occurred_on', '<', $start->addMonth()->toDateString())
            ->when(in_array($this->show, ['income', 'expense'], true), fn ($query) => $query->where('kind', $this->show))
            ->when($search !== '', fn ($query) => $query->where('description', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(15);
    }

    #[Computed]
    public function budget(): ?int
    {
        return app(FinanceSummary::class)->budget(Auth::user(), $this->monthStart(), $this->money->currency);
    }
}; ?>

<div>
    @php($money = $this->money)
    @php($summary = $this->yearSummary)
    @php($totals = $summary['months'][$month])
    @php($budget = $this->budget)
    @php($inputMoney = new MoneyFormat($editingCurrency ?? $money->currency, $money->locale))
    <x-ui::page-header title="Mis finanzas" description="Tus ingresos y gastos. Solo los ves tú.">
        <x-slot:actions>
            <x-ui::button variant="ghost" icon="settings" x-on:click="$dispatch('ui-dialog-open', 'finance-settings')">Moneda y formato</x-ui::button>
            <x-ui::button icon="plus" href="#nuevo-movimiento">Añadir movimiento</x-ui::button>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::stack gap="lg">
        <x-ui::metrics :items="[
            ['label' => 'Ingresos de '.$year, 'value' => $money->format($summary['income']), 'tone' => 'ok', 'symbol' => $money->symbol()],
            ['label' => 'Gastos de '.$year, 'value' => $money->format($summary['expense']), 'tone' => 'bad', 'symbol' => $money->symbol()],
            ['label' => 'Balance', 'value' => $money->format($summary['balance']), 'tone' => $summary['balance'] < 0 ? 'bad' : 'accent', 'symbol' => $money->symbol(), 'sub' => $money->currency.' · '.MoneyFormat::CURRENCIES[$money->currency]],
            ['label' => 'Ahorro', 'value' => $summary['savingsRate'] === null ? '—' : $summary['savingsRate'].' %', 'tone' => ($summary['savingsRate'] ?? 0) < 0 ? 'bad' : 'accent', 'sub' => 'De lo que ha entrado en el año'],
        ]" />
        @if ($summary['otherCurrencies'] !== [])
            <x-ui::notice tone="info">También tienes movimientos en {{ implode(', ', $summary['otherCurrencies']) }}. No se convierten: los totales solo suman {{ $money->currency }}.</x-ui::notice>
        @endif

        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::toolbar>
                    <x-ui::heading>Ingresos y gastos</x-ui::heading>
                    <x-slot:end><x-ui::select name="year" label="Año" :options="$this->years" wire:model.live="year" :value="$year" /></x-slot:end>
                </x-ui::toolbar>
                <x-ui::month-chart action="selectMonth" :label="'Ingresos y gastos por mes de '.$year" :items="collect($summary['months'])->map(fn ($row, $number) => [
                    'key' => $number,
                    'short' => rtrim(Str::ucfirst(CarbonImmutable::create($year, $number, 1)->locale('es')->translatedFormat('M')), '.'),
                    'name' => $this->monthName($number),
                    'income' => $row['income'],
                    'expense' => $row['expense'],
                    'summary' => 'ingresos '.$money->format($row['income']).', gastos '.$money->format($row['expense']).', balance '.$money->format($row['balance']),
                    'current' => $number === $month,
                ])->values()->all()" />
                <details>
                    <summary class="ui-link">Ver los importes en una tabla</summary>
                    <x-ui::table :label="'Meses de '.$year">
                        <x-slot:head><tr><th>Mes</th><th>Ingresos</th><th>Gastos</th><th>Balance</th></tr></x-slot:head>
                        @foreach ($summary['months'] as $number => $row)
                            <tr wire:key="month-{{ $number }}">
                                <x-ui::td label="Mes" primary>
                                    <x-ui::link wire:click="selectMonth({{ $number }})" :aria-current="$number === $month ? 'true' : null">{{ $this->monthName($number) }}</x-ui::link>
                                </x-ui::td>
                                <x-ui::td label="Ingresos" nowrap>{{ $money->format($row['income']) }}</x-ui::td>
                                <x-ui::td label="Gastos" nowrap>{{ $money->format($row['expense']) }}</x-ui::td>
                                <x-ui::td label="Balance" nowrap>
                                    @if ($row['balance'] < 0)<x-ui::status tone="bad">{{ $money->format($row['balance']) }}</x-ui::status>@else{{ $money->format($row['balance']) }}@endif
                                </x-ui::td>
                            </tr>
                        @endforeach
                    </x-ui::table>
                </details>
            </x-ui::stack>
        </x-ui::panel>

        <x-ui::columns>
            <x-ui::panel>
                <x-ui::stack gap="md">
                    <x-ui::toolbar>
                        <x-ui::segmented label="Cambiar de mes">
                            <x-ui::button variant="ghost" size="sm" icon="chevron-left" label="Mes anterior" wire:click="previousMonth" />
                            <x-ui::button variant="ghost" size="sm" icon="chevron-right" label="Mes siguiente" wire:click="nextMonth" />
                        </x-ui::segmented>
                        <x-ui::heading>{{ $this->monthName($month) }} de {{ $year }}</x-ui::heading>
                    </x-ui::toolbar>
                    <x-ui::metrics :items="[
                        ['label' => 'Ingresos', 'value' => $money->format($totals['income']), 'tone' => 'ok'],
                        ['label' => 'Gastos', 'value' => $money->format($totals['expense']), 'tone' => 'bad'],
                        ['label' => 'Balance', 'value' => $money->format($totals['balance']), 'tone' => $totals['balance'] < 0 ? 'bad' : 'accent'],
                    ]" />
                    @if ($budget !== null)
                        <x-ui::meter :value="$budget > 0 ? $totals['expense'] * 100 / $budget : 100" label="Presupuesto de gastos usado">
                            {{ $totals['expense'] > $budget ? 'Superado en '.$money->format($totals['expense'] - $budget) : 'Quedan '.$money->format($budget - $totals['expense']) }} de {{ $money->format($budget) }}
                        </x-ui::meter>
                    @endif
                    <details @if ($errors->has('limit')) open @endif>
                        <summary class="ui-link">{{ $budget === null ? 'Poner un presupuesto de gastos para este mes' : 'Cambiar el presupuesto del mes' }}</summary>
                        <x-ui::form wire:submit="saveLimit" inline>
                            <x-ui::field name="limit" :label="'Presupuesto de gastos ('.$money->currency.')'" :prefix="$money->symbol()" wire:model="limit" inputmode="decimal" />
                            <x-ui::button type="submit" variant="secondary">Guardar presupuesto</x-ui::button>
                        </x-ui::form>
                        <x-ui::text tone="muted">Déjalo vacío para no usar presupuesto en este mes.</x-ui::text>
                    </details>
                    <x-ui::toolbar>
                        <x-ui::segmented label="Mostrar">
                            @foreach (['all' => 'Todos', 'income' => 'Ingresos', 'expense' => 'Gastos'] as $value => $text)
                                <x-ui::button variant="ghost" size="sm" wire:click="$set('show', '{{ $value }}')" :aria-pressed="$show === $value ? 'true' : 'false'">{{ $text }}</x-ui::button>
                            @endforeach
                        </x-ui::segmented>
                        <x-ui::field name="search" label="Buscar por concepto" type="search" wire:model.live.debounce.300ms="search" />
                    </x-ui::toolbar>
                    <x-ui::table :label="'Movimientos de '.$this->monthName($month)" :paginator="$this->transactions">
                        <x-slot:head><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th data-align="end">Importe</th><th data-align="end"><x-ui::sr>Acciones</x-ui::sr></th></tr></x-slot:head>
                        @forelse ($this->transactions as $transaction)
                            <tr wire:key="tx-{{ $transaction->id }}">
                                <x-ui::td label="Fecha" nowrap>{{ CarbonImmutable::parse($transaction->occurred_on)->format('d/m/Y') }}</x-ui::td>
                                <x-ui::td label="Concepto" primary>
                                    {{ $transaction->description }}
                                    @if ($transaction->receipt_id) <x-ui::link :href="route('files.show', $transaction->receipt_id)">Factura</x-ui::link>@endif
                                </x-ui::td>
                                <x-ui::td label="Categoría">{{ $transaction->categoryLabel() }}</x-ui::td>
                                <x-ui::td label="Importe" align="end" nowrap>
                                    <x-ui::status :tone="$transaction->kind === 'income' ? 'ok' : 'bad'">{{ $transaction->kind === 'income' ? '+' : '−' }}{{ $money->format($transaction->amount_minor, $transaction->currency) }}</x-ui::status>
                                </x-ui::td>
                                <x-ui::td align="end" nowrap>
                                    <x-ui::button variant="ghost" size="sm" wire:click="edit({{ $transaction->id }})" href="#nuevo-movimiento">Editar</x-ui::button>
                                    <x-ui::confirm title="¿Eliminar este movimiento?">
                                        <x-slot:trigger><x-ui::button variant="ghost" size="sm">Eliminar</x-ui::button></x-slot:trigger>
                                        Desaparecerá de los totales. Si tiene factura, seguirá en Mis archivos.
                                        <x-slot:action><x-ui::button variant="danger" wire:click="deleteTransaction({{ $transaction->id }})">Eliminar movimiento</x-ui::button></x-slot:action>
                                    </x-ui::confirm>
                                </x-ui::td>
                            </tr>
                        @empty
                            <x-ui::table-empty colspan="5">{{ trim($search) !== '' || $show !== 'all' ? 'Ningún movimiento coincide con el filtro.' : 'No hay movimientos en este mes.' }}</x-ui::table-empty>
                        @endforelse
                    </x-ui::table>
                </x-ui::stack>
            </x-ui::panel>

            @if ($summary['categories']['expense'] !== [] || $summary['categories']['income'] !== [])
                <x-ui::panel>
                    <x-ui::stack gap="md">
                        <x-ui::heading>Por categoría en {{ $year }}</x-ui::heading>
                        @foreach (['expense' => ['Gastos', 'bad'], 'income' => ['Ingresos', 'ok']] as $group => [$title, $tone])
                            @if ($summary['categories'][$group] !== [])
                                <x-ui::heading :level="3">{{ $title }}</x-ui::heading>
                                <x-ui::breakdown :label="$title.' por categoría'" :tone="$tone" :items="collect($summary['categories'][$group])->map(fn ($total, $key) => [
                                    'label' => Categories::label($group, $key),
                                    'value' => $money->format($total),
                                    'percent' => (int) round($total * 100 / max(1, $summary[$group])),
                                ])->values()->all()" />
                            @endif
                        @endforeach
                    </x-ui::stack>
                </x-ui::panel>
            @endif

            <x-slot:aside>
                <x-ui::panel id="nuevo-movimiento">
                    <x-ui::stack gap="md">
                        <x-ui::heading>{{ $editing ? 'Editar movimiento' : 'Añadir movimiento' }}</x-ui::heading>
                        @unless ($editing)
                            <x-ui::uploader :store-url="route('files.store')" :upload-url="url('uploads')" :folder="$this->receiptsFolder" :max-bytes="(int) config('nas.upload_max_bytes')"
                                :multiple="false" accept="image/jpeg,image/png,image/webp,application/pdf" variant="secondary"
                                label="Subir o fotografiar un ticket" hint="Se guarda en Mis archivos › Facturas y se leen la fecha y el total." />
                        @endunless
                        @if ($receipt)
                            <div @if ($scanning) wire:poll.2s="checkScan" @endif>
                                <x-ui::stack gap="sm">
                                    <x-ui::link :href="route('files.show', $receipt)">Ver la factura guardada</x-ui::link>
                                    <x-ui::button variant="ghost" icon="search" wire:click="startScan" :disabled="$scanning">{{ $scanning ? 'Leyendo la factura…' : 'Volver a leer fecha y total' }}</x-ui::button>
                                    @if ($scanning)<x-ui::text tone="muted" role="status">Puede tardar unos segundos. Puedes seguir rellenando el resto.</x-ui::text>@endif
                                </x-ui::stack>
                            </div>
                        @endif
                        @error('receipt')<x-ui::notice tone="warn">{{ $message }}</x-ui::notice>@enderror
                        <x-ui::form wire:submit="saveTransaction">
                            <x-ui::choice-group name="kind" label="Tipo" :options="Categories::KINDS" wire:model.live="kind" />
                            <x-ui::field name="amount" :label="'Importe ('.$inputMoney->currency.')'" :prefix="$inputMoney->symbol()" inputmode="decimal" wire:model="amount" required
                                :help="'Por ejemplo '.$inputMoney->input(1250).'. Siempre en positivo: el tipo indica si entra o sale.'" />
                            <x-ui::select name="category" label="Categoría" :options="Categories::options($kind)" wire:model="category" :value="$category" />
                            <x-ui::field name="description" label="Concepto" wire:model="description" required maxlength="120" />
                            <x-ui::field name="date" label="Fecha" type="date" wire:model="date" required />
                            <x-ui::actions>
                                <x-ui::button type="submit">{{ $editing ? 'Guardar cambios' : 'Guardar movimiento' }}</x-ui::button>
                                @if ($receipt || $editing)<x-ui::button variant="ghost" wire:click="clearDraft">Cancelar</x-ui::button>@endif
                            </x-ui::actions>
                        </x-ui::form>
                    </x-ui::stack>
                </x-ui::panel>
            </x-slot:aside>
        </x-ui::columns>
    </x-ui::stack>

    <x-ui::dialog name="finance-settings" title="Moneda y formato" description="Los movimientos ya guardados conservan su moneda; nada se convierte.">
        <x-ui::form wire:submit="saveSettings">
            <x-ui::select name="currency" label="Moneda" wire:model.live="currency" :value="$currency"
                :options="collect(MoneyFormat::CURRENCIES)->mapWithKeys(fn ($name, $code) => [$code => MoneyFormat::SYMBOLS[$code].'  '.$code.' · '.$name])->all()" />
            <x-ui::select name="format" label="Formato de las cantidades" wire:model="format" :value="$format"
                :options="collect(MoneyFormat::FORMATS)->mapWithKeys(fn ($locale) => [$locale => (new MoneyFormat(in_array($currency, UserPreferences::OPTIONS['finance_currency'], true) ? $currency : 'EUR', $locale))->example()])->all()" />
            <x-ui::actions>
                <x-ui::button type="submit">Guardar</x-ui::button>
                <x-ui::button variant="ghost" x-on:click="$dispatch('ui-dialog-close', 'finance-settings')">Cancelar</x-ui::button>
            </x-ui::actions>
        </x-ui::form>
    </x-ui::dialog>
</div>
