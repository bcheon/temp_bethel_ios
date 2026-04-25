import Foundation

protocol BKCAPI {
    func subscribe(token: String, deviceID: String, groups: [String]) async throws
    func updateGroups(deviceID: String, groups: [String]) async throws
    func unsubscribe(deviceID: String) async throws
    func fetchCampaigns(since: Date?) async throws -> [PushCampaign]
    func sendEvents(_ events: [TelemetryEvent]) async throws -> Int
}

enum BKCAPIError: Error, Equatable {
    case network(String)
    case http(Int)
    case decoding
}
