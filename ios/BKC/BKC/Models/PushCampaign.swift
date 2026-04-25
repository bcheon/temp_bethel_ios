import Foundation

struct PushCampaign: Codable, Identifiable, Equatable {
    var uuid: String
    var title: String
    var body: String
    var deepLink: URL?
    var targetGroups: [String]
    var sentAt: Date
    var isRead: Bool

    var id: String { uuid }

    init(uuid: String, title: String, body: String, deepLink: URL? = nil,
         targetGroups: [String] = [], sentAt: Date = Date(), isRead: Bool = false) {
        self.uuid = uuid
        self.title = title
        self.body = body
        self.deepLink = deepLink
        self.targetGroups = targetGroups
        self.sentAt = sentAt
        self.isRead = isRead
    }
}
