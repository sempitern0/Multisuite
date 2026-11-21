<?php

use App\Livewire\Concerns\NotifiesInterface;
use App\Livewire\Concerns\ThrottlesActions;
use App\Services\Files\PersonalFiles;
use App\Support\ReceiptReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Mis gastos')] class extends Component {
    use NotifiesInterface, ThrottlesActions, WithPagination;

    public const CATEGORIES = ['Alimentación', 'Vivienda', 'Transporte', 'Salud', 'Ocio', 'Otros'];
    private const MONEY = 'regex:/^[0-9]{1,8}(?:[,.][0-9]{1,2})?$/D';

    public string $month = '';
    public string $limit = '';
    public string $description = '';
    public string $amount = '';
    public string $date = '';
    public string $category = 'Otros';
    #[Locked]
    public ?int $receipt = null;
    #[Locked]
    public ?int $editing = null;
    #[Locked]
    public string $entryKey = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
        $this->clearDraft();
        $this->loadLimit();
        if ($id = request()->integer('receipt')) {
            $file = app(PersonalFiles::class)->file(Auth::user(), $id);
            abort_unless($file->isCurrent() && in_array($file->upload()->value('mime'), ReceiptReader::TYPES, true), 404);
            $existing = $this->expenses()->where('receipt_id', $id)->first();
            if ($existing) {
                $this->edit((int) $existing->id);
            } else {
                $this->receipt = $id;
                $this->description = Str::limit(pathinfo($file->name, PATHINFO_FILENAME), 120, '');
            }
        }
    }

    private function expenses(): Builder
    {
        return DB::table('personal_expenses')->where('user_id', Auth::id());
    }

    private function monthlyLimit(): Builder
    {
        return DB::table('monthly_budgets')->where('user_id', Auth::id())->where('month', $this->month.'-01');
    }

    private function loadLimit(): void
    {
        $cents = $this->monthlyLimit()->value('limit_cents');
        $this->limit = $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }

    public function updatedMonth(): void
    {
        $this->validate(['month' => 'required|date_format:Y-m']);
        $this->loadLimit();
        $this->resetPage();
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $value)), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function saveLimit(): void
    {
        $this->validate(['month' => 'required|date_format:Y-m', 'limit' => ['required', self::MONEY]]);
        DB::table('monthly_budgets')->upsert([
            'user_id' => Auth::id(), 'month' => $this->month.'-01', 'limit_cents' => $this->cents($this->limit),
        ], ['user_id', 'month'], ['limit_cents']);
        $this->toast('Presupuesto guardado');
    }

    public function clearDraft(): void
    {
        $this->reset('description', 'amount', 'category', 'receipt', 'editing');
        $this->resetValidation();
        $this->date = now()->format('Y-m-d');
        $this->entryKey = (string) Str::uuid();
    }

    public function edit(int $id): void
    {
        $row = $this->expenses()->where('id', $id)->firstOrFail();
        $this->clearDraft();
        $this->editing = $id;
        $this->receipt = $row->receipt_id;
        $this->date = $row->spent_on;
        $this->description = $row->description;
        $this->category = $row->category;
        $this->amount = number_format($row->amount_cents / 100, 2, ',', '');
    }

    public function readReceipt(PersonalFiles $files, ReceiptReader $reader): void
    {
        $this->throttle('receipt-ocr');
        $file = $files->file(Auth::user(), $this->receipt ?? 0);
        abort_unless($file->isCurrent(), 404);
        try {
            $source = $files->readable(Auth::user(), $file);
            $fields = $reader->read($source['blob'], $source['mime']);
        } catch (RuntimeException) {
            $this->addError('receipt', 'No se pudo leer. Revisa que sea JPG, PNG o PDF de hasta 12 MiB, o introduce los datos a mano.');

            return;
        }
        $this->resetValidation('receipt');
        if ($fields['date'] !== '') {
            $this->date = $fields['date'];
        }
        if ($fields['amount'] !== '') {
            $this->amount = $fields['amount'];
        }
        $this->toast($fields['amount'] === '' ? 'Comprueba la fecha e introduce el total de la factura' : 'Revisa los datos antes de guardar');
    }

    public function saveExpense(): void
    {
        $this->validate([
            'description' => 'required|string|max:120', 'date' => 'required|date_format:Y-m-d',
            'amount' => ['required', self::MONEY], 'category' => Rule::in(self::CATEGORIES),
        ]);
        if ($this->cents($this->amount) === 0) {
            $this->addError('amount', 'Introduce un importe mayor que cero.');

            return;
        }
        $data = ['spent_on' => $this->date, 'description' => trim($this->description),
            'amount_cents' => $this->cents($this->amount), 'category' => $this->category];
        $saved = DB::transaction(function () use ($data): bool {
            if ($this->editing !== null) {
                $row = $this->expenses()->where('id', $this->editing);
                (clone $row)->lockForUpdate()->firstOrFail();
                $row->update($data);

                return true;
            }
            if ($this->receipt !== null) {
                $file = \App\Models\UserFile::where('user_id', Auth::id())->lockForUpdate()->findOrFail($this->receipt);
                abort_unless($file->isCurrent(), 404);
            }

            return DB::table('personal_expenses')->insertOrIgnore($data + [
                'user_id' => Auth::id(), 'receipt_id' => $this->receipt, 'entry_key' => $this->entryKey,
            ]) === 1;
        });
        $this->month = substr($this->date, 0, 7);
        $this->loadLimit();
        $this->clearDraft();
        $this->resetPage();
        $this->toast($saved ? 'Gasto guardado' : 'Este gasto ya estaba registrado');
    }

    public function deleteExpense(int $id): void
    {
        $this->expenses()->where('id', $id)->delete();
        if ($this->editing === $id) {
            $this->clearDraft();
        }
        $this->resetPage();
        $this->toast('Gasto eliminado');
    }

    #[\Livewire\Attributes\Computed]
    public function summary(): array
    {
        // Invalid month input must never reach a DATE comparison in PostgreSQL.
        $month = preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $this->month) && ! str_starts_with($this->month, '0000') ? $this->month : now()->format('Y-m');
        $start = \Carbon\CarbonImmutable::parse($month.'-01');
        $query = $this->expenses()->whereBetween('spent_on', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
        $spent = (int) (clone $query)->sum('amount_cents');
        $budget = DB::table('monthly_budgets')->where('user_id', Auth::id())->where('month', $start->toDateString())->value('limit_cents');

        $categories = (clone $query)->selectRaw('category, sum(amount_cents) as total')->groupBy('category')->orderByDesc('total')->get();

        return ['categories' => $categories, 'spent' => $spent, 'budget' => $budget, 'expenses' => $query->orderByDesc('spent_on')->orderByDesc('id')->paginate(15)];
    }
}; ?>

<div>
    @php(['expenses' => $expenses, 'budget' => $budget, 'spent' => $spent, 'categories' => $categories] = $this->summary)
    @php($euros = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €')
    <x-ui::page-header title="Mis gastos" description="Tu presupuesto y tus facturas personales." />
    <x-ui::stack gap="lg">
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::heading>Presupuesto mensual</x-ui::heading>
                <x-ui::field name="month" label="Mes" type="month" wire:model.change="month" required />
                <x-ui::text><strong>{{ $euros($spent) }}</strong> gastados @if ($budget !== null) · {{ $euros(abs($budget - $spent)) }} {{ $spent > $budget ? 'por encima del presupuesto' : 'disponibles' }} @endif</x-ui::text>
                @if ($categories->isNotEmpty())
                    <details><summary>Gastos por categoría</summary>
                        <dl>@foreach ($categories as $group)<dt>{{ $group->category }}</dt><dd>{{ $euros($group->total) }}</dd>@endforeach</dl>
                    </details>
                @endif
                <x-ui::form wire:submit="saveLimit" inline>
                    <x-ui::field name="limit" label="Presupuesto del mes (€)" wire:model="limit" inputmode="decimal" required />
                    <x-ui::button type="submit" variant="secondary">Guardar presupuesto</x-ui::button>
                </x-ui::form>
            </x-ui::stack>
        </x-ui::panel>
        <x-ui::panel>
            <x-ui::stack gap="md">
                <x-ui::heading>{{ $editing ? 'Editar gasto' : 'Añadir gasto' }}</x-ui::heading>
                @if ($receipt)
                    <x-ui::link :href="route('files.show', $receipt)">Ver factura guardada</x-ui::link>
                    <x-ui::button variant="secondary" wire:click="readReceipt" wire:loading.attr="disabled" wire:target="readReceipt">Leer fecha y total de la factura</x-ui::button>
                    <span wire:loading wire:target="readReceipt" role="status">Leyendo la factura…</span>
                    @error('receipt')<x-ui::notice tone="warn">{{ $message }}</x-ui::notice>@enderror
                @elseif (! $editing)
                    <x-ui::text>Para escanear una factura, sube la foto o el PDF a <x-ui::link :href="route('files.index')">Mis archivos</x-ui::link>, ábrelo y elige «Registrar como gasto».</x-ui::text>
                @endif
                <x-ui::form wire:submit="saveExpense">
                    <x-ui::field name="description" label="Comercio o concepto" wire:model="description" required maxlength="120" />
                    <x-ui::field name="date" label="Fecha del gasto" type="date" wire:model="date" required />
                    <x-ui::field name="amount" label="Total (€)" inputmode="decimal" wire:model="amount" required />
                    <x-ui::select name="category" label="Categoría" wire:model="category" :options="array_combine($this::CATEGORIES, $this::CATEGORIES)" />
                    <x-ui::button type="submit">Guardar gasto</x-ui::button>
                    @if ($receipt || $editing)<x-ui::button variant="ghost" wire:click="clearDraft">Cancelar</x-ui::button>@endif
                </x-ui::form>
            </x-ui::stack>
        </x-ui::panel>
        <x-ui::table label="Movimientos del mes">
            <x-slot:head><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th>Total</th><th>Acciones</th></tr></x-slot:head>
            @forelse ($expenses as $expense)
                <tr wire:key="expense-{{ $expense->id }}">
                    <x-ui::td label="Fecha">{{ \Carbon\CarbonImmutable::parse($expense->spent_on)->format('d/m/Y') }}</x-ui::td>
                    <x-ui::td label="Concepto">{{ $expense->description }} @if ($expense->receipt_id)<x-ui::link :href="route('files.show', $expense->receipt_id)">Factura</x-ui::link>@endif</x-ui::td>
                    <x-ui::td label="Categoría">{{ $expense->category }}</x-ui::td>
                    <x-ui::td label="Total">{{ $euros($expense->amount_cents) }}</x-ui::td>
                    <x-ui::td label="Acciones">
                        <x-ui::button variant="ghost" wire:click="edit({{ $expense->id }})">Editar</x-ui::button>
                        <x-ui::confirm title="¿Eliminar este gasto?">
                            <x-slot:trigger><x-ui::button variant="ghost">Eliminar</x-ui::button></x-slot:trigger>
                            Se descontará del total del mes. La factura seguirá en Mis archivos.
                            <x-slot:action><x-ui::button variant="danger" wire:click="deleteExpense({{ $expense->id }})">Eliminar gasto</x-ui::button></x-slot:action>
                        </x-ui::confirm>
                    </x-ui::td>
                </tr>
            @empty
                <x-ui::table-empty colspan="5">Todavía no tienes gastos en este mes.</x-ui::table-empty>
            @endforelse
        </x-ui::table>
        <x-ui::pagination :paginator="$expenses" />
    </x-ui::stack>
</div>
