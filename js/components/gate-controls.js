const HIPDC_GateControls = {
  async processVehicle(direction, plate, options = {}) {
    const location = await this.getLocation(direction, options.gateId);
    const body = {
      direction,
      gate_id: location.gate_id,
      lane_id: options.laneId || location.lane_id || null,
      plate_number: plate,
      driver_id: options.driverId || null,
      schedule_id: options.scheduleId || null,
      override_reason: options.overrideReason || null
    };
    return this.record('gate-operations/vehicle.php', body);
  },

  async processVisitor(direction, qrToken, gateId, laneId) {
    const location = await this.getLocation(direction, gateId);
    return this.record('gate-operations/visitor.php', {
      direction, gate_id: location.gate_id, lane_id: laneId || location.lane_id || null,
      qr_token: qrToken
    });
  },

  async validateVisitor(direction, qrToken, gateId) {
    const location = await this.getLocation(direction, gateId);
    const data = await HIPDC_API.post('visitors/passes/validate.php', {
      qr_token: qrToken, gate_id: location.gate_id, direction
    });
    HIPDC_Notify.show(data.valid
      ? 'Visitor pass is valid for this gate and time. No physical event was recorded.'
      : 'Visitor pass is not valid for this gate and time.',
    data.valid ? 'ok' : 'warn', 0);
    return data;
  },

  async processGoods(direction, reference, gateId, laneId) {
    const location = await this.getLocation(direction, gateId);
    return this.record('gate-operations/goods.php', {
      direction, gate_id: location.gate_id, lane_id: laneId || location.lane_id || null,
      goods_reference: reference
    });
  },

  async getLocation(direction, gateId) {
    const locations = await HIPDC_API.currentGateLocations();
    const location = locations.find(item =>
      item.operation_direction === direction && (!gateId || Number(item.gate_id) === Number(gateId))
    );
    if (!location) throw new Error('No active gate is assigned for ' + direction + '.');
    return location;
  },

  async record(endpoint, body) {
    const keyBytes = new Uint8Array(24);
    window.crypto.getRandomValues(keyBytes);
    const idempotencyKey = Array.from(keyBytes, value => value.toString(16).padStart(2, '0')).join('');
    const data = await HIPDC_API.post(endpoint, body, {}, { 'Idempotency-Key': idempotencyKey });
    const event = data.event;
    await HIPDC_DB.refresh();
    const granted = event.access_result === 'granted';
    HIPDC_Notify.show(
      granted
        ? 'Access recorded as granted. No physical barrier or hardware control is connected.'
        : 'Access attempt recorded as denied: ' + (event.denial_reason || 'policy check failed'),
      granted ? 'ok' : 'warn',
      0
    );
    return event;
  },

  openGate(direction, plate, approved = false, details = {}) {
    if (!approved && direction === 'entry') {
      HIPDC_Notify.show('An approved access request is required before entry.', 'warn');
      return Promise.reject(new Error('An approved access request is required before entry.'));
    }
    return this.processVehicle(direction, plate, details);
  },

  denyEntry(plate) {
    HIPDC_Notify.show('No access event was recorded. Submit the observed plate through the authorized gate workflow.', 'warn');
    return plate;
  }
};
