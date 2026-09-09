<?php

declare(strict_types=1);

namespace FachDock\Operations;

use DomainException;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class StudentSupportController
{
    public function __construct(
        private readonly LockerSupportService $support,
        private readonly StudentSupportSessionService $sessions,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function loginPage(Request $request): Response
    {
        unset($request);
        if ($this->sessions->current() !== null) {
            return Response::redirect('/student/support');
        }

        return Response::html($this->views->render('student-support-login.php', [
            'csrfToken' => $this->csrf->token(),
            'errors' => [],
        ]));
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->loginError('Die Sitzung ist abgelaufen. Bitte erneut versuchen.', 419);
        }

        try {
            $this->sessions->login(
                $request->postString('matrikelnummer'),
                $request->postString('access_code'),
            );
            $this->csrf->rotate();

            return Response::redirect('/student/support');
        } catch (DomainException $exception) {
            $this->logger->notice('Student support login rejected', [
                'matrikelnummer' => $request->postString('matrikelnummer'),
                'ip_address' => $request->clientIp(),
            ]);

            return $this->loginError($exception->getMessage(), 422);
        }
    }

    public function index(Request $request): Response
    {
        $student = $this->sessions->current();
        if ($student === null) {
            return Response::redirect('/student/support/login');
        }

        return $this->page(
            $student,
            [],
            ($request->query()['reported'] ?? null) === '1',
        );
    }

    public function report(Request $request): Response
    {
        $student = $this->sessions->current();
        if ($student === null) {
            return Response::redirect('/student/support/login');
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($student, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $this->support->reportFromStudent(
                (int) $student['id'],
                $this->positiveInt($request->postString('booking_id')),
                $request->postString('category'),
                $request->postString('description'),
            );
            $this->csrf->rotate();

            return Response::redirect('/student/support?reported=1');
        } catch (DomainException $exception) {
            return $this->page($student, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Student locker support report failed', [
                'error_id' => $errorId,
                'student_id' => (int) $student['id'],
                'exception' => $exception,
            ]);

            return $this->page(
                $student,
                ['Die Meldung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                false,
                500,
            );
        }
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }
        $this->sessions->logout();
        $this->csrf->rotate();

        return Response::redirect('/student/support/login');
    }

    /** @param array<string, mixed> $student
     *  @param list<string> $errors
     */
    private function page(array $student, array $errors, bool $success, int $status = 200): Response
    {
        $studentId = (int) $student['id'];

        return Response::html($this->views->render('student-support.php', [
            'csrfToken' => $this->csrf->token(),
            'student' => $student,
            'errors' => $errors,
            'success' => $success,
            'assignments' => $this->support->reportableForStudent($studentId),
            'incidents' => $this->support->incidentsForStudent($studentId),
            'categories' => LockerIncidentCategory::cases(),
        ]), $status);
    }

    private function loginError(string $message, int $status): Response
    {
        return Response::html($this->views->render('student-support-login.php', [
            'csrfToken' => $this->csrf->token(),
            'errors' => [$message],
        ]), $status);
    }

    private function positiveInt(string $value): int
    {
        $value = trim($value);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        return (int) $value;
    }
}
