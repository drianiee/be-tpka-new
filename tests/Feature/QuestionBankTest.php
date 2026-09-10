<?php

namespace Tests\Feature;

use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use App\Services\QuestionBankService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_creation_creates_four_default_sections(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');

        $response = $this->postJson('/api/question-packages', [
            'name' => 'Paket TPA A',
            'total_duration_minutes' => 120,
        ], $headers)->assertCreated();

        $response->assertJsonPath('data.summary.verbal', 0)
            ->assertJsonPath('data.summary.kuantitatif', 0)
            ->assertJsonPath('data.summary.logika', 0)
            ->assertJsonPath('data.summary.spasial', 0)
            ->assertJsonPath('data.base_score', 200)
            ->assertJsonPath('data.score_span', 600)
            ->assertJsonPath('data.max_score', 800);

        $this->assertDatabaseHas('exam_sections', ['question_package_id' => $response->json('data.id'), 'code' => 'verbal']);
        $this->assertDatabaseHas('exam_sections', ['question_package_id' => $response->json('data.id'), 'code' => 'kuantitatif']);
        $this->assertDatabaseHas('exam_sections', ['question_package_id' => $response->json('data.id'), 'code' => 'logika']);
        $this->assertDatabaseHas('exam_sections', ['question_package_id' => $response->json('data.id'), 'code' => 'spasial']);
    }

    public function test_admin_can_create_text_image_and_text_plus_image_questions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $sectionId = $package->sections()->where('code', 'verbal')->value('id');

        $cases = [
            ['question_text' => 'Soal teks', 'question_image' => null],
            ['question_text' => null, 'question_image' => '/storage/question-images/soal.png'],
            ['question_text' => 'Soal teks dan gambar', 'question_image' => '/storage/question-images/soal-2.png'],
        ];

        foreach ($cases as $index => $case) {
            $this->postJson("/api/question-packages/{$package->id}/questions", [
                'exam_section_id' => $sectionId,
                'order_number' => $index + 1,
                'question_text' => $case['question_text'],
                'question_image' => $case['question_image'],
                'score' => 1,
                'options' => $this->answerOptions(),
            ], $headers)->assertCreated()
                ->assertJsonPath('data.options.0.option_label', 'A');
        }

        $this->assertDatabaseCount('questions', 3);
        $this->assertDatabaseCount('question_options', 12);
        $this->assertSame(3, QuestionPackage::findOrFail($package->id)->total_questions);
        $this->assertSame(1, Question::firstOrFail()->options()->where('is_correct', true)->count());
    }

    public function test_import_is_all_or_nothing_with_row_errors(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $verbal = ExamSection::where('question_package_id', $package->id)->where('code', 'verbal')->firstOrFail();

        $valid = UploadedFile::fake()->createWithContent('questions.csv', implode("\n", [
            'order_number,question_text,option_a_text,option_b_text,option_c_text,option_d_text,correct_option',
            '1,"Sinonim cepat adalah?",Lekas,Lambat,Diam,Turun,A',
        ])."\n");

        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $valid], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 1);
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseHas('questions', ['exam_section_id' => $verbal->id]);
        $this->assertDatabaseHas('question_options', ['option_label' => 'A', 'is_correct' => true]);

        $invalid = UploadedFile::fake()->createWithContent('questions.csv', implode("\n", [
            'order_number,question_text,option_a_text,option_b_text,option_c_text,option_d_text,correct_option',
            '2,"Soal invalid",A,B,C,D,E',
        ])."\n");

        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $invalid], [...$headers, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('imported', 0)
            ->assertJsonStructure(['errors' => [['row', 'field', 'message']]]);
        $this->assertDatabaseCount('questions', 1);
    }

    public function test_import_appends_after_existing_questions_regardless_of_order_column(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $verbal = ExamSection::where('question_package_id', $package->id)->where('code', 'verbal')->firstOrFail();

        $header = 'order_number,question_text,option_a_text,option_b_text,option_c_text,option_d_text,correct_option';

        $first = UploadedFile::fake()->createWithContent('soal.csv', implode("\n", [
            $header,
            '1,"Soal lama satu",A,B,C,D,A',
            '2,"Soal lama dua",A,B,C,D,A',
        ])."\n");
        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $first], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated();

        // Nomor 1-3 diulang lagi: harus jadi 3,4,5, bukan bentrok dengan yang sudah ada.
        $second = UploadedFile::fake()->createWithContent('soal.csv', implode("\n", [
            $header,
            '3,"Import ketiga",A,B,C,D,A',
            '1,"Import pertama",A,B,C,D,A',
            '2,"Import kedua",A,B,C,D,A',
        ])."\n");
        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $second], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated();

        $ordered = Question::where('exam_section_id', $verbal->id)
            ->orderBy('order_number')
            ->pluck('question_text', 'order_number')
            ->all();

        $this->assertSame([
            1 => 'Soal lama satu',
            2 => 'Soal lama dua',
            3 => 'Import pertama',
            4 => 'Import kedua',
            5 => 'Import ketiga',
        ], $ordered);
    }

    public function test_one_file_can_fill_several_sections_and_a_broken_sheet_is_rejected_alone(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $verbal = ExamSection::where('question_package_id', $package->id)->where('code', 'verbal')->firstOrFail();
        $logika = ExamSection::where('question_package_id', $package->id)->where('code', 'logika')->firstOrFail();

        $path = tempnam(sys_get_temp_dir(), 'multi').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $sheets = [
            // Verbal bersih, Logika punya kunci jawaban ngawur di baris ketiga.
            'Verbal' => [[1, 'Verbal satu', 'A', 'B', 'C', 'D', 'A']],
            'Logika' => [[1, 'Logika satu', 'A', 'B', 'C', 'D', 'B'], [2, 'Logika dua', 'A', 'B', 'C', 'D', 'Z']],
        ];

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            foreach (QuestionBankService::IMPORT_COLUMNS as $index => $column) {
                $sheet->setCellValue([$index + 1, 1], $column);
            }
            foreach ($rows as $rowIndex => $values) {
                foreach ($values as $columnIndex => $value) {
                    $sheet->setCellValue([$columnIndex + 1, $rowIndex + 2], $value);
                }
            }
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->post(
            "/api/sections/{$verbal->id}/questions/import",
            ['file' => new UploadedFile($path, 'soal.xlsx', null, null, true)],
            [...$headers, 'Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('imported', 1);

        $this->assertSame(1, Question::where('exam_section_id', $verbal->id)->count());
        $this->assertSame(0, Question::where('exam_section_id', $logika->id)->count());

        @unlink($path);
    }

    public function test_export_produces_a_file_that_can_be_imported_into_another_package(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $source = $this->createPackage($headers);
        $verbal = ExamSection::where('question_package_id', $source->id)->where('code', 'verbal')->firstOrFail();

        $seed = UploadedFile::fake()->createWithContent('soal.csv', implode("\n", [
            'order_number,question_text,option_a_text,option_b_text,option_c_text,option_d_text,correct_option',
            '1,"Sinonim cepat adalah?",Lekas,Lambat,Diam,Turun,A',
            '2,"Lawan kata gersang?",Tandus,Subur,Kering,Panas,B',
        ])."\n");
        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $seed], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated();

        $response = $this->get("/api/question-packages/{$source->id}/questions/export", $headers)->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->getContent());

        $spreadsheet = IOFactory::load($path);
        $this->assertContains('Verbal', $spreadsheet->getSheetNames());
        $this->assertSame('Sinonim cepat adalah?', $spreadsheet->getSheetByName('Verbal')->getCell('B2')->getValue());
        $spreadsheet->disconnectWorksheets();

        // File hasil ekspor harus bisa langsung dipakai untuk mengisi paket lain.
        $target = $this->createPackage($headers, 'Paket Hasil Salinan');
        $targetVerbal = ExamSection::where('question_package_id', $target->id)->where('code', 'verbal')->firstOrFail();

        $this->post(
            "/api/sections/{$targetVerbal->id}/questions/import",
            ['file' => new UploadedFile($path, 'soal-paket.xlsx', null, null, true)],
            [...$headers, 'Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('imported', 2);

        $this->assertSame(2, Question::where('exam_section_id', $targetVerbal->id)->count());

        @unlink($path);
    }

    public function test_import_rejects_file_without_any_filled_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $verbal = ExamSection::where('question_package_id', $package->id)->where('code', 'verbal')->firstOrFail();

        $empty = UploadedFile::fake()->createWithContent(
            'soal.csv',
            "order_number,question_text,option_a_text,option_b_text,option_c_text,option_d_text,correct_option\n",
        );

        $this->post("/api/sections/{$verbal->id}/questions/import", ['file' => $empty], [...$headers, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_sheet_name_decides_the_target_section(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $kuantitatif = ExamSection::where('question_package_id', $package->id)->where('code', 'kuantitatif')->firstOrFail();
        $verbal = ExamSection::where('question_package_id', $package->id)->where('code', 'verbal')->firstOrFail();

        $response = $this->get("/api/sections/{$kuantitatif->id}/questions/import-template", $headers)->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());

        $spreadsheet = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Kuantitatif', 'Contoh'], $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('Kuantitatif');
        $this->assertSame('order_number', $sheet->getCell('A1')->getValue());
        foreach ([1, 'Berapakah $2 + 3$?', '$4$', '$5$', '$6$', '$7$', 'B'] as $index => $value) {
            $sheet->setCellValue([$index + 1, 2], $value);
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->post(
            "/api/sections/{$kuantitatif->id}/questions/import",
            ['file' => new UploadedFile($path, 'soal.xlsx', null, null, true)],
            [...$headers, 'Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('imported', 1);
        $this->assertDatabaseHas('questions', ['exam_section_id' => $kuantitatif->id, 'question_text' => 'Berapakah $2 + 3$?']);

        // Nama sheet yang menentukan tujuan, bukan tombol yang diklik: file bersheet
        // Kuantitatif tetap masuk ke Kuantitatif walau diimport dari tab Verbal.
        $this->post(
            "/api/sections/{$verbal->id}/questions/import",
            ['file' => new UploadedFile($path, 'soal.xlsx', null, null, true)],
            [...$headers, 'Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('imported', 1);
        $this->assertSame(2, Question::where('exam_section_id', $kuantitatif->id)->count());
        $this->assertSame(0, Question::where('exam_section_id', $verbal->id)->count());

        @unlink($path);
    }

    public function test_image_upload_returns_public_url_only(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');

        $this->post('/api/admin/question-images', [
            'image' => UploadedFile::fake()->image('question.png'),
        ], [...$headers, 'Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['url'])
            ->assertJsonMissing(['path'])
            ->assertJsonPath('url', fn (string $url) => str_contains($url, '/storage/question-images/'));
    }

    public function test_question_image_must_come_from_question_image_upload_storage(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);
        $sectionId = $package->sections()->where('code', 'verbal')->value('id');

        $this->postJson("/api/question-packages/{$package->id}/questions", [
            'exam_section_id' => $sectionId,
            'question_text' => null,
            'question_image' => '/tmp/manual-path.png',
            'options' => $this->answerOptions(),
        ], $headers)->assertUnprocessable();
    }

    public function test_participant_attempt_never_contains_answer_key(): void
    {
        $this->seed(DatabaseSeeder::class);
        $adminHeaders = $this->headersFor('admin@tpka.com');
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $package = $this->createPackage($adminHeaders);
        $sectionId = $package->sections()->where('code', 'verbal')->value('id');

        $this->postJson("/api/question-packages/{$package->id}/questions", [
            'exam_section_id' => $sectionId,
            'order_number' => 1,
            'question_text' => 'Soal safety',
            'score' => 1,
            'options' => $this->answerOptions(),
        ], $adminHeaders)->assertCreated();
        $this->patchJson("/api/question-packages/{$package->id}/status", [
            'status' => 'published',
        ], $adminHeaders)->assertOk();

        $schedule = Schedule::create([
            'question_package_id' => $package->id,
            'title' => 'Jadwal Safety',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'registration_start_at' => now()->subDay(),
            'registration_end_at' => now()->addHour(),
            'price' => 0,
            'quota' => 100,
            'status' => 'published',
            'created_by' => User::where('role', 'administrator')->firstOrFail()->id,
        ]);
        ExamRegistration::create(['user_id' => $participant->id, 'schedule_id' => $schedule->id, 'status' => 'approved', 'access_status' => 'unlocked', 'registered_at' => now()]);

        $attempt = $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertSuccessful()
            ->assertJsonMissing(['is_correct']);

        $this->getJson('/api/participant/attempts/'.$attempt->json('id'))
            ->assertOk()
            ->assertJsonMissing(['is_correct']);
    }

    private function headersFor(string $email): array
    {
        $login = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        return ['Authorization' => 'Bearer '.$login->json('token')];
    }

    private function createPackage(array $headers, string $name = 'Paket TPA Test'): QuestionPackage
    {
        $id = $this->postJson('/api/question-packages', [
            'name' => $name,
            'total_duration_minutes' => 120,
        ], $headers)->assertCreated()->json('data.id');

        return QuestionPackage::findOrFail($id);
    }

    private function answerOptions(): array
    {
        return [
            ['option_label' => 'A', 'option_text' => 'A text', 'option_image' => null, 'is_correct' => true, 'order_number' => 1],
            ['option_label' => 'B', 'option_text' => null, 'option_image' => '/storage/question-images/b.png', 'is_correct' => false, 'order_number' => 2],
            ['option_label' => 'C', 'option_text' => 'C text', 'option_image' => '/storage/question-images/c.png', 'is_correct' => false, 'order_number' => 3],
            ['option_label' => 'D', 'option_text' => 'D text', 'option_image' => null, 'is_correct' => false, 'order_number' => 4],
        ];
    }
}
