<?php

namespace Modules\Purchasing\Enums;

enum SupplierInvoiceStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
}
