// Only events first observed after this session's baseline can start a reminder.
export class PendingOrderAlerts {
  constructor() { this.reset() }
  reset() { this.initialized = false; this.baseline = new Set(); this.pending = new Map() }
  observe(rows, now = Date.now()) {
    const current = new Map(rows.filter(row => row.id && Date.parse(row.expires_at) > now).map(row => [row.id, row]))
    if (!this.initialized) {
      this.initialized = true
      this.baseline = new Set(current.keys())
    }
    this.pending = new Map([...current].filter(([id]) => !this.baseline.has(id)))
    return [...this.pending.values()]
  }
  removeOrder(id) {
    for (const [key, row] of this.pending) if (row.order_id === id) this.pending.delete(key)
  }
  due(now = Date.now()) { return [...this.pending.values()].filter(row => Date.parse(row.expires_at) > now) }
}

export function claimOrderReminder(storage, key, now, interval) {
  try {
    const previous = Number(storage.getItem(key))
    if (previous > 0 && now - previous < interval) return false
    storage.setItem(key, String(now))
    return true
  } catch { return false } // Shared storage is required to avoid duplicate tab reminders.
}
