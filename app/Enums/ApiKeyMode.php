<?php

namespace App\Enums;

/**
 * The environment an API key acts in.
 *
 * Customers integrate against test data before they touch real data, so every key names which world it
 * belongs to. The value is also the prefix segment: `sk_test_…` and `sk_live_…`.
 */
enum ApiKeyMode: string
{
    case Live = 'live';
    case Test = 'test';
}
