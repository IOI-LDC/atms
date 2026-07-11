<?php

return [
    'transport' => env('ACCOUNT_EMAIL_TRANSPORT', 'fake'),

    'graph_tenant_id' => env('GRAPH_TENANT_ID'),
    'graph_client_id' => env('GRAPH_CLIENT_ID'),
    'graph_client_secret' => env('GRAPH_CLIENT_SECRET'),
    'graph_mailbox' => env('GRAPH_MAILBOX'),
];
