<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin editing of what the platform says to customers.
 *
 * No create and no delete: the rows mirror NotificationTemplateKey exactly,
 * because a template nothing fires would be words nobody ever reads and a
 * moment with no row would be a message that cannot be edited. The admin
 * owns the sentence and the switch; code owns the list of moments.
 *
 * Every response carries the variables each template may use, read from the
 * enum rather than from the body — so the screen lists what is ACTUALLY
 * substituted at send time, not what someone once typed.
 */
class NotificationTemplatesController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = NotificationTemplate::query()
            ->with('editor:id,name')
            ->orderBy('key')
            ->get();

        return response()->json([
            'data' => $templates->map($this->present(...))->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var NotificationTemplate $template */
        $template = NotificationTemplate::query()->findOrFail($id);

        $validated = $request->validate([
            // An SMS is billed per 160 characters, so the ceiling is a real
            // cost control, not a column width. Only the English body is
            // editable: every notification sends English by decision
            // (2026-08-17) — body_dv still exists as a column but is neither
            // accepted nor read.
            'body_en' => ['sometimes', 'string', 'min:1', 'max:480'],
            'active' => ['sometimes', 'boolean'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $template->fill($validated);
        $template->updated_by = $admin->id;
        $template->save();

        NotificationService::forget($template->key);

        return response()->json([
            'data' => $this->present($template->refresh()->load('editor:id,name')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationTemplate $template): array
    {
        $key = $template->key;

        return [
            'id' => $template->id,
            'key' => $key->value,
            'label' => $key->label(),
            'description' => $key->description(),
            // The panel hides these while the marketplace is off — nothing
            // can send them, and a screen full of dead moments misleads.
            'marketplace_only' => $key->isMarketplace(),
            'body_en' => $template->body_en,
            'active' => $template->active,
            'variables' => collect($key->variables())
                ->map(fn (string $description, string $token): array => [
                    'token' => $token,
                    'description' => $description,
                ])
                ->values(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'updated_by' => $template->editor?->name,
        ];
    }
}
