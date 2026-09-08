<?php

declare(strict_types=1);

namespace FachDock\Location;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

final class LocationAdminController
{
    public function __construct(
        private readonly LocationCatalogService $catalog,
        private readonly CorpusTypeService $corpusTypes,
        private readonly CabinetGroupService $cabinetGroups,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/locations', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/locations/buildings', fn (Request $request): Response => $this->createBuilding($request));
        $router->post('/admin/locations/buildings/update', fn (Request $request): Response => $this->updateBuilding($request));
        $router->post('/admin/locations/floors', fn (Request $request): Response => $this->createFloor($request));
        $router->post('/admin/locations/floors/update', fn (Request $request): Response => $this->updateFloor($request));
        $router->post('/admin/locations/areas', fn (Request $request): Response => $this->createArea($request));
        $router->post('/admin/locations/areas/update', fn (Request $request): Response => $this->updateArea($request));
        $router->post('/admin/locations/corpus-types', fn (Request $request): Response => $this->createCorpusType($request));
        $router->post('/admin/locations/corpus-types/update', fn (Request $request): Response => $this->updateCorpusType($request));
        $router->post('/admin/locations/cabinet-groups', fn (Request $request): Response => $this->createCabinetGroup($request));
        $router->post('/admin/locations/cabinet-groups/update', fn (Request $request): Response => $this->updateCabinetGroup($request));
        $router->post('/admin/locations/cabinet-groups/delete', fn (Request $request): Response => $this->deleteCabinetGroup($request));
        $router->post(
            '/admin/locations/cabinet-groups/restructure',
            fn (Request $request): Response => $this->restructureCabinetGroup($request),
        );
    }

    private function index(Request $request): Response
    {
        unset($request);
        if ($this->sessions->current() === null) {
            return Response::redirect('/login');
        }

        return $this->page();
    }

    private function createBuilding(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->catalog->createBuilding($request->postString('code'), $request->postString('name'));
            $this->audit->staff($staff, 'location.building.created', 'building', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function updateBuilding(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('id'), 'Gebäude');
            $this->catalog->updateBuilding(
                $id,
                $request->postString('code'),
                $request->postString('name'),
                $this->postFlag($request, 'active'),
            );
            $this->audit->staff($staff, 'location.building.updated', 'building', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function createFloor(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->catalog->createFloor(
                $this->positiveInt($request->postString('building_id'), 'Gebäude'),
                $request->postString('code'),
                $request->postString('name'),
                $this->integer($request->postString('sort_order', '0'), 'Sortierung'),
            );
            $this->audit->staff($staff, 'location.floor.created', 'floor', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function updateFloor(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('id'), 'Etage');
            $this->catalog->updateFloor(
                $id,
                $this->positiveInt($request->postString('building_id'), 'Gebäude'),
                $request->postString('code'),
                $request->postString('name'),
                $this->integer($request->postString('sort_order', '0'), 'Sortierung'),
                $this->postFlag($request, 'active'),
            );
            $this->audit->staff($staff, 'location.floor.updated', 'floor', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function createArea(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->catalog->createArea(
                $this->positiveInt($request->postString('floor_id'), 'Etage'),
                $request->postString('code'),
                $request->postString('name'),
            );
            $this->audit->staff($staff, 'location.area.created', 'area', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function updateArea(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('id'), 'Bereich');
            $this->catalog->updateArea(
                $id,
                $this->positiveInt($request->postString('floor_id'), 'Etage'),
                $request->postString('code'),
                $request->postString('name'),
                $this->postFlag($request, 'active'),
            );
            $this->audit->staff($staff, 'location.area.updated', 'area', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function createCorpusType(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $count = $this->positiveInt($request->postString('compartment_count'), 'Fachanzahl');
            $id = $this->corpusTypes->create(
                $request->postString('code'),
                $request->postString('name'),
                $count,
                $this->integerSequence($request->postString('barrier_positions')),
            );
            $this->audit->staff($staff, 'location.corpus_type.created', 'corpus_type', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function updateCorpusType(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('id'), 'Korpustyp');
            $this->corpusTypes->update(
                $id,
                $request->postString('code'),
                $request->postString('name'),
                $this->positiveInt($request->postString('compartment_count'), 'Fachanzahl'),
                $this->integerSequence($request->postString('barrier_positions')),
                [],
                $this->postFlag($request, 'active'),
            );
            $this->audit->staff($staff, 'location.corpus_type.updated', 'corpus_type', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function createCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->cabinetGroups->create(
                $this->positiveInt($request->postString('area_id'), 'Bereich'),
                $request->postString('name'),
                $this->integerSequence($request->postString('corpus_sequence'), false),
            );
            $this->audit->staff($staff, 'location.cabinet_group.created', 'cabinet_group', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function updateCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('group_id'), 'Schrankgruppe');
            $this->cabinetGroups->updateMetadata(
                $id,
                $this->positiveInt($request->postString('area_id'), 'Bereich'),
                $request->postString('name'),
                $this->postFlag($request, 'active'),
            );
            $this->audit->staff($staff, 'location.cabinet_group.updated', 'cabinet_group', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function restructureCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('group_id'), 'Schrankgruppe');
            $this->cabinetGroups->replaceStructure(
                $id,
                $this->integerSequence($request->postString('corpus_sequence'), false),
            );
            $this->audit->staff($staff, 'location.cabinet_group.restructured', 'cabinet_group', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    private function deleteCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('group_id'), 'Schrankgruppe');
            $this->cabinetGroups->deleteUnused($id);
            $this->audit->staff($staff, 'location.cabinet_group.deleted', 'cabinet_group', $id, [
                'ip_address' => $request->clientIp(),
            ]);
        });
    }

    /** @param callable(AuthenticatedStaff): void $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page(['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }

        try {
            $operation($staff);
            $this->csrf->rotate();

            return Response::redirect('/admin/locations');
        } catch (DomainException $exception) {
            return $this->page([$exception->getMessage()], 422);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return $this->page(['Ein Kürzel oder eine Bezeichnung ist bereits vergeben oder noch referenziert.'], 422);
            }

            return $this->technicalFailure($exception);
        } catch (Throwable $exception) {
            return $this->technicalFailure($exception);
        }
    }

    private function technicalFailure(Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Location administration failed', [
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        return $this->page(['Technischer Fehler bei der Standortverwaltung. Fehler-ID: ' . $errorId], 500);
    }

    /** @param list<string> $errors */
    private function page(array $errors = [], int $status = 200): Response
    {
        return Response::html($this->views->render('locations.php', [
            'staff' => $this->sessions->current(),
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'buildings' => $this->catalog->buildings(),
            'floors' => $this->catalog->floors(),
            'areas' => $this->catalog->areas(),
            'corpusTypes' => $this->catalog->corpusTypes(),
            'cabinetGroups' => $this->catalog->cabinetGroups(),
        ]), $status);
    }

    /** @return list<int> */
    private function integerSequence(string $value, bool $allowEmpty = true): array
    {
        $value = trim($value);
        if ($value === '') {
            if ($allowEmpty) {
                return [];
            }
            throw new DomainException('Die Korpusreihenfolge darf nicht leer sein.');
        }

        $result = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $part) {
            if ($part === '' || !ctype_digit($part) || (int) $part < 1) {
                throw new DomainException('Die Reihenfolge darf nur positive Korpustyp-IDs enthalten.');
            }
            $result[] = (int) $part;
        }

        return $result;
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function integer(string $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function postFlag(Request $request, string $key): bool
    {
        $value = $request->post()[$key] ?? null;

        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }
}
