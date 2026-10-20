<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Self-service password change (shell spec §2 "Change password rules",
 * port of changePasswordAction + changeUserPassword).
 *
 * Validation order is exact: required → passwordSame → passwordMismatch →
 * current-password match → strength rules → save → audit with redacted hashes.
 * Field errors are i18n KEYS (the view translates them under validation.*).
 */
class ChangePasswordTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'CorrectHorseBattery9';

    private const NEW_PASSWORD = 'UltraSecurePassphrase42';

    private function submit(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->createUser(['email' => 'me@clinic.test']))
            ->post('/change-password', $data);
    }

    public function test_a_valid_change_updates_the_password_and_redirects_to_the_dashboard(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test']);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user)->assertRedirect(route('dashboard'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $fresh->password));
    }

    public function test_the_new_hash_is_argon2id(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test']);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user);

        $this->assertStringStartsWith('$argon2id$', $user->fresh()->password);
    }

    public function test_a_successful_change_clears_the_must_change_password_flag(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test', 'must_change_password' => true]);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user)->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_all_three_fields_are_required(): void
    {
        $this->submit([])->assertSessionHasErrors([
            'currentPassword' => 'required',
            'newPassword' => 'required',
            'confirmPassword' => 'required',
        ]);
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::PASSWORD,
            'confirmPassword' => self::PASSWORD,
        ])->assertSessionHasErrors(['newPassword' => 'passwordSame']);
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => 'SomethingElseEntirely99',
        ])->assertSessionHasErrors(['confirmPassword' => 'passwordMismatch']);
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $this->submit([
            'currentPassword' => 'not-my-password',
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['currentPassword' => 'currentPasswordWrong']);
    }

    public function test_a_too_short_password_is_rejected(): void
    {
        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'short12',
            'confirmPassword' => 'short12',
        ])->assertSessionHasErrors(['newPassword' => 'passwordTooShort']);
    }

    public function test_a_common_password_is_rejected(): void
    {
        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'password123',
            'confirmPassword' => 'password123',
        ])->assertSessionHasErrors(['newPassword' => 'passwordCommon']);
    }

    public function test_a_password_containing_the_email_local_part_is_rejected(): void
    {
        $user = $this->createUser(['email' => 'abebe@clinic.test']);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'my-abebe-passphrase',
            'confirmPassword' => 'my-abebe-passphrase',
        ], $user)->assertSessionHasErrors(['newPassword' => 'passwordPersonal']);
    }

    public function test_a_password_containing_the_name_is_rejected(): void
    {
        $user = $this->createUser(['email' => 'someone@clinic.test', 'name' => 'Abebech']);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'Abebech-Secret-Pass',
            'confirmPassword' => 'Abebech-Secret-Pass',
        ], $user)->assertSessionHasErrors(['newPassword' => 'passwordPersonal']);
    }

    public function test_a_failed_change_never_touches_the_stored_password(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test']);
        $before = $user->password;

        $this->submit([
            'currentPassword' => 'wrong',
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user);

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_the_audit_row_redacts_both_password_hash_halves(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test']);

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user);

        $row = AuditLog::where('action', 'PASSWORD_CHANGED')->sole();
        $this->assertSame('User', $row->entity);
        $this->assertSame($user->id, $row->entity_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(['passwordHash' => '[REDACTED]'], $row->old_json);
        $this->assertSame(['passwordHash' => '[REDACTED]'], $row->new_json);
    }

    public function test_the_real_hashes_never_appear_in_the_audit_row(): void
    {
        $user = $this->createUser(['email' => 'me@clinic.test']);
        $oldHash = $user->password;

        $this->submit([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ], $user);

        $row = AuditLog::where('action', 'PASSWORD_CHANGED')->sole();
        $this->assertStringNotContainsString((string) $oldHash, (string) json_encode($row->old_json));
        $this->assertStringNotContainsString((string) $oldHash, (string) json_encode($row->new_json));
    }

    public function test_the_password_change_requires_a_session(): void
    {
        $this->post('/change-password', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ])->assertRedirect(login_path());

        $this->assertGuest();
    }

    public function test_the_change_password_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->createUser())
            ->get('/change-password')
            ->assertOk()
            ->assertSee(__('changePassword.title'));
    }

    public function test_a_wrong_current_password_keeps_no_new_password_in_the_old_input(): void
    {
        $this->submit([
            'currentPassword' => 'wrong',
            'newPassword' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['currentPassword']);

        $this->assertSame(self::NEW_PASSWORD, session('_old_input.newPassword'));
        $this->assertNull(session('_old_input.password'));
    }
}
