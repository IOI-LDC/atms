# Notifications

## Architecture

ATMS does **not** send emails directly. All email delivery is delegated to the
company's official notification platform — **Microsoft Power Automate**.

```
ATMS (Laravel)                    Power Automate              Recipient
  ┌──────────┐                   ┌──────────────┐            ┌──────────┐
  │ Event    │ ── queued job ──► │ HTTP trigger  │ ── email ──►│ Inbox    │
  │ fires    │    (POST JSON)    │ flow          │            └──────────┘
  └──────────┘                   └──────────────┘
```

- **ATMS owns:** When to notify, whom to notify, and what data to include.
- **Power Automate owns:** Email composition, template rendering, approved sender,
  delivery, and compliance.
- **Transport:** HTTP POST (webhook) from a Laravel queued job to the Power
  Automate flow endpoint. No DB polling by Power Automate — push-based,
  instant.

## Power Automate Flow

A single HTTP-triggered flow receives a JSON payload and routes to the correct
email template based on the `type` field.

### Endpoint

The flow URL is stored in `config/services.php`:

```php
'power_automate' => [
    'webhook_url' => env('POWER_AUTOMATE_WEBHOOK_URL'),
],
```

### Payload Contract

Every notification POSTs the same envelope:

```json
{
  "type": "mr_created | wo_assigned | sm_order_submitted | sm_order_approved | sm_order_rejected",
  "recipient_email": "user@ldc.com",
  "recipient_name": "Ahmed Al-Sayed",
  "data": {
    // type-specific fields (see below)
  }
}
```

### Flow Logic (built in Power Automate)

1. Receive HTTP request.
2. Parse JSON.
3. Switch on `type`.
4. Render the matching email template.
5. Send via the company-approved Outlook 365 connector.

---

## Notification Triggers

### Phase 1 — Current

#### 1. MR Created

**When:** A Requester submits a new Maintenance Request.

**Who:** All active Maintenance Managers.

**Payload:**

```json
{
  "type": "mr_created",
  "recipient_email": "manager@ldc.com",
  "recipient_name": "Maintenance Manager",
  "data": {
    "mr_number": "MR-0042",
    "asset_name": "Conveyor Belt Motor X3",
    "asset_tag": "L-ELE-048-1234",
    "requester_name": "Ahmed Al-Sayed",
    "priority": "high",
    "description": "Motor overheating, needs inspection",
    "mr_url": "https://atms.ldc.com/maintenance-requests/42"
  }
}
```

**Laravel event:** `MaintenanceRequestCreated`  
**Queued job:** `SendNotificationToPowerAutomate`

---

#### 2. WO Assigned / Reassigned

**When:** Admin or Maintenance Manager assigns (or reassigns) a Work Order to a
Technician or Manager.

**Who:** The assignee (Technician or Maintenance Manager).

**Payload:**

```json
{
  "type": "wo_assigned",
  "recipient_email": "technician@ldc.com",
  "recipient_name": "Hassan Ibrahim",
  "data": {
    "wo_number": "WO-0193",
    "asset_name": "Conveyor Belt Motor X3",
    "asset_tag": "L-ELE-048-1234",
    "assigned_by": "Maintenance Manager",
    "priority": "high",
    "wo_url": "https://atms.ldc.com/work-orders/193"
  }
}
```

**Laravel event:** `WorkOrderAssigned`  
**Queued job:** `SendNotificationToPowerAutomate`

---

### Phase 2 — Store Management (future)

#### 3. SM Order Submitted

**When:** Requester submits a new Store Order for approval.

**Who:** All active Maintenance Managers.

```json
{
  "type": "sm_order_submitted",
  "recipient_email": "manager@ldc.com",
  "recipient_name": "Maintenance Manager",
  "data": {
    "order_number": "SO-0017",
    "requester_name": "Ahmed Al-Sayed",
    "line_items_count": 3,
    "order_url": "https://atms.ldc.com/store-orders/17"
  }
}
```

---

#### 4. SM Order Approved

**When:** Manager approves a Store Order.

**Who:** The Requester who submitted it.

```json
{
  "type": "sm_order_approved",
  "recipient_email": "requester@ldc.com",
  "recipient_name": "Ahmed Al-Sayed",
  "data": {
    "order_number": "SO-0017",
    "approved_by": "Maintenance Manager",
    "order_url": "https://atms.ldc.com/store-orders/17"
  }
}
```

---

#### 5. SM Order Rejected

**When:** Manager rejects a Store Order.

**Who:** The Requester who submitted it.

```json
{
  "type": "sm_order_rejected",
  "recipient_email": "requester@ldc.com",
  "recipient_name": "Ahmed Al-Sayed",
  "data": {
    "order_number": "SO-0017",
    "rejected_by": "Maintenance Manager",
    "rejection_reason": "Part X is out of stock — reorder next week",
    "order_url": "https://atms.ldc.com/store-orders/17"
  }
}
```

---

## Laravel Implementation Notes

### Queued Job

```php
// app/Jobs/SendNotificationToPowerAutomate.php
class SendNotificationToPowerAutomate implements ShouldQueue
{
    public function __construct(
        public string $type,
        public string $recipientEmail,
        public string $recipientName,
        public array $data,
    ) {}

    public function handle(): void
    {
        Http::timeout(15)
            ->retry(3, 1000)
            ->post(config('services.power_automate.webhook_url'), [
                'type' => $this->type,
                'recipient_email' => $this->recipientEmail,
                'recipient_name' => $this->recipientName,
                'data' => $this->data,
            ]);
    }
}
```

### Event Listener Example

```php
// app/Listeners/SendWorkOrderAssignedNotification.php
class SendWorkOrderAssignedNotification
{
    public function handle(WorkOrderAssigned $event): void
    {
        SendNotificationToPowerAutomate::dispatch(
            type: 'wo_assigned',
            recipientEmail: $event->workOrder->assignee->email,
            recipientName: $event->workOrder->assignee->name,
            data: [
                'wo_number' => $event->workOrder->number,
                'asset_name' => $event->workOrder->asset->name,
                'asset_tag' => $event->workOrder->asset->asset_tag,
                'assigned_by' => $event->assignedBy->name,
                'priority' => $event->workOrder->priority,
                'wo_url' => route('work-orders.show', $event->workOrder),
            ],
        );
    }
}
```

### Retry & Failure

- The queued job retries up to 3 times (1s backoff).
- On final failure, the job lands in the `failed_jobs` table.
- Administrators can retry failed notifications from the Laravel Horizon /
  Telescope UI or via `php artisan queue:retry`.

---

## Power Automate Setup Checklist

- [ ] Create HTTP-triggered flow.
- [ ] Register the webhook URL in the ATMS `.env` (`POWER_AUTOMATE_WEBHOOK_URL`).
- [ ] Build the `type` switch and email templates for each notification.
- [ ] Test: POST sample payloads from the ATMS queue worker → verify email arrives.
