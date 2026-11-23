<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\TeamMemberMail;
use App\Models\User;
use App\Services\InviteService;
use App\Services\NotificationLogger;
use App\Support\Permissions;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The company owner's team: who can sign in to this company and what each person may do. Only the owner
 * reaches it, and only for people of their own company.
 */
class TeamController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationLogger $logger,
        private readonly InviteService $invites,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->owner($request);

        return $this->success([
            'members' => $this->members($request)->map(fn (User $member) => $this->present($member))->values(),
            'catalogue' => [
                'modules' => Permissions::modules(),
                'presets' => Permissions::presetsFor($request->user()->company),
                // Roles this company changed from the standard, so the screen can offer to go back.
                'customized' => Permissions::customizedFor($request->user()->company),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            // The role the owner picked to start from. Ticking away from it does not rename it.
            'team_role' => ['nullable', 'string', Rule::in(Permissions::presetKeys())],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $permissions = Permissions::normalize($data['permissions']);

        $member = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            // No password until the member chooses one through the emailed link.
            'password' => null,
            'role' => UserRole::Staff,
            'company_id' => $owner->company_id,
            'status' => UserStatus::Active,
            'permissions' => $permissions,
            'team_role' => $data['team_role'] ?? Permissions::roleFor($permissions, $owner->company),
        ]);

        $emailError = $this->sendLogin($member, created: true);

        return $this->success(
            $this->present($member) + ['email_sent' => $emailError === null, 'email_error' => $emailError],
            __('Team member created.'),
            201,
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->owner($request);
        $member = $this->member($request, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'team_role' => ['sometimes', 'nullable', 'string', Rule::in(Permissions::presetKeys())],
            'permissions' => ['sometimes', 'required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        if (isset($data['permissions'])) {
            $data['permissions'] = Permissions::normalize($data['permissions']);
            $data['team_role'] = ($data['team_role'] ?? null) ?: Permissions::roleFor($data['permissions'], $request->user()->company);
        }

        $member->update($data);

        return $this->success($this->present($member->fresh()), __('Team member saved.'));
    }

    /** Keeps the owner's ticks as this company's own version of a role. New members start from it; existing ones are not changed. */
    public function saveRole(Request $request, string $role): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless(in_array($role, Permissions::presetKeys(), true), 404);

        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $company = $owner->company;
        $company->update(['role_presets' => array_merge((array) $company->role_presets, [$role => Permissions::normalize($data['permissions'])])]);

        return $this->roles($company->fresh(), __('Role saved.'));
    }

    /** Back to the standard permissions for a role. */
    public function resetRole(Request $request, string $role): JsonResponse
    {
        $owner = $this->owner($request);
        abort_unless(in_array($role, Permissions::presetKeys(), true), 404);

        $company = $owner->company;
        $presets = (array) $company->role_presets;
        unset($presets[$role]);
        $company->update(['role_presets' => $presets === [] ? null : $presets]);

        return $this->roles($company->fresh(), __('Role restored to the standard permissions.'));
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $this->owner($request);
        $member = $this->member($request, $user);

        $data = $request->validate(['status' => ['required', Rule::in(UserStatus::values())]]);

        $member->update(['status' => $data['status']]);

        // A member who is switched off is signed out at once, not at their next sign-in.
        if ($member->status === UserStatus::Disabled) {
            $member->tokens()->delete();
            $this->forgetLinks($member);
        }

        return $this->success(
            $this->present($member->fresh()),
            $member->status === UserStatus::Disabled ? __('Team member disabled.') : __('Team member enabled.'),
        );
    }

    /**
     * The member's password is renewed: the old one stops working at once, whatever they were signed in on is
     * closed, and a link to choose a new one is emailed to them. The owner never sees or sets the password.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->owner($request);
        $member = $this->member($request, $user);

        $member->forceFill(['password' => null])->save();
        $member->tokens()->delete();

        $emailError = $this->sendLogin($member, created: false);

        return $this->success(
            $this->present($member->fresh()) + ['email_sent' => $emailError === null, 'email_error' => $emailError],
            __('A link to choose a new password was emailed.'),
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->owner($request);
        $member = $this->member($request, $user);

        $member->tokens()->delete();
        // Links are kept by email: without this, an old one would still work for a new account made on that address.
        $this->forgetLinks($member);
        $member->delete();

        return $this->success([], __('Team member removed.'));
    }

    private function roles(\App\Models\Company $company, string $message): JsonResponse
    {
        return $this->success([
            'presets' => Permissions::presetsFor($company),
            'customized' => Permissions::customizedFor($company),
        ], $message);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->isOwner() && $user->company_id !== null, 403, __('Only the company owner can manage the team.'));

        return $user;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    private function members(Request $request)
    {
        return User::query()
            ->where('company_id', $request->user()->company_id)
            ->where('role', UserRole::Staff->value)
            ->orderBy('name')
            ->get();
    }

    /** A member of this company, and never the owner or anyone from another company. */
    private function member(Request $request, User $user): User
    {
        abort_unless(
            (int) $user->company_id === (int) $request->user()->company_id && $user->role === UserRole::Staff,
            404,
        );

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(User $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'status' => $member->status->value,
            'team_role' => $member->team_role ?? Permissions::roleFor($member->permissionList()),
            'permissions' => $member->permissionList(),
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            // False until the member has used their link: the screen shows them as invited.
            'password_set' => $member->password !== null,
        ];
    }

    /** Any set-password or reset link already sent to this member stops working. */
    private function forgetLinks(User $member): void
    {
        Password::broker('invites')->deleteToken($member);
        Password::broker()->deleteToken($member);
    }

    /** Emails the member a link to choose their password. A failure is reported back, never raised: the member exists either way. */
    private function sendLogin(User $member, bool $created): ?string
    {
        $error = null;

        try {
            $url = $this->invites->urlFor($member);
            Mail::to($member->email)->locale($member->preferredLocale())->sendNow(new TeamMemberMail($member, $url, $created));
        } catch (Throwable $e) {
            // The mail server's own words (host, login name, codes) stay on the server: log and notification log.
            $error = $e->getMessage();
            Log::error('Team member email failed: '.$error, ['exception' => $e]);
        }

        $this->logger->log(
            NotificationChannel::Email,
            $created ? 'team_member_created' : 'team_member_password',
            $member->email,
            $error === null ? NotificationStatus::Sent : NotificationStatus::Failed,
            ['company_id' => $member->company_id, 'user_id' => $member->id, 'error' => $error],
        );

        return $error === null ? null : __('The email could not be sent. Please try again in a few minutes.');
    }
}
