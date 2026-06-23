<?php

namespace Tests\Feature;

use App\Models\ExamRegistration;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'description' => 'Paket test',
            'status' => 'draft',
            'total_duration_minutes' => 120,
        ], $headers)->assertCreated();

        $response->assertJsonPath('data.summary.verbal', 0)
            ->assertJsonPath('data.summary.kuantitatif', 0)
            ->assertJsonPath('data.summary.logika', 0)
            ->assertJsonPath('data.summary.spasial', 0);

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
                'difficulty' => null,
                'explanation' => null,
                'is_active' => true,
                'options' => $this->answerOptions(),
            ], $headers)->assertCreated()
                ->assertJsonPath('data.options.0.option_label', 'A');
        }

        $this->assertDatabaseCount('questions', 3);
        $this->assertDatabaseCount('question_options', 12);
        $this->assertSame(3, QuestionPackage::findOrFail($package->id)->total_questions);
        $this->assertSame(1, Question::firstOrFail()->options()->where('is_correct', true)->count());
    }

    public function test_csv_import_is_all_or_nothing_with_row_errors(): void
    {
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');
        $package = $this->createPackage($headers);

        $valid = UploadedFile::fake()->createWithContent('questions.csv', implode("\n", [
            'section_code,order_number,question_text,question_image,option_a_text,option_a_image,option_b_text,option_b_image,option_c_text,option_c_image,option_d_text,option_d_image,correct_option,score,difficulty,explanation',
            'verbal,1,"Sinonim cepat adalah?",,Lekas,,Lambat,,Diam,,Turun,,A,1,mudah,"Cepat berarti lekas"',
        ])."\n");

        $this->post("/api/question-packages/{$package->id}/questions/import", ['file' => $valid], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 1);
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseHas('question_options', ['option_label' => 'A', 'is_correct' => true]);

        $invalid = UploadedFile::fake()->createWithContent('questions.csv', implode("\n", [
            'section_code,order_number,question_text,question_image,option_a_text,option_a_image,option_b_text,option_b_image,option_c_text,option_c_image,option_d_text,option_d_image,correct_option,score,difficulty,explanation',
            'unknown,2,"Soal invalid",,A,,B,,C,,D,,E,1,,',
        ])."\n");

        $this->post("/api/question-packages/{$package->id}/questions/import", ['file' => $invalid], [...$headers, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('imported', 0)
            ->assertJsonStructure(['errors' => [['row', 'field', 'message']]]);
        $this->assertDatabaseCount('questions', 1);
    }

    public function test_image_upload_returns_storage_path(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $headers = $this->headersFor('admin@tpka.com');

        $this->post('/api/admin/question-images', [
            'image' => UploadedFile::fake()->image('question.png'),
        ], [...$headers, 'Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['url', 'path'])
            ->assertJsonPath('path', fn (string $path) => str_starts_with($path, 'question-images/'));
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
            'is_active' => true,
            'options' => $this->answerOptions(),
        ], $adminHeaders)->assertCreated();

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

    private function createPackage(array $headers): QuestionPackage
    {
        $id = $this->postJson('/api/question-packages', [
            'name' => 'Paket TPA Test',
            'status' => 'draft',
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
