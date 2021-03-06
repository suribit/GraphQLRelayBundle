<?php

namespace Suribit\GraphQLRelayBundle\Types;

use Closure;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ResolveInfo;
use Suribit\GraphQLBundle\Support\Type as GraphQLType;
use Suribit\GraphQLRelayBundle\GlobalIdTrait;

abstract class RelayType extends GraphQLType
{

    use GlobalIdTrait;

    /**
     * List of fields with global identifier.
     *
     * @return array
     */
    public function fields()
    {
        return array_merge($this->relayFields(), $this->getConnections(), [
            'id' => [
                'type' => Type::nonNull(Type::id()),
                'description' => 'ID of type.',
                'resolve' => function ($obj) {
                    return $this->encodeGlobalId(lcfirst($this->attributes['name']), $this->getIdentifier($obj));
                },
            ],
        ]);
    }

    /**
     * Get the identifier of the type.
     *
     * @param  mixed $obj
     * @return mixed
     */
    public function getIdentifier($obj)
    {
        return $obj['id'];
    }

    /**
     * List of available interfaces.
     *
     * @return array
     */
    public function interfaces()
    {
        return [$this->manager->type('node')->original];
    }

    /**
     * Generate Relay compliant edges.
     *
     * @return array
     */
    public function getConnections()
    {
        $edges = [];

        foreach ($this->connections() as $name => $edge) {
            $injectCursor = isset($edge['injectCursor']) ? $edge['injectCursor'] : null;
            $resolveCursor = isset($edge['resolveCursor']) ? $edge['resolveCursor'] : null;

            $edgeType = $this->edgeType($name, $edge['type'], $resolveCursor);
            $connectionType = $this->connectionType($name, Type::listOf($edgeType), $injectCursor);

            $edges[$name] = [
                'type' => $connectionType,
                'description' => 'A connection to a list of items.',
                'args' => [
                    'first' => [
                        'name' => 'first',
                        'type' => Type::int()
                    ],
                    'after' => [
                        'name' => 'after',
                        'type' => Type::string()
                    ]
                ],
                'resolve' => isset($edge['resolve']) ? $edge['resolve'] : function ($collection, array $args, ResolveInfo $info) use ($name) {
                    $items = [];

                    if (is_array($collection) && isset($collection[$name])) {
                        $items = $collection[$name];
                    }

                    if (isset($args['first'])) {
                        $total = count($items);
                        $first = $args['first'];
                        $after = $this->decodeCursor($args);
                        $currentPage = $first && $after ? floor(($first + $after) / $first) : 1;

                        return [
                            'items' => array_slice($items, $after, $first),
                            'total' => $total,
                            'first' => $first,
                            'currentPage' => $currentPage
                        ];
                    }

                    return [
                        'items' => $items,
                        'total' => count($items),
                        'first' => count($items),
                        'currentPage' => 1
                    ];
                }
            ];
        }

        return $edges;
    }

    /**
     * Generate PageInfo object type.
     *
     * @return ObjectType
     */
    protected function pageInfoType()
    {
        return $this->manager->type('pageInfo')->original;
    }

    /**
     * Generate EdgeType.
     *
     * @param  string $name
     * @param  mixed $type
     * @param Closure $resolveCursor
     * @return ObjectType
     */
    protected function edgeType($name, $type, Closure $resolveCursor = null)
    {
        if ($type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }

        return new ObjectType([
            'name' => ucfirst($name) . 'Edge',
            'fields' => [
                'node' => [
                    'type' => $type,
                    'description' => 'The item at the end of the edge.',
                    'resolve' => function ($edge, array $args, ResolveInfo $info) {
                        return $edge;
                    }
                ],
                'cursor' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'A cursor for use in pagination.',
                    'resolve' => function ($edge, array $args, ResolveInfo $info) use ($resolveCursor) {
                        if ($resolveCursor) {
                            return $resolveCursor($edge, $args, $info);
                        }

                        return $this->resolveCursor($edge);
                    }
                ]
            ]
        ]);
    }

    /**
     * Create ConnectionType.
     *
     * @param  string $name
     * @param  mixed $type
     * @param Closure $injectCursor
     * @return ObjectType
     */
    protected function connectionType($name, $type, Closure $injectCursor = null)
    {
        if (!$type instanceof ListOfType) {
            $type = Type::listOf($type);
        }

        return new ObjectType([
            'name' => ucfirst($name) . 'Connection',
            'fields' => [
                'edges' => [
                    'type' => $type,
                    'resolve' => function ($collection, array $args, ResolveInfo $info) use ($injectCursor) {
                        if ($injectCursor) {
                            return $injectCursor($collection, $args, $info);
                        }

                        return $this->injectCursor($collection);
                    }
                ],
                'pageInfo' => [
                    'type' => Type::nonNull($this->pageInfoType()),
                    'description' => 'Information to aid in pagination.',
                    'resolve' => function ($collection, array $args, ResolveInfo $info) {
                        return $collection;
                    }
                ]
            ]
        ]);
    }

    /**
     * Inject encoded cursor into collection items.
     *
     * @param  mixed $collection
     * @return mixed
     */
    protected function injectCursor($collection)
    {
        if ($collection) {
            $page = $collection['currentPage'];

            foreach ($collection['items'] as $x => &$item) {
                $cursor = ($x + 1) * $page;
                $encodedCursor = $this->encodeGlobalId('arrayconnection', $cursor);

                $item['relayCursor'] = $encodedCursor;
            }
        }

        return $collection['items'];
    }

    /**
     * Resolve encoded relay cursor for item.
     *
     * @param  mixed $edge
     * @return string
     */
    protected function resolveCursor($edge)
    {
        return $edge['relayCursor'];
    }

    /**
     * Decode cursor from query arguments.
     *
     * @param  array  $args
     * @return integer
     */
    public function decodeCursor(array $args)
    {
        return isset($args['after']) ? $this->getCursorId($args['after']) : 0;
    }

    /**
     * Get id from encoded cursor.
     *
     * @param  string $cursor
     * @return integer
     */
    protected function getCursorId($cursor)
    {
        return (int)$this->decodeRelayId($cursor);
    }

    /**
     * Available connections for type.
     *
     * @return array
     */
    protected function connections()
    {
        return [];
    }

    /**
     * Get list of available fields for type.
     *
     * @return array
     */
    abstract protected function relayFields();

    /**
     * Fetch type data by id.
     *
     * @param  string $id
     * @return mixed
     */
    abstract public function resolveById($id);

}
