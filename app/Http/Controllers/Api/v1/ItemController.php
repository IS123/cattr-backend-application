<?php

namespace App\Http\Controllers\Api\v1;

use App\EventFilter\Facades\Filter;
use App\Helpers\QueryHelper;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;

abstract class ItemController extends Controller
{
    /**
     * @apiDefine ItemNotFoundError
     * @apiErrorExample {json} No such item
     *  HTTP/1.1 404 Not Found
     *  {
     *    "success": false,
     *    "message": "Item not found",
     *    "error_type": "query.item_not_found"
     *  }
     *
     * @apiVersion 1.0.0
     */

    /**
     * @apiDefine ValidationError
     * @apiErrorExample {json} Validation error
     *  HTTP/1.1 400 Bad Request
     *  {
     *    "success": false,
     *    "message": "Validation error",
     *    "error_type": "validation",
     *    "info": "Invalid id"
     *  }
     *
     * @apiError (Error 400) {String}  info  Validation errors
     *
     * @apiVersion 1.0.0
     */

    /**
     * Display a listing of the resource.
     * @throws Exception
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery(),
                $request->all() ?: []
            )
        );

        $paginate = $request->get('paginate', false);
        $currentPage = $request->get('page', 1);
        $perPage = $request->get('perPage', 15);

        return new JsonResponse(
            Filter::process(
                $this->getEventUniqueName('answer.success.item.list.result'),
                $paginate ?
                    $itemsQuery->paginate($perPage, ['*'], 'page', $currentPage)
                    : $itemsQuery->get()
            )
        );
    }

    /**
     * Returns event's name with current item's unique part
     */
    protected function getEventUniqueName(string $eventName): String
    {
        return "{$eventName}.{$this->getEventUniqueNamePart()}";
    }

    /**
     * Returns unique part of event name for current item
     */
    abstract public function getEventUniqueNamePart(): string;

    /**
     * @throws Exception
     */
    protected function applyQueryFilter(Builder $query, array $filter = []): Builder
    {
        $cls = static::getItemClass();
        $model = new $cls();
        $helper = new QueryHelper();

        $helper->apply($query, $filter, $model);

        return Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.filter'),
            $query
        );
    }

    /**
     * Returns current item's class name
     */
    abstract public function getItemClass(): string;

    /**
     * @param bool $withRelations
     * @param bool $withSoftDeleted
     * @return Builder
     */
    protected function getQuery($withRelations = true, $withSoftDeleted = false): Builder
    {
        /** @var Model $cls */
        $cls = static::getItemClass();

        $query = new Builder($cls::getQuery());
        $query->setModel(new $cls());

        $softDelete = in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($cls));

        if ($softDelete && !$withSoftDeleted) {
            if (method_exists($cls, 'getTable')) {
                $table = (new $cls())->getTable();
                $query->whereNull("$table.deleted_at");
            } else {
                $query->whereNull('deleted_at');
            }
        }

        if ($withRelations) {
            foreach ($this->getQueryWith() as $with) {
                $query->with($with);
            }
        }

        return Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.get'),
            $query
        );
    }

    public function getQueryWith(): array
    {
        return [];
    }

    /**
     * Display count of the resource
     *
     * @throws Exception
     */
    public function count(Request $request): JsonResponse
    {
        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery(),
                $request->all() ?: []
            )
        );

        return new JsonResponse([
            'success' => true,
            'total' => Filter::process(
                $this->getEventUniqueName('answer.success.item.list.count.query.prepare'),
                $itemsQuery->count()
            )
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $requestData = Filter::process($this->getEventUniqueName('request.item.create'), $request->all());

        $validator = Validator::make(
            $requestData,
            Filter::process($this->getEventUniqueName('validation.item.create'), $this->getValidationRules())
        );

        if ($validator->fails()) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        $cls = $this->getItemClass();

        Event::dispatch($this->getEventUniqueName('item.create.before'), $requestData);

        $item = Filter::process(
            $this->getEventUniqueName('item.create'),
            $cls::create($this->filterRequestData($requestData))
        );

        Event::dispatch($this->getEventUniqueName('item.create.after'), [$item, $requestData]);

        return new JsonResponse(
            Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                'success' => true,
                'res' => $item,
            ])
        );
    }

    /**
     * Returns validation rules for current item
     */
    abstract public function getValidationRules(): array;

    /**
     * Opportunity to filtering request data
     *
     * Override this in child class for filtering
     */
    protected function filterRequestData(array $requestData): array
    {
        return $requestData;
    }

    /**
     * Display the specified resource.
     *
     * @throws ModelNotFoundException
     * @throws Exception
     */
    public function show(Request $request): JsonResponse
    {
        $itemId = intval($request->input('id'));

        if (!$itemId) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.show'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => 'Invalid id'
                ]),
                400
            );
        }

        $filters = [
            'id' => $itemId
        ];
        $request->get('with') ? $filters['with'] = $request->get('with') : false;
        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery(),
                $filters ?: []
            )
        );

        $item = $itemsQuery->first();

        if (!$item) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.show'), [
                    'success' => false,
                    'error_type' => 'query.item_not_found',
                    'message' => 'Item not found'
                ]),
                404
            );
        }

        return new JsonResponse(
            Filter::process($this->getEventUniqueName('answer.success.item.show'), $item)
        );
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @throws MassAssignmentException
     * @throws ModelNotFoundException
     * @throws Exception
     */
    public function edit(Request $request): JsonResponse
    {
        $requestData = Filter::process(
            $this->getEventUniqueName('request.item.edit'),
            $request->all()
        );

        $validationRules = $this->getValidationRules();
        $validationRules['id'] = ['required'];

        $validator = Validator::make(
            $requestData,
            Filter::process(
                $this->getEventUniqueName('validation.item.edit'),
                $validationRules
            )
        );

        if ($validator->fails()) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => $validator->errors()
                ]),
                400
            );
        }

        if (!is_int($request->get('id'))) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => 'Invalid id'
                ]),
                400
            );
        }

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery()
            )
        );

        /** @var Model $item */
        $item = collect($itemsQuery->get())->first(static function ($val, $key) use ($request) {
            return $val['id'] === $request->get('id');
        });

        if (!$item) {
            $cls = $this->getItemClass();
            if ($cls::find($request->get('id')) !== null) {
                return new JsonResponse(
                    Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                        'success' => false,
                        'error_type' => 'authorization.forbidden',
                        'message' => 'Access denied to this item',
                    ]),
                    403
                );
            }

            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'success' => false,
                    'error_type' => 'query.item_not_found',
                    'message' => 'Item not found',
                ]),
                404
            );
        }

        $item->fill($this->filterRequestData($requestData));
        $item = Filter::process($this->getEventUniqueName('item.edit'), $item);
        $item->save();

        Event::dispatch($this->getEventUniqueName('item.edit.after'), [$item, $requestData]);

        return new JsonResponse(
            Filter::process($this->getEventUniqueName('answer.success.item.edit'), [
                'success' => true,
                'res' => $item,
            ])
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @throws Exception
     */
    public function destroy(Request $request): JsonResponse
    {
        $itemId = Filter::process($this->getEventUniqueName('request.item.destroy'), $request->get('id'));
        $idInt = is_int($itemId);

        if (!$idInt) {
            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.destroy'), [
                    'success' => false,
                    'error_type' => 'validation',
                    'message' => 'Validation error',
                    'info' => 'Invalid id',
                ]),
                400
            );
        }

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery(),
                ['id' => $itemId]
            )
        );

        /** @var Model $item */
        $item = $itemsQuery->first();
        if (!$item) {
            $cls = $this->getItemClass();
            if ($cls::find($request->get('id')) !== null) {
                return new JsonResponse(
                    Filter::process($this->getEventUniqueName('answer.error.item.remove'), [
                        'success' => false,
                        'error_type' => 'authorization.forbidden',
                        'message' => 'Access denied to this item'
                    ]),
                    403
                );
            }

            return new JsonResponse(
                Filter::process($this->getEventUniqueName('answer.error.item.remove'), [
                    'success' => false,
                    'error_type' => 'query.item_not_found',
                    'message' => 'Item not found',
                ]),
                404
            );
        }

        $item->delete();

        return new JsonResponse(
            Filter::process($this->getEventUniqueName('answer.success.item.remove'), [
                'success' => true,
                'message' => 'Item has been removed'
            ])
        );
    }
}
