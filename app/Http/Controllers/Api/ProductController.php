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
            // Matches the product name OR the manufacturer color code/name —
            // paint customers search by "888" or "burnt sienna" as often as
            // by product name. Dashes are normalized the same way codes are
            // stored, so "B-1408" matches however it was typed.
            $search = Product::normalizeColorCode($request->search) ?? $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('color_code', 'like', "%{$search}%")
                  ->orWhere('color_name', 'like', "%{$search}%");
            });
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
        // Catalog brand grid: only brands with something to sell right now,
        // counting purchasable products (not archived/sold-out ones)
        $brands = \App\Models\Brand::where('is_archived', false)
            ->whereHas('products', fn ($q) => $q->purchasable())
            ->withCount(['products' => fn ($q) => $q->purchasable()])
            ->orderBy('brand_name')
            ->get();

        return response()->json($brands);
    }

    public function categories()
    {
        $categories = \App\Models\Category::where('is_archived', false)->withCount('products')->get();
        return response()->json($categories);
    }

    /**
     * Category filter chips for one brand's product list — each with the
     * count of that brand's purchasable products in the category.
     */
    public function brandCategories(\App\Models\Brand $brand)
    {
        $categories = \App\Models\Category::where('is_archived', false)
            ->whereHas('products', fn ($q) => $q->purchasable()->where('brand_id', $brand->id))
            ->withCount(['products' => fn ($q) => $q->purchasable()->where('brand_id', $brand->id)])
            ->orderBy('category_name')
            ->get();

        return response()->json($categories);
    }
}
