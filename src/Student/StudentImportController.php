<?php

declare(strict_types=1);

namespace FachDock\Student;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Http\UploadedFile;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use PDO;
use RuntimeException;
use Throwable;

final class StudentImportController
{
    private const SESSION_PENDING = 'student_import_pending';

    public function __construct(
        private readonly string $root,
        private readonly PDO $pdo,
        private readonly CsvStudentParser $parser,
        private readonly StudentImportService $imports,
        private readonly StudentImportProfileService $profiles,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/students', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/students/import/profiles', fn (Request $request): Response => $this->createProfile($request));
        $router->post('/admin/students/import/preview', fn (Request $request): Response => $this->preview($request));
        $router->post('/admin/students/import/commit', fn (Request $request): Response => $this->commit($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page(
            $staff,
            null,
            [],
            ($request->query()['imported'] ?? null) === '1',
        );
    }

    private function createProfile(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, null, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $this->profiles->create(
                $request->postString('profile_name'),
                $this->delimiter($request->postString('profile_delimiter', ';')),
                '"',
                $this->encoding($request->postString('profile_encoding', 'UTF-8')),
                [
                    'matrikelnummer' => $request->postString('header_matrikelnummer'),
                    'first_name' => $request->postString('header_first_name'),
                    'last_name' => $request->postString('header_last_name'),
                    'class_name' => $request->postString('header_class_name'),
                    'grade' => $request->postString('header_grade'),
                    'email' => $request->postString('header_email'),
                    'active' => $request->postString('header_active'),
                ],
            );
            $this->csrf->rotate();

            return Response::redirect('/admin/students');
        } catch (Throwable $exception) {
            return $this->page($staff, null, [$exception->getMessage()], false, 422);
        }
    }

    private function preview(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, null, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $file = $request->uploadedFile('csv_file');
            if ($file === null) {
                throw new RuntimeException('Bitte eine CSV-Datei auswählen.');
            }
            $file->assertValid();

            $profileId = $this->positiveIntOrNull($request->postString('profile_id'));
            $mapping = [];
            $enclosure = '"';
            if ($profileId !== null) {
                $profile = $this->profiles->find($profileId);
                $delimiter = $profile['delimiter'];
                $enclosure = $profile['enclosure'];
                $encoding = $profile['encoding'];
                $mapping = $profile['mapping'];
            } else {
                $delimiter = $this->delimiter($request->postString('delimiter', ';'));
                $encoding = $this->encoding($request->postString('encoding', 'UTF-8'));
            }

            $fullImport = $request->postString('full_import') === '1';
            $pending = $this->stage(
                $file,
                $delimiter,
                $enclosure,
                $encoding,
                $mapping,
                $fullImport,
                $profileId,
            );
            $_SESSION[self::SESSION_PENDING] = $pending;

            $rows = $this->parser->parse(
                $pending['path'],
                $pending['delimiter'],
                $pending['enclosure'],
                $pending['encoding'],
                $pending['mapping'],
            );
            $preview = $this->imports->preview($rows, $pending['full_import']);

            return $this->page($staff, $preview, [], false);
        } catch (Throwable $exception) {
            $this->clearPending();

            return $this->page($staff, null, [$exception->getMessage()], false, 422);
        }
    }

    private function commit(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, null, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $pending = $this->pending();
            if ($request->postString('token') !== $pending['token']) {
                throw new RuntimeException('Die Importvorschau ist nicht mehr gültig.');
            }

            $rows = $this->parser->parse(
                $pending['path'],
                $pending['delimiter'],
                $pending['enclosure'],
                $pending['encoding'],
                $pending['mapping'],
            );
            $preview = $this->imports->preview($rows, $pending['full_import']);
            $result = $this->imports->commit(
                $preview,
                $staff->id,
                $pending['filename'],
                $pending['full_import'],
                $request->postString('skip_invalid') === '1',
                $pending['profile_id'],
            );

            $this->clearPending();
            $this->csrf->rotate();

            if ($result['access_codes'] !== []) {
                return Response::download(
                    $this->accessCodeCsv($result['access_codes']),
                    'FachDock-Zugangscodes-Import-' . $result['run_id'] . '.csv',
                    'text/csv; charset=utf-8',
                );
            }

            return Response::redirect('/admin/students?imported=1');
        } catch (Throwable $exception) {
            return $this->page($staff, null, [$exception->getMessage()], false, 422);
        }
    }

    private function administrator(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        return $staff;
    }

    /** @param list<string> $errors */
    private function page(
        AuthenticatedStaff $staff,
        ?StudentImportPreview $preview,
        array $errors,
        bool $success,
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('students.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'preview' => $preview,
            'pending' => $this->pendingOrNull(),
            'profiles' => $this->profiles->all(),
            'errors' => $errors,
            'success' => $success,
            'stats' => $this->studentStats(),
        ]), $status);
    }

    /**
     * @param array<string, string> $mapping
     * @return array{token:string,path:string,filename:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>,full_import:bool,profile_id:?int}
     */
    private function stage(
        UploadedFile $file,
        string $delimiter,
        string $enclosure,
        string $encoding,
        array $mapping,
        bool $fullImport,
        ?int $profileId,
    ): array {
        $directory = $this->root . '/storage/imports/pending';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Importverzeichnis konnte nicht angelegt werden.');
        }

        $token = bin2hex(random_bytes(24));
        $target = $directory . '/' . $token . '.csv';
        $moved = is_uploaded_file($file->temporaryPath)
            ? move_uploaded_file($file->temporaryPath, $target)
            : copy($file->temporaryPath, $target);
        if (!$moved) {
            throw new RuntimeException('Die CSV-Datei konnte nicht für die Vorschau gespeichert werden.');
        }
        @chmod($target, 0600);

        return [
            'token' => $token,
            'path' => $target,
            'filename' => mb_substr(basename($file->name), 0, 255),
            'delimiter' => $delimiter,
            'enclosure' => $enclosure,
            'encoding' => $encoding,
            'mapping' => $mapping,
            'full_import' => $fullImport,
            'profile_id' => $profileId,
        ];
    }

    /** @return array{token:string,path:string,filename:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>,full_import:bool,profile_id:?int} */
    private function pending(): array
    {
        $pending = $this->pendingOrNull();
        if ($pending === null || !is_file($pending['path'])) {
            throw new RuntimeException('Es liegt keine gültige Importvorschau mehr vor.');
        }

        return $pending;
    }

    /** @return array{token:string,path:string,filename:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>,full_import:bool,profile_id:?int}|null */
    private function pendingOrNull(): ?array
    {
        $pending = $_SESSION[self::SESSION_PENDING] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        foreach (['token', 'path', 'filename', 'delimiter', 'enclosure', 'encoding', 'mapping'] as $key) {
            if (!array_key_exists($key, $pending)) {
                return null;
            }
        }
        if (!is_string($pending['token']) || !is_string($pending['path']) || !is_string($pending['filename'])
            || !is_string($pending['delimiter']) || !is_string($pending['enclosure']) || !is_string($pending['encoding'])
            || !is_array($pending['mapping'])) {
            return null;
        }

        $mapping = [];
        foreach ($pending['mapping'] as $field => $header) {
            if (is_string($field) && is_string($header)) {
                $mapping[$field] = $header;
            }
        }

        $profileId = $pending['profile_id'] ?? null;

        return [
            'token' => $pending['token'],
            'path' => $pending['path'],
            'filename' => $pending['filename'],
            'delimiter' => $pending['delimiter'],
            'enclosure' => $pending['enclosure'],
            'encoding' => $pending['encoding'],
            'mapping' => $mapping,
            'full_import' => ($pending['full_import'] ?? false) === true,
            'profile_id' => is_int($profileId) ? $profileId : null,
        ];
    }

    private function clearPending(): void
    {
        $pending = $this->pendingOrNull();
        if ($pending !== null && is_file($pending['path'])) {
            @unlink($pending['path']);
        }
        unset($_SESSION[self::SESSION_PENDING]);
    }

    private function delimiter(string $value): string
    {
        return match ($value) {
            ';', ',' => $value,
            'tab' => "\t",
            default => throw new RuntimeException('Ungültiges CSV-Trennzeichen.'),
        };
    }

    private function encoding(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, ['UTF-8', 'WINDOWS-1252', 'ISO-8859-1'], true)) {
            throw new RuntimeException('Nicht unterstützte CSV-Zeichenkodierung.');
        }

        return $value;
    }

    private function positiveIntOrNull(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('Ungültiges Importprofil.');
        }

        return (int) $value;
    }

    /** @return array{total:int,active:int,inactive:int} */
    private function studentStats(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS total, SUM(active = 1) AS active, SUM(active = 0) AS inactive FROM students'
        );
        if ($row === false) {
            return ['total' => 0, 'active' => 0, 'inactive' => 0];
        }
        $values = $row->fetch(PDO::FETCH_ASSOC);
        if (!is_array($values)) {
            return ['total' => 0, 'active' => 0, 'inactive' => 0];
        }

        return [
            'total' => (int) ($values['total'] ?? 0),
            'active' => (int) ($values['active'] ?? 0),
            'inactive' => (int) ($values['inactive'] ?? 0),
        ];
    }

    /** @param list<array{matrikelnummer:string,first_name:string,last_name:string,class_name:string,access_code:string}> $rows */
    private function accessCodeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Zugangscode-CSV konnte nicht erzeugt werden.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Matrikelnummer', 'Vorname', 'Nachname', 'Klasse', 'Zugangscode'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, [
                $this->safeCsv($row['matrikelnummer']),
                $this->safeCsv($row['first_name']),
                $this->safeCsv($row['last_name']),
                $this->safeCsv($row['class_name']),
                $row['access_code'],
            ], ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($csv)) {
            throw new RuntimeException('Zugangscode-CSV konnte nicht gelesen werden.');
        }

        return $csv;
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
