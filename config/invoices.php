<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who is issuing the document
    |--------------------------------------------------------------------------
    |
    | Only things the operator has actually said. The registration number and
    | the city are on the live site's own footer, put there by the owner, so
    | they are known. Everything else that would normally appear on an
    | invoice — a street address, a tax registration, a bank account — is not
    | recorded anywhere, and a plausible-looking invented one on a document a
    | customer keeps is worse than a missing line.
    |
    | The phone number comes from Admin → Settings through App\Support\Contact,
    | so it cannot disagree with the rest of the site.
    |
    */

    'issuer' => [
        'name' => env('INVOICE_ISSUER_NAME', 'Rihla Travels'),
        'registration' => env('INVOICE_ISSUER_REGISTRATION', 'C11452023'),
        'address' => env('INVOICE_ISSUER_ADDRESS', 'Malé, Maldives'),
        'email' => env('INVOICE_ISSUER_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | **Empty, and deliberately so.** Whether an outbound Umrah package is
    | subject to Maldivian GST, at which rate, and whether Rihla is registered
    | for it are questions nobody has answered. Printing a tax line — or a
    | rate — that nobody confirmed puts a false statement on a document a
    | customer may hand to an accountant.
    |
    | While this is empty the document is titled "Invoice" and makes no tax
    | claim at all, neither that tax is included nor that it is not. Set
    | `note` when the operator says what is true, and the line appears.
    |
    */

    'tax' => [
        // Empty string, not null. A view reading a key that resolves to
        // null is rejected by ContactNumberTest, and rightly — that is how
        // `config('app.whatsapp', '1234567890')` ended up on the live site.
        // '' says "this key exists and nobody has filled it in"; null would
        // be indistinguishable from a typo in the key name.
        'note' => (string) env('INVOICE_TAX_NOTE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terms
    |--------------------------------------------------------------------------
    |
    | **Also empty.** The terms and conditions document is one of the things
    | still outstanding from the operator. An invoice that references terms
    | that do not exist is worse than one that references none: it implies a
    | customer agreed to something nobody can produce.
    |
    */

    'terms' => [
        'note' => (string) env('INVOICE_TERMS_NOTE', ''),
    ],

];
