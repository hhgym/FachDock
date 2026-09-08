<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Throwable;
use UnexpectedValueException;

final class StripePaymentController
{
    public function __construct(
        private readonly StripePaymentService $payments,
        private readonly ParentSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/parent/payment/start', fn (Request $request): Response => $this->start($request));
        $router->get('/parent/payment/return', fn (Request $request): Response => $this->returnFromStripe($request));
        $router->post('/webhooks/stripe', fn (Request $request): Response => $this->webhook($request));
    }

    private function start(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $reservationId = $this->positiveInt($request->postString('reservation_id'), 'Reservierung');
            $result = $this->payments->start($parent, $reservationId);
            $this->csrf->rotate();

            if ($result->bookingId !== null) {
                $this->audit->parent($parent, 'payment.no_fee_booking.created', 'booking', $result->bookingId, [
                    'reservation_id' => $reservationId,
                ]);

                return Response::redirect('/parent?booking_created=1');
            }

            $this->audit->parent($parent, 'payment.stripe_checkout.started', 'payment', $result->paymentId, [
                'reservation_id' => $reservationId,
            ]);

            return Response::redirect($result->checkoutUrl(), 303);
        } catch (DomainException $exception) {
            return $this->errorPage($parent, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Stripe checkout start failed', [
                'error_id' => $errorId,
                'parent_contact_id' => $parent->id,
                'exception' => $exception,
            ]);

            return $this->errorPage(
                $parent,
                'Der Zahlungsvorgang konnte nicht gestartet werden. Fehler-ID: ' . $errorId,
                500,
            );
        }
    }

    private function returnFromStripe(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }

        try {
            $paymentId = $this->positiveInt($this->queryString($request, 'payment_id'), 'Zahlung');
            $payment = $this->payments->paymentForParent($parent, $paymentId);

            return Response::html($this->views->render('parent-payment.php', [
                'parent' => $parent,
                'payment' => $payment,
                'csrfToken' => $this->csrf->token(),
                'error' => null,
            ]));
        } catch (DomainException $exception) {
            return $this->errorPage($parent, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Stripe payment return page failed', [
                'error_id' => $errorId,
                'parent_contact_id' => $parent->id,
                'exception' => $exception,
            ]);

            return $this->errorPage(
                $parent,
                'Der Zahlungsstatus konnte nicht geladen werden. Fehler-ID: ' . $errorId,
                500,
            );
        }
    }

    private function webhook(Request $request): Response
    {
        try {
            $this->payments->handleWebhook(
                $request->rawBody(),
                $request->header('Stripe-Signature'),
            );

            return Response::text('ok');
        } catch (SignatureVerificationException|UnexpectedValueException|DomainException $exception) {
            $this->logger->notice('Stripe webhook rejected', [
                'message' => $exception->getMessage(),
            ]);

            return Response::text('invalid webhook', 'text/plain; charset=utf-8', 400);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Stripe webhook processing failed', [
                'error_id' => $errorId,
                'exception' => $exception,
            ]);

            return Response::text('webhook processing failed', 'text/plain; charset=utf-8', 500);
        }
    }

    private function parent(): AuthenticatedParent|Response
    {
        $parent = $this->sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        return $parent;
    }

    private function errorPage(AuthenticatedParent $parent, string $message, int $status): Response
    {
        return Response::html($this->views->render('parent-payment.php', [
            'parent' => $parent,
            'payment' => null,
            'csrfToken' => $this->csrf->token(),
            'error' => $message,
        ]), $status);
    }

    private function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
