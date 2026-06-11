<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'product_key',
    'price',
])]
class ProductPrice extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'product_key';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }
}
