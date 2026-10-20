<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Patients\PatientValidation;
use App\Support\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Patient ID-photo capture and the /uploads/{key} blob server, ported from the
 * capturePatientIdPhoto action + /api/uploads route.
 *
 * Only PNG/JPEG/WebP ≤ 1 MiB are accepted; bytes live in `stored_files` and are
 * served back to signed-in users with the sniffed MIME type. Replacing a photo
 * deletes the previous blob; a capture failure never leaves a dangling file.
 */
class PatientIdPhotoTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    private function png(int $bytes = 64): UploadedFile
    {
        $content = hex2bin('89504e470d0a1a0a').str_repeat("\x00", $bytes);

        return UploadedFile::fake()->createWithContent('id.png', $content);
    }

    public function test_it_stores_a_png_photo_and_points_the_patient_at_it(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()])
            ->assertRedirect();

        $patient->refresh();
        $this->assertNotNull($patient->id_photo_url);
        $this->assertStringStartsWith('/uploads/patient-id-', $patient->id_photo_url);
        $this->assertSame(1, StoredFile::count());
    }

    public function test_the_capture_writes_an_audited_url_change(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()]);

        $log = AuditLog::where('action', 'PATIENT_ID_PHOTO_CHANGED')->sole();
        $this->assertNull($log->old_json['idPhotoUrl']);
        $this->assertSame($patient->fresh()->id_photo_url, $log->new_json['idPhotoUrl']);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $file = UploadedFile::fake()->createWithContent('id.txt', str_repeat('a', 50));

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/photo", ['idPhoto' => $file])
            ->assertSessionHasErrors('idPhoto');

        $this->assertNull($patient->fresh()->id_photo_url);
        $this->assertSame(0, StoredFile::count());
    }

    public function test_an_oversized_photo_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        // A valid header with a body past the 1 MiB cap.
        $content = hex2bin('89504e470d0a1a0a').str_repeat("\x00", PatientValidation::MAX_ID_PHOTO_BYTES);
        $file = UploadedFile::fake()->createWithContent('id.png', $content);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/photo", ['idPhoto' => $file])
            ->assertSessionHasErrors('idPhoto');

        $this->assertSame(0, StoredFile::count());
    }

    public function test_a_missing_file_reports_no_file(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/photo")
            ->assertSessionHasErrors('idPhoto');

        $this->assertSame(0, StoredFile::count());
    }

    public function test_replacing_a_photo_removes_the_old_blob(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()]);
        $firstUrl = $patient->fresh()->id_photo_url;

        $this->actingAs($user)->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png(128)]);
        $secondUrl = $patient->fresh()->id_photo_url;

        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertSame(1, StoredFile::count());
        $this->assertNull(Storage::get((string) Storage::keyFromUrl($firstUrl)));
    }

    public function test_the_stored_blob_is_served_with_its_sniffed_mime(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()]);
        $url = $patient->fresh()->id_photo_url;

        $response = $this->actingAs($user)->get($url)->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith(hex2bin('89504e470d0a1a0a'), $response->getContent());
    }

    public function test_an_unknown_upload_key_is_a_404(): void
    {
        $this->actingAs($this->owner())
            ->get('/uploads/patient-id-nope.png')
            ->assertNotFound();
    }

    public function test_the_uploads_route_rejects_path_traversal(): void
    {
        // The route pattern forbids `..` and leading dots entirely (never 200).
        $this->actingAs($this->owner())
            ->get('/uploads/..%2f..%2fetc%2fpasswd')
            ->assertNotFound();
    }

    public function test_a_photo_can_be_attached_at_create_time(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post('/patients', [
            'fullName' => 'With Photo',
            'phone' => '0911223344',
            'idPhoto' => $this->png(),
        ])->assertRedirect();

        $patient = Patient::sole();
        $this->assertNotNull($patient->id_photo_url);
        $this->assertSame(1, StoredFile::count());
    }

    public function test_the_detail_page_renders_the_photo_when_present(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()]);
        $url = $patient->fresh()->id_photo_url;

        $this->actingAs($user)
            ->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertSee($url, false);
    }
}
