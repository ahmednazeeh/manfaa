<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\AppReleaseConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile release gates, editable without a deploy: per app (whatever
 * config/mobile.php declares) and per platform, the oldest build the API
 * keeps serving, the latest build available, and where to send someone who
 * must update.
 *
 * GET answers the EFFECTIVE set — override where one is saved, env default
 * otherwise. PUT takes the full set back, because these values only make
 * sense together: a latest below a minimum is an update prompt pointing at
 * a build the API refuses. The public /api/mobile/v1/config endpoint serves
 * whatever this saves, in the exact shape the apps already parse.
 */
class AppReleasesController extends Controller
{
    public function index(AppReleaseConfig $releases): JsonResponse
    {
        return response()->json(['data' => $releases->all()]);
    }

    public function update(Request $request, AppReleaseConfig $releases): JsonResponse
    {
        $rules = [];

        foreach (AppReleaseConfig::apps() as $app) {
            foreach (AppReleaseConfig::PLATFORMS as $platform) {
                $path = $app.'.'.$platform;

                $rules[$path] = ['required', 'array'];
                $rules[$path.'.minimum_build'] = ['required', 'integer', 'min:1'];
                // A latest below the minimum would prompt users toward a
                // build the API refuses to serve.
                $rules[$path.'.latest_build'] = ['required', 'integer', 'min:1', 'gte:'.$path.'.minimum_build'];
                $rules[$path.'.store_url'] = ['nullable', 'string', 'url', 'max:2048'];
            }
        }

        $validated = $request->validate($rules);

        // Rebuilt from OUR catalogue, never from the request shape: an app
        // or platform the config does not declare is silently absent, and
        // the value types are pinned before storage.
        $flags = [];

        foreach (AppReleaseConfig::apps() as $app) {
            foreach (AppReleaseConfig::PLATFORMS as $platform) {
                $fields = $validated[$app][$platform];
                $url = trim((string) ($fields['store_url'] ?? ''));

                $flags[$app][$platform] = [
                    'minimum_build' => (int) $fields['minimum_build'],
                    'latest_build' => (int) $fields['latest_build'],
                    'store_url' => $url === '' ? null : $url,
                ];
            }
        }

        $releases->put($flags, (int) $request->user('admin')->getKey());

        return response()->json(['data' => $releases->all()]);
    }
}
