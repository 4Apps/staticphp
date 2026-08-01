<?php

namespace Api\Controllers;

use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Core\Exceptions\ErrorMessage;
use StaticPHP\Core\Exceptions\ErrorMessage\BadRequest;
use StaticPHP\Utils\Models\Csrf;

/**
 * Json api for the react front end.
 *
 * Returning an array is all it takes - the router sets the json content type and encodes
 * it. Thrown ErrorMessages come back as json too, because the router picks the output
 * format from the request's content type.
 *
 * The store is the session, so the demo needs no database. Everything read back out of it
 * is untyped, which is why store() rebuilds a typed list rather than handing $_SESSION
 * straight to the caller.
 */
class Items extends Controller
{
    public static function construct(?string $class = null, ?string $method = null): void
    {
        // No parent::construct() - that one exists for view rendering, and an api has no
        // views. Skipping it also skips building the twig environment.

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            return;
        }

        // Everything that changes state has to carry the token. React sends it as
        // X-CSRF-Token; the framework does not wire this up on its own, on purpose.
        if (Csrf::validateRequest() === false) {
            throw new ErrorMessage(
                message: 'Invalid CSRF token',
                httpStatusCode: 403
            );
        }
    }

    /**
     * GET /api/items
     *
     * @return array<string, mixed>
     */
    public static function index(): array
    {
        return [
            'items' => self::store(),
            'served_by' => 'php ' . PHP_VERSION,
            'served_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * POST /api/items/create - the body is json, which the router has already merged
     * into $_POST.
     *
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        $title = self::text($_POST['title'] ?? null);
        if ($title === '') {
            throw new BadRequest('Title is required');
        }

        $items = self::store();

        $ids = array_map(static fn(array $item): int => $item['id'], $items);
        $item = [
            'id' => ($ids === [] ? 1 : max($ids) + 1),
            'title' => $title,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $items[] = $item;
        $_SESSION['items'] = $items;

        return ['item' => $item];
    }

    /**
     * @return list<array{id: int, title: string, created_at: string}>
     */
    private static function store(): array
    {
        $stored = $_SESSION['items'] ?? null;

        if (is_array($stored) === false) {
            $seed = [['id' => 1, 'title' => 'Seeded by php', 'created_at' => date('Y-m-d H:i:s')]];
            $_SESSION['items'] = $seed;

            return $seed;
        }

        $items = [];
        foreach ($stored as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $id = ($row['id'] ?? 0);
            $items[] = [
                'id' => (is_int($id) ? $id : 0),
                'title' => self::text($row['title'] ?? null),
                'created_at' => self::text($row['created_at'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * Session and request data arrive untyped; this is where they stop being mixed.
     */
    private static function text(mixed $value): string
    {
        return (is_string($value) ? trim($value) : '');
    }
}
