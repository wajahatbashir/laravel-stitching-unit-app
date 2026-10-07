<?php

namespace Tests\Unit;

use App\Services\ReceiptOcr;
use PHPUnit\Framework\TestCase;

class ReceiptOcrTest extends TestCase
{
    public function test_parses_bank_app_receipt(): void
    {
        $text = "Transaction Successful\nAmount: Rs. 12,500.00\nTo: Ali Traders Pvt Ltd\nDate 05/10/2026 02:14 PM\nRaast ID 99881";
        $r = ReceiptOcr::parse($text);
        $this->assertSame(12500.0, $r['amount']);
        $this->assertSame('2026-10-05', $r['date']);
        $this->assertSame('Ali Traders Pvt Ltd', $r['payee']);
    }

    public function test_parses_pkr_suffix_and_text_dates(): void
    {
        $r = ReceiptOcr::parse("Paid 4500 PKR\nBeneficiary Name: Zain Lace House\n3 Oct 2026");
        $this->assertSame(4500.0, $r['amount']);
        $this->assertSame('2026-10-03', $r['date']);
        $this->assertSame('Zain Lace House', $r['payee']);
    }

    public function test_returns_nulls_when_nothing_found(): void
    {
        $r = ReceiptOcr::parse('hello world');
        $this->assertNull($r['amount']);
        $this->assertNull($r['date']);
        $this->assertNull($r['payee']);
    }

    /** Raw OCR output of the owner's real receipts (NayaPay light/dark + Faysal bill payment). */
    public static function realReceipts(): array
    {
        return [
            'nayapay dark, to own account' => ["Sara Khan\nsarakhan @nayapay\nRs. 1,000\n\n04 Oct 2026, 10:14 PM\nAmount Sent Rs. 1,000\nService Fee (Incl. Tax) Rs. O\nTotal Amount Rs. 1,000\nTransaction ID 0000000000000000abcd... (0\nADDITIONAL INFORMATION A\nSource Acc. Title Ali Raza\nDestination Acc. Title Sara Khan\n\nSent with # NayaPay", 1000.0, '2026-10-04', 'Sara Khan', null],
            'nayapay light, meezan' => ["BES\nBilal Ahmed\nMeezan-0380\nRs. 100\n06 Oct 2026, 03:31 PM\nAmount Sent Rs. 100\nService Fee (Incl. Tax) Rs. O\nTotal Amount Rs. 100\nTransaction ID 00000000000000000abcd... (0\nADDITIONAL INFORMATION A\nDestination Acc. Title Bilal Ahmed\nDestination Bank Meezan Bank\nDestination Acc. Number e00e0380\nSource Acc. Title Ali Raza\nChannel Raast", 100.0, '2026-10-06', 'Bilal Ahmed', null],
            'nayapay dark, jazzcash' => ["Imran Malik\nJazzCash/Mobilink MFB-1899\nRs. 300\n06 Oct 2026, 09:05 PM\nAmount Sent Rs. 300\nTotal Amount Rs. 300\nDestination Acc. Title Imran Malik\nDestination Bank JazzCash - Mobilink\n\nMicrofinance Bank\nRaast ID @00e1899\nSource Acc. Title Ali Raza", 300.0, '2026-10-06', 'Imran Malik', null],
            'faysal bank utility bill' => ["Transaction Status\nPKR 16,054.00\nFrom To\nALI RAZA -\nUtility\nQOAAAAAAAAARAK GD\n00000000000000\nCurrent Account\nLESCO\nTransaction ID: 817917\nPurpose of Payment: Electricity\nDate: 06/10/2026 | Time: 21:24:32", 16054.0, '2026-10-06', 'LESCO', 'Electricity'],
            'nayapay light, easypaisa' => ["Hamza Iqbal\neasypaisa Bank-0756\nRs. 1,000\n\n06 Oct 2026, 05:48 PM\nAmount Sent Rs. 1,000\nTotal Amount Rs. 1,000\nDestination Acc. Title Hamza Iqbal\nDestination Bank easypaisa Bank\nSource Acc. Title Ali Raza", 1000.0, '2026-10-06', 'Hamza Iqbal', null],
            // payee line garbled in the body → falls back to the header name above the account line
            'nayapay header fallback' => ["Bilal Ahmed\nMeezan-0380\nRs. 250\n06 Oct 2026, 03:31 PM\nAmount Sent Rs. 250\nDestination Acc. Title 0 ee\nSource Acc. Title Ali Raza", 250.0, '2026-10-06', 'Bilal Ahmed', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realReceipts')]
    public function test_reads_the_owners_real_receipt_layouts(string $text, float $amount, string $date, string $payee, ?string $title): void
    {
        $r = ReceiptOcr::parse($text);
        $this->assertSame($amount, $r['amount']);
        $this->assertSame($date, $r['date']);
        $this->assertSame($payee, $r['payee']);
        $this->assertSame($title, $r['title']);
    }
}
