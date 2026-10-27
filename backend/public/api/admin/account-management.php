<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    respond(405, [
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $admin = requireAuthorizedUser($pdo, ['administrator']);

    $statusFilter = trim((string) ($_GET['status'] ?? 'all'));
    $roleFilter = trim((string) ($_GET['role'] ?? 'all'));
    $search = trim((string) ($_GET['search'] ?? ''));

    $whereClauses = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $whereClauses[] = 'p.account_status = :account_status';
        $params['account_status'] = $statusFilter;
    }

    if ($roleFilter !== 'all') {
        $whereClauses[] = 'r.role_key = :role_key';
        $params['role_key'] = $roleFilter;
    }

    if ($search !== '') {
        $whereClauses[] = '(p.full_name ILIKE :search OR p.email ILIKE :search OR p.student_id ILIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $whereClauses === [] ? '' : ' WHERE ' . implode(' AND ', $whereClauses);

    $sql = "
        SELECT
            p.id,
            p.student_id,
            p.full_name,
            p.email,
            p.department,
            p.program,
            p.year_level,
            p.section,
            p.approval_status,
            p.account_status,
            p.created_at,
            p.updated_at,
            COALESCE(string_agg(DISTINCT r.role_key, ', ' ORDER BY r.role_key), 'none') AS roles
        FROM public.profiles p
        LEFT JOIN public.user_roles ur ON ur.user_id = p.id
        LEFT JOIN public.roles r ON r.id = ur.role_id
        {$whereSql}
        GROUP BY
            p.id,
            p.student_id,
            p.full_name,
            p.email,
            p.department,
            p.program,
            p.year_level,
            p.section,
            p.approval_status,
            p.account_status,
            p.created_at,
            p.updated_at
        ORDER BY p.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'count' => count($rows),
        'rows' => $rows,
        'filters' => [
            'status' => $statusFilter,
            'role' => $roleFilter,
            'search' => $search,
        ],
    ]);
} catch (RuntimeException $e) {
    error_log('Account management authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Account management error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The account management list could not be loaded.',
    ]);
}
