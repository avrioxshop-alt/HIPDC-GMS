<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('goods.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $direction = $data['direction'] ?? null;
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $companyId = validateOptionalId($data, 'company_id');
    if ($companyId === null) {
        throw new InvalidArgumentException('Company is required.');
    }
    $vehicleId = validateOptionalId($data, 'vehicle_id');
    $driverId = validateOptionalId($data, 'driver_id');
    $scheduleId = validateOptionalId($data, 'schedule_id');
    $gateId = validateOptionalId($data, 'gate_id');
    $laneId = validateOptionalId($data, 'lane_id');
    $purpose = validateOperationalText($data, 'purpose', 255, true);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT status,submitted_by FROM goods_declarations WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $current = $lock->fetch();
    if (!$current) {
        $connection->rollBack();
        notFoundResponse('Goods declaration not found.');
    }
    assertGoodsDeclarationScope($actor, (int) $current['submitted_by']);
    if ($current['status'] !== 'draft') {
        $connection->rollBack();
        jsonResponse(false, 'Only draft declarations can be updated.', [], 409);
    }
    validateGoodsReferences(
        $connection,
        $actor,
        $companyId,
        $vehicleId,
        $driverId,
        $scheduleId,
        $gateId,
        $laneId,
        $direction
    );
    $update = $connection->prepare(
        'UPDATE goods_declarations
         SET direction=:direction,company_id=:company_id,vehicle_id=:vehicle_id,
             driver_id=:driver_id,schedule_id=:schedule_id,gate_id=:gate_id,
             lane_id=:lane_id,purpose=:purpose
         WHERE id=:id AND status=\'draft\''
    );
    $update->execute([
        'direction' => $direction,
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'schedule_id' => $scheduleId,
        'gate_id' => $gateId,
        'lane_id' => $laneId,
        'purpose' => $purpose,
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'goods.update', 'goods_declaration', $id);
    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    handleOperationalValidation($exception);
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    respondToOperationalConflict($exception, 'Goods declaration references conflict with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['declaration' => goodsDeclarationRecord($connection, $id)], 'Goods declaration updated.');
