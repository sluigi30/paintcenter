<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory, TracksActivity;

    public function activityTitle(): string
    {
        return $this->category_name ?? 'Category #' . $this->getKey();
    }


    protected $fillable = [
        'category_name',
        'is_archived',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}