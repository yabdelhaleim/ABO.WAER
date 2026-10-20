<?php

/*
| Single source of truth for the list of Egyptian governorates.
|
| Used by:
|   - resources/views/checkout.blade.php            (the <select> options)
|   - app/Http/Requests/CheckoutRequest.php         (the in: rule)
|   - tests/Feature/CheckoutTest.php                (boundary tests)
|
| Order: alphabetical, English transliteration. The spec says
| "the real list of Egyptian governorates" — there are 27.
|
*/

return [
    'Alexandria',
    'Aswan',
    'Asyut',
    'Beheira',
    'Beni Suef',
    'Cairo',
    'Dakahlia',
    'Damietta',
    'Fayoum',
    'Gharbia',
    'Giza',
    'Ismailia',
    'Kafr El Sheikh',
    'Luxor',
    'Matruh',
    'Minya',
    'Monufia',
    'New Valley',
    'North Sinai',
    'Port Said',
    'Qalyubia',
    'Qena',
    'Red Sea',
    'Sharqia',
    'Sohag',
    'South Sinai',
    'Suez',
];
