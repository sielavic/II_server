<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;
use Symfony\Contracts\Cache\CacheInterface;

final class PostController extends AbstractController
{
    private const int CACHE_TTL = 2629800; // 1 месяц в секундах

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Connection $connection
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/gallery', name: 'api_gallery_list', methods: ['GET', 'HEAD'])]
    #[OA\Get(
        path: '/api/gallery',
        operationId: 'getGalleryList',
        description: 'Возвращает список всех опубликованных изображений галереи (GET) или проверяет их наличие (HEAD)',
        summary: 'Получение списка изображений галереи',
        tags: ['Client API'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный ответ (GET)',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Кэширование на 1 месяц',
                        schema: new OA\Schema(type: 'string')
                    ),
                    new OA\Header(
                        header: 'ETag',
                        description: 'Хэш контента для валидации',
                        schema: new OA\Schema(type: 'string')
                    )
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'status',
                            type: 'string',
                            example: 'success'
                        ),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'images',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(
                                                property: 'id',
                                                type: 'integer',
                                                example: 1
                                            ),
                                            new OA\Property(
                                                property: 'description',
                                                type: 'string',
                                                example: 'самисмимс'
                                            ),
                                            new OA\Property(
                                                property: 'filename',
                                                type: 'string',
                                                example: '67e92f18c6a32.jpg'
                                            ),
                                            new OA\Property(
                                                property: 'links',
                                                properties: [
                                                    new OA\Property(
                                                        property: 'self',
                                                        type: 'string',
                                                        example: '/api/gallery/1'
                                                    )
                                                ],
                                                type: 'object'
                                            )
                                        ]
                                    )
                                )
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'meta',
                            properties: [
                                new OA\Property(
                                    property: 'count',
                                    type: 'integer',
                                    example: 1
                                ),
                                new OA\Property(
                                    property: 'cache',
                                    properties: [
                                        new OA\Property(
                                            property: 'key',
                                            type: 'string',
                                            example: 'gallery_list_query'
                                        ),
                                        new OA\Property(
                                            property: 'status',
                                            type: 'string',
                                            example: 'enabled'
                                        ),
                                        new OA\Property(
                                            property: 'ttl',
                                            type: 'integer',
                                            example: 2629800
                                        )
                                    ],
                                    type: 'object'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 304,
                description: 'Контент не изменился (по ETag)',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Актуальный хэш контента',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            )
        ]
    )]
    #[OA\Head(
        path: '/api/gallery',
        operationId: 'headGalleryList',
        description: 'Проверка состояния списка изображений галереи (возвращает только заголовки)',
        summary: 'Проверка списка изображений',
        tags: ['Client API'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный ответ (HEAD)',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Кэширование на 1 месяц',
                        schema: new OA\Schema(type: 'string')
                    ),
                    new OA\Header(
                        header: 'ETag',
                        description: 'Хэш контента для валидации',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            ),
            new OA\Response(
                response: 304,
                description: 'Контент не изменился (по ETag)',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Актуальный хэш контента',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            )
        ]
    )]
    public function galleryList(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $cacheKey = 'gallery_list_query';

        // Кешируем только данные (не весь ответ)
        $data = $this->cache->get($cacheKey, function () use ($cacheKey) {
            $sql = "SELECT id, description, file_name FROM image 
                WHERE parent_id IS NULL AND is_published = 1
                ORDER BY id DESC";

            $images = $this->connection->fetchAllAssociative($sql);

            return [
                'status' => 'success',
                'data' => [
                    'images' => array_map(function ($img) {
                        return [
                            'id' => $img['id'],
                            'description' => $img['description'],
                            'filename' => $img['file_name'],
                            'links' => [
                                'self' => $this->generateUrl('api_gallery_detail', ['id' => $img['id']])
                            ]
                        ];
                    }, $images)
                ],
                'meta' => [
                    'count' => count($images),
                    'cache' => [
                        'key' => $cacheKey,
                        'ttl' => self::CACHE_TTL
                    ]
                ]
            ];
        });

        return $this->extracted($data, $startTime, $request);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/gallery/{id}', name: 'api_gallery_detail', methods: ['GET', 'HEAD'])]
    #[OA\Get(
        path: '/api/gallery/{id}',
        operationId: 'getGalleryDetail',
        description: 'Возвращает информацию об изображении,
         его дочерних элементах и связанных видео (GET) или проверяет их наличие (HEAD)',
        summary: 'Получение детальной информации об изображении',
        tags: ['Client API'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID основного изображения',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный ответ (GET)',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Кэширование на 1 месяц',
                        schema: new OA\Schema(type: 'string')
                    ),
                    new OA\Header(
                        header: 'ETag',
                        description: 'Хэш контента для валидации',
                        schema: new OA\Schema(type: 'string')
                    )
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'status',
                            type: 'string',
                            example: 'success'
                        ),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'parentImage',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'description', type: 'string', example: 'Описание'),
                                        new OA\Property(property: 'filename', type: 'string', example: 'image.jpg'),
                                        new OA\Property(
                                            property: 'links',
                                            properties: [
                                                new OA\Property(
                                                    property: 'self',
                                                    type: 'string',
                                                    example: '/api/gallery/1'
                                                )
                                            ],
                                            type: 'object'
                                        )
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(
                                    property: 'childImages',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer'),
                                            new OA\Property(property: 'description', type: 'string'),
                                            new OA\Property(property: 'filename', type: 'string'),
                                            new OA\Property(
                                                property: 'links',
                                                properties: [
                                                    new OA\Property(property: 'self', type: 'string')
                                                ],
                                                type: 'object'
                                            )
                                        ]
                                    )
                                ),
                                new OA\Property(
                                    property: 'videos',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer'),
                                            new OA\Property(property: 'title', type: 'string'),
                                            new OA\Property(property: 'youtubeUrl', type: 'string'),
                                            new OA\Property(
                                                property: 'links',
                                                properties: [
                                                    new OA\Property(property: 'source', type: 'string')
                                                ],
                                                type: 'object'
                                            )
                                        ]
                                    )
                                )
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'meta',
                            properties: [
                                new OA\Property(
                                    property: 'cache',
                                    properties: [
                                        new OA\Property(
                                            property: 'child_images',
                                            type: 'string',
                                            example: 'child_images_1'
                                        ),
                                        new OA\Property(property: 'videos', type: 'string', example: 'videos_1')
                                    ],
                                    type: 'object'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 304,
                description: 'Контент не изменился (по ETag)',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Актуальный хэш контента',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            ),
            new OA\Response(
                response: 404,
                description: 'Изображение не найдено',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'error'),
                        new OA\Property(property: 'message', type: 'string', example: 'Изображение не найдено')
                    ]
                )
            )
        ]
    )]
    #[OA\Head(
        path: '/api/gallery/{id}',
        operationId: 'headGalleryDetail',
        description: 'Проверка состояния изображения и связанных данных (возвращает только заголовки)',
        summary: 'Проверка детальной информации об изображении',
        tags: ['Client API'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID основного изображения',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный ответ (HEAD)',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Кэширование на 1 месяц',
                        schema: new OA\Schema(type: 'string')
                    ),
                    new OA\Header(
                        header: 'ETag',
                        description: 'Хэш контента для валидации',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            ),
            new OA\Response(
                response: 304,
                description: 'Контент не изменился (по ETag)',
                headers: [
                    new OA\Header(
                        header: 'ETag',
                        description: 'Актуальный хэш контента',
                        schema: new OA\Schema(type: 'string')
                    )
                ]
            ),
            new OA\Response(
                response: 422,
                description: 'Изображение не найдено'
            )
        ]
    )]
    public function galleryDetail(Request $request, int $id): JsonResponse
    {
        $startTime = microtime(true);
        $cacheKey = "gallery_detail_{$id}";

        $data = $this->cache->get($cacheKey, function () use ($cacheKey, $id) {
            // Основное изображение
            $parent = $this->connection->fetchAssociative(
                "SELECT id, description, file_name FROM image WHERE id = ?",
                [$id]
            );

            if (!$parent) {
                throw $this->createNotFoundException('Изображение не найдено');
            }

            // Дочерние изображения
            $children = $this->connection->fetchAllAssociative(
                "SELECT id, description, file_name FROM image WHERE parent_id = ?",
                [$id]
            );

            // Видео
            $videos = $this->connection->fetchAllAssociative(
                "SELECT id, title, youtube_url FROM video WHERE image_id = ?",
                [$id]
            );

            return [
                'status' => 'success',
                'data' => [
                    'parentImage' => [
                        'id' => $parent['id'],
                        'description' => $parent['description'],
                        'filename' => $parent['file_name'],
                        'links' => [
                            'self' => $this->generateUrl('api_gallery_detail', ['id' => $parent['id']])
                        ]
                    ],
                    'childImages' => array_map(function ($img) {
                        return [
                            'id' => $img['id'],
                            'description' => $img['description'],
                            'filename' => $img['file_name'],
                            'links' => [
                                'self' => $this->generateUrl('api_gallery_detail', ['id' => $img['id']])
                            ]
                        ];
                    }, $children),
                    'videos' => array_map(function ($video) {
                        return [
                            'id' => $video['id'],
                            'title' => $video['title'],
                            'youtubeUrl' => $video['youtube_url'],
                            'links' => [
                                'source' => $video['youtube_url']
                            ]
                        ];
                    }, $videos)
                ],
                'meta' => [
                    'cache' => [
                        'key' => $cacheKey,
                        'ttl' => self::CACHE_TTL
                    ]
                ]
            ];
        });

        return $this->extracted($data, $startTime, $request);
    }

    /**
     * @param mixed $data
     * @param $startTime
     * @param Request $request
     * @return JsonResponse
     */
    public function extracted(mixed $data, $startTime, Request $request): JsonResponse
    {
        $etag = hash('xxh128', json_encode($data));
        $data['meta']['query_time'] = round(microtime(true) - $startTime, 4) . 's';

        if ($request->isMethodCacheable() && $request->headers->get('If-None-Match') === $etag) {
            return new JsonResponse(null, 304);
        }

        return $this->json($data)
            ->setEtag($etag)
            ->setMaxAge(self::CACHE_TTL)
            ->setSharedMaxAge(self::CACHE_TTL);
    }
}
