import Foundation
import UIKit
import FirebaseMessaging
import Security
import UserNotifications

enum PushServiceError: Error, Equatable {
    case unknownGroup(String)
}

final class PushService: NSObject {
    static let shared = PushService()

    private let keychainService = "org.bkc.churchapp"
    private let keychainAccountDeviceID = "deviceID"
    private let userDefaultsDeviceIDKey = "bkc.deviceID"

    private override init() {}

    func requestAuthorization() async -> Bool {
        let center = UNUserNotificationCenter.current()
        do {
            let granted = try await center.requestAuthorization(options: [.alert, .badge, .sound])
            if granted {
                await MainActor.run {
                    UIApplication.shared.registerForRemoteNotifications()
                }
            }
            return granted
        } catch {
            return false
        }
    }

    func getOrCreateDeviceID() -> String {
        if let existing = readFromKeychain(account: keychainAccountDeviceID) {
            return existing
        }
        if let fallback = UserDefaults.standard.string(forKey: userDefaultsDeviceIDKey) {
            // Migrate to keychain
            saveToKeychain(account: keychainAccountDeviceID, value: fallback)
            return fallback
        }
        let newID = UUID().uuidString
        saveToKeychain(account: keychainAccountDeviceID, value: newID)
        UserDefaults.standard.set(newID, forKey: userDefaultsDeviceIDKey)
        return newID
    }

    func subscribe(toGroup group: String) async throws {
        guard let topic = SubscriptionGroup(rawValue: group)?.topic else {
            throw PushServiceError.unknownGroup(group)
        }
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
            Messaging.messaging().subscribe(toTopic: topic) { error in
                if let error = error {
                    continuation.resume(throwing: error)
                } else {
                    continuation.resume()
                }
            }
        }
    }

    func unsubscribe(fromGroup group: String) async throws {
        guard let topic = SubscriptionGroup(rawValue: group)?.topic else {
            throw PushServiceError.unknownGroup(group)
        }
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
            Messaging.messaging().unsubscribe(fromTopic: topic) { error in
                if let error = error {
                    continuation.resume(throwing: error)
                } else {
                    continuation.resume()
                }
            }
        }
    }

    func handleTokenRefresh(_ token: String) {
        let deviceID = getOrCreateDeviceID()
        let groups = Array(GroupStore.shared.groups)
        Task {
            try? await BKCAPIClient.shared.subscribe(token: token, deviceID: deviceID, groups: groups)
        }
    }

    // MARK: - Keychain helpers

    private func saveToKeychain(account: String, value: String) {
        guard let data = value.data(using: .utf8) else { return }
        let query: [CFString: Any] = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: keychainService,
            kSecAttrAccount: account,
            kSecValueData: data
        ]
        SecItemDelete(query as CFDictionary)
        SecItemAdd(query as CFDictionary, nil)
    }

    private func readFromKeychain(account: String) -> String? {
        let query: [CFString: Any] = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: keychainService,
            kSecAttrAccount: account,
            kSecReturnData: true,
            kSecMatchLimit: kSecMatchLimitOne
        ]
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }
}
