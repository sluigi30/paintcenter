<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    //test comment
    public function index(Request $request)
    {
        $query = Product::with(['brand', 'category'])
            ->where('stock', '>', 0)          // hide out-of-stock
            ->where('is_archived', false);    // hide archived

        if ($request->has('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $products = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($products);
    }

    public function show(Product $product)
    {
        // Block direct access to archived or out-of-stock products
        if ($product->is_archived || $product->stock === 0) {
            return response()->json(['message' => 'Product not available.'], 404);
        }

        $product->load(['brand', 'category']);
        return response()->json($product);
    }

    public function brands()
    {
        $brands = \App\Models\Brand::withCount('products')->get();
        return response()->json($brands);
    }

    public function categories()
    {
        $categories = \App\Models\Category::withCount('products')->get();
        return response()->json($categories);
    }
}