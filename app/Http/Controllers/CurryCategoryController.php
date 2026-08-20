<?php

namespace App\Http\Controllers;

use App\Models\CurryCategory;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CurryCategoryController extends Controller
{
    public function index()
    {
        return CurryCategory::query()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = CurryCategory::create([
            'name' => $data['name'],
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $auditService->record($request->user(), 'curry_category_created', $category, $category->toArray());

        return response()->json($category, 201);
    }

    public function update(Request $request, CurryCategory $curry_category, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = $curry_category->only(['name', 'display_order', 'is_active']);
        $curry_category->fill($data);
        $curry_category->save();
        $auditService->record($request->user(), 'curry_category_updated', $curry_category, [
            'before' => $before,
            'after' => $curry_category->only(['name', 'display_order', 'is_active']),
        ]);

        return response()->json($curry_category);
    }
}
