<?php

namespace App\Http\Controllers;

use App\Models\CurryItem;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CurryItemController extends Controller
{
    public function index()
    {
        return CurryItem::query()
            ->with('category:id,name')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'current_price_kyat' => ['required', 'integer', 'min:0'],
            'curry_category_id' => ['nullable', 'integer', 'exists:curry_categories,id'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $item = CurryItem::create([
            'name' => $data['name'],
            'current_price_kyat' => $data['current_price_kyat'],
            'curry_category_id' => $data['curry_category_id'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'is_available' => $data['is_available'] ?? true,
        ]);
        $auditService->record($request->user(), 'curry_item_created', $item, $item->toArray());

        return response()->json($item->load('category:id,name'), 201);
    }

    public function update(Request $request, CurryItem $curry_item, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'current_price_kyat' => ['required', 'integer', 'min:0'],
            'curry_category_id' => ['nullable', 'integer', 'exists:curry_categories,id'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $before = $curry_item->only(['name', 'current_price_kyat', 'curry_category_id', 'display_order', 'is_available']);
        $curry_item->fill($data);
        $curry_item->save();
        $auditService->record($request->user(), 'curry_item_updated', $curry_item, [
            'before' => $before,
            'after' => $curry_item->only(['name', 'current_price_kyat', 'curry_category_id', 'display_order', 'is_available']),
        ]);

        return response()->json($curry_item->load('category:id,name'));
    }

    public function archive(Request $request, CurryItem $curry_item, AuditService $auditService)
    {
        $curry_item->is_archived = true;
        $curry_item->save();
        $auditService->record($request->user(), 'curry_item_archived', $curry_item);

        return response()->json($curry_item);
    }
}
