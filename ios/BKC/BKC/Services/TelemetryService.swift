import Foundation

final class TelemetryService {
    static let shared = TelemetryService()

    private let pendingKey = "bkc.telemetry.pending"
    private let maxAgeSeconds: TimeInterval = 7 * 24 * 60 * 60
    private var flushTimer: Timer?
    private let encoder = JSONEncoder()
    private let decoder = JSONDecoder()

    private init() {
        encoder.dateEncodingStrategy = .iso8601
        decoder.dateDecodingStrategy = .iso8601
        startTimer()
    }

    func logDelivered(campaignUUID: String) {
        append(eventType: TelemetryEvent.EventType.delivered, campaignUUID: campaignUUID)
    }

    func logOpened(campaignUUID: String) {
        append(eventType: TelemetryEvent.EventType.opened, campaignUUID: campaignUUID)
    }

    func logDeeplinked(campaignUUID: String) {
        append(eventType: TelemetryEvent.EventType.deeplinked, campaignUUID: campaignUUID)
    }

    func flush() async {
        var events = loadPending()
        // Drop events older than 7 days
        let cutoff = Date().addingTimeInterval(-maxAgeSeconds)
        events = events.filter { $0.occurredAt >= cutoff }

        guard !events.isEmpty else { return }

        do {
            let accepted = try await BKCAPIClient.shared.sendEvents(events)
            // Remove the events that were successfully sent (first `accepted` in order)
            let remaining = Array(events.dropFirst(min(accepted, events.count)))
            savePending(remaining)
        } catch {
            // Keep buffer intact on failure; will retry next flush
            savePending(events)
        }
    }

    // MARK: - Private

    private func append(eventType: String, campaignUUID: String) {
        let deviceID = PushService.shared.getOrCreateDeviceID()
        let event = TelemetryEvent(
            campaignUUID: campaignUUID,
            deviceID: deviceID,
            eventType: eventType,
            occurredAt: Date()
        )
        var pending = loadPending()
        pending.append(event)
        savePending(pending)
    }

    /// Test seam: exposes the same persistence read path used at process start
    /// so tests can verify the buffer survives a simulated restart.
    func loadPendingForTesting() -> [TelemetryEvent] {
        loadPending()
    }

    private func loadPending() -> [TelemetryEvent] {
        guard let data = UserDefaults.standard.data(forKey: pendingKey) else { return [] }
        return (try? decoder.decode([TelemetryEvent].self, from: data)) ?? []
    }

    private func savePending(_ events: [TelemetryEvent]) {
        guard let data = try? encoder.encode(events) else { return }
        UserDefaults.standard.set(data, forKey: pendingKey)
    }

    private func startTimer() {
        flushTimer = Timer.scheduledTimer(withTimeInterval: 5 * 60, repeats: true) { [weak self] _ in
            Task { await self?.flush() }
        }
    }
}
