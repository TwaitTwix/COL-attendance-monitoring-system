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

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    respond(405, [
        'success' => false,
        'message' => 'Only GET and POST requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $admin = requireAuthorizedUser($pdo, ['administrator', 'event_officer']);
    $adminId = (string) ($admin['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "
            SELECT
                id,
                template_name,
                report_type,
                scope,
                event_id,
                is_default,
                is_active,
                template_config,
                created_by,
                updated_by,
                created_at,
                updated_at
            FROM public.report_templates
            ORDER BY created_at DESC
        ";

        $stmt = $pdo->query($sql);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'count' => count($templates),
            'templates' => $templates,
        ]);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($input)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON request body.',
        ]);
    }

    $templateName = trim((string) ($input['template_name'] ?? ''));
    $reportType = trim((string) ($input['report_type'] ?? 'attendance_summary'));
    $scope = trim((string) ($input['scope'] ?? 'global'));
    $eventId = trim((string) ($input['event_id'] ?? ''));
    $isDefault = (bool) ($input['is_default'] ?? false);
    $isActive = (bool) ($input['is_active'] ?? true);
    $templateConfig = $input['template_config'] ?? [
        'columns' => ['student_name', 'attendance_status', 'checkin_method'],
    ];

    if ($templateName === '') {
        respond(422, [
            'success' => false,
            'message' => 'template_name is required.',
        ]);
    }

    if ($scope === 'event' && $eventId === '') {
        respond(422, [
            'success' => false,
            'message' => 'event_id is required when scope is event.',
        ]);
    }

    $eventExists = true;
    if ($eventId !== '') {
        $eventStmt = $pdo->prepare('SELECT id FROM public.events WHERE id = :event_id LIMIT 1');
        $eventStmt->execute(['event_id' => $eventId]);
        $eventExists = (bool) $eventStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$eventExists) {
        respond(404, [
            'success' => false,
            'message' => 'No event was found for the supplied event_id.',
        ]);
    }

    $templateId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();
    $versionNumber = 1;

    $insertSql = "
        INSERT INTO public.report_templates (
            id,
            template_name,
            report_type,
            scope,
            event_id,
            is_default,
            is_active,
            template_config,
            created_by,
            updated_by,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :template_name,
            :report_type,
            :scope,
            :event_id,
            :is_default,
            :is_active,
            :template_config,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        'id' => $templateId,
        'template_name' => $templateName,
        'report_type' => $reportType,
        'scope' => $scope,
        'event_id' => $eventId !== '' ? $eventId : null,
        'is_default' => $isDefault ? 1 : 0,
        'is_active' => $isActive ? 1 : 0,
        'template_config' => json_encode($templateConfig, JSON_THROW_ON_ERROR),
        'created_by' => $adminId,
        'updated_by' => $adminId,
    ]);

    $versionInsertSql = "
        INSERT INTO public.report_template_versions (
            id,
            template_id,
            version_number,
            template_config,
            changed_by,
            change_note,
            created_at
        ) VALUES (
            :id,
            :template_id,
            :version_number,
            :template_config,
            :changed_by,
            :change_note,
            NOW()
        )
    ";

    $versionId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();
    $versionStmt = $pdo->prepare($versionInsertSql);
    $versionStmt->execute([
        'id' => $versionId,
        'template_id' => $templateId,
        'version_number' => $versionNumber,
        'template_config' => json_encode($templateConfig, JSON_THROW_ON_ERROR),
        'changed_by' => $adminId,
        'change_note' => 'Initial template version',
    ]);

    $createdSql = "
        SELECT
            id,
            template_name,
            report_type,
            scope,
            event_id,
            is_default,
            is_active,
            template_config,
            created_by,
            updated_by,
            created_at,
            updated_at
        FROM public.report_templates
        WHERE id = :id
        LIMIT 1
    ";

    $createdStmt = $pdo->prepare($createdSql);
    $createdStmt->execute(['id' => $templateId]);
    $template = $createdStmt->fetch(PDO::FETCH_ASSOC);

    respond(201, [
        'success' => true,
        'message' => 'Report template created successfully.',
        'template' => $template,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Report template endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Report template endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The report template operation could not be completed.',
    ]);
}
