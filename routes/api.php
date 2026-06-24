<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExamAttemptController;
use App\Http\Controllers\Api\OperatorController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\PartnerOnboardingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\QuestionContentController;
use App\Http\Controllers\Api\QuestionPackageController;
use App\Http\Controllers\Api\ScheduleController;
use App\Models\ExamAttempt;
use App\Models\Partner;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('email/resend', [AuthController::class, 'resend']);
    Route::match(['get', 'post'], 'email/verify', [AuthController::class, 'verifyEmail']);
});

Route::get('schedules/upcoming', [ScheduleController::class, 'upcoming']);
Route::get('certificates/data/{uuid}', [CertificateController::class, 'data']);
Route::get('certificates/pdf/{uuid}', [CertificateController::class, 'pdf']);
Route::get('partner/onboarding/preview', [PartnerOnboardingController::class, 'preview']);
Route::post('partner/onboarding/claim', [PartnerOnboardingController::class, 'claim']);
Route::post('payments/midtrans/callback', [PaymentController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/complete-profile', [AuthController::class, 'completeProfile']);

    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/revenue-chart', [DashboardController::class, 'revenue']);
    Route::get('dashboard/participants-chart', [DashboardController::class, 'participants']);

    Route::middleware('role:administrator,operator')->group(function () {
        Route::get('question-packages/{questionPackage}/sections', [QuestionContentController::class, 'sections']);
        Route::post('question-packages/{questionPackage}/sections', [QuestionContentController::class, 'storeSection']);
        Route::put('sections/{section}', [QuestionContentController::class, 'updateSection']);
        Route::delete('sections/{section}', [QuestionContentController::class, 'deleteSection']);
        Route::get('question-packages/{questionPackage}/questions', [QuestionContentController::class, 'questions']);
        Route::post('question-packages/{questionPackage}/questions', [QuestionContentController::class, 'storePackageQuestion']);
        Route::get('question-packages/{questionPackage}/questions/import-template', [QuestionContentController::class, 'importTemplate']);
        Route::post('question-packages/{questionPackage}/questions/import', [QuestionContentController::class, 'importQuestions']);
        Route::get('questions/{question}', [QuestionContentController::class, 'showQuestion']);
        Route::put('questions/{question}', [QuestionContentController::class, 'updateQuestion']);
        Route::delete('questions/{question}', [QuestionContentController::class, 'deleteQuestion']);
        Route::apiResource('question-packages', QuestionPackageController::class)->parameters(['question-packages' => 'questionPackage']);
        Route::patch('question-packages/{questionPackage}/status', [QuestionPackageController::class, 'status']);
        Route::apiResource('schedules', ScheduleController::class);
        Route::post('schedules/{schedule}/participants', [ScheduleController::class, 'addParticipant']);
        Route::delete('schedules/{schedule}/participants/{userId}', [ScheduleController::class, 'removeParticipant']);
        Route::get('schedules/{schedule}/participants/export', fn () => ['message' => 'Export belum diaktifkan.', 'download_url' => null]);
        Route::post('schedules/{schedule}/partner/import', [ScheduleController::class, 'importPartnerParticipants']);

        Route::get('participants', [ParticipantController::class, 'index']);
        Route::get('participants/{participant}', [ParticipantController::class, 'show']);

        Route::get('partners', fn (Request $r) => Partner::paginate($r->integer('per_page', 10)));
        Route::post('partners', function (Request $r) {
            $p = Partner::create($r->validate(['name' => 'required|string', 'code' => 'nullable|string|unique:partners', 'contact_name' => 'nullable|string', 'contact_email' => 'nullable|email', 'contact_phone' => 'nullable|string', 'is_active' => 'nullable|boolean']));

            return response()->json(['message' => 'Mitra dibuat.', 'partner' => $p], 201);
        });
        Route::get('partners/{partner}', fn (Partner $partner) => ['partner' => $partner, 'schedules' => ['current_page' => 1, 'data' => [], 'last_page' => 1, 'per_page' => 20, 'total' => 0]]);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);

        Route::prefix('admin')->group(function () {
            Route::post('question-images', [QuestionContentController::class, 'uploadImage']);
            Route::apiResource('question-packages', QuestionPackageController::class)->parameters(['question-packages' => 'questionPackage']);
            Route::patch('question-packages/{questionPackage}/status', [QuestionPackageController::class, 'status']);
            Route::get('question-packages/{questionPackage}/sections', [QuestionContentController::class, 'sections']);
            Route::post('question-packages/{questionPackage}/sections', [QuestionContentController::class, 'storeSection']);
            Route::put('sections/{section}', [QuestionContentController::class, 'updateSection']);
            Route::delete('sections/{section}', [QuestionContentController::class, 'deleteSection']);
            Route::get('question-packages/{questionPackage}/questions', [QuestionContentController::class, 'questions']);
            Route::post('question-packages/{questionPackage}/questions', [QuestionContentController::class, 'storePackageQuestion']);
            Route::get('question-packages/{questionPackage}/questions/import-template', [QuestionContentController::class, 'importTemplate']);
            Route::post('question-packages/{questionPackage}/questions/import', [QuestionContentController::class, 'importQuestions']);
            Route::get('questions', [QuestionContentController::class, 'questions']);
            Route::post('questions', [QuestionContentController::class, 'storeQuestion']);
            Route::get('questions/{question}', [QuestionContentController::class, 'showQuestion']);
            Route::put('questions/{question}', [QuestionContentController::class, 'updateQuestion']);
            Route::delete('questions/{question}', [QuestionContentController::class, 'deleteQuestion']);
            Route::get('schedules/{schedule}/scores', fn (Schedule $schedule) => ExamAttempt::whereBelongsTo($schedule)->whereIn('status', ['submitted', 'expired'])->with(['scores', 'user'])->get());
            Route::get('certificates', [CertificateController::class, 'index']);
            Route::post('certificates', [CertificateController::class, 'store']);
            Route::get('certificates/{certificate}', [CertificateController::class, 'show']);
            Route::put('certificates/{certificate}', [CertificateController::class, 'update']);
            Route::put('certificates/{certificate}/layout', [CertificateController::class, 'layout']);
            Route::patch('certificates/{certificate}/activate', [CertificateController::class, 'activate']);
        });
    });

    Route::middleware('role:administrator')->prefix('admin')->group(function () {
        Route::get('operators', [OperatorController::class, 'index']);
        Route::post('operators', [OperatorController::class, 'store']);
        Route::post('users/operator', [OperatorController::class, 'store']);
        Route::get('operators/{operator}', [OperatorController::class, 'show']);
        Route::match(['put', 'patch'], 'operators/{operator}', [OperatorController::class, 'update']);
        Route::patch('operators/{operator}/status', [OperatorController::class, 'status']);
        Route::delete('operators/{operator}', [OperatorController::class, 'destroy']);
    });

    Route::middleware('role:peserta')->prefix('participant')->group(function () {
        Route::get('schedules', [ParticipantController::class, 'schedules']);
        Route::post('schedules/{schedule}/register', [ParticipantController::class, 'register']);
        Route::post('schedules/{schedule}/pay', [ParticipantController::class, 'register']);
        Route::get('my-schedules', [ParticipantController::class, 'mySchedules']);
        Route::get('my-schedules/{schedule}', [ParticipantController::class, 'mySchedule']);
        Route::post('schedules/{schedule}/attempts', [ExamAttemptController::class, 'start']);
        Route::get('attempts/{attempt}', [ExamAttemptController::class, 'show']);
        Route::put('attempts/{attempt}/answers/{questionId}', [ExamAttemptController::class, 'answer']);
        Route::post('attempts/{attempt}/answers/batch', [ExamAttemptController::class, 'batch']);
        Route::post('attempts/{attempt}/heartbeat', [ExamAttemptController::class, 'heartbeat']);
        Route::post('attempts/{attempt}/submit', [ExamAttemptController::class, 'submit']);
        Route::get('attempts/{attempt}/result', [ExamAttemptController::class, 'result']);
        Route::get('scores', [ParticipantController::class, 'scores']);
        Route::get('scores/{attempt}', [ParticipantController::class, 'score']);
        Route::get('certificates', fn () => ['data' => []]);
        Route::get('certificates/{attempt}', [ParticipantController::class, 'score']);
    });

});
