<?php

namespace App\Livewire\Admin\Engagement;

use App\Enums\PushCampaignStatus;
use App\Integrations\Firebase\FcmClient;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\NotificationLog;
use App\Models\PushCampaign;
use App\Models\User;
use App\Services\Notifications\PushCampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Push notifications')]
class Notifications extends Component
{
    use AdminComponent, WithPagination;

    #[Url]
    public string $tab = 'campaigns';

    public bool $showForm = false;

    public string $title = '';

    public string $body = '';

    public string $route = '/tabs/home';

    public string $type = 'announcement';

    public string $audience = 'all';

    public string $platform = 'android';

    public string $appVersion = '';

    public string $userEmails = '';

    public string $sendMode = 'now';

    public string $scheduledAt = '';

    #[Url(except: '')]
    public string $logChannel = '';

    #[Url(except: '')]
    public string $logStatus = '';

    public function mount(): void
    {
        $this->authorizeAbility('notifications.view');
    }

    public function create(): void
    {
        $this->authorizeAbility('notifications.send');
        $this->resetValidation();
        $this->reset('title', 'body', 'appVersion', 'userEmails', 'scheduledAt');
        $this->fill(['route' => '/tabs/home', 'type' => 'announcement', 'audience' => 'all', 'sendMode' => 'now', 'showForm' => true]);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'route' => ['nullable', 'string', 'max:255', function ($a, $v, $fail) {
                if (! PushCampaignService::isAllowedRoute($v)) {
                    $fail('Use an in-app path such as /events or /devotional.');
                }
            }],
            'type' => ['required', 'string', 'max:40', 'alpha_dash'],
            'audience' => ['required', Rule::in(['all', 'platform', 'version', 'users'])],
            'platform' => ['exclude_unless:audience,platform', 'required', Rule::in(['android', 'ios'])],
            'appVersion' => ['exclude_unless:audience,version', 'required', 'string', 'max:20'],
            'userEmails' => ['exclude_unless:audience,users', 'required', 'string', 'max:20000'],
            'sendMode' => ['required', Rule::in(['now', 'later'])],
            'scheduledAt' => ['exclude_unless:sendMode,later', 'required', 'date', 'after:now'],
        ];
    }

    /** @return array{audience: string, filters: array} */
    private function audienceDefinition(): array
    {
        $filters = match ($this->audience) {
            'platform' => ['platform' => $this->platform],
            'version' => ['app_version' => trim($this->appVersion)],
            'users' => ['user_ids' => $this->resolveUserIds()],
            default => [],
        };

        return ['audience' => $this->audience, 'filters' => $filters];
    }

    /** @return list<string> */
    private function resolveUserIds(): array
    {
        $identifiers = collect(preg_split('/[\s,;]+/', mb_strtolower($this->userEmails)) ?: [])->filter()->unique()->take(1000);
        if ($identifiers->isEmpty()) {
            return [];
        }

        return User::query()->where(fn ($q) => $q->whereIn('email', $identifiers)->orWhereIn('phone', $identifiers)->orWhereIn('id', $identifiers))->pluck('id')->all();
    }

    #[Computed]
    public function estimate(): int
    {
        if (! $this->showForm) {
            return 0;
        }

        return app(PushCampaignService::class)->estimate(new PushCampaign($this->audienceDefinition()));
    }

    public function send(PushCampaignService $push): void
    {
        $this->authorizeAbility('notifications.send');
        $this->validate($this->rules());
        $definition = $this->audienceDefinition();
        if ($this->audience === 'users' && $definition['filters']['user_ids'] === []) {
            $this->addError('userEmails', 'None of these emails or phone numbers match a member.');

            return;
        }

        $later = $this->sendMode === 'later';
        $campaign = PushCampaign::query()->create([
            'title' => trim($this->title),
            'body' => trim($this->body),
            'route' => $this->route ?: null,
            'type' => $this->type,
            'audience' => $definition['audience'],
            'filters' => $definition['filters'],
            'status' => $later ? PushCampaignStatus::Scheduled : PushCampaignStatus::Draft,
            'scheduled_at' => $later ? Carbon::parse($this->scheduledAt, config('uec.timezone'))->utc() : null,
            'created_by' => auth()->id(),
            'target_count' => $push->estimate(new PushCampaign($definition)),
        ]);

        if (! $later) {
            $push->dispatch($campaign);
        }

        $this->audit($later ? 'push.scheduled' : 'push.sent', $campaign, $campaign->title, ['audience' => $campaign->audience, 'target' => $campaign->target_count]);
        $this->showForm = false;
        $this->toast($later ? 'Notification scheduled.' : 'Notification is being sent.');
    }

    public function cancel(string $id): void
    {
        $this->authorizeAbility('notifications.send');
        $campaign = PushCampaign::query()->where('status', PushCampaignStatus::Scheduled->value)->findOrFail($id);
        $campaign->forceFill(['status' => PushCampaignStatus::Cancelled])->save();
        $this->audit('push.cancelled', $campaign, $campaign->title);
        $this->toast('Scheduled notification cancelled.');
    }

    public function render(FcmClient $fcm)
    {
        return view('livewire.admin.engagement.notifications', [
            'campaigns' => $this->tab === 'campaigns' ? PushCampaign::query()->with('creator')->latest()->paginate(20) : null,
            'logs' => $this->tab === 'log' ? NotificationLog::query()->with('user')
                ->when($this->logChannel !== '', fn ($q) => $q->where('channel', $this->logChannel))
                ->when($this->logStatus !== '', fn ($q) => $q->where('status', $this->logStatus))
                ->latest('id')->paginate(30) : null,
            'fcmReady' => $fcm->isConfigured(),
            'routes' => config('uec.push.allowed_route_prefixes'),
        ]);
    }
}
