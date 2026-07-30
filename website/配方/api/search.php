<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/recipes.php';

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = isset($_GET['limit']) ? max(0, min(500, (int) $_GET['limit'])) : 0;
$recipes = search_recipes($query, $limit);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'query' => $query,
    'total' => count($recipes),
    'items' => recipes_to_public_payload($recipes),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
