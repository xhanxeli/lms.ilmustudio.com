<?php

return [
    'api_key' => env('BILLPLZ_API_KEY', 'your_default_api_key'),
    'collection_id' => env('BILLPLZ_COLLECTION_ID', 'your_default_collection_id'),
    'callback_url' => env('BILLPLZ_CALLBACK_URL', 'https://lms.ilmustudio.com/payment/billplz/callback'),
];
