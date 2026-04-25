import Foundation

struct TelemetryEvent: Codable, Equatable {
    var campaignUUID: String
    var deviceID: String
    var eventType: String
    var occurredAt: Date

    enum EventType {
        static let delivered = "delivered"
        static let opened = "opened"
        static let deeplinked = "deeplinked"
    }
}
