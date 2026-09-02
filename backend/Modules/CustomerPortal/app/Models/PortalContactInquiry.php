<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalContactInquiry extends Model
{
    protected $fillable = [
        'organization_id',
        'customer_account_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
