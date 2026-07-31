# VJ — Quick Question on BC Store Management

**To:** VJ (ERP Consultant)

Hi VJ — quick architectural question before we go too far on the maintenance
app's store module.

Does Dynamics 365 Business Central currently have a **Store Management / Store
Order** module implemented and actively used at LDC? If so, we would rather
integrate with it than build a separate store system.

Specifically, we want to know:

1. **Is Store Order (or equivalent — inventory issue/consumption workflow)
   currently live?** Are parts issued from the store through BC, or is store
   management handled outside the ERP?

2. **If it exists, can we query a specific store order by number through the BC
   OData API?** Something like:

   ```
   GET /ODataV4/Company('LDC-LIVE')/storeOrders?$filter=no eq 'SO-00123'
   ```

   We would need the order header (status, date, requester) and its line items
   (part code, description, quantity, unit of measure).

3. **If the store order workflow is already in BC**, we would change our
   approach: instead of the maintenance app reading from a standalone parts
   table, it would query BC's store orders. A technician creating a Work Order
   part-request would pull from a live store order, and the consumption would
   be recorded natively in BC — eliminating the need for a separate SM
   subsystem and any write-back integration.

If Store Order is NOT in use, no problem — we proceed with our own SM module.
Just checking before we build something that might already exist.

Thanks.

---

## VJ's Reply — 2026-06-25

> Currently, we are not using a separate Store Management or Store Order
> module in Dynamics 365 Business Central. Inventory management and material
> issuance are handled through the Warehouse Management functionality
> available in BC.
>
> Parts are issued and consumed through warehouse-related transactions rather
> than a dedicated store order workflow. Therefore, there is no active
> "Store Order" entity or process that can be queried through the standard BC
> OData API in the format described below.
>
> As a result:
>
> - There is no separate Store Order module currently implemented or in use.
> - Store order headers and lines are not available through standard OData
>   endpoints because the process is managed through warehouse transactions.
>
> If your proposed solution requires a dedicated store request/order
> workflow, you may proceed with your own SM module. Alternatively, we can
> discuss leveraging the existing warehouse processes and inventory
> transactions in BC to determine whether they can support the required
> functionality.
>
> If needed, we can arrange a discussion to review the current warehouse
> management process and identify the most suitable integration approach.

### Outcome

**Decision:** Build the SM subsystem as a focused maintenance-consumption
module. Decline the "integrate on top of BC Warehouse Management" path — BC
Warehouse remains the physical/warehouse execution layer; SM only needs a
narrow consumption **write-back** at Goods Receipt.

**Follow-up:** [`ERP_WAREHOUSE_FOLLOWUP.md`](./ERP_WAREHOUSE_FOLLOWUP.md) —
two asks: the parts/M&S/consumables read URL, and confirmation that the part
QTY in BC can be updated when a part is consumed.
