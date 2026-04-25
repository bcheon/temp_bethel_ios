import Foundation

enum GroupStoreError: Error {
    case cannotUnsubscribeAll
    case partialFailure(Error)
}

// Test seam: lets us drive the add/remove + rollback flow without touching FCM.
struct GroupSyncOps {
    var subscribe: (String) async throws -> Void
    var unsubscribe: (String) async throws -> Void
}

enum GroupSync {
    /// Apply add/remove ops with rollback on partial failure. Returns the
    /// list of operations actually executed against FCM (after rollback).
    @discardableResult
    static func apply(
        toAdd: [String],
        toRemove: [String],
        ops: GroupSyncOps
    ) async throws -> (addedTopics: [String], removedTopics: [String]) {
        var successfulAdds: [String] = []
        var successfulRemoves: [String] = []
        do {
            for group in toAdd {
                try await ops.subscribe(group)
                successfulAdds.append(group)
            }
            for group in toRemove {
                try await ops.unsubscribe(group)
                successfulRemoves.append(group)
            }
            return (successfulAdds, successfulRemoves)
        } catch {
            // Rollback in reverse order
            for group in successfulAdds {
                try? await ops.unsubscribe(group)
            }
            for group in successfulRemoves {
                try? await ops.subscribe(group)
            }
            throw GroupStoreError.partialFailure(error)
        }
    }
}

final class GroupStore: ObservableObject {
    static let shared = GroupStore()

    @Published private(set) var groups: Set<String>
    @Published private(set) var hasOnboarded: Bool

    private let groupsKey = "bkc.groups"
    private let onboardedKey = "bkc.hasOnboarded"

    private init() {
        if let saved = UserDefaults.standard.stringArray(forKey: "bkc.groups") {
            groups = Set(saved)
        } else {
            groups = []
        }
        hasOnboarded = UserDefaults.standard.bool(forKey: "bkc.hasOnboarded")
    }

    func completeOnboarding(groups requestedGroups: [String]) async throws {
        var newGroups = Set(requestedGroups)
        newGroups.insert(SubscriptionGroup.all.rawValue)

        // Subscribe FCM topics
        for group in newGroups {
            try await PushService.shared.subscribe(toGroup: group)
        }

        // Register with server
        let token = await fcmToken()
        let deviceID = PushService.shared.getOrCreateDeviceID()
        try await BKCAPIClient.shared.subscribe(token: token, deviceID: deviceID, groups: Array(newGroups))

        // Only persist on full success
        await MainActor.run {
            self.groups = newGroups
            self.hasOnboarded = true
            UserDefaults.standard.set(Array(newGroups), forKey: groupsKey)
            UserDefaults.standard.set(true, forKey: onboardedKey)
        }
    }

    func setGroups(_ newGroups: [String]) async throws {
        let newSet = Set(newGroups)
        guard newSet.contains(SubscriptionGroup.all.rawValue) else {
            throw GroupStoreError.cannotUnsubscribeAll
        }

        let toAdd = Array(newSet.subtracting(groups))
        let toRemove = Array(groups.subtracting(newSet))

        let ops = GroupSyncOps(
            subscribe: { try await PushService.shared.subscribe(toGroup: $0) },
            unsubscribe: { try await PushService.shared.unsubscribe(fromGroup: $0) }
        )
        try await GroupSync.apply(toAdd: toAdd, toRemove: toRemove, ops: ops)

        let deviceID = PushService.shared.getOrCreateDeviceID()
        try await BKCAPIClient.shared.updateGroups(deviceID: deviceID, groups: Array(newSet))

        await MainActor.run {
            self.groups = newSet
            UserDefaults.standard.set(Array(newSet), forKey: groupsKey)
        }
    }

    func resyncFromServer() async throws {
        let campaigns = try await BKCAPIClient.shared.fetchCampaigns(since: nil)
        CampaignCache.shared.merge(campaigns)
    }

    // MARK: - Private

    private func fcmToken() async -> String {
        await withCheckedContinuation { continuation in
            Messaging.messaging().token { token, _ in
                continuation.resume(returning: token ?? "")
            }
        }
    }
}

// Needed for fcmToken helper
import FirebaseMessaging
