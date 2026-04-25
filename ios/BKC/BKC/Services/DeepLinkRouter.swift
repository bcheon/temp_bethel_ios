import Foundation
import Combine
import UIKit

final class DeepLinkRouter: ObservableObject {
    static let shared = DeepLinkRouter()

    @Published var selectedTab: Int = 0
    // Injected URL for WebViewTab (tab 0) when routing to an event page
    @Published var injectedHomeURL: URL? = nil
    // Campaign UUID to highlight in NotificationListView after routing
    @Published var highlightedCampaignUUID: String? = nil

    private init() {}

    func route(url: URL) {
        let deepLink = DeepLink.from(url: url)
        switch deepLink {
        case .home:
            selectedTab = 0
        case .sermon:
            selectedTab = 2
        case .news(let campaignUUID):
            selectedTab = 1
            highlightedCampaignUUID = campaignUUID
            if let uuid = campaignUUID {
                TelemetryService.shared.logDeeplinked(campaignUUID: uuid)
            }
        case .event(let eventURL):
            selectedTab = 0
            injectedHomeURL = eventURL
        case .external(let externalURL):
            UIApplication.shared.open(externalURL)
        }
    }
}
