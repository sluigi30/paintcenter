<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory, TracksActivity;

    public function activityTitle(): string
    {
        return $this->brand_name ?? 'Brand #' . $this->getKey();
    }


    protected $fillable = [
        'brand_name',
        'image',
        'is_archived',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}