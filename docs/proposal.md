I want to establish the system as a **multi-store, multi-tenant-capable retail platform with offline-first POS, inventory management, customer portal, procurement, personnel management, and order fulfilment**.

# Multi-Store Offline-First POS & Retail Management Platform

## Role

Act as a **principal software architect, senior Laravel engineer, senior Vue engineer, database architect, DevOps engineer, security engineer, and retail/POS domain expert**.

I want you to design and build a production-grade **multi-store Point of Sale (POS) and Retail Management Platform**.

Do not treat this as a simple CRUD application.

The system must be designed as a serious commercial retail platform capable of supporting:

- Multiple stores/outlets
- Multiple warehouses
- Multiple personnel/users
- Multiple suppliers
- Multiple customers
- Products and product variants
- Inventory management
- Purchasing and supplier management
- Sales and POS
- Returns and refunds
- Customer orders
- Invoices
- Payments
- Deliveries
- Customer self-service portal
- Offline POS operation
- Online synchronization
- Reporting and analytics
- Role-based access control
- Audit trails
- Notifications
- Multi-store stock transfers
- Promotions and pricing
- Tax configuration
- Cash management
- End-of-day reconciliation

The architecture must be scalable and maintainable enough to eventually support hundreds or thousands of stores and potentially millions of products, customers, transactions, and inventory movements.

---

# 1. Core Technology Stack

Use the following stack unless there is a compelling architectural reason to change it.

### Backend

- Laravel 13
- PHP 8.4+
- MySQL 8+
- Redis
- Laravel Queue
- Laravel Scheduler
- Laravel Events/Listeners
- Laravel Notifications
- Laravel Sanctum
- Laravel Fortify
- Laravel Policies/Gates
- Laravel API Resources
- Laravel Broadcasting where appropriate
- Laravel nWidart modules

### Frontend

Use:

- Vue 3
- Inertia.js
- TypeScript
- Tailwind CSS
- Vite

Use VUE only DO NOT USE IT with INERTIA

### Infrastructure

Design for:

- Docker
- Nginx
- Redis
- MySQL
- Object storage/S3-compatible storage
- Queue workers
- Scheduler
- WebSockets where required

The application should be deployable to:

- VPS
- Cloud infrastructure
- Docker
- Kubernetes in the future

---

# 2. Architecture Principles

Follow these principles throughout the project.

## Domain-driven modular architecture

Organize the application into logical modules rather than putting everything into controllers group similar domains or areas into single modules to avoid having too many modules.

Suggested domains:

```text
Authentication
Organization
Stores
Users
Personnel
Roles & Permissions
Products
Categories
Brands
Units
Pricing
Customers
Suppliers
Purchasing
Inventory
POS
Sales
Payments
Invoices
Returns
Refunds
Orders
Deliveries
Promotions
Taxes
Accounting
Notifications
Reporting
Audit
Settings
```

Do not create a separate module merely for the sake of creating modules.

Keep domain boundaries clear.

---

# 3. Multi-Store Architecture

The system must support multiple retail outlets.

Example:

```text
Organization
    ├── Store A
    ├── Store B
    ├── Store C
    └── Warehouse
```

Each store should be able to have:

- Store details
- Address
- Contact details
- Opening hours
- Currency
- Tax configuration
- POS terminals
- Personnel
- Inventory
- Customers
- Sales
- Orders
- Cash registers
- Delivery configuration

A user may have access to:

```text
All stores
Specific stores
Specific warehouse
Specific departments
```

Do not assume every employee can see every store.

Implement proper store-level authorization.

---

# 4. POS Requirements

The POS must be optimized for speed.

The POS interface should support:

- Product search
- Barcode scanning
- SKU search
- Product categories
- Product images
- Product variants
- Quantity adjustment
- Price override where authorized
- Discounts
- Promotions
- Tax
- Customer selection
- Walk-in customers
- Customer accounts
- Multiple payment methods
- Split payments
- Partial payments
- Cash
- Card
- Bank transfer
- Mobile payments
- Store credit
- Gift cards where appropriate
- Receipt printing
- Digital receipts
- Email/SMS receipts
- Hold/suspend sale
- Resume suspended sale
- Cancel sale
- Refund
- Exchange
- Return
- Cash drawer management
- Cash in/out
- End-of-day reconciliation

The POS must work with poor or intermittent internet connectivity.

---

# 5. Offline-First POS

This is one of the most important requirements.

The POS should continue functioning when the internet goes down.

Do not simply cache the website.

Design a proper **offline-first architecture**.

The browser/device should maintain a local database/storage layer capable of storing:

- Products
- Product variants
- Prices
- Tax configuration
- Relevant customer data
- Store inventory snapshot
- POS configuration
- User/session information
- Pending sales
- Pending payments
- Pending returns
- Synchronization metadata

Consider using:

- IndexedDB
- Dexie.js or an equivalent robust IndexedDB abstraction

The POS should be a PWA-capable application.

---

# 6. Synchronization Architecture

Design a robust synchronization mechanism.

Each offline transaction must have:

```text
UUID
Device ID
Store ID
Terminal ID
User ID
Created timestamp
Local sequence number
Sync status
Server timestamp
```

Example:

```text
sale_uuid
device_uuid
store_id
terminal_id
cashier_id
local_sequence
created_at
sync_status
synced_at
```

When connectivity returns:

```text
Offline Device
      ↓
Synchronization Queue
      ↓
API
      ↓
Server Validation
      ↓
Transaction Processing
      ↓
Inventory Update
      ↓
Payment Recording
      ↓
Invoice Generation
      ↓
Sync Confirmation
```

Implement idempotency.

A transaction must never be processed twice because of:

- Network retries
- Browser refresh
- Duplicate API requests
- Device reconnection
- Queue retries

Use unique transaction UUIDs/idempotency keys.

---

# 7. Conflict Resolution

Design explicit conflict resolution rules.

Examples:

### Inventory

If two terminals sell the same product while offline, the server must reconcile inventory correctly when they synchronize.

Do not silently overwrite inventory.

Inventory must be represented as a ledger of movements rather than relying exclusively on a mutable quantity field.

Use concepts such as:

```text
Stock Movement
Stock Receipt
Stock Sale
Stock Return
Stock Adjustment
Stock Transfer
Stock Reservation
Stock Release
```

The system should maintain a reliable inventory history.

---

# 8. Inventory Management

Products must support:

- SKU
- Barcode
- Product name
- Description
- Category
- Brand
- Supplier
- Cost price
- Selling price
- Wholesale price
- Retail price
- Tax
- Unit
- Product variants
- Images
- Reorder level
- Minimum stock
- Maximum stock
- Weight
- Dimensions
- Serial numbers where required
- Batch numbers where required
- Expiry dates where required

Inventory must be store-specific.

Example:

```text
Product
    ↓
Store Inventory
    ↓
Available Quantity
Reserved Quantity
Damaged Quantity
In Transit Quantity
```

Support:

- Stock receiving
- Stock adjustments
- Stock transfers
- Stock counts
- Stock takes
- Damaged goods
- Expired goods
- Low-stock alerts
- Reorder suggestions
- Warehouse inventory
- Store inventory

---

# 9. Inventory Ledger

Create an immutable inventory movement ledger.

Example:

```text
inventory_movements

id
uuid
product_id
store_id
warehouse_id
movement_type
quantity
unit_cost
reference_type
reference_id
batch_id
serial_number
performed_by
created_at
```

Movement types should include:

```text
purchase
sale
sale_return
purchase_return
transfer_out
transfer_in
adjustment
damage
expiry
opening_balance
stock_count
reservation
reservation_release
```

Inventory balances can be derived/materialized from these movements.

Do not make direct quantity manipulation the primary source of truth.

---

# 10. Purchasing & Suppliers

The supplier module should support:

- Suppliers
- Supplier contacts
- Supplier addresses
- Supplier products
- Purchase orders
- Purchase order approval
- Goods received
- Partial receiving
- Supplier invoices
- Supplier payments
- Purchase returns
- Supplier balances
- Supplier transaction history

Workflow:

```text
Purchase Request
       ↓
Purchase Order
       ↓
Approval
       ↓
Goods Received
       ↓
Inventory Updated
       ↓
Supplier Invoice
       ↓
Payment
```

Support partial deliveries.

---

# 11. Customer Management

Customers should have:

- Name
- Email
- Phone
- Address
- Customer number
- Customer group
- Loyalty information
- Credit limit
- Account balance
- Order history
- Purchase history
- Invoices
- Payments
- Returns
- Delivery addresses

Support:

- Walk-in customer
- Registered customer
- Business customer
- Corporate customer

---

# 12. Customer Web Portal

Create a customer-facing web portal.

Customers should be able to:

- Register
- Login
- Verify email
- Manage profile
- Manage addresses
- Browse products
- Search products
- Filter products
- View product details
- Add products to cart
- Checkout
- Select delivery method
- Select store
- Select pickup
- Make payment
- View orders
- Track orders
- View invoices
- Download invoices
- View payments
- Request returns
- View refunds
- View delivery status

Order status should support:

```text
Pending
Confirmed
Processing
Ready for Pickup
Out for Delivery
Delivered
Cancelled
Returned
Refunded
```

---

# 13. Customer Ordering

Customers can place orders online.

The system should determine:

- Which store fulfils the order
- Available inventory
- Delivery location
- Delivery fee
- Tax
- Discounts
- Promotions
- Payment status

Support:

```text
Home delivery
Store pickup
Click & collect
```

Orders must reserve stock appropriately.

Avoid overselling.

---

# 14. Order Fulfilment

Design order fulfilment as a separate domain.

Example:

```text
Customer Order
      ↓
Payment
      ↓
Stock Reservation
      ↓
Order Confirmation
      ↓
Picking
      ↓
Packing
      ↓
Dispatch
      ↓
Delivery
      ↓
Completed
```

Create proper entities for:

- Order
- Order Item
- Fulfilment
- Shipment
- Delivery
- Delivery Address
- Delivery Status
- Tracking Number

---

# 15. Invoices

Invoices should support:

- Invoice number
- Customer
- Store
- Items
- Quantity
- Unit price
- Discount
- Tax
- Total
- Payment status
- Due date
- Payment history

Statuses:

```text
Draft
Issued
Partially Paid
Paid
Overdue
Cancelled
Refunded
```

Invoices should be downloadable as PDF.

Use proper invoice numbering per organization/store where appropriate.

---

# 16. Payments

Create a payment abstraction rather than tightly coupling the application to one payment provider.

Example:

```text
Payment Gateway
    ├── Cash
    ├── Card
    ├── Bank Transfer
    ├── Stripe
    ├── Paystack
    └── Other Providers
```

Create a common payment interface.

Support:

- Payment initiation
- Payment confirmation
- Payment failure
- Payment reversal
- Refund
- Partial payment
- Split payment
- Webhook processing
- Idempotency

Payment webhooks must be secure and idempotent.

---

# 17. Personnel Management

Personnel should support:

- Employees
- Managers
- Cashiers
- Store managers
- Warehouse staff
- Delivery personnel
- Procurement officers
- Administrators

Implement RBAC.

Example roles:

```text
Super Administrator
Organization Administrator
Store Manager
Assistant Store Manager
Cashier
Inventory Manager
Warehouse Manager
Procurement Officer
Accountant
Sales Staff
Delivery Staff
Customer
```

Permissions should be granular.

Examples:

```text
sales.create
sales.refund
sales.discount
inventory.view
inventory.adjust
inventory.transfer
purchase.create
purchase.approve
supplier.view
customer.view
customer.edit
reports.view
users.manage
stores.manage
```

---

# 18. Cash Register Management

Each POS terminal should support:

```text
Register
Terminal
Cashier Session
Opening Balance
Cash In
Cash Out
Sales
Refunds
Closing Balance
Expected Balance
Actual Balance
Variance
```

A cashier should open a register session before processing sales.

At closing:

```text
Expected Cash
Actual Cash
Variance
```

Record the reconciliation.

---

# 19. Returns & Refunds

Design a complete return system.

Support:

- Full return
- Partial return
- Product exchange
- Refund
- Store credit
- Original payment method
- Damaged return
- Return reason

A return must automatically update the appropriate inventory ledger.

Refunds must be tracked independently from sales.

---

# 20. Pricing & Promotions

Support multiple price lists.

Example:

```text
Retail
Wholesale
VIP
Corporate
Store-specific
Online
```

Promotions should support:

- Percentage discount
- Fixed discount
- Buy X Get Y
- Product-specific promotions
- Category promotions
- Customer-group promotions
- Date-based promotions
- Store-specific promotions
- Quantity-based pricing

Do not hard-code pricing rules into the POS UI.

Pricing should be handled by a pricing domain/service.

---

# 21. Tax

Design tax as configurable.

Support:

- Tax rates
- Tax categories
- Tax-inclusive pricing
- Tax-exclusive pricing
- Store-specific tax rules
- Product-specific tax rules

Do not hard-code one country's tax rules into the application.

---

# 22. Notifications

Implement a notification system supporting:

- Email
- SMS abstraction
- Push notifications
- In-app notifications

Events such as:

```text
Order placed
Order confirmed
Payment received
Order shipped
Order delivered
Invoice generated
Low stock
Purchase order approved
Stock received
Refund processed
```

---

# 23. Reporting & Analytics

Create dashboards for:

### Executive

- Total sales
- Revenue
- Gross profit
- Number of orders
- Average order value
- Top products
- Top stores
- Sales trends

### Store Manager

- Daily sales
- Cashier performance
- Inventory
- Low stock
- Returns
- Discounts
- Register reconciliation

### Inventory

- Stock value
- Stock movement
- Fast-moving products
- Slow-moving products
- Dead stock
- Reorder requirements

### Purchasing

- Supplier spend
- Purchase history
- Outstanding supplier balances

### Customer

- Customer lifetime value
- Repeat customers
- Top customers
- Purchase frequency

Reports should be filterable by:

```text
Date
Store
Product
Category
Customer
Supplier
Personnel
Payment method
```

---

# 24. Audit System

Every sensitive operation must be auditable.

Record:

```text
User
Action
Entity
Entity ID
Before
After
IP Address
User Agent
Timestamp
Store
```

Audit events should include:

- Price changes
- Inventory adjustments
- Refunds
- Discounts
- User permission changes
- Purchase approvals
- Sales voids
- Order cancellations
- Payment changes

Audit logs should be append-only.

---

# 25. Security

Implement:

- Laravel authentication
- Sanctum
- Fortify
- RBAC
- Policies
- Rate limiting
- CSRF protection
- XSS protection
- SQL injection protection
- Secure password policies
- Two-factor authentication
- Session management
- Device/session tracking
- API authentication
- Secure webhooks
- Audit logs
- Encryption for sensitive data
- Secure file uploads

Never trust client-side validation.

All important business rules must be enforced server-side.

---

# 26. API Architecture

Build a clean versioned API:

```text
/api/v1
```

The API should support:

```text
Authentication
Products
Inventory
Sales
Orders
Customers
Suppliers
Purchasing
Payments
Invoices
Deliveries
Stores
Users
Reports
Synchronization
```

The offline POS should communicate with the backend through dedicated synchronization endpoints.

---

# 27. Offline Synchronization API

Create endpoints such as:

```text
POST /api/v1/sync/push
POST /api/v1/sync/pull
GET  /api/v1/sync/status
```

The synchronization system should support:

- Batch synchronization
- Retry
- Idempotency
- Conflict detection
- Conflict resolution
- Sync cursors
- Device registration
- Device authentication
- Sync checkpoints

Avoid downloading the entire database to a device.

Only synchronize the data required by that store/device.

---

# 28. Database Design

Design a normalized relational database.

Important tables should include concepts such as:

```text
organizations
stores
warehouses
terminals
users
employees
roles
permissions

products
product_variants
categories
brands
units
barcodes

customers
customer_addresses
customer_groups

suppliers
supplier_contacts

purchase_orders
purchase_order_items
goods_receipts
goods_receipt_items

inventory_movements
inventory_balances
stock_transfers
stock_transfer_items
stock_counts

sales
sale_items
sale_payments

orders
order_items
order_fulfilments
shipments
deliveries

invoices
invoice_items
payments
refunds

price_lists
prices
promotions
promotion_rules

taxes

cash_registers
cashier_sessions
cash_movements

notifications
audit_logs

devices
sync_batches
sync_records
```

Use UUIDs where appropriate.

Use database constraints and indexes properly.

Pay particular attention to:

- Foreign keys
- Unique constraints
- Composite indexes
- Store-specific indexes
- Transaction boundaries
- Decimal precision for monetary values
- Concurrency

Never use floating point values for money.

---

# 29. Transaction Integrity

Sales must be processed atomically.

For example:

```text
BEGIN TRANSACTION

Create Sale
Create Sale Items
Validate Inventory
Create Inventory Movements
Update Inventory Balance
Create Payment
Create Invoice
Update Customer Balance
Record Audit Event

COMMIT
```

If any critical operation fails, the transaction should roll back.

Use database transactions appropriately.

---

# 30. Concurrency

The application must account for multiple users and terminals selling the same products simultaneously.

Use appropriate:

- Database transactions
- Row locking
- Optimistic locking where useful
- Unique constraints
- Idempotency keys
- Queue processing

Do not rely on:

```php
$product->stock--;
$product->save();
```

as the sole mechanism for inventory correctness.

---

# 31. UX Requirements

The POS interface should prioritize speed.

Keyboard shortcuts should be supported.

Examples:

```text
F2 Search
F4 Customer
F8 Discount
F9 Payment
ESC Cancel
```

Make the POS usable with:

- Mouse
- Keyboard
- Touchscreen
- Barcode scanner

The POS should work well on:

- Desktop
- Tablet
- POS touchscreen

---

# 32. PWA

The POS should be installable as a PWA.

Implement:

- Service worker
- App manifest
- Offline shell
- IndexedDB
- Background synchronization where supported
- Connectivity detection
- Sync status indicator

The interface should clearly show:

```text
ONLINE
OFFLINE
SYNCING
SYNC ERROR
```

Never hide synchronization failures from the user.

---

# 33. Device Management

A store may have multiple POS terminals.

Example:

```text
Store A

POS-001
POS-002
POS-003
```

Each device should have:

- Device UUID
- Terminal ID
- Store ID
- Device name
- Registration status
- Last synchronization time
- Last active user
- Software version

Administrators should be able to disable a compromised device.

---

# 34. Background Processing

Use queues for expensive operations:

```text
Invoice generation
Email
SMS
Notifications
Reports
Synchronization
Webhook processing
Inventory reconciliation
Data exports
```

Do not block the POS while performing unnecessary background operations.

---

# 35. Testing

Build tests alongside the application.

Include:

### Unit Tests

For:

- Pricing
- Tax
- Discounts
- Inventory
- Promotions
- Payment calculations

### Feature Tests

For:

- Sales
- Purchases
- Orders
- Returns
- Refunds
- Inventory
- Authentication

### Authorization Tests

Verify that:

```text
Store A manager cannot access Store B data.
```

### API Tests

Test all important endpoints.

### Offline Sync Tests

Test:

- Offline sale
- Reconnection
- Duplicate sync
- Failed sync
- Retry
- Conflict
- Partial batch failure

### Browser/E2E Tests

Test critical POS workflows.

---

# 36. Observability

Implement structured logging.

Track:

- Application errors
- Failed jobs
- API failures
- Synchronization failures
- Payment failures
- Webhook failures
- Slow queries
- Inventory inconsistencies

Design the system so it can eventually integrate with:

- Sentry
- OpenTelemetry
- Prometheus
- Grafana

---

# 37. Import & Export

Provide import/export functionality.

Support:

```text
Products
Customers
Suppliers
Inventory
Prices
```

CSV/Excel imports should have:

- Validation
- Preview
- Error reporting
- Duplicate detection
- Import history

Large imports should be queued.

---

# 38. Search

Implement efficient search for:

- Products
- Customers
- Suppliers
- Orders
- Invoices
- Sales

The POS product search must be extremely fast.

Design the system so that a dedicated search engine such as:

```text
Meilisearch
Typesense
Elasticsearch
```

can be introduced later without rewriting the domain logic.

---

# 39. Data Isolation

Every store-specific query must respect authorization and organization boundaries.

Do not rely solely on developers remembering:

```php
->where('store_id', ...)
```

Design reusable mechanisms for enforcing tenant/store scope.

However, avoid global scopes that make legitimate cross-store administrative operations unnecessarily difficult.

---

# 40. Configuration

Avoid hard-coded business rules.

Put configurable settings into a proper settings system.

Examples:

```text
Currency
Tax
Invoice numbering
Receipt numbering
Low-stock threshold
Order expiry
Payment settings
Store settings
POS settings
Receipt settings
Notification settings
Delivery settings
```

---

# 41. Code Quality

Follow:

- SOLID
- DRY
- KISS
- Clean Architecture principles where appropriate
- Laravel conventions
- PSR standards
- Strong typing
- PHP enums
- Form Requests
- DTOs where useful
- Service classes where appropriate
- Actions for important business operations
- Policies
- Events
- Listeners
- Jobs

Avoid:

- Massive controllers
- Massive Vue components
- Business logic inside Blade templates
- Business logic inside database models where it doesn't belong
- Duplicate business logic
- Hard-coded store IDs
- Hard-coded permissions
- Hard-coded tax rules
- Hard-coded payment providers

---

# 42. Important Domain Services

Create dedicated application/domain services for important workflows.

Examples:

```text
SaleService
InventoryService
InventoryReservationService
StockTransferService
PurchaseOrderService
OrderService
OrderFulfilmentService
PaymentService
RefundService
InvoiceService
PricingService
PromotionService
TaxService
CustomerService
SynchronizationService
CashRegisterService
```

Each service should have clear responsibilities.

---

# 43. Events

Use domain events where appropriate.

Examples:

```text
SaleCompleted
InventoryAdjusted
StockReceived
OrderPlaced
OrderConfirmed
OrderShipped
OrderDelivered
PaymentCompleted
PaymentFailed
RefundCompleted
LowStockDetected
PurchaseOrderApproved
```

These events should drive secondary operations such as:

- Notifications
- Reporting
- Audit
- Emails
- Synchronization

Do not make critical financial/inventory operations dependent on asynchronous events unless the consistency model explicitly allows it.

---

# 44. Admin Portal

Build an administration portal containing:

```text
Dashboard

Organization
Stores
Warehouses
Terminals

Products
Categories
Brands
Units
Prices
Promotions

Inventory
Stock
Stock Transfers
Stock Counts
Adjustments

Sales
Orders
Invoices
Payments
Returns
Refunds

Purchasing
Suppliers
Purchase Orders
Goods Received

Customers

Personnel
Users
Roles
Permissions

Reports
Analytics

Notifications

Audit Logs

Settings
```

---

# 45. Store Manager Portal

Store managers should have a focused dashboard showing:

```text
Today's Sales
Today's Orders
Current Stock
Low Stock
Pending Orders
Returns
Cash Registers
Cashier Performance
Top Products
```

They should only see data for stores they are authorized to manage.

---

# 46. Customer Portal

Create a separate customer-facing experience.

Navigation:

```text
Dashboard
Shop
Orders
Invoices
Payments
Returns
Addresses
Profile
Notifications
```

The customer must never have access to internal administration APIs.

---

# 47. API and Frontend Separation

Keep domain/business logic independent of the UI.

The following clients should eventually be able to consume the same backend:

```text
Admin Web
Store Manager Web
POS PWA
Customer Web Portal
Mobile App
Third-party integrations
```

Do not implement business rules exclusively in Vue.

---

# 48. Future Integrations

Design extension points for:

```text
Payment gateways
Accounting software
ERP systems
E-commerce platforms
Shipping providers
SMS providers
Email providers
Tax systems
Barcode scanners
Receipt printers
Cash drawers
Mobile applications
BI platforms
```

Do not implement all integrations now.

Create clean interfaces so they can be added later.

---

# 49. Implementation Strategy

Do NOT attempt to build the entire application in one step.

Work in phases.

## Phase 1 — Architecture

First analyze the requirements and produce:

```text
architecture.md
domain-model.md
database-design.md
api-design.md
offline-sync-design.md
security-design.md
permission-matrix.md
```

Also create an implementation roadmap.

Do not start coding until the architecture has been reviewed.

---

## Phase 2 — Foundation

Implement:

- Laravel configuration
- Authentication
- Organizations
- Stores
- Users
- Roles
- Permissions
- Store access
- Settings
- Audit infrastructure

---

## Phase 3 — Product & Inventory

Implement:

- Products
- Categories
- Brands
- Units
- Variants
- Barcodes
- Store inventory
- Inventory ledger
- Stock adjustments
- Stock transfers
- Stock counts

---

## Phase 4 — Purchasing

Implement:

- Suppliers
- Purchase orders
- Approvals
- Goods receiving
- Supplier invoices
- Supplier payments
- Purchase returns

---

## Phase 5 — POS

Implement:

- POS UI
- Cart
- Product search
- Barcode scanning
- Customers
- Discounts
- Taxes
- Payments
- Receipts
- Cash registers
- Cashier sessions

---

## Phase 6 — Offline POS

Implement:

- PWA
- IndexedDB
- Offline product cache
- Offline cart
- Offline sales
- Local transaction UUIDs
- Sync queue
- Push synchronization
- Pull synchronization
- Idempotency
- Conflict handling
- Sync monitoring

This phase must receive significant architectural attention.

---

## Phase 7 — Customer Portal

Implement:

- Registration
- Authentication
- Product catalogue
- Shopping cart
- Checkout
- Orders
- Payments
- Invoices
- Returns
- Delivery tracking

---

## Phase 8 — Fulfilment & Delivery

Implement:

- Stock reservation
- Picking
- Packing
- Shipment
- Delivery
- Tracking
- Store pickup

---

## Phase 9 — Reporting

Implement:

- Sales reports
- Inventory reports
- Purchase reports
- Customer reports
- Store performance
- Cashier performance
- Profitability

---

## Phase 10 — Hardening

Implement:

- Security review
- Performance optimization
- Database optimization
- Queue monitoring
- Error monitoring
- Backup strategy
- Disaster recovery
- Load testing
- Offline synchronization testing
- E2E testing

---

# 50. Cursor Development Rules

This is extremely important.

Do not blindly generate large amounts of code.

Before implementing each major feature:

1. Explain the architecture.
2. Identify affected modules.
3. Identify affected database tables.
4. Identify API changes.
5. Identify frontend changes.
6. Identify authorization implications.
7. Identify offline synchronization implications.
8. Identify testing requirements.
9. Implement the smallest coherent unit.
10. Run tests.
11. Fix failures.
12. Update documentation.

Never modify unrelated functionality simply to make a feature work.

---

# 51. Database Migration Rules

Every database change must use migrations.

Never manually modify production databases.

Use:

```text
migrations
seeders
factories
```

Create realistic seed data for development.

---

# 52. Business Logic Rules

Critical business operations should be represented as explicit application actions.

Examples:

```text
CompleteSale
ProcessReturn
ReceiveStock
TransferStock
CreatePurchaseOrder
ApprovePurchaseOrder
PlaceOrder
ConfirmOrder
ReserveStock
ReleaseStock
ProcessPayment
ProcessRefund
CloseCashierSession
SynchronizeDevice
```

These operations should be testable independently from controllers and UI.

---

# 53. Financial Accuracy

Money calculations must use appropriate decimal types.

Never use floating point arithmetic for:

```text
Price
Tax
Discount
Payment
Refund
Invoice totals
Profit
```

Define appropriate precision and rounding rules.

Every financial transaction must be traceable.

---

# 54. Inventory Accuracy

Inventory must be treated as a critical business domain.

Every stock change must have:

```text
Reason
Reference
User
Store
Timestamp
Quantity
Cost
```

Never allow arbitrary inventory changes without an audit trail.

---

# 55. Offline Safety

Never assume offline data is automatically trustworthy.

When synchronizing an offline transaction, the server must validate:

- Product exists
- Product is sellable
- Price rules
- Tax
- User authorization
- Store authorization
- Terminal authorization
- Transaction timestamp
- Transaction UUID
- Payment information
- Inventory rules

The server remains the ultimate authority.

---

# 56. Documentation

Maintain documentation as the application evolves.

Create:

```text
/docs
    architecture.md
    domain-model.md
    database.md
    api.md
    authentication.md
    authorization.md
    inventory.md
    pos.md
    offline-sync.md
    payments.md
    orders.md
    fulfilment.md
    deployment.md
    testing.md
    troubleshooting.md
```

Update relevant documentation whenever architecture changes.

---

# 57. Definition of Done

A feature is not complete merely because the UI works.

A feature is complete only when:

```text
Database
Backend
Authorization
Business Logic
API
Frontend
Validation
Error Handling
Audit
Events
Tests
Documentation
Offline behaviour where applicable
```

have all been considered.

---

# 58. First Task

Do NOT start building the POS UI.

Your first task is to analyze this entire specification.

Produce:

1. System architecture
2. Domain architecture
3. Database ERD proposal
4. Complete database table proposal
5. Relationship design
6. Multi-store isolation strategy
7. Authentication/authorization architecture
8. POS architecture
9. Offline-first architecture
10. Synchronization architecture
11. Conflict-resolution strategy
12. Inventory ledger architecture
13. Order/fulfilment architecture
14. Payment architecture
15. Customer portal architecture
16. API architecture
17. Queue/event architecture
18. Security architecture
19. Testing strategy
20. Deployment architecture
21. Development roadmap

Then identify:

- Ambiguous requirements
- Potential scalability problems
- Potential data consistency problems
- Potential offline synchronization problems
- Security risks
- POS hardware considerations
- Areas where the architecture should be simplified

Do not generate application code yet.

After producing the architecture, wait for approval before beginning Phase 1 implementation.

---

# Final Principle

Build this as a **retail platform**, not merely a POS.

The POS is one client of the platform.

The architecture should ultimately look conceptually like:

```text
                         RETAIL PLATFORM
                               │
        ┌──────────────────────┼──────────────────────┐
        │                      │                      │
     ADMIN                  STORES                 CUSTOMERS
        │                      │                      │
        │                 POS TERMINALS         WEB PORTAL
        │                      │                      │
        └──────────────────────┼──────────────────────┘
                               │
                         APPLICATION API
                               │
       ┌───────────────┬───────┼────────┬───────────────┐
       │               │       │        │               │
    Sales          Inventory Orders  Payments       Customers
       │               │       │        │               │
       └───────────────┴───────┼────────┴───────────────┘
                               │
                         DOMAIN SERVICES
                               │
                  ┌────────────┴────────────┐
                  │                         │
                MySQL                    Redis
                  │                         │
                  └────────────┬────────────┘
                               │
                         Async Workers
                               │
                 ┌─────────────┼─────────────┐
                 │             │             │
             Notifications   Reports     Integrations
```

The most important architectural requirements are:

**financial accuracy, inventory accuracy, multi-store isolation, offline operation, synchronization, security, auditability, scalability, and maintainability.**

Do not sacrifice these for speed of initial development.
