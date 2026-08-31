<?php

namespace App\Console\Commands;

use App\Services\Invoices\CreditNote;
use App\Services\Invoices\CreditNotePdf;
use App\Support\Identity;
use Illuminate\Console\Command;

/**
 * Writes one ayeT-shaped credit note so the layout can be checked without
 * a paid withdrawal. Local only.
 */
class SampleInvoiceCommand extends Command
{
    protected $signature = 'invoice:sample
        {--out= : Destination path. Defaults to the Desktop.}';

    protected $description = 'Generate a sample publisher credit-note PDF';

    public function handle(CreditNotePdf $pdf): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('invoice:sample is local only.');

            return self::FAILURE;
        }

        $note = new CreditNote(
            number: 'CN-12435',
            publisherId: '15969',
            issueDate: '2026-08-20',
            dueDate: '2026-09-19',
            periodStart: '2026-03-01',
            periodEnd: '2026-03-31',
            billingEmail: 'admin@rewardedcoin.us',
            payeeName: 'Mark Adal',
            payeeAddress: 'Alexandria',
            payeeCityLine: '21515',
            payeeCountry: 'Egypt',
            payeeVat: '',
            lines: [
                ['description' => 'Publisher Earnings 10-2025 (#16890 Rewardedcoin)', 'amount' => '7.95'],
                ['description' => 'Publisher Earnings 10-2025 (#16897 Rewardedcoin)', 'amount' => '10.10'],
                ['description' => 'Publisher Earnings 11-2025 (#16897 Rewardedcoin)', 'amount' => '0.21'],
                ['description' => 'Publisher Earnings 11-2025 (#16890 Rewardedcoin)', 'amount' => '5.40'],
                ['description' => 'Publisher Earnings 12-2025 (#16890 Rewardedcoin)', 'amount' => '0.47'],
                ['description' => 'Publisher Earnings 03-2026 (#16890 Rewardedcoin)', 'amount' => '3.60'],
                ['description' => 'Publisher Earnings 03-2026 (#16897 Rewardedcoin)', 'amount' => '5.07'],
            ],
            subtotal: '32.80',
            vatRate: '0.00',
            vatAmount: '0.00',
            total: '32.80',
            paymentInstruction: 'PayPal: rewardedcoin@gmail.com',
            issuer: Identity::invoiceIssuer(),
        );

        $path = $this->option('out') ?: ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: storage_path('app')).DIRECTORY_SEPARATOR.$note->filename();

        file_put_contents($path, $pdf->render($note));

        $this->info($path);

        return self::SUCCESS;
    }
}
