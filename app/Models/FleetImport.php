<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * État d'un import de flotte différé (ST-0702).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $filename
 * @property string|null $storage_ref
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $imported
 * @property int $skipped
 * @property int $failed
 * @property array<int, array<string, mixed>>|null $errors
 * @property string $status
 * @property Carbon|null $created_at
 */
class FleetImport extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
