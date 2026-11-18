<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Auth\Login;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    /** Every dashboard page, as [route name, params]. */
    public static function pages(): array
    {
        return [
            ['admin.dashboard', []], ['admin.users.index', []], ['admin.feedback', []],
            ['admin.hymns.index', []], ['admin.hymns.create', []], ['admin.devotionals.index', []],
            ['admin.sunday-school.index', []], ['admin.events', []], ['admin.branches', []], ['admin.pages', []], ['admin.bible', []],
            ['admin.payments', []], ['admin.subscriptions', []], ['admin.plans', []],
            ['admin.notifications', []], ['admin.app', []], ['admin.reports', []],
            ['admin.admins', []], ['admin.integrations', []], ['admin.operations', []], ['admin.audit', []],
        ];
    }

    public function test_every_page_renders_for_a_super_admin(): void
    {
        $admin = User::factory()->withRole('super_admin')->create();

        foreach (self::pages() as [$route, $params]) {
            $this->actingAs($admin)->get(route($route, $params))->assertOk();
        }
    }

    public function test_guests_are_redirected_to_login_and_members_are_refused(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertSee('Welcome back');

        $member = $this->member();
        $this->actingAs($member)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_role_permissions_are_enforced_per_page(): void
    {
        $editor = User::factory()->withRole('content_editor')->create();
        $this->actingAs($editor)->get(route('admin.hymns.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.payments'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.admins'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.users.index'))->assertForbidden();

        $finance = User::factory()->withRole('finance')->create();
        $this->actingAs($finance)->get(route('admin.payments'))->assertOk();
        $this->actingAs($finance)->get(route('admin.reports'))->assertOk();
        $this->actingAs($finance)->get(route('admin.hymns.index'))->assertForbidden();
        $this->actingAs($finance)->get(route('admin.notifications'))->assertForbidden();

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('admin.integrations'))->assertOk();
        $this->actingAs($admin)->get(route('admin.admins'))->assertForbidden(); // super admin only
    }

    public function test_navigation_only_shows_permitted_sections(): void
    {
        $finance = User::factory()->withRole('finance')->create();

        $this->actingAs($finance)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Payments')->assertDontSee('Hymnal')->assertDontSee('Admins &amp; roles', false);
    }

    public function test_login_requires_an_admin_role_and_is_rate_limited(): void
    {
        User::factory()->withRole('member')->create(['email' => 'member@example.com']);
        $admin = User::factory()->withRole('admin')->create(['email' => 'admin@uec.org.ng']);

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'Password123')->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test(Login::class)->set('email', 'ADMIN@uec.org.ng')->set('password', 'Password123')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login', 'actor_id' => $admin->id]);

        auth()->logout();
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'admin@uec.org.ng')->set('password', 'wrong-password')->call('login');
        }
        Livewire::test(Login::class)->set('email', 'admin@uec.org.ng')->set('password', 'Password123')->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspended_admin_is_signed_out(): void
    {
        $admin = User::factory()->withRole('admin')->suspended()->create();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_responses_have_security_headers(): void
    {
        $this->get(route('admin.login'))->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
