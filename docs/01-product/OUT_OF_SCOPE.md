# Out of Scope

The proposed system is intended to be a simple operational maintenance application. The following items are excluded from the current scope unless agreed separately.

## 1. Financial Asset Management

The system will not manage asset capitalization, depreciation, disposal, impairment, asset valuation, book value, or accounting treatment of fixed assets. These activities will remain fully within the ERP.

## 2. Procurement and Purchasing

The system will not handle purchase requests, purchase orders, supplier quotations, goods receiving, invoicing, payment approvals, or supplier management.

## 3. Full Inventory / Warehouse Management

The system will not replace the ERP or warehouse system for full stock control. Advanced inventory functions such as bin-level warehouse control, stock valuation, stock counting, purchase replenishment, batch/lot tracking, expiry tracking, and warehouse accounting are excluded.

## 4. Parts Costing and Financial Tracking

The system will not calculate maintenance financial cost, asset cost, stock value, or accounting cost. Parts usage may be recorded operationally against a work order, but financial costing will remain outside the system.

The system will not maintain stock-on-hand quantities or create warehouse,
issue, return, reservation, procurement, or valuation transactions from Work
Order parts usage.

## 5. Labor Tracking

The system will not track technician labor hours, labor cost, timesheets, technician productivity, or labor-based maintenance costing.

This exclusion includes hour logs, labor rates, labor costs, timesheets,
attendance-derived maintenance effort, utilization metrics, and productivity
reporting.

## 6. Technician Wallet / Personal Stock

The system will not track parts personally held by technicians as individual wallets. Any advanced process for issuing parts to technicians, returning unused materials, or managing partial consumables can be considered later if required.

## 7. Logistics, Gate Passes, and Asset Transfer Documents

The system will not include logistics workflows, gate passes, shipment manifests, delivery notes, bulk transfers, transport approvals, or movement documents. Only physical location history of assets is included.

The Logistics role does not alter this boundary. It exists only to update asset
physical location and view location history.

## 8. Handover Management

Shift handovers, crew handovers, site handover notes, and operational handover approvals are excluded.

## 9. Advanced Governance and Audit Module

The system will not include a dedicated governance module, audit campaign
management, compliance workflows, or advanced approval chains. A lightweight
append-only technical audit log is included for security-sensitive and workflow
actions, but a full audit/governance interface is excluded.

## 10. Advanced Checklist Management

Configurable checklist functionality is excluded from the initial scope unless specifically requested. This includes inspection checklist templates, mandatory photo checklists, pass/fail forms, scoring, checklist versioning, checklist approvals, and checklist-based defect generation.

A simple work order completion note can be included, but a full checklist engine is out of scope.

## 11. Full Document Management System

Basic attachments against assets and parts are included. However, the system will not act as a full document management system. Advanced document versioning, approval workflows, document expiry alerts, controlled document distribution, e-signatures, and document lifecycle management are excluded unless agreed separately.

## 12. Mobile Application

A dedicated native mobile application is excluded unless agreed separately. The first version is assumed to be a web application, with responsive screens where practical.

## 13. QR Code / Barcode Scanning

QR code generation, barcode scanning, asset label printing, and scan-to-open asset records are excluded from the base scope unless added as a separate requirement.

## 14. IoT / Automatic Meter Reading

The system will not automatically read operating hours, kilometers, or sensor values from machines, GPS devices, telemetry platforms, or IoT systems. Usage readings will be entered manually or imported only if a simple data source is available.

## 15. Advanced Preventive Maintenance Optimization

The system will support simple preventive maintenance triggering based on date, operating hours, kilometers, or other readings. Advanced predictive maintenance, AI-based failure prediction, condition-based monitoring, and optimization algorithms are excluded.

MVP PM Rules apply only to individual ERP-linked assets. Category-level,
asset-type-level, unit/package-level, group, and reusable template rules are
excluded unless approved as later scope.

## 16. Multi-Level Approval Workflow

The base workflow includes Maintenance Manager review and approval before a maintenance request becomes a work order. Multi-level approvals, delegation rules, approval limits, and complex authorization matrices are excluded.

## 17. External Notifications

Workflow emails, SMS, WhatsApp, push notifications, and escalation reminders
are excluded unless agreed separately. The only included external notifications
are account activation and password-reset emails delivered through Microsoft
Power Automate. Basic in-system status visibility can be provided.

## 18. Advanced Reporting and BI

The system will include only basic operational dashboards and simple reports. Advanced analytics, custom report builders, Power BI dashboards, financial analysis, and KPI packs are excluded.

## 19. ERP Write-Back

The system will not update the ERP with asset changes, capitalization, disposal, financial data, parts transactions, or maintenance records unless agreed separately. ERP integration is assumed to be read-only for fixed assets and parts.

## 20. Offline Mode

Offline working, offline synchronization, and conflict resolution are excluded from the initial scope.

## 21. Multi-Tenant SaaS Features

The system is assumed to be deployed for a single client environment. Multi-tenant SaaS billing, tenant self-registration, subscription management, and tenant-level commercial controls are excluded.
