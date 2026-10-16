<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class CustomerProfilePhoto extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'customer_id', 'file_path', 'version', 'width', 'height', 'size_bytes'];

    protected $hidden = ['file_path'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer'];

    public function url(string $realm = 'erp'): string
    {
        return route(($realm === 'dhiran' ? 'dhiran.borrowers' : 'customers').'.photo.show', [
            'customer' => $this->customer_id, 'version' => $this->version,
        ]);
    }
}
