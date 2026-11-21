<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

class PersonalBudgetTest extends TestCase
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

    public function test_receipt_is_private_reviewable_and_charged_only_once(): void
    {
        $this->upload($this->owner, 'ticket.png', file_get_contents(base_path('tests/Fixtures/budgets/ticket.png')))->assertOk();
        $id = $this->entry('ticket.png')->id;
        $page = Livewire::withQueryParams(['receipt' => $id])->test('pages::budgets')
            ->call('readReceipt')->assertHasNoErrors()->assertSet('date', '2026-11-18')->assertSet('amount', '42,70');
        $this->assertDatabaseCount('personal_expenses', 0);
        $page->set('amount', '42,75')->call('saveExpense')->assertHasNoErrors()->assertSee('42,75 €');
        Livewire::withQueryParams(['receipt' => $id])->test('pages::budgets')->call('saveExpense')->assertHasNoErrors();
        $this->assertDatabaseCount('personal_expenses', 1);
        $this->assertDatabaseHas('personal_expenses', ['receipt_id' => $id, 'amount_cents' => 4275]);
        $stranger = User::factory()->create(['role' => 'owner']);
        $this->actingAs($stranger)->get(route('budgets.index', ['receipt' => $id]))->assertNotFound();
        $this->get(route('files.content', ['fileId' => $id]))->assertNotFound();
    }

    public function test_monthly_limit_and_manual_expenses_belong_to_the_signed_in_person(): void
    {
        $this->actingAs($this->owner);
        $page = Livewire::test('pages::budgets')->set('month', '2026-11')->set('limit', '100')->call('saveLimit')
            ->set('description', 'Mercado privado')->set('date', '2026-11-12')->set('amount', '11,99')
            ->call('saveExpense')->assertHasNoErrors()->assertSee('88,01 €');
        $id = DB::table('personal_expenses')->value('id');
        $page->call('edit', $id)->set('amount', '12')->call('saveExpense')->assertSee('88,00 €');
        $page->set('month', '2026-10')->assertDontSee('Mercado privado');
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::budgets')->set('month', '2026-11')->assertDontSee('Mercado privado')->call('deleteExpense', $id);
        $this->assertDatabaseHas('personal_expenses', ['id' => $id, 'amount_cents' => 1200]);
        $this->actingAs($this->owner);
        Livewire::test('pages::budgets')->call('deleteExpense', $id);
        $this->assertDatabaseCount('personal_expenses', 0);
    }
}
