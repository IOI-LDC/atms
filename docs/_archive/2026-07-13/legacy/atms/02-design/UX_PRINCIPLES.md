# UX Principles

## 1. Operational Simplicity

The product should feel like a simple maintenance tracking system, not an ERP clone.

## 2. One Main Workflow

The workflow should always be clear:

**Maintenance Request → Approval → Work Order → Closure**

## 3. Work Orders Are Not Created Directly

Normal users should not create Work Orders directly. Work Orders are created from approved Maintenance Requests.

## 4. ERP Data Is Reference Data

Fixed assets and parts come from ERP. The UI should make this clear. Users should not feel they are editing the official ERP asset or part master.

## 5. Avoid Out-of-Scope Language

Do not use labels that imply excluded modules, such as:

- Chain of Custody
- Gate Pass
- Shipment
- Procurement
- Financial Cost
- Depreciation
- Audit Campaign
- Governance

## 6. Use Operational Labels

Preferred labels:

- Location History
- Parts Used
- Usage & Meter Readings
- Approve & Create Work Order
- Work Order Activity History
- ERP Reference Data

## 7. Keep Statuses Visible

Each main object should show clear status badges:

- Asset status
- Maintenance Request status
- Work Order status
- PM rule status
- ERP sync status
