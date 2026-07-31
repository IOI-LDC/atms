<?php

namespace App\Support\Jobs;

class OverlapKeys
{
    public const ERP_PART_SYNC = 'erp-part-sync';

    public const PM_EVALUATION = 'pm-evaluation';

    /**
     * Serializes every outbound email. Exchange Online throttles concurrent
     * application access to a single mailbox, so all notifications share this key.
     */
    public const ACCOUNT_EMAIL = 'account-email-mailbox';
}
