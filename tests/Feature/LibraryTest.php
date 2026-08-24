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

    public function test_library_starts_empty_and_exposes_explorer_interface(): void
    {
        self::assertSame(0, DocumentFolder::query()->count());
        self::assertSame(0, LibraryDocument::query()->count());

        $this->get('/bibliotheque')
            ->assertOk()
            ->assertSee('Bibliothèque')
            ->assertSee('Aucun dossier n’est créé automatiquement.')
            ->assertSee('Nouveau dossier')
            ->assertSee('Importer un fichier')
            ->assertSee('Ce dossier est vide')
            ->assertSee('Bibliothèque</span>', false);
    }

    public function test_folder_crud_breadcrumb_upload_download_and_recursive_delete(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        $year = DocumentFolder::query()->where('name', '2026')->firstOrFail();

        $this->post('/bibliotheque/dossiers', ['parent_id' => $year->id, 'name' => 'Août'])
            ->assertRedirect('/bibliotheque/'.$year->id);
        $august = DocumentFolder::query()->where('parent_id', $year->id)->where('name', 'Août')->firstOrFail();

        $this->get('/bibliotheque/'.$august->id)
            ->assertOk()->assertSee('2026')->assertSee('Août')->assertSee('Ce dossier est vide');

        $this->patch('/bibliotheque/dossiers/'.$august->id, ['name' => 'Aout 2026'])
            ->assertRedirect('/bibliotheque/'.$year->id);
        self::assertSame('Aout 2026', $august->fresh()->name);

        $file = UploadedFile::fake()->createWithContent('bulletin-aout.txt', 'salaire-aout');
        $this->post('/bibliotheque/fichiers', ['folder_id' => $august->id, 'document' => $file])
            ->assertRedirect('/bibliotheque/'.$august->id);

        $document = LibraryDocument::query()->firstOrFail();
        self::assertSame($august->id, $document->folder_id);
        self::assertSame('bulletin-aout.txt', $document->original_name);
        $storedPath = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name;
        self::assertFileExists($storedPath);
        self::assertSame('salaire-aout', file_get_contents($storedPath));

        $download = $this->get('/bibliotheque/fichiers/'.$document->id.'/telecharger')->assertOk();
        self::assertStringContainsString('bulletin-aout.txt', (string) $download->headers->get('content-disposition'));

        $this->delete('/bibliotheque/dossiers/'.$year->id)->assertRedirect('/bibliotheque');
        self::assertSame(0, DocumentFolder::query()->count());
        self::assertSame(0, LibraryDocument::query()->count());
        self::assertFileDoesNotExist($storedPath);
    }

    public function test_duplicate_folder_name_is_rejected_only_within_same_parent(): void
    {
        $this->post('/bibliotheque/dossiers', ['name' => '2026'])->assertRedirect('/bibliotheque');
        $this->from('/bibliotheque')->post('/bibliotheque/dossiers', ['name' => '2026'])
            ->assertRedirect('/bibliotheque')->assertSessionHasErrors('name');

        $year = DocumentFolder::query()->where('name', '2026')->firstOrFail();
        $this->post('/bibliotheque/dossiers', ['parent_id' => $year->id, 'name' => '2026'])
            ->assertRedirect('/bibliotheque/'.$year->id);

        self::assertSame(2, DocumentFolder::query()->count());
    }

    public function test_file_can_be_stored_at_library_root_and_deleted(): void
    {
        $this->post('/bibliotheque/fichiers', [
            'document' => UploadedFile::fake()->createWithContent('racine.txt', 'root-file'),
        ])->assertRedirect('/bibliotheque');

        $document = LibraryDocument::query()->firstOrFail();
        self::assertNull($document->folder_id);
        $path = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name;
        self::assertFileExists($path);

        $this->delete('/bibliotheque/fichiers/'.$document->id)->assertRedirect('/bibliotheque');
        self::assertDatabaseCount('library_documents', 0);
        self::assertFileDoesNotExist($path);
    }
}
