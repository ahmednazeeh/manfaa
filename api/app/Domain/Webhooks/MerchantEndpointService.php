<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Jobs\SendWebhook;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Webhook endpoints a MERCHANT owns (owner, 2026-08-22).
 *
 * One place for the rules, because two doors lead here: the merchant panel
 * (Settings › API access) and `/v1/webhooks` (a credential registering its
 * own endpoint, so a plugin needs no manual setup). Both must agree on the
 * cap, the URL guard and the secret, or the two doors would drift.
 *
 * Registration returns the plaintext signing secret EXACTLY ONCE, inside
 * {@see IssuedEndpoint}; only the encrypted form is stored, and there is no
 * retrieval path. Losing it means registering again.
 */
final class MerchantEndpointService
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    /**
     * @param  list<string>  $events  already validated against WebhookEvents::all()
     *
     * @throws EndpointCapReachedException
     */
    public function register(
        Merchant $merchant,
        string $url,
        array $events,
        ?string $label,
        ?MerchantUser $by = null,
        ?ApiCredential $credential = null,
    ): IssuedEndpoint {
        $live = WebhookEndpoint::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('active', true)
            ->count();

        if ($live >= WebhookEndpoint::MAX_PER_MERCHANT) {
            throw EndpointCapReachedException::at(WebhookEndpoint::MAX_PER_MERCHANT);
        }

        $secret = 'whsec_'.Str::random(48);

        $endpoint = WebhookEndpoint::query()->create([
            'merchant_id' => $merchant->getKey(),
            'api_credential_id' => $credential?->getKey(),
            'url' => $url,
            'label' => $label === null || trim($label) === '' ? null : trim($label),
            'events' => array_values(array_unique($events)),
            'secret' => $secret,
            'active' => true,
            'created_by_merchant_user_id' => $by?->getKey(),
        ]);

        return new IssuedEndpoint($endpoint, $secret);
    }

    /**
     * The merchant's endpoints, newest first, each with its latest delivery so
     * the panel can say "last heard from: 2 minutes ago — 200".
     *
     * @return Collection<int, WebhookEndpoint>
     */
    public function list(Merchant $merchant)
    {
        return WebhookEndpoint::query()
            ->where('merchant_id', $merchant->getKey())
            ->with(['deliveries' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('id')
            ->get();
    }

    /** Hard delete; delivery history cascades (operational telemetry, not money). */
    public function remove(WebhookEndpoint $endpoint): void
    {
        $endpoint->delete();
    }

    /**
     * Switch off every endpoint a credential registered — called when that
     * credential is revoked. Panel-made endpoints have no credential and are
     * untouched; a merchant who revokes a plugin's token should not lose the
     * URL they typed in by hand.
     */
    public function deactivateForCredential(ApiCredential $credential): int
    {
        return WebhookEndpoint::query()
            ->where('api_credential_id', $credential->getKey())
            ->where('active', true)
            ->update(['active' => false, 'updated_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * Send a `webhook.test` to ONE endpoint, regardless of its subscriptions,
     * so a merchant can prove the URL and the signature before anything real
     * happens. Same job, same headers, same signature as a real event — the
     * receiver cannot tell the difference except by the event name.
     */
    public function sendTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => WebhookEvents::TEST,
            'payload' => $this->dispatcher->envelope(WebhookEvents::TEST, [
                'merchant_id' => (int) $endpoint->merchant_id,
                'endpoint_id' => (int) $endpoint->getKey(),
                'message' => 'This is a test delivery from Manfaa. If you can verify the signature, you are set up.',
            ]),
            'attempt' => 0,
            'status' => 'pending',
        ]);

        SendWebhook::dispatch($delivery->getKey());

        return $delivery;
    }
}
