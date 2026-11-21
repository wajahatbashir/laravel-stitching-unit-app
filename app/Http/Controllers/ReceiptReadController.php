<?php

namespace App\Http\Controllers;

use App\Services\ReceiptOcr;
use Illuminate\Http\Request;

/** POST /receipts/ocr — reads a payment receipt image for any form that has a receipt box (expenses, worker / customer payments). */
class ReceiptReadController extends Controller
{
    public function __invoke(Request $r)
    {
        $u = $r->user();
        abort_unless($u->can('expenses.create') || $u->can('worker_payments.create') || $u->can('customer_payments.create'), 403);
        $r->validate(['receipt' => 'required|image|max:10240']);

        // read the upload straight from PHP's temp file: nothing is copied or kept by this endpoint
        return response()->json(ReceiptOcr::read($r->file('receipt')->getRealPath()));
    }
}
