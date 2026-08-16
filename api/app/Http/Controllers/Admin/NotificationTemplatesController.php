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
            // An SMS is billed per 160 characters (fewer once Thaana pushes
            // it to unicode), so the ceiling is a real cost control, not a
            // column width.
            'body_en' => ['sometimes', 'string', 'min:1', 'max:480'],
            'body_dv' => ['sometimes', 'nullable', 'string', 'max:480'],
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
            'body_en' => $template->body_en,
            'body_dv' => $template->body_dv,
            'active' => $template->active,
            /**
             * Which body actually goes out. Customers carry no language
             * preference yet, so a written Dhivehi body wins and English is
             * the fallback — stated here so the screen can say so rather
             * than leaving an admin to guess which one they are editing.
             */
            'sends' => $template->sendsDhivehi() ? 'dv' : 'en',
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
