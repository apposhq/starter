<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute, counted per API key rather than per IP, so a customer's
    | budget is theirs no matter how many machines they call from. Test keys get a
    | smaller share because they exist for development, not traffic.
    |
    | Only authenticated requests are metered — anything without a usable key is
    | answered 401 by the guard, which runs first.
    |
    */

    'rate_limits' => [
        'live' => (int) env('API_RATE_LIMIT_LIVE', 1000),
        'test' => (int) env('API_RATE_LIMIT_TEST', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | The default page size, and the ceiling a caller can ask for. The cap is what
    | stops one request from selecting an entire table.
    |
    */

    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Delivery Retention
    |--------------------------------------------------------------------------
    |
    | Days of delivery history to keep. The rows exist to answer "why did this
    | stop arriving?", which is a question about the recent past — kept forever
    | they are just a table that grows with traffic and slows the page that
    | reads it.
    |
    */

    'webhook_retention_days' => (int) env('API_WEBHOOK_RETENTION_DAYS', 30),

];
