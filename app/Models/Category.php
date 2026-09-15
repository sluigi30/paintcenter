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

    /** A product can sit under several headings — see the category_product pivot. */
    public function products()
    {
        return $this->belongsToMany(Product::class);
    }
}