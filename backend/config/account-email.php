<?php

return [
    'transport' => env('ACCOUNT_EMAIL_TRANSPORT', 'fake'),

    'graph_tenant_id' => env('GRAPH_TENANT_ID'),
    'graph_client_id' => env('GRAPH_CLIENT_ID'),
    'graph_client_secret' => env('GRAPH_CLIENT_SECRET'),
    'graph_mailbox' => env('GRAPH_MAILBOX'),

    /*
     * Optional blind-copy recipient applied to every outbound message. Leave unset
     * in production unless a monitoring copy is deliberately wanted; there is no
     * default, so an unset value sends no BCC.
     */
    'bcc' => env('ACCOUNT_EMAIL_BCC'),
];
