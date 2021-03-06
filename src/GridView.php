<?php

declare(strict_types=1);

namespace Assurrussa\GridView;

use Assurrussa\GridView\Exception\ColumnsException;
use Assurrussa\GridView\Exception\QueryException;
use Assurrussa\GridView\Export\ExportData;
use Assurrussa\GridView\Interfaces\ColumnInterface;
use Assurrussa\GridView\Interfaces\GridInterface;
use Assurrussa\GridView\Models\Model;
use Assurrussa\GridView\Support\Button;
use Assurrussa\GridView\Support\Buttons;
use Assurrussa\GridView\Support\Column;
use Assurrussa\GridView\Support\ColumnCeil;
use Assurrussa\GridView\Support\Columns;
use Assurrussa\GridView\Support\EloquentPagination;
use Assurrussa\GridView\Support\Input;
use Assurrussa\GridView\Support\Inputs;
use Illuminate\Contracts\Support\Renderable;

/**
 * Class GridView
 *
 * @package Assurrussa\GridView
 */
class GridView implements GridInterface
{

    const NAME = 'amiGrid';

    /** @var array */
    private $_config;
    /** @var \Illuminate\Support\Collection $_request */
    private $_request;
    /** @var \Illuminate\Database\Eloquent\Builder */
    private $_query;
    /** @var \Illuminate\Database\Eloquent\Model */
    private $_model;
    /** @var bool */
    private $_isStrict;

    /** @var array */
    protected $requestParams = [];
    /** @var string */
    protected $formAction = '';

    /** @var string */
    public $id;
    /** @var boolean */
    public $ajax = true;
    /** @var boolean */
    public $isTrimLastSlash = true;
    /** @var int */
    public $page;
    /** @var int */
    public $limit;
    /** @var string */
    public $orderBy;
    /** @var string */
    public $orderByDefault = Column::FILTER_ORDER_BY_ASC;
    /** @var string */
    public $search;
    /** @var bool */
    public $searchInput = false;
    /** @var string */
    public $sortName;
    /** @var string */
    public $sortNameDefault = 'id';
    /** @var array */
    public $counts;
    /** @var array */
    public $filter;
    /** @var bool */
    public $export = false;
    /** @var bool */
    public $exportCurrent = false;
    /**  @var bool */
    public $visibleColumn;
    /** @var Columns */
    public $columns;
    /** @var Buttons */
    public $buttons;
    /** @var Inputs */
    public $inputs;
    /** @var EloquentPagination */
    public $pagination;

    /**
     * GridView constructor.
     */
    public function __construct(
        Columns $columns,
        Buttons $buttons,
        Inputs $inputs,
        EloquentPagination $eloquentPagination
    ) {
        $this->_config = config('amigrid');
        $this->columns = $columns;
        $this->buttons = $buttons;
        $this->inputs = $inputs;
        $this->pagination = $eloquentPagination;
        $this->counts = $this->_getConfig('limit', [
            10  => 10,
            25  => 25,
            100 => 100,
            200 => 200,
        ]);
        $this->filter = $this->_getConfig('filter', [
            'operator'    => 'like',
            'beforeValue' => '',
            'afterValue'  => '%',
        ]);
        $this->setVisibleColumn((bool)$this->_getConfig('visibleColumn', true));
        $this->setStrictMode((bool)$this->_getConfig('strict', true));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return GridInterface
     */
    public function setQuery(\Illuminate\Database\Eloquent\Builder $query): GridInterface
    {
        $this->_query = $query;
        $this->_model = $this->_query->getModel();
        $this->pagination->setQuery($this->_query);

        return $this;
    }

    /**
     * example:
     * $fields = [
     *             'ID'            => 'id',
     *             'Time'          => 'setup_at',
     *             0               => 'brand.name',
     *             'Name'          => function() {return 'name';},
     *         ];
     *
     * @param array $fields
     *
     * @return $this
     */
    public function setFieldsForExport(array $array): GridInterface
    {
        $this->columns->setFields($array);

        return $this;
    }

    /**
     * @return Button
     */
    public function button(): Button
    {
        $button = new Button();
        $this->buttons->setButton($button);

        return $button;
    }

    /**
     * @param string $name
     * @param string $title
     *
     * @return Column
     */
    public function column(string $name = null, string $title = null): Column
    {
        $column = new Column();
        $this->columns->setColumn($column);

        if ($name) {
            $column->setKey($name);
        }
        if ($title) {
            $column->setValue($title);
        }

        return $column;
    }

    /**
     * @return Input
     */
    public function input(): Input
    {
        $input = new Input();
        $this->inputs->setInput($input);

        return $input;
    }

    /**
     * @param callable    $action
     * @param string|null $value
     *
     * @return ColumnInterface
     */
    public function columnActions(Callable $action, string $value = null): ColumnInterface
    {
        return $this->column(Column::ACTION_NAME, $value)->setActions($action);
    }

    /**
     * @return Button
     */
    public function columnAction(): Button
    {
        return new Button();
    }

    /**
     * @return ColumnCeil
     */
    public static function columnCeil(): ColumnCeil
    {
        return new ColumnCeil();
    }

    /**
     * @param array  $data
     * @param string $path
     * @param array  $mergeData
     *
     * @return string
     * @throws \Throwable
     */
    public function render(array $data = [], string $path = 'gridView', array $mergeData = []): string
    {
        if (request()->ajax() || request()->wantsJson()) {
            $path = $path === 'gridView' ? 'part.grid' : $path;
        }

        return static::view($path, $data, $mergeData)->render();
    }

    /**
     * @param array  $data
     * @param string $path
     * @param array  $mergeData
     *
     * @return string
     * @throws \Throwable
     */
    public function renderFirst(array $data = [], string $path = 'gridView', array $mergeData = []): string
    {
        $path = $path === 'gridView' ? 'part.tableTrItem' : $path;

        $headers = $data['data']->headers;
        $item = (array)$data['data']->data;

        return static::view($path, [
            'headers' => $headers,
            'item'    => $item,
        ], $mergeData)->render();
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param string|null $view
     * @param array       $data
     * @param array       $mergeData
     *
     * @return Renderable
     */
    public static function view(string $view = null, array $data = [], array $mergeData = []): Renderable
    {
        return view(self::NAME . '::' . $view, $data, $mergeData);
    }

    /**
     * Translate the given message.
     *
     * @param string|null $id
     * @param array       $parameters
     * @param string|null $locale
     *
     * @return string
     */
    public static function trans(string $id = null, array $parameters = [], string $locale = null): string
    {
        return (string)trans(self::NAME . '::' . $id, $parameters, $locale);
    }

    /**
     * Return get result
     *
     * @return \Assurrussa\GridView\Helpers\GridViewResult
     * @throws ColumnsException
     * @throws QueryException
     */
    public function get(): \Assurrussa\GridView\Helpers\GridViewResult
    {
        $gridViewResult = $this->_getGridView();

        $gridViewResult->data = $this->pagination->get($this->page, $this->limit);
        $gridViewResult->pagination = $this->_getPaginationRender();
        $gridViewResult->simple = false;

        return $gridViewResult;
    }

    /**
     * @param bool $isCount
     *
     * @return Helpers\GridViewResult
     * @throws ColumnsException
     * @throws QueryException
     */
    public function getSimple(bool $isCount = false): \Assurrussa\GridView\Helpers\GridViewResult
    {
        $gridViewResult = $this->_getGridView($isCount);
        $gridViewResult->data = $this->pagination->getSimple($this->page, $this->limit, $isCount);
        $gridViewResult->pagination = $this->_getPaginationRender();
        $gridViewResult->simple = true;

        return $gridViewResult;
    }

    /**
     * @return \Assurrussa\GridView\Helpers\GridViewResult
     * @throws ColumnsException
     * @throws QueryException
     */
    public function first(): \Assurrussa\GridView\Helpers\GridViewResult
    {
        $this->_fetch();

        $_listRow = [];
        if ($instance = $this->_query->first()) {
            if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
                foreach ($this->columns->getColumns() as $column) {
                    $_listRow[$column->getKey()] = $column->getValues($instance);
                }
                $buttons = $this->columns->filterActions($instance);
                if (count($buttons)) {
                    $_listRow = array_merge($_listRow, [Column::ACTION_NAME => implode('', $buttons)]);
                }
            }
        }

        $gridViewResult = new \Assurrussa\GridView\Helpers\GridViewResult();
        $gridViewResult->headers = $this->columns->toArray();
        $gridViewResult->data = $_listRow;

        return $gridViewResult;
    }

    /**
     * @param string $id
     *
     * @return GridView
     */
    public function setId(string $id): GridInterface
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        if (!$this->id) {
            $this->setId(self::NAME . '_' . 1);
        }

        return $this->id;
    }

    /**
     * @param array $array
     *
     * @return GridInterface
     */
    public function setCounts(array $array): GridInterface
    {
        $this->counts = $array;

        return $this;
    }

    /**
     * @param bool $visibleColumn
     *
     * @return GridInterface
     */
    public function setVisibleColumn(bool $visibleColumn): GridInterface
    {
        $this->visibleColumn = $visibleColumn;

        return $this;
    }

    /**
     * @param bool $isStrict
     *
     * @return GridInterface
     */
    public function setStrictMode(bool $isStrict): GridInterface
    {
        $this->_isStrict = $isStrict;

        return $this;
    }

    /**
     * @param bool $isAjax
     *
     * @return GridInterface
     */
    public function setAjax(bool $isAjax): GridInterface
    {
        $this->ajax = $isAjax;

        return $this;
    }

    /**
     * @param bool $isTrimLastSlash
     *
     * @return GridInterface
     */
    public function setTrimLastSlash(bool $isTrimLastSlash): GridInterface
    {
        $this->isTrimLastSlash = $isTrimLastSlash;

        return $this;
    }

    /**
     * @param bool $searchInput
     *
     * @return $this
     */
    public function setSearchInput(bool $searchInput = false): GridInterface
    {
        $this->searchInput = $searchInput;

        return $this;
    }

    /**
     * @return GridView
     */
    public function setOrderByDesc(): GridInterface
    {
        $this->orderByDefault = Column::FILTER_ORDER_BY_DESC;

        return $this;
    }

    /**
     * @return string
     */
    public function getOrderBy(): string
    {
        return $this->orderByDefault;
    }

    /**
     * @return string
     */
    public function getSortName(): string
    {
        return $this->sortNameDefault;
    }

    /**
     * @param string $sortNameDefault
     *
     * @return $this
     */
    public function setSortName(string $sortNameDefault): GridInterface
    {
        $this->sortNameDefault = $sortNameDefault;

        return $this;
    }

    /**
     * @param bool $export
     *
     * @return GridView
     */
    public function setExport(bool $export): GridInterface
    {
        $this->export = $export;

        return $this;
    }

    /**
     * @param string $url
     *
     * @return $this
     */
    public function setFormAction(string $url): GridInterface
    {
        $this->formAction = $url;

        return $this;
    }

    /**
     * @return string
     */
    public function getFormAction(): string
    {
        return $this->formAction;
    }

    /**
     * @return bool
     */
    public function isExport(): bool
    {
        return $this->export && $this->exportCurrent;
    }

    /**
     * @return boolean
     */
    public function isVisibleColumn(): bool
    {
        return $this->visibleColumn;
    }

    /**
     * @return bool
     */
    public function isSearchInput(): bool
    {
        return $this->searchInput;
    }

    /**
     * @return bool
     */
    public function isStrictMode(): bool
    {
        return $this->_isStrict;
    }

    /**
     * @param bool $isCount
     *
     * @return Helpers\GridViewResult
     * @throws ColumnsException
     * @throws QueryException
     */
    private function _getGridView(bool $isCount = false): \Assurrussa\GridView\Helpers\GridViewResult
    {
        $this->_fetch();

        $gridViewResult = new \Assurrussa\GridView\Helpers\GridViewResult();
        $gridViewResult->id = $this->getId();
        $gridViewResult->ajax = $this->ajax;
        $gridViewResult->isTrimLastSlash = $this->isTrimLastSlash;
        $gridViewResult->formAction = $this->getFormAction();
        $gridViewResult->requestParams = $this->requestParams;
        $gridViewResult->headers = $this->columns->toArray();
        $gridViewResult->buttonCreate = $this->buttons->getButtonCreate();
        $gridViewResult->buttonExport = $this->buttons->getButtonExport();
        $gridViewResult->buttonCustoms = $this->buttons->render();
        $gridViewResult->inputCustoms = $this->inputs->render();
        $gridViewResult->filter = $this->_request->toArray();
        $gridViewResult->page = $this->page;
        $gridViewResult->orderBy = $this->orderBy;
        $gridViewResult->search = $this->search;
        $gridViewResult->limit = $this->limit;
        $gridViewResult->sortName = $this->sortName;
        $gridViewResult->counts = $this->counts;
        $gridViewResult->searchInput = $this->searchInput;
        $gridViewResult->exportData = $this->_getExport();

        return $gridViewResult;
    }

    /**
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed|null
     */
    private function _getConfig(string $key, $default = null)
    {
        if (isset($this->_config[$key])) {
            return $this->_config[$key];
        }

        return $default;
    }

    /**
     * @return GridInterface
     * @throws ColumnsException
     * @throws QueryException
     */
    private function _fetch(): GridInterface
    {
        if (!$this->_query) {
            throw new QueryException();
        }
        if (!$this->_model) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        $this->_setRequest()
            ->_hasColumns();

        $this->pagination->setColumns($this->columns);

        if (!$this->columns->count()) {
            throw new ColumnsException();
        }

        $this->requestParams = $this->_request->all();
        $this->page = (int)$this->_request->pull('page', 1);
        $this->orderBy = $this->_request->pull('by', $this->getOrderBy());
        $this->search = $this->_request->pull('search', '');
        $this->limit = $this->_countItems();
        $this->sortName = $this->_request->pull('sort', $this->getSortName());
        $this->exportCurrent = (bool)$this->_request->pull('export', false);

        $this->_filterScopes();
        if ($this->isSearchInput()) {
            $this->_filterSearch($this->search, null, $this->filter['operator'], $this->filter['beforeValue'], $this->filter['afterValue']);
        }
        $this->_filterOrderBy($this->sortName, $this->orderBy);

        return $this;
    }

    /**
     * @return array|null
     */
    private function _getExport(): ?array
    {
        if($this->isExport()) {
            return (new ExportData())->fetch($this->_query, $this->columns->toFields());
        }

        return null;
    }

    /**
     * @return string
     */
    private function _getPaginationRender(): string
    {
        return $this->pagination->render($this->_getConfig('pathPagination'), $this->requestParams, $this->getFormAction());
    }

    /**
     * @return $this
     */
    private function _setRequest(): GridInterface
    {
        if (!$this->_request) {
            $this->_request = collect(request()->all());
        }

        return $this;
    }

    /**
     * Very simple filtration scopes.<br><br>
     *
     * Example:
     * * method - `public function scopeCatalogId($int) {}`
     *
     * @return \Illuminate\Support\Collection
     */
    private function _filterScopes(): \Illuminate\Support\Collection
    {
        if (count($this->_request) > 0) {
            foreach ($this->_request as $scope => $value) {
                if (!empty($value) || $value === 0 || $value === '0') {
                    $value = (string)$value;
                    //checked scope method for model
                    if (method_exists($this->_model, 'scope' . \Illuminate\Support\Str::camel($scope))) {
                        $this->_query->{\Illuminate\Support\Str::camel($scope)}($value);
                    } else {
                        $this->_filterSearch($scope, $value, $this->filter['operator'], $this->filter['beforeValue'],
                            $this->filter['afterValue']);
                    }
                }
            }
            $this->_query->addSelect($this->_model->getTable() . '.*');
        }

        return $this->_request;
    }

    /**
     * The method filters the data according
     *
     * @param string|int|null $search
     * @param mixed|null      $value       word
     * @param string          $operator    equal sign - '=', 'like' ...
     * @param string          $beforeValue First sign before value
     * @param string          $afterValue  Last sign after value
     */
    private function _filterSearch(
        string $search = null,
        string $value = null,
        string $operator = '=',
        string $beforeValue = '',
        string $afterValue = ''
    ): void {
        if ($search) {
            if ($value) {
                $value = trim($value);
            }
            $search = trim($search);
            // поиск по словам
            $this->_query->where(function ($query) use (
                $search,
                $value,
                $operator,
                $beforeValue,
                $afterValue
            ) {
                /** @var \Illuminate\Database\Eloquent\Builder $query */
                $tableName = $this->_model->getTable();
                if ($value) {
                    if (Model::hasColumn($this->_model, $search)) {
                        $query->orWhere($tableName . '.' . $search, $operator, $beforeValue . $value . $afterValue);
                    }
                } elseif ($this->isStrictMode()) {
                    if (method_exists($this->_model, 'toFieldsAmiGrid')) {
                        $list = $this->_model->toFieldsAmiGrid();
                        foreach ($list as $column) {
                            if (Model::hasColumn($this->_model, $column)) {
                                $query->orWhere($tableName . '.' . $column, $operator, $beforeValue . $search . $afterValue);
                            }
                        }
                    }
                } else {
                    $list = \Schema::getColumnListing($tableName);
                    foreach ($list as $column) {
                        if ($this->_hasFilterExecuteForCyrillicColumn($search, $column)) {
                            continue;
                        }
                        if (Model::hasColumn($this->_model, $column)) {
                            $query->orWhere($tableName . '.' . $column, $operator, $beforeValue . $search . $afterValue);
                        }
                    }
                }
            });
        }
    }

    /**
     * @param string $sortName
     * @param string $orderBy
     */
    private function _filterOrderBy(string $sortName, string $orderBy): void
    {
        if ($sortName) {
            if (!Model::hasColumn($this->_model, $sortName)) {
                $sortName = $this->sortNameDefault;
                $this->sortName = $this->sortNameDefault;
            }
            $this->_query->orderBy($this->_query->getModel()->getTable() . '.' . $sortName, $orderBy);
        }
    }

    /**
     * Because of problems with the search Cyrillic, crutch.<br><br>
     * Из-за проблем поиска с кириллицей, костыль.
     *
     * @param string $search
     * @param string $column
     *
     * @return bool
     */
    private function _hasFilterExecuteForCyrillicColumn(string $search, string $column): bool
    {
        if (!preg_match("/[\w]+/i", $search) && \Assurrussa\GridView\Enums\FilterEnum::hasFilterExecuteForCyrillicColumn($column)) {
            return true;
        }

        return false;
    }

    /**
     * @return int
     */
    private function _countItems(): int
    {
        $count = $this->_request->has('count')
            ? $this->_request->pull('count')
            : \Illuminate\Support\Arr::first($this->counts);

        if (!isset($this->counts[$count])) {
            $count = \Illuminate\Support\Arr::first($this->counts, null, 10);
        }

        return (int)$count;
    }

    /**
     * Check exists prepared columns<br><br>
     * Проверка существует ли предварительная подготовка данных.
     */
    private function _hasColumns(): void
    {
        if (!$this->columns->count()) {
            $this->_prepareColumns();
        }
    }

    /**
     * The method takes the default column for any model<br><br>
     * Метод получает колонки по умолчанию для любой модели
     */
    private function _prepareColumns(): void
    {
        if (method_exists($this->_model, 'toFieldsAmiGrid')) {
            $lists = $this->_model->toFieldsAmiGrid();
        } else {
            $lists = \Schema::getColumnListing($this->_model->getTable());
        }
        if ($this->isVisibleColumn()) {
            $lists = array_diff($lists, $this->_model->getHidden());
        }
        foreach ($lists as $key => $list) {
            $this->column($list, $list)
                ->setDateActive(true)
                ->setSort(true);
        }
        $this->columnActions(function ($data) {
            $buttons = [];
            if ($this->_getConfig('routes')) {
                $pathNameForModel = strtolower(\Illuminate\Support\Str::plural(\Illuminate\Support\Str::camel(class_basename($data))));
                $buttons[] = $this->columnAction()
//                    ->setActionDelete('amigrid.delete', [$pathNameForModel, $data->id])
                    ->setUrl('/' . $pathNameForModel . '/delete')
                    ->setLabel('delete');
                $buttons[] = $this->columnAction()
//                    ->setActionShow('amigrid.show', [$pathNameForModel, $data->id])
                    ->setUrl('/' . $pathNameForModel)
                    ->setLabel('show')
//                    ->setHandler(function ($data) {
//                        return $data->id % 2;
//                    })
                ;
                $buttons[] = $this->columnAction()
//                    ->setActionEdit('amigrid.edit', [$pathNameForModel, $data->id])
                    ->setUrl('/' . $pathNameForModel . '/edit')
                    ->setLabel('edit');
            }

            return $buttons;
        });
        if ($this->_getConfig('routes')) {
            $pathNameForModel = strtolower(\Illuminate\Support\Str::plural(\Illuminate\Support\Str::camel(class_basename($this->_model))));
            $this->button()->setButtonCreate('/' . $pathNameForModel . '/create');
        }
    }
}
