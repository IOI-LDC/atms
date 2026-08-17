#!/usr/bin/env sh
# Read-only survey of what is actually populated in an ATMS database.
#
# Written for the pre-handover question "the LDC team have entered values in
# production and we do not know which of it needs carrying over". Run it on the
# server before any deploy that touches data, and keep the output.
#
# It writes nothing. Every statement is a COUNT or a GROUP BY.
#
#   ./scripts/survey-data.sh                 # human-readable
#   ./scripts/survey-data.sh > survey.txt    # keep a copy alongside the backup
set -eu

echo "ATMS data survey — $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=================================================================="
echo

docker compose exec -T api php artisan tinker --execute="
\$section = function (string \$title) { echo PHP_EOL.'── '.\$title.' '.str_repeat('─', max(0, 56 - strlen(\$title))).PHP_EOL; };
\$count   = function (string \$label, string \$table) {
    try { printf(\"  %-34s %8d\" . PHP_EOL, \$label, DB::table(\$table)->count()); }
    catch (\Throwable \$e) { printf(\"  %-34s %8s\" . PHP_EOL, \$label, 'n/a'); }
};
\$breakdown = function (string \$table, string \$column) {
    try {
        foreach (DB::table(\$table)->selectRaw(\"\$column, count(*) c\")->groupBy(\$column)->orderBy(\$column)->get() as \$r) {
            \$v = \$r->\$column;
            \$label = \$v === null ? '(null)' : (is_bool(\$v) ? (\$v ? 'true' : 'false') : (string) \$v);
            if (\$label === '') { \$label = 'false'; }
            printf(\"      %-30s %8d\" . PHP_EOL, \$label, \$r->c);
        }
    } catch (\Throwable \$e) { echo '      (unavailable)'.PHP_EOL; }
};

// ── Reference data: the source of truth until the Phase 3 ERP sync exists.
//    This is what must survive every deploy and the handover itself.
\$section('REFERENCE DATA — preserve');
\$count('Assets', 'assets');
\$breakdown('assets', 'operational_status');
\$count('  · with an asset tag', 'assets');
echo '      tagged: '.DB::table('assets')->whereNotNull('asset_tag')->count().PHP_EOL;
\$count('Parts', 'parts');
\$count('Locations', 'locations');
\$breakdown('locations', 'type');
\$count('Maintenance categories', 'maintenance_categories');
\$count('Master data items', 'master_data_items');
\$breakdown('master_data_items', 'group_key');
\$count('Usage reading types', 'usage_reading_types');
\$count('FA subclass type codes', 'fa_subclass_type_codes');

// ── Configuration LDC build by hand. Losing it means re-doing real work.
\$section('CONFIGURATION — LDC-entered, preserve');
\$count('Users', 'users');
\$breakdown('users', 'is_active');
\$count('PM rules', 'pm_rules');
\$count('Asset PM assignments', 'asset_pm_assignments');
\$count('  · manual (not category-derived)', 'asset_pm_assignments');
echo '      manual: '.DB::table('asset_pm_assignments')->where('origin', 'manual')->count().PHP_EOL;
\$count('WO form templates', 'form_templates');
\$count('WO form template fields', 'form_template_fields');

// ── Transactional records. Test data pre-handover; reset at handover.
\$section('TRANSACTIONAL — test data, reset at handover');
\$count('Maintenance requests', 'maintenance_requests');
\$breakdown('maintenance_requests', 'status');
\$count('Work orders', 'work_orders');
\$breakdown('work_orders', 'status');
\$count('Work order parts', 'work_order_parts');
\$count('Asset meter readings', 'asset_meter_readings');
\$count('Bookings', 'bookings');
\$count('Attachments', 'attachments');
\$count('Audit logs', 'audit_logs');

// ── Anything here means a deploy is about to disturb live work.
\$section('IN-FLIGHT WORK — drain or expect disruption');
echo '  Open/in-progress work orders:      '.DB::table('work_orders')->whereIn('status', ['open','in_progress'])->count().PHP_EOL;
echo '  Completed, awaiting close:         '.DB::table('work_orders')->where('status','completed')->count().PHP_EOL;
echo '  Pending maintenance requests:      '.DB::table('maintenance_requests')->where('status','pending_review')->count().PHP_EOL;
echo '  Active bookings:                   '.DB::table('bookings')->where('status','active')->count().PHP_EOL;
echo '  Unconfirmed meter readings:        '.DB::table('asset_meter_readings')->whereNull('confirmed_at')->count().PHP_EOL;
"

echo
echo "=================================================================="
echo "Reference data and configuration are the source of truth until the"
echo "Phase 3 ERP sync exists. Transactional rows are test data today."
