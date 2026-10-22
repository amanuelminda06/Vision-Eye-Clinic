<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Roles;
use App\Support\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The doctor picture: the ERP upload, the login-gated read routes and the
 * public doctor card.
 *
 * Uploading writes a `staff-<uuid>.<ext>` blob and stores `/uploads/<key>` in
 * `staff.photo_url`. The public route must accept that prefix too — the legacy
 * rows carried `/api/uploads/…`, and the narrow public route used to 404 every
 * picture the ERP itself had stored.
 */
class StaffPhotoTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => Roles::OWNER]);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doctor.png', $this->pngBytes());
    }

    private function pngBytes(): string
    {
        return hex2bin('89504e470d0a1a0a').str_repeat("\x00", 64);
    }

    /** Stores a real blob and returns its key. */
    private function storeBlob(): string
    {
        $key = 'staff-'.Str::uuid().'.png';
        Storage::put($key, $this->pngBytes());

        return $key;
    }

    public function test_an_owner_uploads_a_doctor_photo(): void
    {
        $this->actingAs($this->owner())->post('/staff', [
            'fullName' => 'Dr. Abebe Kebede',
            'jobTitle' => 'Ophthalmologist',
            'specialty' => 'Ophthalmology',
            'isDoctor' => 'true',
            'showOnWebsite' => 'true',
            'photo' => $this->png(),
        ])->assertRedirect('/staff');

        $staff = Staff::sole();

        $this->assertStringStartsWith('/uploads/staff-', (string) $staff->photo_url);
        $this->assertSame(1, StoredFile::count());
        $this->assertNotNull(Storage::get((string) Storage::keyFromUrl($staff->photo_url)));

        $log = AuditLog::where('action', 'STAFF_CREATED')->sole();
        $this->assertSame($staff->photo_url, $log->new_json['photoUrl']);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->actingAs($this->owner())->post('/staff', [
            'fullName' => 'Dr. Abebe Kebede',
            'jobTitle' => 'Ophthalmologist',
            'photo' => UploadedFile::fake()->createWithContent('doctor.txt', str_repeat('a', 50)),
        ])->assertSessionHasErrors('photo');

        $this->assertSame(0, Staff::count());
        $this->assertSame(0, StoredFile::count());
    }

    public function test_replacing_the_photo_deletes_the_previous_blob(): void
    {
        $oldKey = $this->storeBlob();
        $staff = Staff::factory()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'job_title' => 'Ophthalmologist',
            'photo_url' => '/uploads/'.$oldKey,
        ]);

        $this->actingAs($this->owner())->post("/staff/{$staff->id}", [
            'fullName' => 'Dr. Abebe Kebede',
            'jobTitle' => 'Ophthalmologist',
            'isDoctor' => 'true',
            'photo' => $this->png(),
        ])->assertRedirect('/staff');

        $staff->refresh();
        $newKey = Storage::keyFromUrl($staff->photo_url);

        $this->assertNotNull($newKey);
        $this->assertNotSame($oldKey, $newKey);
        $this->assertNull(Storage::get($oldKey));
        $this->assertNotNull(Storage::get($newKey));
        $this->assertSame(1, StoredFile::count());
    }

    public function test_the_public_route_serves_an_uploaded_photo(): void
    {
        $key = $this->storeBlob();
        $staff = Staff::factory()->doctor()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/uploads/'.$key,
        ]);

        $response = $this->get("/api/public/doctors/{$staff->id}/photo");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->pngBytes(), (string) $response->getContent());
    }

    public function test_the_public_route_serves_the_legacy_api_uploads_prefix(): void
    {
        $key = $this->storeBlob();
        $staff = Staff::factory()->doctor()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/api/uploads/'.$key,
        ]);

        $this->get("/api/public/doctors/{$staff->id}/photo")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_public_route_refuses_a_patient_id_photo(): void
    {
        $patientKey = 'patient-id-'.Str::uuid().'.png';
        Storage::put($patientKey, $this->pngBytes());

        $staff = Staff::factory()->doctor()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/uploads/'.$patientKey,
        ]);

        $this->get("/api/public/doctors/{$staff->id}/photo")->assertNotFound();
    }

    public function test_the_public_route_404s_for_an_unpublished_or_photoless_doctor(): void
    {
        $key = $this->storeBlob();

        $unpublished = Staff::factory()->create([
            'is_doctor' => true,
            'show_on_website' => false,
            'photo_url' => '/uploads/'.$key,
        ]);

        $photoless = Staff::factory()->doctor()->create(['photo_url' => null]);

        $this->get("/api/public/doctors/{$unpublished->id}/photo")->assertNotFound();
        $this->get("/api/public/doctors/{$photoless->id}/photo")->assertNotFound();
    }

    public function test_the_public_doctor_card_points_at_the_narrow_photo_route(): void
    {
        $key = $this->storeBlob();
        $staff = Staff::factory()->doctor()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/uploads/'.$key,
        ]);

        $html = (string) $this->get('/doctors')->getContent();

        $this->assertStringContainsString("/api/public/doctors/{$staff->id}/photo", $html);
    }

    public function test_the_staff_list_renders_the_photo_thumbnail(): void
    {
        $key = $this->storeBlob();
        Staff::factory()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/uploads/'.$key,
        ]);

        $html = (string) $this->actingAs($this->owner())->get('/staff')->getContent();

        $this->assertStringContainsString('/staff-photos/'.$key, $html);
    }

    public function test_the_staff_list_falls_back_to_the_initials_without_a_photo(): void
    {
        Staff::factory()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => null,
        ]);

        $html = (string) $this->actingAs($this->owner())->get('/staff')->getContent();

        $this->assertStringNotContainsString('/staff-photos/', $html);
        $this->assertStringContainsString('rounded-full bg-brand-100', $html);
    }

    public function test_a_staff_member_can_read_a_colleague_photo_but_not_a_patient_id_photo(): void
    {
        $key = $this->storeBlob();
        $staffUser = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staffUser)->get('/staff-photos/'.$key)->assertOk();

        $patientKey = 'patient-id-'.Str::uuid().'.png';
        Storage::put($patientKey, $this->pngBytes());

        $this->actingAs($staffUser)->get('/staff-photos/'.$patientKey)->assertNotFound();
    }

    public function test_the_staff_photo_route_needs_a_session(): void
    {
        $key = $this->storeBlob();

        $this->get('/staff-photos/'.$key)->assertRedirect($this->loginPath());
    }

    public function test_the_doctor_photo_is_visible_in_the_erp_dashboard(): void
    {
        $key = $this->storeBlob();
        Staff::factory()->doctor()->create([
            'full_name' => 'Dr. Abebe Kebede',
            'photo_url' => '/uploads/'.$key,
        ]);

        $html = (string) $this->actingAs($this->createUser(['role' => Roles::STAFF]))
            ->get('/dashboard')
            ->getContent();

        $this->assertStringContainsString('/staff-photos/'.$key, $html);
    }
}
