<?php

namespace Tests\Feature;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
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
            ->assertSee('Bibliothèque')
            ->assertSee('Aucun dossier n’est créé automatiquement.')
            ->assertSee('Nouveau dossier')
            ->assertSee('Corbeille')
            ->assertDontSee('Ajouter une image')
            ->assertSee('Votre bibliothèque est vide')
            ->assertSee('Bibliothèque</span>', false);

        $this->get('/bibliotheque/corbeille')
            ->assertOk()
            ->assertSee('La corbeille est vide')
            ->assertSee('Retour à la bibliothèque');
    }

    public function test_folder_trash_requires_exact_name_can_restore_and_force_delete_recursively(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        $year = DocumentFolder::query()->where('name', '2026')->firstOrFail();

        $this->post('/bibliotheque/dossiers', ['parent_id' => $year->id, 'name' => 'Août'])
            ->assertRedirect('/bibliotheque/'.$year->id);
        $august = DocumentFolder::query()->where('parent_id', $year->id)->where('name', 'Août')->firstOrFail();

        $this->get('/bibliotheque/'.$august->id)
            ->assertOk()->assertSee('2026')->assertSee('Août')->assertSee('Ajouter une image')->assertSee('Ce dossier est vide');

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

        $this->from('/bibliotheque')->delete('/bibliotheque/dossiers/'.$year->id, ['confirmation_name' => '2026 '])
            ->assertRedirect('/bibliotheque')->assertSessionHasErrors('confirmation_name');
        self::assertFalse($year->fresh()->trashed());

        $this->delete('/bibliotheque/dossiers/'.$year->id, ['confirmation_name' => '2026'])->assertRedirect('/bibliotheque');
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

        $this->delete('/bibliotheque/dossiers/'.$year->id, ['confirmation_name' => '2026'])->assertRedirect('/bibliotheque');
        $this->from('/bibliotheque/corbeille')->delete('/bibliotheque/corbeille/dossiers/'.$year->id, ['confirmation_name' => '2025'])
            ->assertRedirect('/bibliotheque/corbeille')->assertSessionHasErrors('confirmation_name');
        self::assertNotNull(DocumentFolder::withTrashed()->find($year->id));
        self::assertFileExists($storedPath);

        $this->delete('/bibliotheque/corbeille/dossiers/'.$year->id, ['confirmation_name' => '2026'])
            ->assertRedirect('/bibliotheque/corbeille');
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

        $this->delete('/bibliotheque/dossiers/'.$original->id, ['confirmation_name' => '2026'])->assertRedirect('/bibliotheque');
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        self::assertSame(1, DocumentFolder::query()->where('name', '2026')->count());
        self::assertSame(2, DocumentFolder::withTrashed()->where('name', '2026')->count());

        $this->post('/bibliotheque/corbeille/dossiers/'.$original->id.'/restaurer')
            ->assertRedirect('/bibliotheque/corbeille')->assertSessionHasErrors('trash');
        self::assertTrue(DocumentFolder::withTrashed()->findOrFail($original->id)->trashed());
    }
}
