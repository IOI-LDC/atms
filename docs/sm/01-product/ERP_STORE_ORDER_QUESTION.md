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
