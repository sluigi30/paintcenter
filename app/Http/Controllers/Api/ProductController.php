<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Products are listed once each; their purchasable sizes come along
     * in a `variants` array (each with its own price + stock). The
     * top-level `price` is the lowest variant price ("from ₱…") and
     * `stock` is the total across sizes — both computed on the model.
     */
    public function index(Request $request)
    {
        $query = Product::with(['brand', 'category', 'activeVariants'])
            ->purchasable();   // active product with at least one in-stock size

        if ($request->has('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Price filters match if ANY active size falls in the range
        if ($request->has('min_price')) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_archived', false)
                ->where('price', '>=', $request->min_price));
        }

        if ($request->has('max_price')) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_archived', false)
                ->where('price', '<=', $request->max_price));
        }

        $products = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($products);
    }

    public function show(Product $product)
    {
        // Block archived products; out-of-stock sizes are still listed so
        // the app can show them as disabled options.
        if ($product->is_archived || $product->variants()->where('is_archived', false)->count() === 0) {
            return response()->json(['message' => 'Product not available.'], 404);
        }

        $product->load(['brand', 'category', 'activeVariants']);
        return response()->json($product);
    }

    public function brands()
    {
        $brands = \App\Models\Brand::where('is_archived', false)->withCount('products')->get();
        return response()->json($brands);
    }

    public function categories()
    {
        $categories = \App\Models\Category::where('is_archived', false)->withCount('products')->get();
        return response()->json($categories);
    }
}
