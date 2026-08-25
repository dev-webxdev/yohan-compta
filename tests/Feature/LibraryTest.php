<?php

namespace Tests\Feature;

use App\Models\DocumentFolder;
use App\Models\DocumentLink;
use App\Models\LibraryDocument;
use App\Models\MonthlySalary;
use App\Models\WorkDay;
use App\Services\LibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $libraryRoot;
    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->libraryRoot = storage_path('framework/testing/library-'.bin2hex(random_bytes(5)));
        config(['filesystems.disks.local.root' => $this->libraryRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->libraryRoot);
        config(['filesystems.disks.local.root' => $this->originalLocalRoot]);
        parent::tearDown();
    }

    public function test_library_starts_empty_requires_a_folder_for_upload_and_exposes_trash(): void
    {
        self::assertSame(0, DocumentFolder::query()->count());
        self::assertSame(0, LibraryDocument::query()->count());

        $this->get('/bibliotheque')
            ->assertOk()
            ->assertSee('Documents')
            ->assertSee('Aucun dossier n’est créé automatiquement.')
            ->assertSee('Nouveau dossier')
            ->assertSee('Corbeille')
            ->assertDontSee('Ajouter un document')
            ->assertSee('Votre espace Documents est vide')
            ->assertSee('Documents</span>', false);

        $this->get('/bibliotheque/corbeille')
            ->assertOk()
            ->assertSee('La corbeille est vide')
            ->assertSee('Retour aux documents');
    }

    public function test_folder_trash_can_restore_and_force_delete_recursively(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        $year = DocumentFolder::query()->where('name', '2026')->firstOrFail();

        $this->post('/bibliotheque/dossiers', ['parent_id' => $year->id, 'name' => 'Août'])
            ->assertRedirect('/bibliotheque/'.$year->id);
        $august = DocumentFolder::query()->where('parent_id', $year->id)->where('name', 'Août')->firstOrFail();

        $this->get('/bibliotheque/'.$august->id)
            ->assertOk()->assertSee('2026')->assertSee('Août')->assertSee('Ajouter un document')->assertSee('Ce dossier est vide');

        $this->patch('/bibliotheque/dossiers/'.$august->id, ['name' => 'Aout 2026'])
            ->assertRedirect('/bibliotheque/'.$year->id);
        self::assertSame('Aout 2026', $august->fresh()->name);

        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $august->id,
            'document' => UploadedFile::fake()->image('bulletin-aout.png', 40, 30),
        ])->assertRedirect('/bibliotheque/'.$august->id);

        $document = LibraryDocument::query()->firstOrFail();
        self::assertSame($august->id, $document->folder_id);
        self::assertSame('bulletin-aout.png', $document->original_name);
        self::assertSame('image/png', $document->mime_type);
        $storedPath = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name;
        self::assertFileExists($storedPath);

        $download = $this->get('/bibliotheque/fichiers/'.$document->id.'/telecharger')->assertOk();
        self::assertStringContainsString('bulletin-aout.png', (string) $download->headers->get('content-disposition'));

        $this->delete('/bibliotheque/dossiers/'.$year->id)->assertRedirect('/bibliotheque');
        $trashedYear = DocumentFolder::withTrashed()->findOrFail($year->id);
        self::assertTrue($trashedYear->trashed());
        self::assertDatabaseHas('document_folders', ['id' => $august->id, 'deleted_at' => null]);
        self::assertDatabaseHas('library_documents', ['id' => $document->id, 'deleted_at' => null]);
        self::assertFileExists($storedPath);
        $this->get('/bibliotheque/'.$august->id)->assertNotFound();
        $this->get('/bibliotheque/fichiers/'.$document->id.'/telecharger')->assertNotFound();

        $this->get('/bibliotheque/corbeille')->assertOk()->assertSee('2026');
        $this->post('/bibliotheque/corbeille/dossiers/'.$year->id.'/restaurer')->assertRedirect('/bibliotheque/corbeille');
        self::assertFalse(DocumentFolder::query()->findOrFail($year->id)->trashed());
        $this->get('/bibliotheque/'.$august->id)->assertOk()->assertSee('bulletin-aout.png');

        $this->delete('/bibliotheque/dossiers/'.$year->id)->assertRedirect('/bibliotheque');
        $this->delete('/bibliotheque/corbeille/dossiers/'.$year->id)->assertRedirect('/bibliotheque/corbeille');
        self::assertNull(DocumentFolder::withTrashed()->find($year->id));
        self::assertNull(DocumentFolder::withTrashed()->find($august->id));
        self::assertNull(LibraryDocument::withTrashed()->find($document->id));
        self::assertFileDoesNotExist($storedPath);
    }

    public function test_upload_accepts_only_real_images_inside_an_active_folder(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => 'Photos'])->assertRedirect('/bibliotheque');
        $folder = DocumentFolder::query()->where('name', 'Photos')->firstOrFail();

        $this->from('/bibliotheque')->post('/bibliotheque/fichiers', [
            'document' => UploadedFile::fake()->image('racine.png'),
        ])->assertRedirect('/bibliotheque')->assertSessionHasErrors('folder_id');
        self::assertDatabaseCount('library_documents', 0);

        $this->from('/bibliotheque/'.$folder->id)->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->createWithContent('attaque.jpg', "<?php echo 'danger';"),
        ])->assertRedirect('/bibliotheque/'.$folder->id)->assertSessionHasErrors('document');
        self::assertDatabaseCount('library_documents', 0);

        $this->from('/bibliotheque/'.$folder->id)->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'not-an-image'),
        ])->assertRedirect('/bibliotheque/'.$folder->id)->assertSessionHasErrors('document');
        self::assertDatabaseCount('library_documents', 0);

        $png = UploadedFile::fake()->image('source.png', 20, 20);
        $this->from('/bibliotheque/'.$folder->id)->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->createWithContent('image-renommee.jpg', file_get_contents($png->getRealPath())),
        ])->assertRedirect('/bibliotheque/'.$folder->id)->assertSessionHasErrors('document');
        self::assertDatabaseCount('library_documents', 0);

        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->image('photo.webp', 24, 24),
        ])->assertRedirect('/bibliotheque/'.$folder->id);

        $document = LibraryDocument::query()->firstOrFail();
        self::assertSame($folder->id, $document->folder_id);
        self::assertSame('image/webp', $document->mime_type);
    }

    public function test_pdf_documents_are_accepted_and_kept_secure(): void
    {
        $folder = DocumentFolder::query()->create(['name' => 'Bulletins']);
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->createWithContent('bulletin.pdf', $pdf),
        ])->assertRedirect('/bibliotheque/'.$folder->id);

        $document = LibraryDocument::query()->firstOrFail();
        self::assertSame('application/pdf', $document->mime_type);
        self::assertSame('bulletin.pdf', $document->original_name);
        self::assertFileExists(app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name);
        $this->get('/bibliotheque/'.$folder->id)->assertOk()->assertSee('PDF');
        $preview = $this->get('/bibliotheque/fichiers/'.$document->id.'/voir')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        self::assertStringContainsString('inline', (string) $preview->headers->get('content-disposition'));
        self::assertStringContainsString('bulletin.pdf', (string) $preview->headers->get('content-disposition'));
    }

    public function test_document_can_be_associated_with_salary_and_opened_from_salary_page(): void
    {
        $salary = MonthlySalary::query()->create([
            'month' => '2026-08',
            'net_amount_cents' => 190000,
        ]);
        $folder = DocumentFolder::query()->create(['name' => 'Paie']);
        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->image('bulletin.png', 32, 24),
        ])->assertRedirect('/bibliotheque/'.$folder->id);
        $document = LibraryDocument::query()->firstOrFail();

        $this->post('/bibliotheque/fichiers/'.$document->id.'/associations', [
            'target' => 'salary:'.$salary->id,
        ])->assertRedirect();

        $link = DocumentLink::query()->firstOrFail();
        self::assertSame($document->id, $link->document_id);
        self::assertSame('salary', $link->target_type);
        self::assertSame((string) $salary->id, $link->target_key);

        $this->get('/salaires')
            ->assertOk()
            ->assertSee('Documents associés : 1')
            ->assertSee('target=salary%3A'.$salary->id, false);
        $this->get('/bibliotheque?target=salary%3A'.$salary->id)
            ->assertOk()
            ->assertSee('Documents associés')
            ->assertSee('bulletin.png');

        $this->delete('/bibliotheque/fichiers/'.$document->id.'/associations/'.$link->id)->assertRedirect();
        self::assertDatabaseCount('document_links', 0);
    }

    public function test_document_association_only_offers_existing_salaries_and_months_with_recorded_hours(): void
    {
        $salary = MonthlySalary::query()->create([
            'month' => '2025-07',
            'net_amount_cents' => 185000,
        ]);
        WorkDay::query()->create([
            'date' => '2025-08-12',
            'driving_minutes' => 120,
            'warehouse_minutes' => 30,
            'meal_allowance_mode' => 'auto',
        ]);
        WorkDay::query()->create([
            'date' => '2025-09-12',
            'driving_minutes' => 0,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $folder = DocumentFolder::query()->create(['name' => 'Associations']);
        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->image('justificatif.png', 28, 20),
        ])->assertRedirect('/bibliotheque/'.$folder->id);
        $document = LibraryDocument::query()->firstOrFail();

        $this->get('/bibliotheque/'.$folder->id)
            ->assertOk()
            ->assertSee('Salaire')
            ->assertSee('Heures du mois')
            ->assertSee('Salaire Juillet 2025')
            ->assertSee('Août 2025')
            ->assertDontSee('Septembre 2025')
            ->assertDontSee('Paiement heures sup');

        $this->post('/bibliotheque/fichiers/'.$document->id.'/associations', [
            'target' => 'month:2025-08',
        ])->assertRedirect();
        self::assertDatabaseHas('document_links', [
            'document_id' => $document->id,
            'target_type' => 'month',
            'target_key' => '2025-08',
        ]);

        $this->from('/bibliotheque/'.$folder->id)->post('/bibliotheque/fichiers/'.$document->id.'/associations', [
            'target' => 'month:2025-09',
        ])->assertRedirect('/bibliotheque/'.$folder->id)->assertSessionHasErrors('target');
        $this->from('/bibliotheque/'.$folder->id)->post('/bibliotheque/fichiers/'.$document->id.'/associations', [
            'target' => 'payment:1',
        ])->assertRedirect('/bibliotheque/'.$folder->id)->assertSessionHasErrors('target');

        self::assertSame(1, DocumentLink::query()->count());
        self::assertSame($salary->id, MonthlySalary::query()->firstOrFail()->id);
    }

    public function test_document_trash_restore_and_permanent_delete_preserve_file_until_final_deletion(): void
    {
        $folder = DocumentFolder::query()->create(['name' => 'Images']);
        $this->post('/bibliotheque/fichiers', [
            'folder_id' => $folder->id,
            'document' => UploadedFile::fake()->image('preuve.png', 30, 30),
        ])->assertRedirect('/bibliotheque/'.$folder->id);

        $document = LibraryDocument::query()->firstOrFail();
        $path = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name;
        self::assertFileExists($path);

        $this->delete('/bibliotheque/fichiers/'.$document->id)->assertRedirect('/bibliotheque/'.$folder->id);
        self::assertNull(LibraryDocument::query()->find($document->id));
        self::assertTrue(LibraryDocument::withTrashed()->findOrFail($document->id)->trashed());
        self::assertFileExists($path);
        $this->get('/bibliotheque/fichiers/'.$document->id.'/telecharger')->assertNotFound();
        $this->get('/bibliotheque/corbeille')->assertOk()->assertSee('preuve.png');

        $this->post('/bibliotheque/corbeille/fichiers/'.$document->id.'/restaurer')->assertRedirect('/bibliotheque/corbeille');
        self::assertFalse(LibraryDocument::query()->findOrFail($document->id)->trashed());
        self::assertFileExists($path);

        $this->delete('/bibliotheque/fichiers/'.$document->id)->assertRedirect('/bibliotheque/'.$folder->id);
        $this->delete('/bibliotheque/corbeille/fichiers/'.$document->id)->assertRedirect('/bibliotheque/corbeille');
        self::assertNull(LibraryDocument::withTrashed()->find($document->id));
        self::assertFileDoesNotExist($path);
    }

    public function test_duplicate_active_folder_name_is_rejected_but_trashed_name_can_be_reused(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        $original = DocumentFolder::query()->where('name', '2026')->firstOrFail();
        $this->from('/bibliotheque')->post('/bibliotheque/dossiers', ['name' => '2026'])
            ->assertRedirect('/bibliotheque')->assertSessionHasErrors('name');

        $this->delete('/bibliotheque/dossiers/'.$original->id)->assertRedirect('/bibliotheque');
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        self::assertSame(1, DocumentFolder::query()->where('name', '2026')->count());
        self::assertSame(2, DocumentFolder::withTrashed()->where('name', '2026')->count());

        $this->post('/bibliotheque/corbeille/dossiers/'.$original->id.'/restaurer')
            ->assertRedirect('/bibliotheque/corbeille')->assertSessionHasErrors('trash');
        self::assertTrue(DocumentFolder::withTrashed()->findOrFail($original->id)->trashed());
    }
}
