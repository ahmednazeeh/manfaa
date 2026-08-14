<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Database\Seeders\SandboxSeeder;
use Illuminate\Console\Command;

/**
 * `manfaa:sandbox` — seed the §9.5 sandbox fixtures and print the vendor
 * quickstart: the plaintext bearer token, the published customer codes,
 * and ready-to-paste curl lines. Safe to re-run (the seeder is idempotent
 * and re-anchors the scheduled rate decrease); refuses in production,
 * where the published token and codes would mint real cashback.
 */
class SandboxCommand extends Command
{
    protected $signature = 'manfaa:sandbox';

    protected $description = 'Seed the §9.5 sandbox fixtures (Sandbox POS, sandbox-store, test customers, vendor credential) and print the integration quickstart';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('manfaa:sandbox refuses to run in production — the sandbox token and customer codes are public knowledge.');

            return self::FAILURE;
        }

        $seeder = new SandboxSeeder;
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->run();

        $token = SandboxSeeder::plainTextToken();

        if ($token === null) {
            $this->error('Sandbox credential missing after seeding — inspect the api_credentials table.');

            return self::FAILURE;
        }

        $merchant = Merchant::query()->where('slug', SandboxSeeder::MERCHANT_SLUG)->sole();
        $branch = $merchant->branches()->orderBy('id')->first();
        $base = rtrim((string) config('app.url'), '/').'/api/v1';

        $this->info('Sandbox fixtures ready.');
        $this->newLine();

        $this->line('  Merchant : '.$merchant->name.' (slug '.$merchant->slug.', min eligible 5000 laari, validation window '.$merchant->validation_window_days.'d)');
        $this->line('  Branch   : #'.$branch?->getKey().' '.$branch?->name);
        $this->line('  Rate     : '.SandboxSeeder::RATE_BP.' bp now, scheduled decrease to '.SandboxSeeder::PENDING_RATE_BP.' bp at next 00:00 UTC+5 (visible as pending_decrease)');
        $this->newLine();

        $this->line('  Customers:');

        foreach (SandboxSeeder::CUSTOMERS as $customer) {
            $this->line(sprintf(
                '    %s  %-15s %s%s',
                $customer['customer_code'],
                $customer['name'],
                $customer['phone'],
                $customer['status'] === 'active' ? '' : '  ('.$customer['status'].' — lookup answers valid: false)',
            ));
        }

        $this->newLine();
        $this->line('  Bearer token (all abilities — sandbox only, shown on every run):');
        $this->newLine();
        $this->line('    '.$token);
        $this->newLine();

        $this->line('  Quickstart:');
        $this->newLine();
        $this->line('    # Current rate for the till display');
        $this->line('    curl -s '.$base.'/merchants/me/rate \\');
        $this->line('      -H "Authorization: Bearer '.$token.'"');
        $this->newLine();
        $this->line('    # Confirm a customer code before crediting');
        $this->line('    curl -s "'.$base.'/customers/lookup?ref=111111" \\');
        $this->line('      -H "Authorization: Bearer '.$token.'"');
        $this->newLine();
        $this->line('    # Record a sale (fresh UUID per sale — reuse it only to retry the SAME sale)');
        $this->line('    curl -s -X POST '.$base.'/transactions \\');
        $this->line('      -H "Authorization: Bearer '.$token.'" \\');
        $this->line('      -H "Idempotency-Key: $(uuidgen)" \\');
        $this->line('      -H "Content-Type: application/json" \\');
        $this->line('      -d \'{"invoice_no":"INV-1001","customer_ref":"111111","eligible_amount":118000,"sale_amount":125000,"occurred_at":"\'$(date +%Y-%m-%dT%H:%M:%S%z | sed "s/\\(..\\)$/:\\1/")\'"}\'');
        $this->newLine();
        $this->line('    # Reverse it (id from the response above)');
        $this->line('    curl -s -X POST '.$base.'/transactions/{id}/reverse \\');
        $this->line('      -H "Authorization: Bearer '.$token.'" \\');
        $this->line('      -H "Idempotency-Key: $(uuidgen)" \\');
        $this->line('      -H "Content-Type: application/json" \\');
        $this->line('      -d \'{"reason":"customer_refund","occurred_at":"\'$(date +%Y-%m-%dT%H:%M:%S%z | sed "s/\\(..\\)$/:\\1/")\'"}\'');
        $this->newLine();
        $this->line('  Full walkthrough: docs/integration-guide.md');

        return self::SUCCESS;
    }
}
