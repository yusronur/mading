<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Modules\Quiz\QuestionTemplates;
use App\Modules\Quiz\SimpleDocx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tahap 11: bank soal, editor soal, impor Excel/Word. */
class QuestionBankTest extends TestCase
{
    private object $school;

    private User $teacher;

    private User $other;

    private QuestionBank $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('madingo.storage.disk', 'local'));
        $this->school = $this->makeSchool();
        $this->teacher = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->other = $this->makeUser($this->school, User::ROLE_TEACHER);
        $this->actingAs($this->teacher)->post(route('banks.store'), ['name' => 'IPA 7 - Sel', 'subject' => 'IPA', 'grade' => 7])->assertRedirect();
        $this->bank = $this->inSchool($this->school, fn () => QuestionBank::firstOrFail());
    }

    private function questions()
    {
        return $this->inSchool($this->school, fn () => Question::where('bank_id', $this->bank->id)->orderBy('position')->with('attachments')->get());
    }

    public function test_create_each_question_type_and_key_is_stored(): void
    {
        $base = ['difficulty' => 2, 'points' => 1, 'tags' => ['Sel']];
        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), $base + [
            'type' => 'mc', 'prompt' => '<p>Organel fotosintesis? $x^2$</p><script>alert(1)</script>',
            'spec' => ['options' => ['Mitokondria', 'Kloroplas'], 'correct' => [1]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), $base + [
            'type' => 'short', 'prompt' => 'Ibu kota Jawa Timur?', 'spec' => ['answers' => ['Surabaya', 'Kota Surabaya']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), $base + [
            'type' => 'tf', 'prompt' => 'Bumi datar.', 'spec' => ['correct' => false],
        ])->assertSessionHasNoErrors();

        $qs = $this->questions();
        $this->assertSame(['mc', 'short', 'tf'], $qs->pluck('type')->all());
        $this->assertSame('b', $qs[0]->data['answer']);
        $this->assertStringNotContainsString('script', $qs[0]->prompt);
        $this->assertStringContainsString('$x^2$', $qs[0]->prompt);

        $this->actingAs($this->teacher)->get(route('banks.index'))->assertInertia(fn ($p) => $p->component('Quiz/Bank')
            ->has('questions', 3)->where('questions.0.options.1.correct', true)->where('questions.1.answers.0', 'Surabaya')
            ->where('banks.0.count', 3));
        $this->actingAs($this->teacher)->get(route('banks.index', ['type' => 'tf']))->assertInertia(fn ($p) => $p->has('questions', 1));
        $this->actingAs($this->teacher)->get(route('banks.index', ['tag' => 'Sel']))->assertInertia(fn ($p) => $p->has('questions', 3));
    }

    public function test_validation_messages(): void
    {
        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), [
            'type' => 'mc', 'prompt' => 'Soal', 'difficulty' => 2, 'points' => 1, 'spec' => ['options' => ['a', 'b']],
        ])->assertSessionHasErrors('spec');
        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), [
            'type' => 'tf', 'prompt' => '', 'difficulty' => 2, 'points' => 1, 'spec' => ['correct' => true],
        ])->assertSessionHasErrors('prompt');
    }

    public function test_bank_is_private_to_owner(): void
    {
        $this->actingAs($this->other)->get(route('banks.index'))->assertInertia(fn ($p) => $p->has('banks', 0));
        $this->actingAs($this->other)->post(route('questions.store', $this->bank), ['type' => 'tf', 'prompt' => 'x', 'difficulty' => 1, 'points' => 1, 'spec' => ['correct' => true]])->assertForbidden();
        $this->actingAs($this->other)->delete(route('banks.destroy', $this->bank))->assertForbidden();

        $student = $this->makeUser($this->school, User::ROLE_STUDENT);
        $this->actingAs($student)->get(route('banks.index'))->assertForbidden();
    }

    public function test_media_upload_attach_duplicate_and_remove(): void
    {
        $img = UploadedFile::fake()->image('sel.png', 400, 300);
        $card = $this->actingAs($this->teacher)->post(route('questions.upload'), ['file' => $img])->assertCreated()->json();
        $this->actingAs($this->teacher)->post(route('questions.upload'), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertStatus(422);

        $this->actingAs($this->teacher)->post(route('questions.store', $this->bank), [
            'type' => 'tf', 'prompt' => 'Gambar ini sel tumbuhan.', 'difficulty' => 1, 'points' => 1, 'spec' => ['correct' => true], 'media' => [$card['id']],
        ])->assertSessionHasNoErrors();
        $q = $this->questions()->first();
        $this->assertCount(1, $q->attachments);

        // Guru lain tidak bisa melihat media soal
        $this->actingAs($this->other)->get(route('attachments.show', $card['id']))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('attachments.show', $card['id']))->assertOk();

        $this->actingAs($this->teacher)->post(route('questions.duplicate', $q))->assertSessionHas('success');
        $this->assertCount(2, $this->questions());
        $this->assertCount(1, $this->questions()[1]->attachments);

        $this->actingAs($this->teacher)->put(route('questions.update', $q), [
            'type' => 'tf', 'prompt' => 'Tanpa gambar.', 'difficulty' => 1, 'points' => 2, 'spec' => ['correct' => true], 'media' => [],
        ])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->questions()[0]->attachments);
        $this->assertSame(1, $this->inSchool($this->school, fn () => Attachment::count()));
    }

    public function test_import_excel_template_with_preview(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, app(QuestionTemplates::class)->xlsx());
        $file = fn () => new UploadedFile($path, 'soal.xlsx', null, null, true);

        $this->actingAs($this->teacher)->post(route('banks.import.preview', $this->bank), ['file' => $file()])
            ->assertOk()->assertJsonPath('valid', 4)->assertJsonPath('rows.1.type', 'mcm');
        $this->actingAs($this->teacher)->post(route('banks.import', $this->bank), ['file' => $file()])->assertSessionHas('success');

        $qs = $this->questions();
        $this->assertSame(['mc', 'mcm', 'tf', 'short'], $qs->pluck('type')->all());
        $this->assertSame(['a', 'c', 'e'], $qs[1]->data['answers']);
        $this->assertSame(['25', 'dua puluh lima'], $qs[3]->data['answers']);
        $this->assertSame(['Sel'], $qs[0]->tags);
        $this->assertSame(2, $qs[1]->points);
    }

    public function test_import_word_with_auto_detected_types_errors_and_image(): void
    {
        $png = UploadedFile::fake()->image('g.png', 50, 50)->getContent();
        $bin = SimpleDocx::build([
            'Ulangan Harian IPA',
            '1. Perhatikan gambar berikut.',
            ['image' => $png],
            'Gambar tersebut adalah ...',
            'A. Sel hewan',
            'B. Sel tumbuhan',
            'Kunci: B',
            'Pembahasan: Ada dinding sel.',
            '2. Air mendidih pada suhu 100 derajat Celsius.',
            'Kunci: Benar',
            '3. Soal tanpa kunci',
            'A. satu',
            'B. dua',
            '4. Sebutkan gas yang dihasilkan fotosintesis!',
            'Kunci: oksigen | O2',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'w').'.docx';
        file_put_contents($path, $bin);
        $file = fn () => new UploadedFile($path, 'soal.docx', null, null, true);

        $res = $this->actingAs($this->teacher)->post(route('banks.import.preview', $this->bank), ['file' => $file()])->assertOk();
        $res->assertJsonPath('total', 4)->assertJsonPath('valid', 3)->assertJsonPath('rows.0.images', 1)
            ->assertJsonPath('rows.2.errors.0', 'Kunci jawaban kosong.');

        $this->actingAs($this->teacher)->post(route('banks.import', $this->bank), ['file' => $file()])->assertSessionHas('success');
        $qs = $this->questions();
        $this->assertSame(['mc', 'tf', 'short'], $qs->pluck('type')->all());
        $this->assertStringContainsString('Gambar tersebut adalah', $qs[0]->prompt);
        $this->assertCount(1, $qs[0]->attachments);
        $this->assertSame('image', $qs[0]->attachments[0]->kind);
        $this->assertTrue($qs[1]->data['answer']);
    }

    public function test_templates_download(): void
    {
        $this->actingAs($this->teacher)->get(route('banks.template', 'xlsx'))->assertOk()->assertHeader('content-disposition', 'attachment; filename="templat-soal-madingo.xlsx"');
        $this->actingAs($this->teacher)->get(route('banks.template', 'docx'))->assertOk();
    }
}
