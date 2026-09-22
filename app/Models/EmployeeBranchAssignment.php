<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBranchAssignment extends Model
{
    protected $fillable = [
        'employee_id', 'from_branch_id', 'to_branch_id', 'changed_by', 'reason', 'changed_at',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'changed_by' => 'integer',
        'changed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
