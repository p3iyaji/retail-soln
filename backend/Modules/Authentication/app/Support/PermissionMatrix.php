<?php

namespace Modules\Authentication\Support;

/**
 * Canonical permission matrix from Epic 0 (permission-matrix.md).
 * Role codes: SA, OA, SM, CA, IM, PO, AC, DS
 */
final class PermissionMatrix
{
    /**
     * @return array<string, array{name: string, code: string}>
     */
    public static function roles(): array
    {
        return [
            'SA' => ['code' => 'super_admin', 'name' => 'Super Admin'],
            'OA' => ['code' => 'org_admin', 'name' => 'Organization Admin'],
            'SM' => ['code' => 'store_manager', 'name' => 'Store Manager'],
            'CA' => ['code' => 'cashier', 'name' => 'Cashier'],
            'IM' => ['code' => 'inventory_manager', 'name' => 'Inventory Manager'],
            'PO' => ['code' => 'procurement_officer', 'name' => 'Procurement Officer'],
            'AC' => ['code' => 'accountant', 'name' => 'Accountant'],
            'DS' => ['code' => 'delivery_staff', 'name' => 'Delivery Staff'],
        ];
    }

    /**
     * Org-wide roles may access any store without store_user rows.
     *
     * @return list<string>
     */
    public static function orgWideRoleCodes(): array
    {
        return ['super_admin', 'org_admin'];
    }

    /**
     * @return array<string, array{roles: list<string>, scope: string}>
     */
    public static function permissions(): array
    {
        // ● = listed role abbreviation; scope org|store
        $rows = [
            'org.view' => ['SA', 'OA', 'SM', 'IM', 'PO', 'AC'],
            'org.update' => ['SA', 'OA'],
            'stores.view' => ['SA', 'OA', 'SM', 'CA', 'IM', 'PO', 'AC', 'DS'],
            'stores.create' => ['SA', 'OA'],
            'stores.update' => ['SA', 'OA', 'SM'],
            'users.view' => ['SA', 'OA', 'SM'],
            'users.manage' => ['SA', 'OA'],
            'users.assign-roles' => ['SA', 'OA'],
            'users.assign-stores' => ['SA', 'OA', 'SM'],
            'roles.view' => ['SA', 'OA'],
            'products.view' => ['SA', 'OA', 'SM', 'CA', 'IM', 'PO'],
            'products.manage' => ['SA', 'OA', 'IM'],
            'products.import' => ['SA', 'OA', 'IM'],
            'catalog.view' => ['SA', 'OA', 'SM', 'CA', 'IM', 'PO'],
            'inventory.view' => ['SA', 'OA', 'SM', 'CA', 'IM', 'PO'],
            'inventory.adjust' => ['SA', 'OA', 'SM', 'IM'],
            'inventory.transfer' => ['SA', 'OA', 'SM', 'IM'],
            'inventory.count' => ['SA', 'OA', 'SM', 'IM'],
            'inventory.approve-oversell' => ['SA', 'OA', 'SM', 'IM'],
            'suppliers.view' => ['SA', 'OA', 'SM', 'IM', 'PO', 'AC'],
            'suppliers.manage' => ['SA', 'OA', 'PO'],
            'purchase.create' => ['SA', 'OA', 'SM', 'PO'],
            'purchase.approve' => ['SA', 'OA', 'SM'],
            'purchase.receive' => ['SA', 'OA', 'SM', 'IM', 'PO'],
            'purchase.pay' => ['SA', 'OA', 'PO', 'AC'],
            'pricing.manage' => ['SA', 'OA'],
            'promotions.manage' => ['SA', 'OA', 'SM'],
            'tax.manage' => ['SA', 'OA', 'AC'],
            'loyalty.manage' => ['SA', 'OA'],
            'customers.view' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'customers.manage' => ['SA', 'OA', 'SM', 'CA'],
            'pos.session' => ['SA', 'OA', 'SM', 'CA'],
            'sales.create' => ['SA', 'OA', 'SM', 'CA'],
            'sales.view' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'sales.void' => ['SA', 'OA', 'SM'],
            'sales.refund' => ['SA', 'OA', 'SM'],
            'sales.price-override' => ['SA', 'OA', 'SM'],
            'sales.discount' => ['SA', 'OA', 'SM', 'CA'],
            'sales.installments.create' => ['SA', 'OA', 'SM'],
            'sales.installments.collect' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'sales.installments.view' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'sales.installments.cancel' => ['SA', 'OA', 'SM'],
            'sales.installments.partial' => ['SA', 'OA', 'SM'],
            'invoices.view' => ['SA', 'OA', 'SM', 'AC'],
            'invoices.manage' => ['SA', 'OA', 'SM', 'AC'],
            'invoices.proforma.view' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'invoices.proforma.create' => ['SA', 'OA', 'SM'],
            'invoices.proforma.update' => ['SA', 'OA', 'SM'],
            'invoices.proforma.issue' => ['SA', 'OA', 'SM'],
            'invoices.proforma.convert' => ['SA', 'OA', 'SM'],
            'invoices.proforma.cancel' => ['SA', 'OA', 'SM'],
            'invoices.proforma.honor-prices' => ['SA', 'OA', 'SM'],
            'payments.manual' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'payments.stripe' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'payments.paystack' => ['SA', 'OA', 'SM', 'CA', 'AC'],
            'registers.reconcile' => ['SA', 'OA', 'SM', 'AC'],
            'audit.view' => ['SA', 'OA', 'SM', 'AC'],
            'settings.manage' => ['SA', 'OA'],
            'accounting.view' => ['SA', 'OA', 'AC'],
            'accounting.post' => ['SA', 'OA', 'AC'],
            'devices.manage' => ['SA', 'OA', 'SM'],
            'reports.view' => ['SA', 'OA', 'SM', 'IM', 'PO', 'AC'],
            'warehouses.view' => ['SA', 'OA', 'SM', 'IM', 'PO'],
            'warehouses.manage' => ['SA', 'OA'],
        ];

        $storeScoped = [
            'stores.view', 'stores.update', 'users.assign-stores',
            'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.count', 'inventory.approve-oversell',
            'purchase.create', 'purchase.receive', 'promotions.manage',
            'customers.view', 'customers.manage', 'pos.session',
            'sales.create', 'sales.view', 'sales.void', 'sales.refund', 'sales.price-override', 'sales.discount',
            'sales.installments.create', 'sales.installments.collect', 'sales.installments.view',
            'sales.installments.cancel', 'sales.installments.partial',
            'invoices.view', 'invoices.manage',
            'invoices.proforma.view', 'invoices.proforma.create', 'invoices.proforma.update',
            'invoices.proforma.issue', 'invoices.proforma.convert', 'invoices.proforma.cancel',
            'invoices.proforma.honor-prices',
            'payments.manual', 'payments.stripe', 'payments.paystack',
            'registers.reconcile', 'devices.manage', 'reports.view',
        ];

        $permissions = [];
        foreach ($rows as $name => $roleKeys) {
            $permissions[$name] = [
                'roles' => $roleKeys,
                'scope' => in_array($name, $storeScoped, true) ? 'store' : 'org',
            ];
        }

        return $permissions;
    }
}
