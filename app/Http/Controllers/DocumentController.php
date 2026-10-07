<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Support\Ledger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Shareable PDFs: worker payslip (period ledger), worker payment voucher, customer statement.
 * Nothing is publicly reachable: every PDF needs a login; sharing sends the file itself (share sheet) or a text-only WhatsApp message.
 */
class DocumentController extends Controller
{
    private const KINDS = ['payslip', 'voucher', 'statement'];

    /** Digits-only international number for wa.me (a leading 0 is taken as Pakistan, +92). */
    public static function waNumber(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = '92'.substr($d, 1);
        }

        return $d;
    }

    private function authorizeKind(string $kind, int $id): void
    {
        $u = auth()->user();
        if ($kind === 'statement') {
            abort_unless($u->can('customers.view'), 403);
            Customer::findOrFail($id);

            return;
        }
        $worker = $kind === 'payslip' ? Worker::findOrFail($id) : WorkerPayment::withoutGlobalScopes()->with('worker')->findOrFail($id)->worker;
        $own = $u->can('my.ledger') && $worker->user_id === $u->id;
        abort_unless($own || $u->can($kind === 'payslip' ? 'workers.view' : 'worker_payments.view'), 403);
    }

    private function render(string $kind, int $id, ?string $from, ?string $to)
    {
        if ($kind === 'payslip') {
            $w = Worker::findOrFail($id);
            $pdf = Pdf::loadView('documents.payslip', ['w' => $w, 'from' => $from, 'to' => $to] + Ledger::build($w, $from, $to));
            $name = 'payslip-'.str($w->name)->slug().'-'.($to ?: now()->toDateString());
        } elseif ($kind === 'voucher') {
            $p = WorkerPayment::withoutGlobalScopes()->with('worker')->findOrFail($id);
            $day = $p->date->toDateString();
            $before = round($p->worker->earned(null, $day) - $p->worker->paid(null, $day) + (float) $p->amount, 2); // owed just before this payment
            $pdf = Pdf::loadView('documents.voucher', ['p' => $p, 'before' => $before, 'after' => round($before - (float) $p->amount, 2)]);
            $name = 'voucher-'.$p->id.'-'.str($p->worker->name)->slug();
        } else {
            $c = Customer::findOrFail($id);
            $pdf = Pdf::loadView('documents.statement', ['c' => $c, 'from' => $from, 'to' => $to] + $this->customerStatement($c, $from, $to));
            $name = 'statement-'.str($c->name)->slug().'-'.($to ?: now()->toDateString());
        }

        return [$pdf, $name.'.pdf'];
    }

    /** Invoices and payments (direct and advance) as one running ledger, with brought-forward balance. */
    private function customerStatement(Customer $c, ?string $from, ?string $to): array
    {
        $ev = collect();
        foreach ($c->invoices()->get() as $i) {
            $ev->push([$i->date, __('Invoice').' '.$i->number, (float) $i->total, 0.0]);
        }
        foreach (CustomerPayment::withoutGlobalScopes()->where('customer_id', $c->id)->get() as $p) {
            $ev->push([$p->date, ($p->invoice_id ? __('Payment') : __('Advance payment')).' ('.label($p->mode).')'.($p->reference ? " {$p->reference}" : ''), 0.0, (float) $p->amount]);
        }
        $ev = $ev->sortBy(fn ($e) => $e[0]->timestamp)->values();
        $f = $from ? \Carbon\Carbon::parse($from)->startOfDay() : null;
        $t = $to ? \Carbon\Carbon::parse($to)->endOfDay() : null;
        $opening = $f ? round($ev->filter(fn ($e) => $e[0]->lt($f))->sum(fn ($e) => $e[2] - $e[3]), 2) : 0.0;
        $bal = $opening;
        $lines = [];
        foreach ($ev->filter(fn ($e) => (! $f || $e[0]->gte($f)) && (! $t || $e[0]->lte($t))) as $e) {
            $bal = round($bal + $e[2] - $e[3], 2);
            $lines[] = ['date' => $e[0], 'desc' => $e[1], 'billed' => $e[2], 'received' => $e[3], 'balance' => $bal];
        }

        return ['opening' => $opening, 'lines' => $lines, 'closing' => $bal, 'advance' => $c->advance(), 'receivable' => $c->receivable()];
    }

    public function download(Request $r, string $kind, int $id)
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $this->authorizeKind($kind, $id);
        [$pdf, $name] = $this->render($kind, $id, $r->query('date_from') ?: null, $r->query('date_to') ?: null);

        return $pdf->download($name);
    }

    public function emailStatement(Request $r, int $id)
    {
        abort_unless($r->user()->can('customers.view'), 403);
        $c = Customer::findOrFail($id);
        $data = $r->validate(['to' => 'required|email', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date']);
        [$pdf, $name] = $this->render('statement', $c->id, $data['date_from'] ?? null, $data['date_to'] ?? null);
        $biz = biz('business_name', 'Lumiere Premium');
        $body = __('Dear :n,', ['n' => $c->name])."\n\n".__('Please find attached your account statement from :b.', ['b' => $biz])."\n".__('Outstanding balance: :a', ['a' => money($c->receivable())])."\n\n".__('Thank you,')."\n$biz";

        Mail::raw($body, fn ($m) => $m->to($data['to'])->subject(__('Account statement').' — '.$biz)->attachData($pdf->output(), $name, ['mime_type' => 'application/pdf']));

        return back()->with('success', __('Statement emailed to :e.', ['e' => $data['to']]));
    }
}
