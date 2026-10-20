<?php

namespace App\Services;

class CustomerService
{
    public function normalizephone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, 0020)) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, 20) && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);

        }

        return $digits ?: null;
    }
}
