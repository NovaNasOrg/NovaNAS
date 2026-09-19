<?php

namespace App\Models;

use Database\Factories\DesktopSharedFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $samba_name
 * @property string|null $path
 * @property string $type
 */
class DesktopSharedFolder extends Model
{
    /** @use HasFactory<DesktopSharedFolderFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'samba_name',
        'path',
        'type',
    ];
}
