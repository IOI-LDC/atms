# VJ — Follow-up: Parts Read URL + QTY Update on Consumption

**To:** VJ (ERP Consultant)
**Date:** 2026-06-25
**Re:** Your reply on BC Store Management (no Store Order module)
**Related:** [`ERP_STORE_ORDER_QUESTION.md`](./ERP_STORE_ORDER_QUESTION.md)

---

Hi VJ — thanks for the clear answer. Understood: no Store Order module, and
parts issuance flows through Warehouse Management.

We've decided to build our own Store Management (SM) module for the
maintenance app. From the ERP side we only need two things — and we don't need
to know the internal BC mechanics. We'll leave the "how" entirely to you.

## 1. Read — the parts / consumables / M&S catalogue URL

Please provide the URL that returns all **M&S, Consumables, and Parts** from
BC. We'll use the same Entra ID token we already have working for fixed
assets — just need the endpoint, and confirmation it's the same auth.

## 2. Write — update Parts QTY when a part is consumed

When a part is used in our system (consumed against a work order / issued at
goods receipt), **the quantity in BC must go down.** That is the only ERP
write-back we need.

Question: **can you update the parts QTY in BC when we tell you a part was
consumed?** If yes, what do you need from us so that update happens — an API
endpoint we call, a record/file we send you, or something you set up on your
side?

For each consumption we can provide:

- Part code
- Quantity consumed
- Date
- Reference (work order / order number)

Is that enough, or do you need any additional fields? We'll structure the
handoff however is easiest for you.

Thanks VJ.
