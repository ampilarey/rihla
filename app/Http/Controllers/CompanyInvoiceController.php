<?php

namespace App\Http\Controllers;

use App\Models\CompanyInvoice;
use App\Services\Companies\CompanyBilling;
use App\Support\CompanyRole;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rihla's invoice to an Umrah company, as a PDF (billing automation). Two
 * kinds of reader: Rihla's staff who may see the company's record, and the
 * company's own Owners — nobody else at the company, since an invoice is
 * the business's account with Rihla. Anybody else gets a 404, which says
 * nothing about whether the invoice exists.
 */
class CompanyInvoiceController extends Controller
{
    public function pdf(Request $request, CompanyInvoice $invoice, CompanyBilling $billing): Response
    {
        $user = $request->user();
        $company = $invoice->company;

        abort_unless($user !== null && $company !== null && (
            $user->can('partner.view') || $user->roleAtCompany($company) === CompanyRole::OWNER
        ), 404);

        $pdf = $billing->pdf($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
