<?php

return [
    'provider' => env('LDC_ERP_PROVIDER', 'business_central'),

    'oauth' => [
        'token_url' => sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', env('LDC_ERP_TENANT_ID')),
        'client_id' => env('LDC_ERP_CLIENT_ID'),
        'client_secret' => env('LDC_ERP_CLIENT_SECRET'),
        'scope' => 'https://api.businesscentral.dynamics.com/.default',
    ],

    'api' => [
        'base_url' => sprintf(
            'https://api.businesscentral.dynamics.com/v2.0/%s/%s/ODataV4/Company(\'%s\')',
            env('LDC_ERP_TENANT_ID'),
            env('LDC_ERP_ENVIRONMENT'),
            env('LDC_ERP_COMPANY')
        ),
        'parts_endpoint' => env('LDC_ERP_PARTS_API'),
        'timeout' => 30,
    ],
];
