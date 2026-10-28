<?php

namespace Tests\Feature;

use App\Support\PlansCatalog;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlansCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_plans_are_a_separate_billing_cycle(): void
    {
        $this->seed(PlansSeeder::class);

        $catalog = PlansCatalog::forModal();
        $marketing = $catalog['marketing'];

        $this->assertSame('mkt_pro', $marketing['keys']['pro']);
        $this->assertSame('mkt_starter', $marketing['keys']['free']);
        $this->assertSame(20, $marketing['prices']['usd']['pro'][0]);
        $this->assertSame(20000, $marketing['prices']['ngn']['pro'][0]);

        $this->assertSame('mkt_pro_annual', $marketing['year']['keys']['pro']);
        $this->assertSame('mkt_starter_annual', $marketing['year']['keys']['free']);
        $this->assertSame('mkt_enterprise_annual', $marketing['year']['keys']['enterprise']);
        $this->assertSame(200, $marketing['year']['prices']['usd']['pro'][0]);
        $this->assertSame(200000, $marketing['year']['prices']['ngn']['pro'][0]);
        $this->assertSame(100, $marketing['year']['prices']['usd']['free'][0]);

        $this->assertSame('tx_pro', $catalog['transactional']['keys']['pro']);
        $this->assertSame('tx_pro_annual', $catalog['transactional']['year']['keys']['pro']);
        $this->assertSame(200, $catalog['transactional']['year']['prices']['usd']['pro'][0]);
    }
}
