<?php

namespace App\Support\Jobs;

class OverlapKeys
{
    public const ERP_PART_SYNC = 'erp-part-sync';

    public const PM_EVALUATION = 'pm-evaluation';

    /**
     * Suffixed per scope (`:rule-7`, `:asset-42`) so two reconciles of the same
     * rule cannot interleave while unrelated ones still run in parallel.
     */
    public const PM_CATEGORY_RECONCILE = 'pm-category-reconcile';

    /**
     * Serializes every outbound email. Exchange Online throttles concurrent
     * application access to a single mailbox, so all notifications share this key.
     */
    public const ACCOUNT_EMAIL = 'account-email-mailbox';
}
