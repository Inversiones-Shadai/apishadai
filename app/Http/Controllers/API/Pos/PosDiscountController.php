<?php

namespace App\Http\Controllers\API\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosDiscountRequest;
use App\Http\Resources\Pos\PosManagementResource;
use App\Repositories\Pos\PosDiscountRepository;
use App\Services\Images\ImageStorage;
use App\Utils\Services\Base64UtilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosDiscountController extends Controller
{
    public function __construct(private readonly PosDiscountRepository $repository) {}

    public function index(): JsonResponse
    {
        return $this->sendResponse(PosManagementResource::collection($this->repository->paginate()), 'ok', 200, true);
    }

    public function show(int $id): JsonResponse
    {
        $record = $this->repository->find($id);

        return $record === null ? $this->sendError('Regla no encontrada.', null, 404)
            : $this->sendResponse(PosManagementResource::make($record), 'ok');
    }

    public function choices(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['tipo' => 'required|in:STOCK,CATEGORIA,PRODUCTO', 'buscar' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:100000']);
        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $type = $request->input('tipo');
        $query = DB::table(match ($type) {
            'STOCK' => 'stocks', 'CATEGORIA' => 'prod_categorias', default => 'productos'
        });
        $name = $type === 'CATEGORIA' ? 'nombre' : 'descripcion';
        $search = trim((string) $request->input('buscar', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($name, $search): void {
                $q->where($name, 'like', '%'.$search.'%');
            });
        }
        $columns = ['id', $name.' as nombre'];
        if ($type === 'PRODUCTO') {
            $columns[] = 'codigo';
            $columns[] = 'imagen';
        }

        $page = $query->select($columns)->orderBy($name)->orderBy('id')->paginate($type === 'PRODUCTO' ? 10 : 30);
        if ($type === 'PRODUCTO') {
            $page->through(function (object $product): array {
                return ['id' => $product->id, 'nombre' => $product->nombre, 'codigo' => $product->codigo,
                    'imagen' => $this->productThumbnail($product->imagen)];
            });
        }

        return $this->sendResponse($page, 'ok', 200, true);
    }

    private function productThumbnail(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (Str::isUuid($stored)) {
            return app(ImageStorage::class)->url($stored);
        }
        $base64 = app(Base64UtilityService::class)->validate($stored);
        if ($base64 === null) {
            return null;
        }
        $binary = base64_decode($base64, true);
        $info = getimagesizefromstring($binary);
        if (! function_exists('imagecreatefromstring')) {
            return 'data:'.$info['mime'].';base64,'.$base64;
        }
        $source = imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }
        $scale = min(1, 128 / $info[0], 128 / $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $thumbnail = imagecreatetruecolor($width, $height);
        try {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            ob_start();
            try {
                imagepng($thumbnail);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            return 'data:image/png;base64,'.base64_encode($png);
        } finally {
            unset($source, $thumbnail);
        }
    }

    public function store(PosDiscountRequest $request): JsonResponse
    {
        return $this->persist($request, null, 'GUARDAR');
    }

    public function update(PosDiscountRequest $request, int $id): JsonResponse
    {
        return $this->persist($request, $id, 'GUARDAR');
    }

    public function destroy(PosDiscountRequest $request, int $id): JsonResponse
    {
        return $this->persist($request, $id, 'DESACTIVAR');
    }

    public function delete(PosDiscountRequest $request, int $id): JsonResponse
    {
        return $this->persist($request, $id, 'ELIMINAR');
    }

    private function persist(PosDiscountRequest $request, ?int $id, string $operation): JsonResponse
    {
        try {
            Validator::make(['solicitud' => $request->header('Idempotency-Key')], ['solicitud' => 'required|uuid'])->validate();
            $response = $this->repository->write($request->header('Idempotency-Key'), (int) $request->user()->getAuthIdentifier(), $operation, $id, $request->validated());

            $message = match ($operation) {
                'DESACTIVAR' => 'Regla desactivada.',
                'ELIMINAR' => 'Regla eliminada.',
                default => 'Regla guardada.',
            };

            return $this->sendResponse($response, $message, $id === null ? 201 : 200);
        } catch (ValidationException $exception) {
            return $this->sendError($exception->getMessage(), ['errors' => $exception->errors()], 422);
        } catch (ModelNotFoundException) {
            return $this->sendError('Regla no encontrada.', null, 404);
        } catch (HttpException $exception) {
            return $this->sendError($exception->getMessage(), null, $exception->getStatusCode());
        }
    }
}
