<?php

namespace App\Http\Controllers;

use App\Models\CurryItem;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CurryItemController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $search = trim((string) $request->query('q', ''));
        $perPage = min(50, max(10, $request->integer('per_page', 25)));

        return CurryItem::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function store(Request $request, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'current_price_kyat' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $item = CurryItem::create([
            'name' => $data['name'],
            'current_price_kyat' => $data['current_price_kyat'],
            'display_order' => $data['display_order'] ?? 0,
            'is_available' => $data['is_available'] ?? true,
        ]);
        $auditService->record($request->user(), 'curry_item_created', $item, $item->toArray());

        return response()->json($item, 201);
    }

    public function update(Request $request, CurryItem $curry_item, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'current_price_kyat' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $before = $curry_item->only(['name', 'current_price_kyat', 'display_order', 'is_available']);
        $curry_item->fill($data);
        $curry_item->save();
        $auditService->record($request->user(), 'curry_item_updated', $curry_item, [
            'before' => $before,
            'after' => $curry_item->only(['name', 'current_price_kyat', 'display_order', 'is_available']),
        ]);

        return response()->json($curry_item);
    }

    public function archive(Request $request, CurryItem $curry_item, AuditService $auditService)
    {
        $curry_item->is_archived = true;
        $curry_item->save();
        $auditService->record($request->user(), 'curry_item_archived', $curry_item);

        return response()->json($curry_item);
    }
}
