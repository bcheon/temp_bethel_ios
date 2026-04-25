import Foundation

final class CampaignCache: ObservableObject {
    static let shared = CampaignCache()

    @Published private(set) var campaigns: [PushCampaign] = []
    private(set) var lastSyncedAt: Date?

    private let maxCount = 50
    private let cacheURL: URL

    private init() {
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
        cacheURL = caches.appendingPathComponent("campaigns.json")
        load()
    }

    func merge(_ deltas: [PushCampaign]) {
        var existing = campaigns
        for delta in deltas {
            if let idx = existing.firstIndex(where: { $0.uuid == delta.uuid }) {
                // Preserve read state on merge
                var updated = delta
                updated.isRead = existing[idx].isRead
                existing[idx] = updated
            } else {
                existing.append(delta)
            }
        }
        // Sort newest first, cap at maxCount (FIFO eviction of oldest)
        existing.sort { $0.sentAt > $1.sentAt }
        if existing.count > maxCount {
            existing = Array(existing.prefix(maxCount))
        }
        campaigns = existing
        lastSyncedAt = Date()
        persist()
    }

    /// Test seam: empties the in-memory cache and removes the persisted file.
    /// Production code should not call this; only tests need to reset state.
    func clear() {
        campaigns = []
        lastSyncedAt = nil
        try? FileManager.default.removeItem(at: cacheURL)
    }

    func markRead(uuid: String) {
        guard let idx = campaigns.firstIndex(where: { $0.uuid == uuid }) else { return }
        campaigns[idx].isRead = true
        persist()
    }

    // MARK: - Private

    private func load() {
        guard FileManager.default.fileExists(atPath: cacheURL.path) else { return }
        do {
            let data = try Data(contentsOf: cacheURL)
            let decoder = JSONDecoder()
            decoder.dateDecodingStrategy = .iso8601
            let stored = try decoder.decode(StoredCache.self, from: data)
            campaigns = stored.campaigns
            lastSyncedAt = stored.lastSyncedAt
        } catch {
            // Recover from corrupted JSON by clearing
            campaigns = []
            lastSyncedAt = nil
            try? FileManager.default.removeItem(at: cacheURL)
        }
    }

    private func persist() {
        do {
            let encoder = JSONEncoder()
            encoder.dateEncodingStrategy = .iso8601
            let stored = StoredCache(campaigns: campaigns, lastSyncedAt: lastSyncedAt)
            let data = try encoder.encode(stored)
            try data.write(to: cacheURL, options: .atomic)
        } catch {
            // Persistence failure is non-fatal; in-memory state remains valid
        }
    }

    private struct StoredCache: Codable {
        var campaigns: [PushCampaign]
        var lastSyncedAt: Date?
    }
}
