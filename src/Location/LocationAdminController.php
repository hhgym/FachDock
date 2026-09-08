<?php

declare(strict_types=1);

namespace FachDock\Location;

use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Throwable;

final class LocationAdminController
{
    public function __construct(
        private readonly LocationCatalogService $catalog,
        private readonly CorpusTypeService $corpusTypes,
        private readonly CabinetGroupService $cabinetGroups,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/locations', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/locations/buildings', fn (Request $request): Response => $this->createBuilding($request));
        $router->post('/admin/locations/floors', fn (Request $request): Response => $this->createFloor($request));
        $router->post('/admin/locations/areas', fn (Request $request): Response => $this->createArea($request));
        $router->post('/admin/locations/corpus-types', fn (Request $request): Response => $this->createCorpusType($request));
        $router->post('/admin/locations/cabinet-groups', fn (Request $request): Response => $this->createCabinetGroup($request));
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
        return $this->mutate($request, function () use ($request): void {
            $this->catalog->createBuilding($request->postString('code'), $request->postString('name'));
        });
    }

    private function createFloor(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): void {
            $this->catalog->createFloor(
                $this->positiveInt($request->postString('building_id'), 'Gebäude'),
                $request->postString('code'),
                $request->postString('name'),
                (int) $request->postString('sort_order', '0'),
            );
        });
    }

    private function createArea(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): void {
            $this->catalog->createArea(
                $this->positiveInt($request->postString('floor_id'), 'Etage'),
                $request->postString('code'),
                $request->postString('name'),
            );
        });
    }

    private function createCorpusType(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): void {
            $count = $this->positiveInt($request->postString('compartment_count'), 'Fachanzahl');
            $barrierPositions = $this->integerSequence($request->postString('barrier_positions'));
            $this->corpusTypes->create(
                $request->postString('code'),
                $request->postString('name'),
                $count,
                $barrierPositions,
            );
        });
    }

    private function createCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): void {
            $this->cabinetGroups->create(
                $this->positiveInt($request->postString('area_id'), 'Bereich'),
                $request->postString('name'),
                $this->integerSequence($request->postString('corpus_sequence'), false),
            );
        });
    }

    private function restructureCabinetGroup(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): void {
            $this->cabinetGroups->replaceStructure(
                $this->positiveInt($request->postString('group_id'), 'Schrankgruppe'),
                $this->integerSequence($request->postString('corpus_sequence'), false),
            );
        });
    }

    /** @param callable(): void $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        if ($this->sessions->current() === null) {
            return Response::redirect('/login');
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page(['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }

        try {
            $operation();
            $this->csrf->rotate();

            return Response::redirect('/admin/locations');
        } catch (Throwable $exception) {
            return $this->page([$exception->getMessage()], 422);
        }
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
            throw new \DomainException('Die Korpusreihenfolge darf nicht leer sein.');
        }

        $result = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $part) {
            if ($part === '' || !ctype_digit($part) || (int) $part < 1) {
                throw new \DomainException('Die Reihenfolge darf nur positive Korpustyp-IDs enthalten.');
            }
            $result[] = (int) $part;
        }

        return $result;
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }
}
