# Client Scope Confirmation Checklist

Use this checklist before finalizing proposal or implementation scope.

## ERP

- [ ] ERP provides fixed asset data.
- [ ] ERP provides parts data.
- [ ] ERP integration is read-only.
- [ ] No ERP write-back is required.
- [ ] Sync method is identified.

## Assets

- [ ] Asset current location is required.
- [ ] Asset location history is required.
- [ ] Asset usage readings are required.
- [ ] Asset attachments are required.
- [ ] Financial asset lifecycle is out of scope.

## Parts

- [ ] Parts are sourced from ERP.
- [ ] Parts can be selected on Work Orders.
- [ ] Parts attachments are required.
- [ ] Confirm whether Logistics users require Parts Reference access because of warehouse-team overlap; default MVP access is No.
- [ ] Parts costing is out of scope.
- [ ] Full inventory management is out of scope.

## Maintenance Requests

- [ ] Corrective MRs are user-created.
- [ ] Preventive MRs are system-generated.
- [ ] Maintenance Manager approval is required.
- [ ] Approved MR creates Work Order.

## Work Orders

- [ ] Work Orders are not created directly by normal users.
- [ ] Parts used can be recorded.
- [ ] Labor tracking is out of scope.
- [ ] Closure notes are required.
- [ ] Closed WOs become asset maintenance history.

## PM Rules

- [ ] Date-based PM rules are required.
- [ ] Usage-based PM rules are required.
- [ ] Kilometer-based rules are required if applicable.
- [ ] Due/overdue logic is required.

## Attachments

- [ ] Asset attachments are required.
- [ ] Part attachments are required.
- [ ] WO/MR attachments are desired or confirmed.
- [ ] Full document management is out of scope.

## Deployment

- [ ] Dockerized deployment on VPS is acceptable.
- [ ] PostgreSQL is acceptable.
- [ ] Laravel backend is acceptable.
- [ ] Vue frontend is acceptable.
