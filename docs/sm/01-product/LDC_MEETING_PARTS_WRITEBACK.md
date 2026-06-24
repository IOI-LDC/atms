# LDC ERP Meeting — Parts Integration & Store GR Write-Back

**Date:** 2026-06-24
**Purpose:** Align with LDC and the ERP consultant on the parts data flow: (1) confirm the parts read endpoint and field mapping, and (2) agree on the mechanism for SM to push quantity updates back to ERP when goods are issued to a requester.

---

## 1. Where We Are Today

We have the LDC ERP connection established and working:

| Capability | Status |
|---|---|
| **Authentication** | ✅ Token endpoint working (`client_id` + `client_secret` → bearer token) |
| **Asset endpoint** | ✅ URL confirmed, field mapping agreed |
| **Parts endpoint** | ❌ **Needed** — URL, auth (same token?), and list of available fields |
| **ERP → SM sync (parts)** | Designed but blocked on parts endpoint details |
| **SM → ERP write-back** | Not yet implemented; this is the purpose of the meeting |

The read-only parts sync (`SyncErpPartsJob`) is designed and partially built —
it will pull parts master data from LDC ERP into SM on a weekly schedule
(manual trigger also available). SM then manages the part catalogue, inventory,
and the Order → Approval → Dispatch → Goods Receipt workflow. ATMS reads parts
from SM for Work Order part-request forms.

---

## 2. Current Flow & Where Write-Back Fits

<div style="page-break-inside: avoid;">

<div style="border: 2px solid #16213e; border-radius: 6px; padding: 10px 20px; text-align: center; background: #eef0f5; width: 340px; margin: 14px auto 0 auto;">
  <strong style="font-size: 13pt; color: #16213e;">LDC ERP</strong><br>
  Parts Master · Token Auth (✅ working)
</div>

<div style="text-align: center; margin: 2px 0; font-size: 12pt; color: #16213e;">▼</div>
<div style="text-align: center; margin: 0 0 4px 0; font-size: 9pt; color: #555;">
  SyncErpPartsJob — weekly or manual trigger<br>
  (parts endpoint URL &amp; fields — needed)
</div>
<div style="text-align: center; margin: 0 0 0 0; font-size: 12pt; color: #16213e;">▼</div>

<div style="border: 2px solid #c41e3a; border-radius: 6px; padding: 12px 20px; text-align: center; background: #fef5f6; width: 480px; margin: 10px auto 0 auto;">
  <strong style="font-size: 14pt; color: #c41e3a;">SM — Store Management</strong><br><br>

  <table style="width: 100%; margin: 0 auto; border: none;">
    <tr>
      <td style="border: 1px solid #ccc; background: #fff; padding: 8px 14px; font-weight: 600; border-radius: 4px;">ORDER</td>
      <td style="border: none; background: none; padding: 4px 6px; font-size: 14pt;">→</td>
      <td style="border: 1px solid #ccc; background: #fff; padding: 8px 14px; font-weight: 600; border-radius: 4px;">APPROVAL</td>
      <td style="border: none; background: none; padding: 4px 6px; font-size: 14pt;">→</td>
      <td style="border: 1px solid #ccc; background: #fff; padding: 8px 14px; font-weight: 600; border-radius: 4px;">DISPATCH</td>
      <td style="border: none; background: none; padding: 4px 6px; font-size: 14pt;">→</td>
      <td style="border: 2px solid #c41e3a; background: #fef5f6; padding: 8px 14px; font-weight: 700; border-radius: 4px;">GOODS<br>RECEIPT</td>
    </tr>
  </table>

  <div style="margin-top: 10px; font-size: 9.5pt; color: #666; border-top: 1px solid #ecc; padding-top: 8px;">
    Item issued to requester, exits store inventory
  </div>
</div>

<div style="text-align: center; margin: 4px 0; font-size: 12pt; color: #c41e3a;">▲</div>
<div style="border: 2px dashed #c41e3a; border-radius: 6px; padding: 10px 16px; text-align: center; background: #fff8f8; width: 440px; margin: 0 auto 0 auto;">
  <strong style="font-size: 12pt; color: #c41e3a;">⬆ WRITE-BACK (proposed)</strong><br>
  <span style="font-size: 9.5pt; color: #555;">
  At GR confirmation, SM pushes quantity update back to LDC ERP<br>
  (via a Store Order workflow or equivalent ERP transaction)
  </span>
</div>

</div>

### Key points

| Aspect | Detail |
|---|---|
| **Read direction** | ERP → SM. Parts master data synced on schedule. |
| **Write-back direction** | SM → ERP (proposed). Triggered at GR — when item is issued to requester and leaves store. |
| **Current status** | Token auth ✅ · Asset endpoint ✅ · Parts endpoint ❌ (blocking) |
| **Write-back mechanism** | Not yet designed. Needs ERP input on transaction type (Store Order? Stock Issue?). |

---

## 3. What We Need from This Meeting

### Q1 — What is the Parts endpoint, and what fields are available?

We have the token endpoint and asset endpoint working. We need the same for parts:

- **Parts endpoint URL** — what is it, and does it use the same bearer token?
- **Available fields** — which fields does the ERP expose for each part? For example:
  - Part ID / code
  - Name / description
  - Unit of measure
  - Category
  - Current status (active / inactive?)
  - Stock-on-hand quantity (does ERP expose this, or only SM tracks it?)
  - Last updated timestamp
- **Pagination** — cursor-based? Page size limits?
- **Incremental sync** — is there an `updated_since` filter?

### Q2 — Can we push quantity updates back through a Store Order workflow?

This is the core question. When SM issues items at GR, the store's stock decreases. LDC wants the ERP to reflect this. We need to understand the mechanism:

- Does LDC ERP have a **Store Order** or **Stock Issue** transaction type that SM can call to decrement inventory quantities?
- What is the **endpoint and payload** for such a transaction? For example:
  - Part ID / code
  - Quantity issued (decrement)
  - Transaction date
  - Order reference (should SM carry an ERP PO number?)
  - Requester / recipient identifier
  - Store / warehouse code
- Is the **same token** used for write operations, or does it need elevated scopes?
- Is there an existing **API spec or example payload** we can review?

### Q3 — How are partial issues and edge cases handled?

Real-world issuance rarely matches the order exactly:

- **Partial issue:** Ordered 10, only 7 available to issue. Can the ERP accept a GR for 7 (partial)?
- **Split issue:** One order fulfilled across multiple GRs over time. Does ERP support multiple GR transactions against one order?
- **Cancellation / return:** If issued items are returned, is that a separate reversal transaction or a negative GR?
- **Idempotency:** If SM retries a push due to a network error, how does the ERP prevent duplicate GR records? Is there an idempotency key?
- **Reconciliation:** If the push fails, what is the recovery path — manual re-push, or ERP-side adjustment?

---

## 4. Technical Implications (if write-back agreed)

| Area | Impact |
|---|---|
| **Parts read sync** | Add parts endpoint URL and field mapping to `config/erp.php`. Confirm token works for parts. |
| **ERP adapter** | `ErpSource` contract gains write methods (`postGoodsReceipt()` / `postStoreOrder()`). |
| **SM workflow** | GR confirmation triggers a queued job to push to ERP. |
| **Error handling** | Queue job with retry/backoff; audit log records success/failure. |
| **Auth** | Confirm existing token scopes cover write operations. |
| **Field mapping** | Map SM part/order data to ERP's expected Store Order / GR transaction payload. |
