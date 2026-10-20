<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Support\Storage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MySQL-only behaviour: the upload round trip and the packet-size limit.
 *
 * The photos are capped at 1 MiB by the validation, but the storage column is
 * a MEDIUMBLOB (16 MB) — this test proves that a 3 MB payload survives the
 * round trip byte for byte and that the server's packet limit is what would
 * stop a larger one (never a silent truncation).
 */
class MySqlUploadTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The upload round trip is specific to MySQL 8.0.');
        }
    }

    public function test_a_three_megabyte_payload_survives_the_round_trip(): void
    {
        // A PNG header followed by 3 MB of deterministic filler.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $payload = $png.str_repeat('A', 3 * 1024 * 1024 - strlen($png));

        $this->assertSame(3 * 1024 * 1024, strlen($payload));

        $key = Storage::put('test-3mb.png', $payload);

        // The bytes come back exactly as they went in.
        $read = Storage::get($key);

        $this->assertNotNull($read);
        $this->assertSame(strlen($payload), strlen((string) $read));
        $this->assertSame($payload, $read);
        $this->assertSame(md5($payload), md5((string) $read));
    }

    public function test_the_stored_blob_is_read_back_with_the_raw_bytes(): void
    {
        $payload = random_bytes(1024 * 512);

        $key = Storage::put('test-512k.bin', $payload);

        $row = StoredFile::query()->where('key', $key)->firstOrFail();

        $this->assertSame(strlen($payload), strlen((string) $row->getRawOriginal('data')));
        $this->assertSame($payload, $row->getRawOriginal('data'));
    }

    public function test_the_column_holds_more_than_three_megabytes(): void
    {
        // MEDIUMBLOB is 16 MB; the app must never hit the column limit.
        $column = DB::selectOne(
            'SELECT character_maximum_length AS len, data_type AS type
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ? AND column_name = ?',
            ['stored_files', 'data']
        );

        $this->assertSame('mediumblob', strtolower((string) $column->type));
        $this->assertSame(16777215, (int) $column->len);
        $this->assertGreaterThan(3 * 1024 * 1024, (int) $column->len);
    }

    public function test_the_server_packet_limit_is_documented_and_large_enough(): void
    {
        $maxPacket = (int) DB::selectOne('SELECT @@max_allowed_packet AS size')->size;

        // A 3 MB upload plus the surrounding statement must fit.
        $this->assertGreaterThan(
            4 * 1024 * 1024,
            $maxPacket,
            'max_allowed_packet is too small for the 3 MB upload round trip.',
        );
    }

    public function test_a_payload_beyond_the_packet_limit_fails_instead_of_truncating(): void
    {
        $maxPacket = (int) DB::selectOne('SELECT @@max_allowed_packet AS size')->size;

        // A payload larger than the packet limit must raise an error — the
        // bytes must never be silently truncated.
        $tooBig = str_repeat('B', $maxPacket + 1024);

        $threw = false;
        try {
            DB::table('stored_files')->insert([
                'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZZ',
                'key' => 'test-oversized.bin',
                'mime_type' => 'application/octet-stream',
                'data' => $tooBig,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'An oversized payload was accepted — the data would be truncated.');

        $stored = DB::table('stored_files')->where('key', 'test-oversized.bin')->value('data');

        $this->assertNull($stored, 'A truncated row was written.');
    }
}
