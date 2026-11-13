<?php

namespace Tests\Feature\Site;

use App\Admin\AdminDownloads;
use App\Downloads\DownloadRules;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Models\User;
use App\Site\Downloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DownloadsPageTest extends TestCase
{
    use RefreshDatabase, SeedsSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOwner();
        $this->makeDepartment('CIT', 'cit', ['id' => 10]);
        $this->makeDepartment('CBA', 'cba', ['id' => 11]);
        $this->makeDownload('Enrolment form', null, null);
        $this->makeDownload('Handbook', 'General', 10);
        $this->makeDownload('Syllabus', 'Academics', 10, '["curriculum"]');
        $this->makeDownload('Schedule', 'Academics', 11);
        DownloadDirectory::query()->create(['name' => 'Empty folder', 'department_id' => 11]);
    }

    private function names(array $files): array
    {
        $names = array_column($files, 'fileName');
        sort($names);

        return $names;
    }

    public function test_it_hands_over_the_layout(): void
    {
        $page = Downloads::getDownloadsPage([]);
        $this->assertSame(['CBA', 'CIT'], array_column($page['layout']['departments'], 'name'));
        $this->assertSame([], $page['layout']['navLinks']);
    }

    public function test_it_counts_downloads_per_directory_files_without_one_under_general(): void
    {
        $page = Downloads::getDownloadsPage([]);
        $this->assertSame(4, $page['totalCount']);
        $this->assertSame([['Academics', 2], ['Empty folder', 0], ['General', 2]], array_map(fn (array $f) => [$f['name'], $f['count']], $page['folders']));
        $groups = array_column(array_map(fn (array $g) => [$g['name'], $this->names($g['files'])], $page['groups']), 1, 0);
        $this->assertSame(['Enrolment form', 'Handbook'], $groups['General']);
        $this->assertSame(['Schedule', 'Syllabus'], $groups['Academics']);
        $this->assertFalse($page['seo']['noindex']);
        $this->assertSame('/downloads', $page['seo']['canonical']);
    }

    public function test_it_narrows_the_list_by_directory_while_counts_keep_covering_every_directory(): void
    {
        $page = Downloads::getDownloadsPage(['directory' => 'General']);
        $this->assertSame(['Enrolment form', 'Handbook'], $this->names($page['downloads']));
        $this->assertSame('General', $page['selected']);
        $this->assertSame(4, $page['totalCount']);
        $academics = array_values(array_filter($page['folders'], fn (array $f) => $f['name'] === 'Academics'));
        $this->assertSame(2, $academics[0]['count']);
        $this->assertTrue($page['seo']['noindex']);
        $this->assertTrue($page['seo']['follow']);
        $this->assertSame('/downloads', $page['seo']['canonical']);
    }

    public function test_it_filters_by_search_and_department_and_carries_both_into_the_folder_links(): void
    {
        $page = Downloads::getDownloadsPage(['search' => 'curriculum', 'departmentId' => 10]);
        $this->assertSame(['Syllabus'], $this->names($page['downloads']));
        $this->assertSame(1, $page['totalCount']);
        $this->assertNotContains('Empty folder', array_column($page['folders'], 'name'));
        $academics = array_values(array_filter($page['folders'], fn (array $f) => $f['name'] === 'Academics'));
        $this->assertSame('/downloads?search=curriculum&department_id=10&directory=Academics', $academics[0]['href']);
        $this->assertSame('/downloads?search=curriculum&department_id=10', $page['allHref']);
        $this->assertTrue($page['seo']['noindex']);
    }

    public function test_an_unknown_directory_is_an_empty_list(): void
    {
        $page = Downloads::getDownloadsPage(['directory' => 'Nope']);
        $this->assertSame([], $page['downloads']);
        $this->assertSame([], $page['groups']);
    }

    public function test_directory_names_are_listed_once_by_name(): void
    {
        DownloadDirectory::query()->create(['name' => 'Empty folder', 'department_id' => 10]);
        $this->assertSame(['Empty folder'], DownloadRules::getDirectoryNames());
    }

    public function test_tags_encode_the_comma_separated_field_as_json_and_decode_it_back(): void
    {
        $this->assertSame('["a","b","c"]', DownloadRules::encodeTags(' a, b ,,c'));
        $this->assertSame('[]', DownloadRules::encodeTags(null));
        $this->assertSame(['freshman', '2026'], DownloadRules::parseTags(DownloadRules::encodeTags('freshman, 2026')));
    }

    public function test_tags_tolerate_legacy_comma_separated_double_encoded_and_empty_values(): void
    {
        $this->assertSame(['a', 'b'], DownloadRules::parseTags('a, b'));
        $this->assertSame(['a', 'b'], DownloadRules::parseTags('"a,b"'));
        $this->assertSame([], DownloadRules::parseTags('  '));
        $this->assertSame([], DownloadRules::parseTags(null));
    }

    public function test_download_facts_take_the_extension_from_the_stored_key_else_the_file_name(): void
    {
        $facts = fn (string $path, string $name) => DownloadRules::downloadFacts(new Download(['file_path' => $path, 'file_name' => $name]));
        $this->assertSame(['url' => '/storage/downloads/abc.PDF', 'extension' => 'pdf', 'kind' => 'pdf'], $facts('downloads/abc.PDF', 'Handbook.docx'));
        $this->assertSame(['docx', 'doc'], [$facts('downloads/abc', 'Handbook.docx')['extension'], $facts('downloads/abc', 'Handbook.docx')['kind']]);
        $this->assertSame(['', 'other'], [$facts('downloads/abc', 'Handbook')['extension'], $facts('downloads/abc', 'Handbook')['kind']]);
    }

    public function test_the_admin_list_falls_back_to_the_file_name_extension_for_an_extensionless_key(): void
    {
        $row = new Download(['file_name' => 'Calendar.xlsx', 'file_path' => 'downloads/cal', 'file_size' => 10, 'tags' => '[]']);
        $row->created_at = $this->day(1);
        $view = AdminDownloads::toAdminDownload($row);
        $this->assertSame('/storage/downloads/cal', $view['url']);
        $this->assertSame('xlsx', $view['extension']);
        $this->assertSame('sheet', $view['kind']);
    }

    public function test_a_download_without_a_directory_is_general_in_the_file_view(): void
    {
        $row = Download::query()->with('department')->where('file_name', 'Enrolment form')->firstOrFail();
        $this->assertNull($row->directory);
        $view = AdminDownloads::toAdminDownload($row);
        $this->assertSame(DownloadRules::DEFAULT_DIRECTORY, $view['directory']);
        $this->assertSame([], $view['tags']);
        $this->assertNull($view['departmentName']);
    }

    private function admin(string $role, ?int $departmentId): User
    {
        return User::query()->create(['name' => $role, 'email' => "{$role}{$departmentId}@example.test", 'role' => $role, 'department_id' => $departmentId]);
    }

    public function test_the_folder_map_merges_files_without_a_directory_into_general(): void
    {
        $view = AdminDownloads::getAdminFolders($this->admin(User::ROLE_SUPERADMIN, null), ['kind' => 'all'], fn (string $n) => "?folder={$n}");
        $this->assertSame(4, $view['totalFiles']);
        $this->assertSame(400, $view['totalBytes']);
        $this->assertSame(
            [['Empty folder', 0, true, true], ['General', 2, false, false], ['Academics', 2, false, false]],
            array_map(fn (array $f) => [$f['name'], $f['count'], $f['id'] !== null, $f['canDelete']], $view['folders']),
        );
        $this->assertSame('?folder=Academics', array_values(array_filter($view['folders'], fn ($f) => $f['name'] === 'Academics'))[0]['href']);
    }

    public function test_a_department_user_sees_their_department_but_shared_folders_stay_listed(): void
    {
        $user = $this->admin(User::ROLE_DEPARTMENT_USER, 10);
        $view = AdminDownloads::getAdminFolders($user, ['kind' => 'department', 'id' => 10, 'name' => 'CIT', 'slug' => 'cit'], fn (string $n) => "?folder={$n}");
        $this->assertSame(2, $view['totalFiles']);
        $this->assertSame([['Academics', 1], ['General', 1]], array_map(fn (array $f) => [$f['name'], $f['count']], $view['folders']));
    }

    public function test_the_general_directory_filter_includes_files_stored_without_one(): void
    {
        $general = Download::query()->where(DownloadRules::directoryWhere(DownloadRules::DEFAULT_DIRECTORY))->pluck('file_name')->sort()->values()->all();
        $this->assertSame(['Enrolment form', 'Handbook'], $general);
        $this->assertSame(['Schedule', 'Syllabus'], Download::query()->where(DownloadRules::directoryWhere('Academics'))->pluck('file_name')->sort()->values()->all());
    }
}
