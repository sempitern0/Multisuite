<?php

namespace Tests\Feature;

use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\Finance\FinanceSummary;
use App\Services\Storage\StoredBlob;
use App\Support\Finance\MoneyFormat;
use App\Support\Finance\ReceiptReader;
use App\Support\Finance\ReceiptUnreadable;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

class PersonalFinanceTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    public function test_a_receipt_is_read_in_the_background_reviewed_and_recorded_once(): void
    {
        $this->upload($this->owner, 'ticket.png', (string) file_get_contents(base_path('tests/Fixtures/finances/ticket.png')))->assertOk();
        $id = $this->entry('ticket.png')->id;

        $page = Livewire::withQueryParams(['receipt' => $id])->test('pages::finances')
            ->call('startScan')->assertHasNoErrors()->assertSet('scanning', false)
            ->assertSet('date', '2026-11-18')->assertSet('amount', '42,70');
        $this->assertDatabaseCount('personal_transactions', 0);

        $page->set('category', 'food')->set('amount', '42,75')->call('saveTransaction')->assertHasNoErrors()->assertSee('42,75');
        // Opening the same receipt again edits the movement instead of charging it twice.
        Livewire::withQueryParams(['receipt' => $id])->test('pages::finances')
            ->assertSet('editing', PersonalTransaction::value('id'))->call('saveTransaction')->assertHasNoErrors();
        $this->assertDatabaseCount('personal_transactions', 1);
        $this->assertDatabaseHas('personal_transactions', ['receipt_id' => $id, 'amount_minor' => 4275, 'currency' => 'EUR', 'kind' => 'expense', 'category' => 'food']);

        $stranger = User::factory()->create(['role' => 'owner']);
        $this->actingAs($stranger)->get(route('finances.index', ['receipt' => $id]))->assertNotFound();
        $this->get(route('files.content', ['fileId' => $id]))->assertNotFound();
    }

    public function test_an_unreadable_receipt_says_why_and_the_movement_can_be_typed(): void
    {
        $this->upload($this->owner, 'ticket.png', (string) file_get_contents(base_path('tests/Fixtures/finances/ticket.png')))->assertOk();
        $this->app->instance(ReceiptReader::class, new class extends ReceiptReader
        {
            public function read(StoredBlob $blob, string $mime): array
            {
                throw new ReceiptUnreadable('La imagen es demasiado grande o ilegible. Puedes introducir el movimiento a mano.');
            }
        });

        Livewire::withQueryParams(['receipt' => $this->entry('ticket.png')->id])->test('pages::finances')
            ->call('startScan')->assertHasErrors('receipt')->assertSee('demasiado grande o ilegible')
            ->set('amount', '9,99')->call('saveTransaction')->assertHasNoErrors();
        $this->assertDatabaseHas('personal_transactions', ['amount_minor' => 999]);
        $this->assertSame(['amount' => '1234.56', 'date' => '2026-03-01'], app(ReceiptReader::class)->suggest("Fecha 01/03/2026\nTOTAL 1.234,56 €"));
    }

    public function test_the_year_adds_income_and_expenses_by_month_in_the_chosen_currency(): void
    {
        $this->actingAs($this->owner);
        $page = Livewire::test('pages::finances');
        $this->record($page, 'income', 'salary', '2026-01-31', '1.500,00');
        $this->record($page, 'expense', 'food', '2026-01-15', '12,5');
        $this->record($page, 'expense', 'housing', '2026-02-01', '700');

        $page->set('currency', 'USD')->set('format', 'en_US')->call('saveSettings')->assertHasNoErrors();
        $this->record($page, 'expense', 'leisure', '2026-02-10', '1,234.56');
        $page->set('year', 2026)->call('selectMonth', 2)->assertSee('$1,234.56')->assertSee('€700.00')->assertSee('También tienes movimientos en EUR');

        $summary = app(FinanceSummary::class);
        $eur = $summary->year($this->owner, 2026, 'EUR');
        $this->assertSame([150000, 1250, 148750], [$eur['months'][1]['income'], $eur['months'][1]['expense'], $eur['months'][1]['balance']]);
        $this->assertSame([150000, 71250, 78750], [$eur['income'], $eur['expense'], $eur['balance']]);
        $this->assertSame(['housing' => 70000, 'food' => 1250], $eur['categories']['expense']);
        $usd = $summary->year($this->owner, 2026, 'USD');
        $this->assertSame([123456, ['EUR']], [$usd['expense'], $usd['otherCurrencies']]);

        $yen = new MoneyFormat('JPY', 'de_CH');
        $this->assertSame(1234, $yen->parse('1.234'));
        // The grouping mark of de_CH depends on the ICU version (’ or ').
        $this->assertMatchesRegularExpression("/^CHF.1[’']234\\.56$/u", (new MoneyFormat('CHF', 'de_CH'))->format(123456));
        $this->assertNull((new MoneyFormat('EUR', 'es_ES'))->parse('-5'));
    }

    public function test_movements_and_budgets_belong_to_their_owner_and_bad_input_is_explained(): void
    {
        $this->actingAs($this->owner);
        $page = Livewire::test('pages::finances')->set('year', 2026)->call('selectMonth', 11);
        $this->record($page, 'expense', 'food', '2026-11-12', '11,99');
        $page->set('limit', '100')->call('saveLimit')->assertHasNoErrors()->assertSee('Quedan 88,01');
        $this->assertDatabaseHas('monthly_budgets', ['user_id' => $this->owner->id, 'currency' => 'EUR', 'limit_minor' => 10000]);
        $id = (int) PersonalTransaction::value('id');

        $page->set('description', 'Prueba')->set('amount', '0')->call('saveTransaction')->assertHasErrors('amount');
        $page->set('amount', 'doce')->call('saveTransaction')->assertHasErrors('amount');
        $page->set('kind', 'income')->set('category', 'food')->set('amount', '5')->call('saveTransaction')->assertHasErrors('category');

        $this->actingAs(User::factory()->create());
        Livewire::test('pages::finances')->call('edit', $id)->assertSet('editing', null)
            ->call('deleteTransaction', $id)->assertDispatched('ui-toast', text: 'Ese movimiento ya no existe', tone: 'warn');
        $this->assertDatabaseHas('personal_transactions', ['id' => $id]);

        $this->actingAs($this->owner);
        Livewire::test('pages::finances')->call('deleteTransaction', $id)->assertDispatched('ui-toast', text: 'Movimiento eliminado', tone: 'ok');
        $page->set('limit', '')->call('saveLimit');
        $this->assertDatabaseCount('personal_transactions', 0);
        $this->assertDatabaseCount('monthly_budgets', 0);
        $page->set('year', 1999)->assertHasErrors('year')->assertSet('year', (int) now()->year);
    }

    public function test_the_operator_can_switch_finances_off(): void
    {
        config(['portal.finances.enabled' => false]);
        $this->actingAs($this->owner)->get(route('finances.index'))->assertForbidden();
        $labels = collect(Navigation::for($this->owner)['groups'])->flatMap(fn (array $group) => array_column($group['items'], 'label'));
        $this->assertNotContains('Mis finanzas', $labels);
    }

    private function record(mixed $page, string $kind, string $category, string $date, string $amount): void
    {
        $page->set('kind', $kind)->set('category', $category)->set('description', ucfirst($category))
            ->set('date', $date)->set('amount', $amount)->call('saveTransaction')->assertHasNoErrors();
    }
}
